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
 * Storage for the links found on indexed pages (tl_openai_page_link).
 *
 * Every query is parameterised; no value from a crawled page is ever concatenated
 * into SQL. Values are additionally length-clamped before insert, so a hostile
 * page cannot trigger a column overflow error inside Contao's indexing path.
 */
class PageLinkRepository
{
    private const TABLE = 'tl_openai_page_link';

    /**
     * Per-process cache of the "is the feature switched on anywhere?" question.
     * The indexPage hook fires for every crawled page AND on live front-end
     * traffic, so this must never cost more than one query per PHP process.
     */
    private bool|null $featureEnabled = null;

    /**
     * Per-process cache of the schema check.
     */
    private bool|null $schemaReady = null;

    /**
     * @var list<string>|null
     */
    private array|null $siteHosts = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * True when at least one configuration has auto-sync AND link collection
     * enabled. An installation without the premium add-on can never reach this
     * state: auto_update_enabled is license-guarded in the backend.
     */
    public function isFeatureEnabled(): bool
    {
        if (null !== $this->featureEnabled) {
            return $this->featureEnabled;
        }

        if (!$this->isSchemaReady()) {
            return $this->featureEnabled = false;
        }

        try {
            $enabled = $this->connection->fetchOne(
                "SELECT 1 FROM tl_openai_config WHERE auto_update_enabled = '1' AND auto_update_include_links = '1' LIMIT 1",
            );
        } catch (\Throwable) {
            return $this->featureEnabled = false;
        }

        return $this->featureEnabled = false !== $enabled;
    }

    /**
     * Guards every code path against a database that has not been migrated yet
     * (fresh install, or a bundle update before contao:migrate ran).
     */
    public function isSchemaReady(): bool
    {
        if (null !== $this->schemaReady) {
            return $this->schemaReady;
        }

        try {
            $schemaManager = $this->connection->createSchemaManager();

            if (!$schemaManager->tablesExist([self::TABLE, 'tl_openai_config'])) {
                return $this->schemaReady = false;
            }

            $columns = $schemaManager->listTableColumns('tl_openai_config');

            return $this->schemaReady = isset($columns['auto_update_include_links']);
        } catch (\Throwable) {
            return $this->schemaReady = false;
        }
    }

    /**
     * Hosts that count as "this website": every root page domain plus, for
     * single-domain setups without an explicit dns, nothing (the extractor always
     * adds the crawled host itself).
     *
     * @return list<string>
     */
    public function siteHosts(): array
    {
        if (null !== $this->siteHosts) {
            return $this->siteHosts;
        }

        try {
            $rows = $this->connection->fetchFirstColumn(
                "SELECT DISTINCT dns FROM tl_page WHERE type = 'root' AND dns != ''",
            );
        } catch (\Throwable) {
            return $this->siteHosts = [];
        }

        $hosts = [];

        foreach ($rows as $dns) {
            $dns = strtolower(trim((string) $dns));

            if ('' !== $dns) {
                // tl_page.dns may carry a scheme or a port in older installations.
                $host = parse_url(str_contains($dns, '://') ? $dns : 'https://'.$dns, PHP_URL_HOST);

                if (\is_string($host) && '' !== $host) {
                    $hosts[] = $host;
                }
            }
        }

        return $this->siteHosts = array_values(array_unique($hosts));
    }

    /**
     * Replace all links stored for one indexed document.
     *
     * Skips the write entirely when the extracted set is byte-identical to what is
     * already stored - the common case, since most re-indexes change nothing.
     *
     * @param list<PageLink> $links
     */
    public function replaceForSource(int $pageId, string $sourceUrl, string $language, array $links): void
    {
        if ($pageId <= 0 || '' === $sourceUrl) {
            return;
        }

        $sourceHash = sha1($sourceUrl);

        if ($this->fingerprint($links) === $this->storedFingerprint($sourceHash)) {
            return;
        }

        $now = time();
        $language = mb_substr($language, 0, 5);

        $this->connection->transactional(
            static function (Connection $connection) use ($sourceHash, $sourceUrl, $pageId, $language, $links, $now): void {
                $connection->delete(self::TABLE, ['source_hash' => $sourceHash]);

                foreach ($links as $link) {
                    $connection->insert(self::TABLE, [
                        'tstamp' => $now,
                        'page_id' => $pageId,
                        'source_url' => mb_substr($sourceUrl, 0, 2048),
                        'source_hash' => $sourceHash,
                        'url' => mb_substr($link->url, 0, 2048),
                        'url_hash' => $link->urlHash(),
                        'label' => mb_substr($link->label, 0, 512),
                        'link_title' => mb_substr($link->linkTitle, 0, 512),
                        'type' => mb_substr($link->type, 0, 16),
                        'mime' => mb_substr($link->mime, 0, 100),
                        'file_path' => mb_substr($link->filePath, 0, 1024),
                        'file_size' => max(0, $link->fileSize),
                        'language' => $language,
                        'position' => max(0, $link->position),
                        'occurrences' => max(1, $link->occurrences),
                    ]);
                }
            },
        );
    }

    /**
     * All links of the given pages, grouped by page id and ordered as they appear
     * in the page. A page indexed under several URLs (reader pages) contributes
     * the links of all its documents, matching how the sync aggregates content.
     *
     * @param list<int> $pageIds
     *
     * @return array<int, list<PageLink>>
     */
    public function findForPages(array $pageIds): array
    {
        if ([] === $pageIds || !$this->isSchemaReady()) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT page_id, url, label, link_title, type, mime, file_path, file_size, position, occurrences
             FROM '.self::TABLE.'
             WHERE page_id IN (?)
             ORDER BY page_id, position, id',
            [$pageIds],
            [ArrayParameterType::INTEGER],
        );

        /** @var array<int, array<string, PageLink>> $byPage */
        $byPage = [];

        foreach ($rows as $row) {
            $pageId = (int) $row['page_id'];
            $link = PageLink::fromArray($row);
            $key = $link->urlHash();

            // A page can be indexed under several URLs (paginated readers), and the
            // sync merges those documents into ONE page document - so the same link
            // can arrive several times. Merge it here, or the rendered list would
            // repeat it.
            if (isset($byPage[$pageId][$key])) {
                $existing = $byPage[$pageId][$key];
                $byPage[$pageId][$key] = $existing
                    ->withOccurrences($existing->occurrences + $link->occurrences)
                    ->withLabel(PageLink::betterLabel($existing->label, $link->label, $existing->url))
                ;

                continue;
            }

            // Bound the merge itself; the real per-page limit is applied below, by
            // type preference.
            if (\count($byPage[$pageId] ?? []) >= PageLinkExtractor::MAX_LINKS_SCANNED) {
                continue;
            }

            $byPage[$pageId][$key] = $link;
        }

        $result = [];

        foreach ($byPage as $pageId => $links) {
            // A page indexed under many URLs can exceed the per-document cap, so
            // apply the same document-first preference here as the extractor does.
            $result[$pageId] = PageLink::capByTypePreference(
                array_values($links),
                PageLinkExtractor::MAX_LINKS_PER_PAGE,
            );
        }

        return $result;
    }

    /**
     * URLs of protected pages in Contao's own search index. A link to one of them
     * must never be advertised by a public chatbot, even if the linking page is
     * public.
     *
     * Queried as a whole instead of filtering by the collected URLs: the protected
     * set is small on a normal site, and one bounded query beats an IN() clause
     * with thousands of parameters.
     *
     * Keyed by PageLink::comparisonKey(), not by the raw URL: a page linked as
     * "https://www.example.com/intern/" and indexed as "http://example.com/intern"
     * must still be recognised as the same protected target.
     *
     * @return array<string, true> keyed by comparison key
     */
    public function protectedUrls(): array
    {
        try {
            $rows = $this->connection->fetchFirstColumn(
                'SELECT url FROM tl_search WHERE protected = 1 LIMIT 5000',
            );
        } catch (\Throwable) {
            return [];
        }

        $protected = [];

        foreach ($rows as $url) {
            $protected[PageLink::comparisonKey((string) $url)] = true;
        }

        return $protected;
    }

    /**
     * Indexed URLs whose page is no longer published, keyed like protectedUrls().
     *
     * The counterpart to the publish filter in VectorStoreAutoUpdateService::readAllPages():
     * that one stops an unpublished page from being uploaded, this one stops OTHER pages
     * from linking to it. Without it the page itself is gone from the chatbot's knowledge
     * while a link block still points a visitor at a URL that 404s.
     *
     * Contao does not purge tl_search on unpublish (its PageSearchListener reacts to an
     * alias change, noSearch, robots=noindex and deletion only), so the row is still there
     * to be recognised - which is exactly what makes this check possible.
     *
     * @return array<string, true> keyed by comparison key
     */
    public function unpublishedUrls(): array
    {
        $now = time();
        $time = $now - $now % 60;

        try {
            $rows = $this->connection->fetchFirstColumn(
                // ORDER BY, because the LIMIT would otherwise truncate an arbitrary,
                // run-to-run varying subset on a site with more than 5000 indexed URLs
                // behind retired pages. A different subset each run means link blocks
                // flapping, page hashes changing and documents being re-uploaded for no
                // reason - which costs real money in KI-optimiert mode. Deterministic
                // truncation keeps the output stable even when the cap does bite.
                //
                // The timestamps are bound as INTEGER on purpose. tl_page.start/stop are
                // varchar, and Doctrine binds an untyped parameter as a STRING, which
                // would make this a lexicographic comparison - correct today only because
                // every Unix timestamp happens to be 10 digits wide, and quietly wrong the
                // moment one is not. INTEGER makes MySQL compare numerically, exactly as
                // core does (PageModel.php:855 interpolates a raw int).
                "SELECT s.url
                 FROM tl_search s
                 INNER JOIN tl_page p ON p.id = s.pid
                 WHERE p.published != '1'
                    OR (p.start != '' AND p.start > ?)
                    OR (p.stop != '' AND p.stop <= ?)
                 ORDER BY s.url
                 LIMIT 5000",
                [$time, $time],
                [ParameterType::INTEGER, ParameterType::INTEGER],
            );
        } catch (\Throwable) {
            return [];
        }

        $unpublished = [];

        foreach ($rows as $url) {
            $unpublished[PageLink::comparisonKey((string) $url)] = true;
        }

        return $unpublished;
    }

    /**
     * Drop rows whose source document no longer exists in Contao's search index.
     *
     * That is the exact signal we want: Contao removes a tl_search row when a page
     * 404s, is deleted or is excluded from indexing, so this inherits core's own
     * deletion semantics instead of guessing with timestamps.
     */
    public function pruneOrphans(): int
    {
        if (!$this->isSchemaReady()) {
            return 0;
        }

        try {
            return (int) $this->connection->executeStatement(
                'DELETE l FROM '.self::TABLE.' l
                 LEFT JOIN tl_search s ON s.url = l.source_url
                 WHERE s.id IS NULL',
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Remove every stored link. Used when the feature is switched off so no stale
     * data lingers.
     */
    public function clear(): void
    {
        if (!$this->isSchemaReady()) {
            return;
        }

        $this->connection->executeStatement('DELETE FROM '.self::TABLE);
    }

    /**
     * @param list<PageLink> $links
     */
    private function fingerprint(array $links): string
    {
        if ([] === $links) {
            return 'empty';
        }

        $parts = [];

        foreach ($links as $link) {
            $parts[] = implode("\x1F", [
                $link->url,
                $link->label,
                $link->type,
                $link->linkTitle,
                $link->mime,
                $link->filePath,
                (string) $link->fileSize,
                (string) $link->position,
                (string) $link->occurrences,
            ]);
        }

        return sha1(implode("\x1E", $parts));
    }

    private function storedFingerprint(string $sourceHash): string
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT url, label, link_title, type, mime, file_path, file_size, position, occurrences
             FROM '.self::TABLE.'
             WHERE source_hash = ?
             ORDER BY position, id',
            [$sourceHash],
        );

        if ([] === $rows) {
            return 'empty';
        }

        return $this->fingerprint(array_map(PageLink::fromArray(...), $rows));
    }
}
