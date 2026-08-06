<?php

/*
 * This file is part of the JUHE Contao OpenAI Assistant premium add-on.
 *
 * (c) JUHE IT-solutions
 *
 * @license Proprietary - see LICENSE-PREMIUM. Usage of the premium add-on
 *          requires a valid premium subscription from JUHE IT-solutions.
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Premium\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Counts the news, FAQ and event items that a reader page renders.
 *
 * Why this exists: news items, FAQ entries and events have no pages of their own.
 * They are all rendered by ONE reader page and differ only by the auto_item part
 * of the URL. Contao indexes each of them under its own URL, but they all share
 * the reader page's tl_page id - so counting tl_page rows alone would let an
 * installation with a single page and hundreds of news items stay inside the
 * smallest subscription plan while putting the content of hundreds of items into
 * the knowledge base. (They all end up in ONE vector-store document; the item
 * budget meters content volume, the page budget meters files.)
 *
 * The counted number is therefore what the plan limit is enforced against, both
 * when the page selection is saved and while a synchronisation runs.
 *
 * Only items that really reach the knowledge base are counted:
 *   - the item must be published, including its optional start/stop window
 *     (same rule Contao itself applies, see PageModel::findPublishedById()),
 *   - an item whose "source" points somewhere else (internal page, article or
 *     external URL) never gets a reader URL - the reader 301-redirects and the
 *     list module links straight to the target - so it is not a document here.
 *
 * Everything is schema-guarded: the news, FAQ and calendar bundles are optional,
 * and the optional columns differ between them (tl_faq has no start/stop and no
 * source). Missing tables and columns are skipped instead of failing the query.
 */
class ReaderItemCounter
{
    /**
     * parent table (carries jumpTo) => item table (carries pid).
     */
    private const SOURCES = [
        'tl_news_archive' => 'tl_news',
        'tl_faq_category' => 'tl_faq',
        'tl_calendar' => 'tl_calendar_events',
    ];

    /**
     * "source" values that make an item redirect instead of rendering on the
     * reader page (ModuleNewsReader / ModuleEventReader throw a
     * RedirectResponseException for these). Such an item has no reader URL at all.
     */
    private const REDIRECTING_SOURCES = ['internal', 'article', 'external'];

    /**
     * @var array<string, bool>|null cache of table existence per PHP process
     */
    private array|null $tableCache = null;

    /**
     * @var array<string, array<string, true>> cache of column names per table, so the
     *                                         save-time check and the sync do not list
     *                                         the same schema over and over
     */
    private array $columnCache = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Number of reader items per page, for the given pages only.
     *
     * Capped at the number of URLs the page actually has in the search index. The raw
     * database count would over-charge: a list module showing 5 of 300 news items
     * without pagination means 295 of them are never crawled and never reach the
     * chatbot, and a customer must not be asked to upgrade for content the knowledge
     * base does not contain.
     *
     * Before the first crawl nothing is indexed and the count is therefore 0. That is
     * the honest answer - the knowledge base really is empty - and the number that
     * decides the plan message during a run is computed after the crawl has just
     * refreshed the index.
     *
     * @param array<int, int|string> $pageIds
     * @param array<int, int>|null   $indexedRows page id => indexed URL count; looked up
     *                                            when the caller does not have it already
     *
     * @return array<int, int> page id => item count (pages without items are absent)
     */
    public function countByPage(array $pageIds, array|null $indexedRows = null): array
    {
        $pageIds = array_values(array_unique(array_filter(array_map(intval(...), $pageIds))));

        if ([] === $pageIds) {
            return [];
        }

        $counts = [];

        foreach (self::SOURCES as $parentTable => $itemTable) {
            foreach ($this->countForSource($parentTable, $itemTable, $pageIds) as $pageId => $count) {
                $counts[$pageId] = ($counts[$pageId] ?? 0) + $count;
            }
        }

        if ([] === $counts) {
            return [];
        }

        $indexedRows ??= $this->indexedRowsByPage(array_keys($counts));

        foreach ($counts as $pageId => $count) {
            // min(), not "rows - 1": whether the bare reader page has an indexed URL of
            // its own depends on requireItem, and being off by one in the customer's
            // favour is not worth a second query to find out.
            $capped = min($count, $indexedRows[$pageId] ?? 0);

            if ($capped > 0) {
                $counts[$pageId] = $capped;
            } else {
                unset($counts[$pageId]);
            }
        }

        return $counts;
    }

    /**
     * How many URLs each page currently has in Contao's search index.
     *
     * Protected rows are excluded for the same reason the synchronisation excludes
     * them: they are never uploaded, so they must not count towards a plan either.
     *
     * @param list<int> $pageIds
     *
     * @return array<int, int>
     */
    private function indexedRowsByPage(array $pageIds): array
    {
        if (!$this->tableExists('tl_search')) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT pid, COUNT(*) AS urls
             FROM tl_search
             WHERE pid IN (?)
               AND COALESCE(protected, 0) = 0
             GROUP BY pid',
            [$pageIds],
            [ArrayParameterType::INTEGER],
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['pid']] = (int) $row['urls'];
        }

        return $counts;
    }

    /**
     * @param list<int> $pageIds
     *
     * @return array<int, int>
     */
    private function countForSource(string $parentTable, string $itemTable, array $pageIds): array
    {
        if (!$this->tableExists($parentTable) || !$this->tableExists($itemTable)) {
            return [];
        }

        $columns = $this->columnsOf($itemTable);
        $where = ['parent.jumpTo IN (?)'];
        $types = [ArrayParameterType::INTEGER];
        $params = [$pageIds];

        if (isset($columns['published'])) {
            $where[] = "item.published = '1'";
        }

        // Same publication window Contao applies to a page, with the current time
        // floored to the minute and "stop" compared strictly greater.
        if (isset($columns['start'], $columns['stop'])) {
            $now = time();
            $time = $now - $now % 60;

            // Typed explicitly: the query already carries an ArrayParameterType, which
            // sends it through DBAL's array-parameter expansion. Leaving a scalar
            // untyped there is not worth the risk across DBAL 3 and 4.
            $where[] = "(item.start = '' OR item.start <= ?)";
            $params[] = $time;
            $types[] = ParameterType::INTEGER;

            $where[] = "(item.stop = '' OR item.stop > ?)";
            $params[] = $time;
            $types[] = ParameterType::INTEGER;
        }

        if (isset($columns['source'])) {
            $where[] = '(item.source IS NULL OR item.source NOT IN (?))';
            $params[] = self::REDIRECTING_SOURCES;
            $types[] = ArrayParameterType::STRING;
        }

        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT parent.jumpTo AS page_id, COUNT(*) AS items
                 FROM %s item
                 INNER JOIN %s parent ON parent.id = item.pid
                 WHERE %s
                 GROUP BY parent.jumpTo',
                $itemTable,
                $parentTable,
                implode(' AND ', $where),
            ),
            $params,
            $types,
        );

        $counts = [];

        foreach ($rows as $row) {
            $pageId = (int) $row['page_id'];

            if ($pageId > 0) {
                $counts[$pageId] = (int) $row['items'];
            }
        }

        return $counts;
    }

    private function tableExists(string $table): bool
    {
        if (null === $this->tableCache) {
            $this->tableCache = [];

            foreach ($this->connection->createSchemaManager()->listTableNames() as $name) {
                $this->tableCache[strtolower($name)] = true;
            }
        }

        return isset($this->tableCache[strtolower($table)]);
    }

    /**
     * @return array<string, true> lower-cased column names of the table
     */
    private function columnsOf(string $table): array
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }

        $columns = [];

        foreach (array_keys($this->connection->createSchemaManager()->listTableColumns($table)) as $name) {
            $columns[strtolower((string) $name)] = true;
        }

        return $this->columnCache[$table] = $columns;
    }
}
