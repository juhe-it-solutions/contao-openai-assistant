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

/**
 * Resolves which pages are member-protected, from tl_page rather than from the search index.
 *
 * WHY THIS EXISTS. The sync used to read protection from tl_search.protected alone, which is
 * the flag Contao's indexer resolved at crawl time. That is the right value when it is
 * current - and it is not current after an editor ticks "Protect page", because:
 *
 *   - Contao's PageSearchListener purges tl_search on an alias change, on noSearch /
 *     searchIndexer, on robots=noindex and on delete - but it has NO callback for the
 *     protected field (verified on the 5.3, 5.7 and 6.0 sources);
 *   - the crawler cannot repair it: an anonymous request for a protected page is not 2xx, so
 *     SearchIndexSubscriber::needsContent() returns DECISION_NEGATIVE and never calls
 *     $indexer->delete() - it only skips;
 *   - the front-end path cannot repair it either: SearchIndexListener deletes on 404 and 410
 *     only, never on the 401 a protected page returns.
 *
 * So the stale row keeps protected=0 and member-only content stays in a knowledge base that
 * an anonymous chat endpoint answers from. This class supplies the authoritative answer, and
 * callers use it IN ADDITION to tl_search.protected - the index flag still usefully catches
 * protection that core resolved in ways the page tree alone does not show.
 *
 * Inheritance is core semantics, from PageModel::loadDetails(): protection trickles down from
 * any ancestor and a descendant cannot switch it back off. So "protected" means "this page or
 * any ancestor up to the root is protected", which is why the walk goes DOWN from every
 * protected page rather than up from every candidate - the protected subtrees are the small
 * part of a normal site.
 */
class PageProtectionResolver
{
    /**
     * Depth guard. Contao page trees are shallow; this only has to stop a pid cycle created
     * by a corrupt database from looping forever, and the visited-set below already does.
     */
    private const MAX_DEPTH = 64;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Every protected page id, inherited protection included.
     *
     * Returns a set rather than a list so callers can test membership directly. An empty
     * result means "no page on this site is protected", which is the normal case.
     *
     * @return array<int, true>
     */
    public function protectedPageIds(): array
    {
        try {
            $seeds = array_map(
                intval(...),
                $this->connection->fetchFirstColumn("SELECT id FROM tl_page WHERE protected = '1'"),
            );
        } catch (\Throwable) {
            // Never break a sync over this lookup: an empty set only means nothing extra is
            // excluded, and the tl_search.protected filter callers also apply still stands.
            return [];
        }

        if ([] === $seeds) {
            return [];
        }

        $protected = [];

        foreach ($seeds as $id) {
            $protected[$id] = true;
        }

        $frontier = $seeds;
        $depth = 0;

        while ([] !== $frontier && $depth < self::MAX_DEPTH) {
            try {
                $children = array_map(
                    intval(...),
                    $this->connection->fetchFirstColumn(
                        'SELECT id FROM tl_page WHERE pid IN (?)',
                        [$frontier],
                        [ArrayParameterType::INTEGER],
                    ),
                );
            } catch (\Throwable) {
                break;
            }

            $frontier = [];

            foreach ($children as $childId) {
                if (isset($protected[$childId])) {
                    // Already known, which also makes a cyclic pid chain terminate.
                    continue;
                }

                $protected[$childId] = true;
                $frontier[] = $childId;
            }

            ++$depth;
        }

        return $protected;
    }

    /**
     * The indexed URLs of every protected page.
     *
     * Kept here next to the id resolution so the two can never drift: a page excluded from
     * the upload has to be excluded from the link blocks of surviving pages too, or the
     * chatbot stops answering from it while still pointing visitors at it.
     *
     * @return list<string>
     */
    public function protectedUrls(): array
    {
        $pageIds = array_keys($this->protectedPageIds());

        if ([] === $pageIds) {
            return [];
        }

        try {
            return array_map(
                strval(...),
                $this->connection->fetchFirstColumn(
                    // ORDER BY before LIMIT, for the same reason unpublishedUrls() has it:
                    // an arbitrary run-to-run subset would make link blocks flap and pages
                    // re-upload for no reason.
                    'SELECT url FROM tl_search WHERE pid IN (?) ORDER BY url LIMIT 5000',
                    [$pageIds],
                    [ArrayParameterType::INTEGER],
                ),
            );
        } catch (\Throwable) {
            return [];
        }
    }
}
