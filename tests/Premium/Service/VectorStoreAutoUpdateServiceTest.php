<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant premium add-on.
 *
 * (c) JUHE IT-solutions
 *
 * @license Proprietary - see LICENSE-PREMIUM. Usage of the premium add-on
 *          requires a valid premium subscription from JUHE IT-solutions.
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Premium\Service;

use Contao\CoreBundle\Crawl\Escargot\Factory as EscargotFactory;
use Contao\CoreBundle\Util\ProcessUtil;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\IntegerType;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Controller\BackendModule\VectorStoreAutoUpdateController;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\BoilerplateFilter;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\LicenseValidationService;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\LinkIndexDocumentBuilder;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\LinkSectionBuilder;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageLink;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageLinkFilter;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageLinkRepository;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\ReaderItemCounter;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreAutoUpdateService;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreFileSync;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreSyncMessageTranslator;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class VectorStoreAutoUpdateServiceTest extends TestCase
{
    public function testReconcileStaleRunsPersistsDeadRunAsErrorAndLogsIt(): void
    {
        $lastRun = time() - 1200;

        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAllAssociative')
            ->willReturn([['id' => 7, 'auto_update_last_run' => $lastRun]])
        ;

        $executed = [];
        $connection
            ->method('executeStatement')
            ->willReturnCallback(
                static function (string $sql, array $params = []) use (&$executed): int {
                    $executed[] = [$sql, $params];

                    return 1; // guarded UPDATE matched → the run is confirmed dead
                },
            )
        ;

        $inserted = [];
        $connection
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(
                static function (string $table, array $data) use (&$inserted): int {
                    $inserted[] = [$table, $data];

                    return 1;
                },
            )
        ;

        $this->createService($connection)->reconcileStaleRuns();

        $this->assertCount(1, $executed);
        [$sql, $params] = $executed[0];
        $this->assertStringContainsString("SET auto_update_last_status = 'error'", $sql);
        $this->assertSame('MSC.vsau_err_run_stale', $params[0]);
        $this->assertSame(7, $params[1]);

        [$table, $data] = $inserted[0];
        $this->assertSame('tl_openai_sync_log', $table);
        $this->assertSame(7, $data['pid']);
        $this->assertSame('error', $data['status']);
        $this->assertSame('MSC.vsau_err_run_stale', $data['message']);
        $this->assertSame($lastRun, $data['run_at'], 'run_at must record the last heartbeat of the dead run.');
    }

    public function testReconcileStaleRunsSkipsLogWhenRunRecoveredConcurrently(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAllAssociative')
            ->willReturn([['id' => 7, 'auto_update_last_run' => time() - 1200]])
        ;

        $connection
            ->method('executeStatement')
            ->willReturn(0) // guarded UPDATE missed → run finished or heartbeated meanwhile
        ;

        $connection
            ->expects($this->never())
            ->method('insert')
        ;

        $this->createService($connection)->reconcileStaleRuns();
    }

    public function testReconcileStaleRunsPrunesOldLogRowsForTheConfig(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAllAssociative')
            ->willReturn([['id' => 7, 'auto_update_last_run' => time() - 1200]])
        ;

        // pruneSyncLog probes for the cutoff row id via fetchOne(... OFFSET ...).
        $connection
            ->method('fetchOne')
            ->willReturn(42)
        ;

        $executed = [];
        $connection
            ->method('executeStatement')
            ->willReturnCallback(
                static function (string $sql, array $params = []) use (&$executed): int {
                    $executed[] = [$sql, $params];

                    return 1;
                },
            )
        ;
        $connection
            ->method('insert')
            ->willReturn(1)
        ;

        $this->createService($connection)->reconcileStaleRuns();

        $this->assertContains(
            ['DELETE FROM tl_openai_sync_log WHERE pid = ? AND id <= ?', [7, 42]],
            $executed,
            'A logged stale run must trigger retention pruning of older sync-log rows for that config.',
        );
    }

    public function testCountScopePagesCountsOnlyPublishedContentPages(): void
    {
        $connection = $this->createMock(Connection::class);

        $captured = null;
        $connection
            ->method('fetchFirstColumn')
            ->willReturnCallback(
                static function (string $sql, array $params = []) use (&$captured): array {
                    $captured = [$sql, $params];

                    return [1, 2];
                },
            )
        ;

        $scope = $this->createService($connection)->countScopeBreakdown([1, 2, 3]);

        $this->assertSame(2, $scope['pages'], 'Only the published, non-structural pages count.');
        $this->assertSame(0, $scope['items'], 'No reader items in this fixture.');
        $this->assertNotNull($captured);
        [$sql, $params] = $captured;
        $this->assertStringContainsString("p.published = '1'", $sql);
        $this->assertStringContainsString('p.type NOT IN', $sql);
        $this->assertSame([[1, 2, 3]], $params);
    }

    /**
     * The plan page budget must count what the sync uploads, not what the operator picked.
     * readAllPages() drops protected search rows outright, so a protected page never
     * becomes a document - but it used to consume a slot in the budget all the same. With
     * a 20-page plan and a callback that THROWS, that difference refuses the save of a
     * configuration whose real upload stays under the limit.
     *
     * Asserted on the query rather than on a count because the exclusion has to happen in
     * SQL: the caller only ever sees the surviving ids.
     */
    public function testCountScopePagesExcludesPagesIndexedOnlyAsProtected(): void
    {
        $connection = $this->createMock(Connection::class);

        $captured = null;
        $connection
            ->method('fetchFirstColumn')
            ->willReturnCallback(
                static function (string $sql, array $params = []) use (&$captured): array {
                    $captured = [$sql, $params];

                    return [1];
                },
            )
        ;

        $this->createService($connection)->countScopeBreakdown([1, 2]);

        $this->assertNotNull($captured);
        [$sql] = $captured;

        // Read from the resolved flag in Contao's index, not from tl_page.protected:
        // protection is inherited down the tree, so a protected page's children carry an
        // empty flag of their own and would slip through.
        $this->assertStringContainsString('tl_search', $sql, 'Protection is read from the search index, where Contao stores the resolved flag.');
        $this->assertStringContainsString('s.protected = 1', $sql);

        // A page is only dropped when EVERY indexed row for it is protected. Pages with no
        // search rows at all must keep counting: before the first crawl the index is empty,
        // and a rule that required a row would quietly reduce every plan count to zero.
        $this->assertStringContainsString('COALESCE(s.protected, 0) = 0', $sql, 'A page with any public row still counts.');
    }

    public function testCountScopeBreakdownReportsPagesAndItemsSeparately(): void
    {
        // The loophole this guards: one published page carrying a news reader module
        // with hundreds of items counted as a single page, so an installation could put
        // the content of hundreds of items into the knowledge base on the smallest plan.
        // Pages and items are budgeted separately, so both numbers have to be reported.
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchFirstColumn')
            ->willReturn([277])
        ;

        $readerItems = $this->createMock(ReaderItemCounter::class);
        $readerItems
            ->method('countByPage')
            ->with([277])
            ->willReturn([277 => 300])
        ;

        $breakdown = $this->createService($connection, $readerItems)->countScopeBreakdown([277]);

        $this->assertSame(1, $breakdown['pages'], 'One page against the page budget.');
        $this->assertSame(300, $breakdown['items'], 'But 300 items against the item budget.');
    }

    public function testItemBudgetOverageMakesTheRunPartialWithoutLosingContent(): void
    {
        $service = $this->createService($this->createMock(Connection::class));

        [$status, $message] = $service->summariseRun([
            'files_failed' => 0,
            'pages_skipped' => 0,
            'page_limit' => 20,
            'dropped_items' => 0,
            'items_in_scope' => 63,
            'item_limit' => 50,
        ]);

        $this->assertSame('partial', $status, 'An item overage must not be reported as a plain success.');
        $this->assertSame('MSC.vsau_plan_item_limit_exceeded|63|50', $message);
    }

    public function testTruncationMessageNamesTheReaderItemsLostWithADroppedPage(): void
    {
        $service = $this->createService($this->createMock(Connection::class));

        [, $message] = $service->summariseRun([
            'files_failed' => 0,
            'pages_skipped' => 1,
            'page_limit' => 20,
            'dropped_items' => 300,
            'items_in_scope' => 0,
            'item_limit' => 0,
        ]);

        $this->assertSame(
            'MSC.vsau_plan_limit_truncated_items|1|20|300',
            $message,
            '"1 page was not synced" badly understates a page that held 300 entries.',
        );
    }

    public function testTruncationKeepsTheShorterWordingWhenNoItemsWereLost(): void
    {
        $service = $this->createService($this->createMock(Connection::class));

        [, $message] = $service->summariseRun([
            'files_failed' => 0,
            'pages_skipped' => 2,
            'page_limit' => 20,
            'dropped_items' => 0,
            'items_in_scope' => 0,
            'item_limit' => 0,
        ]);

        $this->assertSame('MSC.vsau_plan_limit_truncated|2|20', $message);
    }

    public function testEveryReasonIsReportedAndACleanRunIsSuccess(): void
    {
        $service = $this->createService($this->createMock(Connection::class));

        [$status, $message] = $service->summariseRun([
            'files_failed' => 3,
            'pages_skipped' => 1,
            'page_limit' => 20,
            'dropped_items' => 0,
            'items_in_scope' => 63,
            'item_limit' => 50,
        ]);

        $this->assertSame('partial', $status);
        $this->assertSame(
            [
                'MSC.vsau_plan_limit_truncated|1|20',
                'MSC.vsau_plan_item_limit_exceeded|63|50',
                'MSC.vsau_partial_files_failed|3',
            ],
            explode(VectorStoreSyncMessageTranslator::COMPOUND_SEPARATOR, $message),
            'A run can hit several limits at once; all of them must be surfaced.',
        );

        [$clean, $noMessage] = $service->summariseRun([
            'files_failed' => 0,
            'pages_skipped' => 0,
            'page_limit' => 20,
            'dropped_items' => 0,
            'items_in_scope' => 21,
            'item_limit' => 0,
        ]);

        $this->assertSame('success', $clean);
        $this->assertSame('', $noMessage);
    }

    public function testPlanPageLimitDropsOnlyThePagesBeyondTheCap(): void
    {
        $service = $this->createService($this->createMock(Connection::class));

        $result = $service->applyPlanPageLimit([3 => 'c', 1 => 'a', 2 => 'b'], 2);

        $this->assertSame([1 => 'a', 2 => 'b'], $result['pages'], 'Deterministic by page id.');
        $this->assertSame(1, $result['skipped']);
        $this->assertSame([3], $result['dropped'], 'The dropped ids are reported so their reader items can be counted.');
    }

    public function testPlanPageLimitIsIgnoredWithoutALimit(): void
    {
        $service = $this->createService($this->createMock(Connection::class));

        $result = $service->applyPlanPageLimit([1 => 'a', 2 => 'b'], 0);

        $this->assertSame([1 => 'a', 2 => 'b'], $result['pages'], 'Enterprise (no limit) must never drop a page.');
        $this->assertSame(0, $result['skipped']);
        $this->assertSame([], $result['dropped']);
    }

    public function testWholeSiteFallbackOnlyCountsLiveSiteRoots(): void
    {
        $connection = $this->createMock(Connection::class);

        $captured = null;
        $connection
            ->method('fetchFirstColumn')
            ->willReturnCallback(
                static function (string $sql, array $params = []) use (&$captured): array {
                    // The root lookup; anything else is collectPageSubtreeIds() asking
                    // for children, which this fixture does not have.
                    if (str_contains($sql, "type = 'root'")) {
                        $captured = [$sql, $params];

                        return [42];
                    }

                    return [];
                },
            )
        ;

        $pageIds = $this->createService($connection)->resolveScopePageIds(null);

        $this->assertSame([42], $pageIds);
        $this->assertNotNull($captured, 'An empty page selection must resolve the scope through the site roots.');

        [$sql, $params] = $captured;
        $this->assertStringContainsString("published = '1'", $sql);
        $this->assertStringContainsString("start = '' OR start <= ?", $sql, 'A root whose start date lies in the future is not live yet.');
        $this->assertStringContainsString("stop = '' OR stop > ?", $sql, 'A root whose stop date has passed is no longer live.');

        $this->assertCount(2, $params);
        $this->assertSame($params[0], $params[1], 'Both window bounds must be compared against the same instant.');
        $this->assertSame(0, $params[0] % 60, 'The instant must be floored to a full minute, like Contao Date::floorToMinute().');
    }

    public function testReconcileStaleRunsDoesNothingWithoutStaleRows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAllAssociative')
            ->willReturn([])
        ;

        $connection
            ->expects($this->never())
            ->method('executeStatement')
        ;

        $connection
            ->expects($this->never())
            ->method('insert')
        ;

        $this->createService($connection)->reconcileStaleRuns();
    }

    public function testResolveScopeRootDomainsDetectsPagesSpanningTwoDomains(): void
    {
        $connection = $this->createConnectionWithPages([
            10 => ['pid' => 1, 'type' => 'regular', 'dns' => ''],
            1 => ['pid' => 0, 'type' => 'root', 'dns' => 'abc.tld'],
            20 => ['pid' => 2, 'type' => 'regular', 'dns' => ''],
            2 => ['pid' => 0, 'type' => 'root', 'dns' => 'xyz.tld'],
        ]);

        $domains = $this->createService($connection)->resolveScopeRootDomains([10, 20]);

        sort($domains);
        $this->assertSame(['abc.tld', 'xyz.tld'], $domains);
    }

    public function testResolveScopeRootDomainsReturnsSingleDomainForOneRoot(): void
    {
        $connection = $this->createConnectionWithPages([
            10 => ['pid' => 1, 'type' => 'regular', 'dns' => ''],
            11 => ['pid' => 1, 'type' => 'regular', 'dns' => ''],
            1 => ['pid' => 0, 'type' => 'root', 'dns' => 'abc.tld'],
        ]);

        $domains = $this->createService($connection)->resolveScopeRootDomains([10, 11]);

        $this->assertSame(['abc.tld'], $domains);
    }

    public function testResolveScopeRootDomainsIgnoresDomainLessRoot(): void
    {
        $connection = $this->createConnectionWithPages([
            10 => ['pid' => 1, 'type' => 'regular', 'dns' => ''],
            1 => ['pid' => 0, 'type' => 'root', 'dns' => ''],
        ]);

        $this->assertSame([], $this->createService($connection)->resolveScopeRootDomains([10]));
    }

    // ------------------------------------------------- page links (§18 criteria)

    /**
     * @return array{0: VectorStoreAutoUpdateService, 1: array<int, list<PageLink>>}
     */
    private function createServiceWithLinks(): array
    {
        $links = [
            7 => [
                new PageLink('https://example.com/files/preisliste.pdf', 'Preisliste 2026', PageLink::TYPE_FILE, '', '', 'files/preisliste.pdf', 1_258_291),
                new PageLink('https://example.com/kontakt.html', 'Kontakt', PageLink::TYPE_PAGE),
            ],
        ];

        return [$this->createService($this->createMock(Connection::class)), $links];
    }

    /**
     * Criterion 1: the link block is appended to the page content.
     */
    public function testAppendsTheLinkSectionAfterTheContent(): void
    {
        [$service, $links] = $this->createServiceWithLinks();

        $out = $service->appendLinkSection(
            'Der Seitentext.',
            ['page_id' => 7, 'title' => 'Preise', 'language' => 'de'],
            $links,
            true,
        );

        $this->assertStringStartsWith('Der Seitentext.', $out);
        $this->assertStringContainsString('## Weiterführende Links auf „Preise"', $out);
        $this->assertStringContainsString('[Preisliste 2026](https://example.com/files/preisliste.pdf) — PDF, 1,2 MB', $out);
        $this->assertStringContainsString('[Kontakt](https://example.com/kontakt.html)', $out);
    }

    /**
     * Criterion 6: with the feature off, the uploaded document is byte-identical
     * to what an installation without this feature produces.
     */
    public function testProducesByteIdenticalContentWhenDisabled(): void
    {
        [$service, $links] = $this->createServiceWithLinks();
        $page = ['page_id' => 7, 'title' => 'Preise', 'language' => 'de'];

        $this->assertSame(
            'Der Seitentext.',
            $service->appendLinkSection('Der Seitentext.', $page, $links, false),
        );

        // A page without links is untouched even while the feature is on.
        $this->assertSame(
            'Der Seitentext.',
            $service->appendLinkSection('Der Seitentext.', ['page_id' => 99], $links, true),
        );
    }

    /**
     * Criteria 4 + 5: the content hash is stable when nothing changed and changes
     * when - and only when - the links change.
     */
    public function testContentHashTracksLinkChangesOnly(): void
    {
        [$service, $links] = $this->createServiceWithLinks();
        $page = ['page_id' => 7, 'title' => 'Preise', 'language' => 'de'];

        $first = hash('sha256', $service->appendLinkSection('Text', $page, $links, true));
        $again = hash('sha256', $service->appendLinkSection('Text', $page, $links, true));
        $this->assertSame($first, $again, 'unchanged input must not trigger a re-upload');

        $changed = $links;
        $changed[7][] = new PageLink('https://example.com/agb.html', 'AGB', PageLink::TYPE_PAGE);

        $this->assertNotSame(
            $first,
            hash('sha256', $service->appendLinkSection('Text', $page, $changed, true)),
            'a changed link set must change the hash',
        );
    }

    /**
     * Criterion: the directory document is added with page_id 0 and must not be
     * counted as a content page (it is appended after the plan cap).
     */
    public function testAppendsTheDirectoryDocumentWithoutConsumingPageQuota(): void
    {
        [$service, $links] = $this->createServiceWithLinks();

        $pages = [[
            'page_id' => 7,
            'url' => 'https://example.com/preise.html',
            'title' => 'Preise',
            'language' => 'de',
            'content' => 'Text',
            'search_checksum' => 'abc',
        ]];

        $contentPageCount = \count($pages);
        $withIndex = $service->appendLinkIndexDocument($pages, $links, true);

        $this->assertCount($contentPageCount + 1, $withIndex);
        $this->assertSame(0, $withIndex[1]['page_id']);
        $this->assertSame('https://example.com/', $withIndex[1]['url'], 'cites the site root, not a page');
        $this->assertStringContainsString('Link- und Dokumentenverzeichnis', (string) $withIndex[1]['content']);
        $this->assertSame($pages[0], $withIndex[0], 'existing page entries are untouched');

        $this->assertSame($pages, $service->appendLinkIndexDocument($pages, $links, false));
    }

    /**
     * @param array<int, array{pid: int, type: string, dns: string}> $pages
     */
    private function createConnectionWithPages(array $pages): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAssociative')
            ->willReturnCallback(
                static function (string $sql, array $params = []) use ($pages): array|false {
                    $id = (int) ($params[0] ?? 0);

                    return $pages[$id] ?? false;
                },
            )
        ;

        return $connection;
    }

    /**
     * With contao.search.index_protected enabled, member-only page bodies reach
     * tl_search. The chat endpoint answering from the vector store is anonymous, so
     * those rows must never be read for upload. Pages that were uploaded before this
     * filter existed leave the scope and are removed by VectorStoreFileSync's
     * existing "pages that dropped out of scope" pass.
     */
    public function testNeverReadsProtectedPagesForUpload(): void
    {
        $capturedSql = null;

        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAssociative')
            ->willReturn(['auto_update_site_root' => serialize(['7'])])
        ;
        $connection
            ->method('fetchFirstColumn')
            ->willReturn([7])
        ;
        $connection
            ->method('fetchAllAssociative')
            ->willReturnCallback(
                static function (string $sql) use (&$capturedSql): array {
                    if (str_contains($sql, 'FROM tl_search')) {
                        $capturedSql = $sql;
                    }

                    return [];
                },
            )
        ;

        $method = new \ReflectionMethod(VectorStoreAutoUpdateService::class, 'readAllPages');
        $method->invoke($this->createService($connection), 1);

        $this->assertNotNull($capturedSql, 'readAllPages() must query tl_search.');
        $this->assertStringContainsString(
            'COALESCE(s.protected, 0) = 0',
            $capturedSql,
            'Protected pages must be excluded - and via COALESCE, because a NULL would '
            .'drop the row and make the reconcile delete that page from the vector store.',
        );
    }

    /**
     * Contao 5.3/5.7 encode "=", "(", ")" and the quotes of every posted value, so
     * an AI-polish prompt used to reach the model as "&#40;kurz&#41;". Contao 6
     * stores the raw text, where the decode is a no-op.
     */
    public function testStoredPromptTemplateIsDecodedForTheModel(): void
    {
        $method = new \ReflectionMethod(VectorStoreAutoUpdateService::class, 'decodeStoredText');

        $this->assertSame(
            'Fasse den Text zusammen (kurz, sachlich) = ein Dokument.',
            $method->invoke(null, 'Fasse den Text zusammen &#40;kurz, sachlich&#41; &#61; ein Dokument.'),
            'A prompt saved on Contao 5.3/5.7 must reach the model as the admin typed it.',
        );

        $this->assertSame(
            'Fasse den Text zusammen (kurz) = ein Dokument.',
            $method->invoke(null, 'Fasse den Text zusammen (kurz) = ein Dokument.'),
            'On Contao 6 the value is already raw and must survive unchanged.',
        );

        $this->assertSame('', $method->invoke(null, null), 'An unset template stays empty.');
    }

    /**
     * The downloadable manifest is the only artefact that ties a page to the OpenAI file
     * holding it - the uploaded files carry no index and the platform shows ids only.
     */
    public function testManifestNamesTheVectorStoreFileOfEveryPage(): void
    {
        $method = new \ReflectionMethod(VectorStoreAutoUpdateService::class, 'buildManifest');

        $manifest = $method->invoke(
            $this->createService($this->createMock(Connection::class)),
            [
                ['page_id' => 7, 'url' => 'https://example.com/preise', 'title' => 'Preise', 'content' => 'Inhalt A'],
                ['page_id' => 8, 'url' => 'https://example.com/lang', 'title' => 'Langtext', 'content' => 'Inhalt B'],
                ['page_id' => 0, 'url' => 'https://example.com/', 'title' => 'Linkverzeichnis', 'content' => 'Inhalt C'],
            ],
            ['added' => 1, 'updated' => 0, 'removed' => 0, 'unchanged' => 1, 'files_uploaded' => 3, 'files_failed' => 0, 'bytes' => 42],
            [
                7 => ['state' => 'added', 'files' => ['file-aaa']],
                8 => ['state' => 'unchanged', 'files' => ['file-bbb', 'file-ccc']],
                0 => ['state' => 'added', 'files' => ['file-ddd']],
            ],
            null,
        );

        $this->assertStringContainsString("Page ID: 7 | Status: added\nVector store file: file-aaa", $manifest);
        $this->assertStringContainsString(
            "Page ID: 8 | Status: unchanged\nVector store files: file-bbb (part 1/2), file-ccc (part 2/2)",
            $manifest,
        );
        // The synthetic link directory is not a Contao page, so it must not claim an id.
        $this->assertStringContainsString("Page ID: – (link directory) | Status: added\nVector store file: file-ddd", $manifest);
    }

    /**
     * A page the sync never reached (or one whose upload failed with nothing in the store)
     * must still be identifiable in the manifest instead of silently claiming a file.
     */
    public function testManifestStaysHonestWithoutAFileForThePage(): void
    {
        $method = new \ReflectionMethod(VectorStoreAutoUpdateService::class, 'buildManifest');

        $manifest = $method->invoke(
            $this->createService($this->createMock(Connection::class)),
            [
                ['page_id' => 7, 'url' => 'https://example.com/a', 'title' => 'A', 'content' => 'Inhalt A'],
                ['page_id' => 9, 'url' => 'https://example.com/b', 'title' => 'B', 'content' => 'Inhalt B'],
            ],
            ['added' => 0, 'updated' => 0, 'removed' => 0, 'unchanged' => 0, 'files_uploaded' => 0, 'files_failed' => 1, 'bytes' => 0],
            [7 => ['state' => 'failed', 'files' => []]],
            null,
        );

        $this->assertStringContainsString("Page ID: 7 | Status: failed\nVector store file: – (not indexed)", $manifest);
        $this->assertStringContainsString("URL: https://example.com/b\nPage ID: 9\n", $manifest);
    }

    /**
     * A reader page carries one indexed URL per news/FAQ/event entry. Naming the merged
     * document after the first of them is arbitrary - and stays wrong once that entry is
     * deleted, because its search-index row outlives it.
     */
    public function testAReaderPageIsNamedAfterThePageNotAfterOneEntry(): void
    {
        $method = new \ReflectionMethod(VectorStoreAutoUpdateService::class, 'aggregateByPage');

        $rows = [
            // Ordered by URL, exactly as readAllPages() returns them.
            ['page_id' => 7, 'url' => 'https://example.com/aktuelles.html', 'title' => 'Aktuelles - Beispiel GmbH', 'page_title' => 'Aktuelles', 'language' => 'de', 'checksum' => 'a'],
            ['page_id' => 7, 'url' => 'https://example.com/aktuelles/artikel-a.html', 'title' => 'Artikel A - Beispiel GmbH', 'page_title' => 'Aktuelles', 'language' => 'de', 'checksum' => 'b'],
            ['page_id' => 7, 'url' => 'https://example.com/aktuelles/artikel-b.html', 'title' => 'Artikel B - Beispiel GmbH', 'page_title' => 'Aktuelles', 'language' => 'de', 'checksum' => 'c'],
        ];

        $out = $method->invoke($this->createService($this->createMock(Connection::class)), $rows, ['Übersicht', 'Text A', 'Text B']);

        $this->assertSame('Aktuelles', $out[7]['title'], 'The page name must win over one entry\'s <title>.');
        $this->assertSame('https://example.com/aktuelles.html', $out[7]['url'], 'The merged document must cite the reader page.');
        $this->assertSame(['Übersicht', 'Text A', 'Text B'], $out[7]['contents']);
    }

    /**
     * Even when the bare reader page itself was not indexed, the shortest entry URL is a
     * better citation than whichever row happened to sort first.
     */
    public function testTheShortestIndexedUrlWinsAndAMissingPageRowFallsBackToTheIndexedTitle(): void
    {
        $method = new \ReflectionMethod(VectorStoreAutoUpdateService::class, 'aggregateByPage');

        $rows = [
            ['page_id' => 9, 'url' => 'https://example.com/a/lange-unterseite.html', 'title' => 'Lang - Beispiel', 'page_title' => null, 'language' => 'de', 'checksum' => 'a'],
            ['page_id' => 9, 'url' => 'https://example.com/a.html', 'title' => 'Kurz - Beispiel', 'page_title' => '', 'language' => 'de', 'checksum' => 'b'],
        ];

        $out = $method->invoke($this->createService($this->createMock(Connection::class)), $rows, ['Text 1', 'Text 2']);

        $this->assertSame('https://example.com/a.html', $out[9]['url']);
        $this->assertSame('Lang - Beispiel', $out[9]['title'], 'Without a page row the indexed title remains the only name.');
    }

    /**
     * The backend list serves a page's indexed text out of the stored manifest, because the
     * uploaded files cannot be read back from OpenAI. Blocks are cut by page id.
     */
    public function testExtractsOnePagesBlockFromTheManifest(): void
    {
        $method = new \ReflectionMethod(VectorStoreAutoUpdateController::class, 'extractPageBlock');
        $controller = (new \ReflectionClass(VectorStoreAutoUpdateController::class))->newInstanceWithoutConstructor();

        $manifest = "# Vector store sync manifest\n\n- Pages indexed: 2\n\n---\n\n"
            ."## Preise\nURL: https://example.com/preise\nPage ID: 7 | Status: added\nVector store file: file-aaa\n\nInhalt A\n\n---\n\n"
            ."## Team\nURL: https://example.com/team\nPage ID: 70 | Status: unchanged\nVector store file: file-bbb\n\nInhalt B\n\n---\n\n";

        $this->assertSame(
            "## Preise\nURL: https://example.com/preise\nPage ID: 7 | Status: added\nVector store file: file-aaa\n\nInhalt A\n",
            $method->invoke($controller, $manifest, 7, 'https://example.com/preise'),
        );

        // Page 7 must never be answered with the block of page 70.
        $this->assertStringContainsString('Inhalt B', (string) $method->invoke($controller, $manifest, 70, ''));
        $this->assertNull($method->invoke($controller, $manifest, 99, 'https://example.com/unknown'));

        // A page whose own text contains a "---" line keeps its full block.
        $withRule = "# Manifest\n\n---\n\n"
            ."## Preise\nURL: https://example.com/preise\nPage ID: 7 | Status: added\nVector store file: file-aaa\n\n"
            ."Oben\n\n---\n\nUnten\n\n---\n\n"
            ."## Team\nURL: https://example.com/team\nPage ID: 8 | Status: added\nVector store file: file-bbb\n\nInhalt B\n\n---\n\n";

        $block = (string) $method->invoke($controller, $withRule, 7, 'https://example.com/preise');
        $this->assertStringContainsString('Oben', $block);
        $this->assertStringContainsString('Unten', $block);
        $this->assertStringNotContainsString('Inhalt B', $block);
    }

    /**
     * The extraction must work on what buildManifest() really writes, not on a hand-built
     * fixture: a summary that did not end in a blank line glued the first page block to it,
     * so exactly one page per run answered "no document stored yet" in the backend.
     */
    public function testEveryPageOfARealManifestCanBeReadBackIncludingTheFirst(): void
    {
        $build = new \ReflectionMethod(VectorStoreAutoUpdateService::class, 'buildManifest');
        $extract = new \ReflectionMethod(VectorStoreAutoUpdateController::class, 'extractPageBlock');
        $controller = (new \ReflectionClass(VectorStoreAutoUpdateController::class))->newInstanceWithoutConstructor();

        $manifest = $build->invoke(
            $this->createService($this->createMock(Connection::class)),
            [
                ['page_id' => 246, 'url' => 'https://example.com/home.html', 'title' => 'Home', 'content' => 'Inhalt A'],
                ['page_id' => 250, 'url' => 'https://example.com/impressum.html', 'title' => 'Impressum', 'content' => 'Inhalt B'],
                ['page_id' => 0, 'url' => 'https://example.com/', 'title' => 'Linkverzeichnis', 'content' => 'Inhalt C'],
            ],
            ['added' => 3, 'updated' => 0, 'removed' => 0, 'unchanged' => 0, 'files_uploaded' => 3, 'files_failed' => 0, 'bytes' => 42],
            [],
            null,
        );

        $first = (string) $extract->invoke($controller, $manifest, 246, 'https://example.com/home.html');
        $this->assertStringStartsWith('## Home', $first);
        $this->assertStringContainsString('Inhalt A', $first);
        $this->assertStringNotContainsString('Inhalt B', $first);
        // The summary belongs to the run, not to the page.
        $this->assertStringNotContainsString('Pages indexed', $first);

        $this->assertStringContainsString('Inhalt B', (string) $extract->invoke($controller, $manifest, 250, 'https://example.com/impressum.html'));
        // The link directory has no page id and is matched by its URL alone.
        $this->assertStringContainsString('Inhalt C', (string) $extract->invoke($controller, $manifest, 0, 'https://example.com/'));
    }

    /**
     * Manifests written before the page id was part of a block - and the link directory,
     * which has no page id at all - are matched by their URL line.
     */
    public function testFallsBackToTheUrlWhenTheManifestHasNoPageId(): void
    {
        $method = new \ReflectionMethod(VectorStoreAutoUpdateController::class, 'extractPageBlock');
        $controller = (new \ReflectionClass(VectorStoreAutoUpdateController::class))->newInstanceWithoutConstructor();

        $manifest = "# Vector store sync manifest\n\n---\n\n"
            ."## Preise\nURL: https://example.com/preise\n\nAlter Inhalt\n\n---\n\n";

        $this->assertSame(
            "## Preise\nURL: https://example.com/preise\n\nAlter Inhalt\n",
            $method->invoke($controller, $manifest, 7, 'https://example.com/preise'),
        );
    }

    /**
     * The crawl must never produce a percentage, whatever sits in its progress columns.
     *
     * It once did: the number of pages Contao had newly indexed was divided by the size of
     * the whole search index. Both numbers are true and they count different populations -
     * an unchanged page is skipped before it is written (Search::indexPage()), so a live
     * site with one edited page and 1072 indexed URLs displayed "1 of about 1072" and a bar
     * that could not move. A guard by phase name, so writing a total into those columns
     * again cannot resurrect the bar.
     */
    public function testTheCrawlNeverYieldsAPercentage(): void
    {
        $percent = new \ReflectionMethod(VectorStoreAutoUpdateController::class, 'progressPercent');
        $controller = (new \ReflectionClass(VectorStoreAutoUpdateController::class))->newInstanceWithoutConstructor();

        $crawl = static fn (int $current, int $total): array => [
            'auto_update_last_status' => 'running',
            'auto_update_progress_phase' => 'crawl',
            'auto_update_progress_current' => $current,
            'auto_update_progress_total' => $total,
        ];

        $this->assertNull($percent->invoke($controller, $crawl(1, 1072)));
        $this->assertNull($percent->invoke($controller, $crawl(0, 0)));
        $this->assertNull($percent->invoke($controller, $crawl(500, 500)));
    }

    /**
     * The phases that do count pages against a set they hold in memory keep their bar.
     */
    public function testPolishAndUploadStillReportAPercentage(): void
    {
        $percent = new \ReflectionMethod(VectorStoreAutoUpdateController::class, 'progressPercent');
        $controller = (new \ReflectionClass(VectorStoreAutoUpdateController::class))->newInstanceWithoutConstructor();

        $phase = static fn (string $phase, int $current, int $total): array => [
            'auto_update_last_status' => 'running',
            'auto_update_progress_phase' => $phase,
            'auto_update_progress_current' => $current,
            'auto_update_progress_total' => $total,
        ];

        $this->assertSame(25, $percent->invoke($controller, $phase('polish', 5, 20)));
        $this->assertSame(100, $percent->invoke($controller, $phase('upload', 20, 20)));
        // A page set that grew mid-run must not render a bar wider than its track.
        $this->assertSame(100, $percent->invoke($controller, $phase('upload', 21, 20)));
        // Nothing is reported for a run that is not in flight.
        $this->assertNull($percent->invoke($controller, [
            'auto_update_last_status' => 'success',
            'auto_update_progress_phase' => 'polish',
            'auto_update_progress_current' => 5,
            'auto_update_progress_total' => 20,
        ]));
    }


    /**
     * A rewrite is reused only when NOTHING that could change it has changed. The source
     * text is the obvious half; the model and the prompt are the half that is easy to
     * forget, and forgetting it would mean a corrected prompt never reaches the pages it
     * was written to fix.
     */
    public function testCachedPolishOnlyHitsWhenSourceAndParametersBothMatch(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchOne')
            ->willReturnCallback(
                static fn (string $sql, array $params): string|false => ['sum-a', 'fp-a'] === [$params[2], $params[3]]
                    ? 'cached document'
                    : false,
            )
        ;

        $method = new \ReflectionMethod(VectorStoreAutoUpdateService::class, 'cachedPolish');
        $service = $this->createService($connection);

        $this->assertSame('cached document', $method->invoke($service, 1, 7, 'sum-a', 'fp-a'));
        $this->assertNull($method->invoke($service, 1, 7, 'sum-CHANGED', 'fp-a'), 'Edited page must be polished again.');
        $this->assertNull($method->invoke($service, 1, 7, 'sum-a', 'fp-CHANGED'), 'New model or prompt must be polished again.');
    }

    /**
     * The cache key is a hash of the text actually sent to the model, NOT of the page's
     * search-index checksums. BoilerplateFilter decides what counts as chrome from how
     * often a block occurs ACROSS the pages in scope, so adding or removing one page can
     * change what is stripped from a DIFFERENT page whose own tl_search rows never moved.
     * Keyed on those checksums, the cache would then hand back a rewrite of text that is
     * no longer the input.
     */
    public function testCacheKeyFollowsTheTextSentToTheModel(): void
    {
        $seen = [];
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchOne')
            ->willReturnCallback(
                static function (string $sql, array $params) use (&$seen): string|false {
                    $seen[] = $params[2];

                    return false;
                },
            )
        ;

        $method = new \ReflectionMethod(VectorStoreAutoUpdateService::class, 'cachedPolish');
        $service = $this->createService($connection);

        $before = hash('sha256', "Impressum\n\nEchter Inhalt");
        $after = hash('sha256', 'Echter Inhalt');

        $method->invoke($service, 1, 7, $before, 'fp');
        $method->invoke($service, 1, 7, $after, 'fp');

        $this->assertSame([$before, $after], $seen, 'A different filtered text must be a different key.');
        $this->assertNotSame($before, $after);
    }

    /**
     * A cache that cannot be read costs tokens, never correctness - so it must degrade to
     * "polish it again", which is exactly what happened before the cache existed.
     */
    public function testCachedPolishFallsBackToPolishingWhenTheCacheCannotBeRead(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('table missing'));

        $method = new \ReflectionMethod(VectorStoreAutoUpdateService::class, 'cachedPolish');

        $this->assertNull($method->invoke($this->createService($connection), 1, 7, 'sum', 'fp'));
    }

    /**
     * An empty cached document must never be served: it would upload an empty knowledge
     * document for a page that has content.
     */
    public function testCachedPolishIgnoresAnEmptyStoredDocument(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn("   \n  ");

        $method = new \ReflectionMethod(VectorStoreAutoUpdateService::class, 'cachedPolish');

        $this->assertNull($method->invoke($this->createService($connection), 1, 7, 'sum', 'fp'));
    }

    /**
     * A response the model cut off at its output limit arrives as a perfectly valid 200
     * with a document that stops mid-sentence - the failure mode of a long reader page.
     * It must be discarded so the caller falls back to the complete faithful text, and so
     * it never reaches the cache, where the truncation would become permanent.
     */
    public function testTruncatedRewriteIsDiscardedButStillReportsItsTokens(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'choices' => [['finish_reason' => 'length', 'message' => ['content' => '## Aktuelles\n\nErster Artikel, abgeschn']]],
            'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 16384],
        ], JSON_THROW_ON_ERROR)));

        $service = $this->createService($this->createMock(Connection::class), null, $http);
        $method = new \ReflectionMethod(VectorStoreAutoUpdateService::class, 'polishPage');
        $result = $method->invoke($service, 'sk-test', 'gpt-4o-mini', 'Aktuelles', 'https://example.com/aktuelles', 'Text', null);

        $this->assertSame('', $result['text'], 'A truncated document must not be used.');
        $this->assertSame(900, $result['tokens_in'], 'The tokens were spent and must still be reported.');
        $this->assertSame(16384, $result['tokens_out']);
    }

    /**
     * The complementary case: a complete response is used as-is.
     */
    public function testCompleteRewriteIsUsed(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '## Aktuelles']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ], JSON_THROW_ON_ERROR)));

        $service = $this->createService($this->createMock(Connection::class), null, $http);
        $method = new \ReflectionMethod(VectorStoreAutoUpdateService::class, 'polishPage');
        $result = $method->invoke($service, 'sk-test', 'gpt-4o-mini', 'Aktuelles', 'https://example.com/aktuelles', 'Text', null);

        $this->assertSame('## Aktuelles', $result['text']);
    }

    /**
     * The run-state columns did not all arrive in the same release: an installation
     * updated from 2.1.x has the progress columns and not auto_update_run_started. If
     * the gate only asked about the older ones it would wave that install through, and
     * the first run-state UPDATE would fail on an unknown column - inside a method whose
     * contract is to never throw.
     */
    public function testRunIsSkippedWhenTheNewestRunStateColumnIsMissing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->schemaManager([
            'auto_update_progress_phase',
            'auto_update_progress_current',
            'auto_update_progress_total',
        ]));
        $connection->expects($this->never())->method('executeStatement');

        $this->assertSame('skipped', $this->createService($connection)->run(7));
    }

    /**
     * The manual button writes the same columns, so it needs the same guard - phrased as
     * an error the operator can act on rather than an SQL exception.
     */
    public function testManualDispatchRefusesAnOutdatedSchema(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->schemaManager([
            'auto_update_progress_phase',
            'auto_update_progress_current',
            'auto_update_progress_total',
        ]));
        $connection->expects($this->never())->method('executeStatement');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MSC.vsau_err_schema_outdated');

        $this->createService($connection)->dispatchRun(7);
    }

    /**
     * The run log row is inserted at the very END of a run. A gate that only asks about
     * tl_openai_config would let an install whose schema update was applied in parts
     * crawl the whole site, pay the full rewrite bill and upload every file, only to fail
     * on the log insert - and repeat that spend on every schedule. The gate therefore
     * covers the sync-log columns this version writes as well.
     */
    public function testRunIsSkippedWhenTheSyncLogIsMissingTheItemsColumn(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->schemaManager(
            [
                'auto_update_progress_phase',
                'auto_update_progress_current',
                'auto_update_progress_total',
                'auto_update_run_started',
            ],
            [],
        ));
        $connection->expects($this->never())->method('executeStatement');

        $this->assertSame('skipped', $this->createService($connection)->run(7));
    }

    /**
     * Same gap, seen from the manual button: it must refuse with the actionable message
     * rather than let the run start and die at the end.
     */
    public function testManualDispatchRefusesASyncLogMissingTheItemsColumn(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->schemaManager(
            [
                'auto_update_progress_phase',
                'auto_update_progress_current',
                'auto_update_progress_total',
                'auto_update_run_started',
            ],
            [],
        ));
        $connection->expects($this->never())->method('executeStatement');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MSC.vsau_err_schema_outdated');

        $this->createService($connection)->dispatchRun(7);
    }

    /**
     * A fresh install before the very first migrate has no extension tables at all. The
     * gate must answer that without asking for their columns.
     */
    public function testRunIsSkippedBeforeTheExtensionTablesExist(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->schemaManager([], [], false));
        $connection->expects($this->never())->method('executeStatement');

        $this->assertSame('skipped', $this->createService($connection)->run(7));
    }

    /**
     * Counter-test: a migrated install must pass the gate. It stops at the license check
     * right after it, which is how we can tell the schema was not what refused it.
     */
    public function testManualDispatchPassesTheSchemaGateOnAMigratedInstall(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->schemaManager([
            'auto_update_progress_phase',
            'auto_update_progress_current',
            'auto_update_progress_total',
            'auto_update_run_started',
            'auto_update_last_crawl',
            'auto_update_crawl_signature',
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MSC.vsau_err_no_license');

        $this->createService($connection)->dispatchRun(7);
    }

    public function testCrawlModeAlwaysCrawlsEvenWhenNothingChanged(): void
    {
        $config = [
            'auto_update_crawl_mode' => VectorStoreAutoUpdateService::CRAWL_ALWAYS,
            'auto_update_last_crawl' => time() - 60,
            'auto_update_crawl_signature' => 'abc',
        ];

        $this->assertTrue($this->createService($this->createMock(Connection::class))->shouldCrawl($config, 'abc'));
    }

    public function testCrawlModeNeverSkipsEvenWhenEverythingChanged(): void
    {
        $config = [
            'auto_update_crawl_mode' => VectorStoreAutoUpdateService::CRAWL_NEVER,
            'auto_update_last_crawl' => 0,
            'auto_update_crawl_signature' => 'abc',
        ];

        $this->assertFalse($this->createService($this->createMock(Connection::class))->shouldCrawl($config, 'different'));
    }

    public function testAutoModeSkipsTheCrawlWhileTheSiteIsUnchanged(): void
    {
        $config = [
            'auto_update_crawl_mode' => VectorStoreAutoUpdateService::CRAWL_AUTO,
            'auto_update_last_crawl' => time() - 60,
            'auto_update_crawl_signature' => 'abc',
        ];

        $this->assertFalse($this->createService($this->createMock(Connection::class))->shouldCrawl($config, 'abc'));
    }

    public function testAutoModeCrawlsAsSoonAsTheSignatureMoves(): void
    {
        $config = [
            'auto_update_crawl_mode' => VectorStoreAutoUpdateService::CRAWL_AUTO,
            'auto_update_last_crawl' => time() - 60,
            'auto_update_crawl_signature' => 'abc',
        ];

        $this->assertTrue($this->createService($this->createMock(Connection::class))->shouldCrawl($config, 'def'));
    }

    /**
     * The safety net for everything the signature cannot see - start/stop dates, insert
     * tags, content pulled in from elsewhere. Without it the gate could hold a stale
     * knowledge base indefinitely.
     */
    public function testAutoModeCrawlsOnceTheLastCrawlIsOlderThanTheMaximumAge(): void
    {
        $config = [
            'auto_update_crawl_mode' => VectorStoreAutoUpdateService::CRAWL_AUTO,
            'auto_update_last_crawl' => time() - VectorStoreAutoUpdateService::CRAWL_MAX_AGE_SECONDS - 1,
            'auto_update_crawl_signature' => 'abc',
        ];

        $this->assertTrue($this->createService($this->createMock(Connection::class))->shouldCrawl($config, 'abc'));
    }

    public function testAutoModeAlwaysCrawlsWhenItNeverHas(): void
    {
        $config = [
            'auto_update_crawl_mode' => VectorStoreAutoUpdateService::CRAWL_AUTO,
            'auto_update_last_crawl' => 0,
            'auto_update_crawl_signature' => '',
        ];

        $this->assertTrue($this->createService($this->createMock(Connection::class))->shouldCrawl($config, 'abc'));
    }

    /**
     * A clock that jumped backwards makes the age negative. That must read as "cannot
     * vouch for freshness", not as "crawled in the future, so recent enough".
     */
    public function testAutoModeCrawlsWhenTheLastCrawlLiesInTheFuture(): void
    {
        $config = [
            'auto_update_crawl_mode' => VectorStoreAutoUpdateService::CRAWL_AUTO,
            'auto_update_last_crawl' => time() + 7200,
            'auto_update_crawl_signature' => 'abc',
        ];

        $this->assertTrue($this->createService($this->createMock(Connection::class))->shouldCrawl($config, 'abc'));
    }

    public function testAnUnreadableSignatureIsNeverMistakenForNothingChanged(): void
    {
        $config = [
            'auto_update_crawl_mode' => VectorStoreAutoUpdateService::CRAWL_AUTO,
            'auto_update_last_crawl' => time() - 60,
            'auto_update_crawl_signature' => '',
        ];

        $this->assertTrue($this->createService($this->createMock(Connection::class))->shouldCrawl($config, ''));
    }

    public function testAConfigWithoutTheNewColumnsBehavesLikeAutoAndCrawls(): void
    {
        $this->assertTrue($this->createService($this->createMock(Connection::class))->shouldCrawl([], 'abc'));
    }

    public function testTheSignatureChangesWhenARecordIsDeletedWithoutAnyTimestampMoving(): void
    {
        $before = $this->signatureFor(['ts' => 1000, 'rows_count' => 42]);
        $after = $this->signatureFor(['ts' => 1000, 'rows_count' => 41]);

        $this->assertNotSame('', $before);
        $this->assertNotSame($before, $after);
    }

    public function testTheSignatureIsStableWhileNothingChanges(): void
    {
        $this->assertSame(
            $this->signatureFor(['ts' => 1000, 'rows_count' => 42]),
            $this->signatureFor(['ts' => 1000, 'rows_count' => 42]),
        );
    }

    public function testTheSignatureIsEmptyWhenNoContentTableExists(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(false);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $this->assertSame('', $this->createService($connection)->siteContentSignature());
    }

    public function testADatabaseErrorYieldsNoSignatureRatherThanAWrongOne(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willThrowException(new \RuntimeException('gone'));

        $this->assertSame('', $this->createService($connection)->siteContentSignature());
    }

    /**
     * @param array{ts: int, rows_count: int} $row
     */
    private function signatureFor(array $row): string
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('fetchAssociative')->willReturn($row);

        return $this->createService($connection)->siteContentSignature();
    }

    /**
     * @param list<string> $columns     columns tl_openai_config is reported to have
     * @param list<string> $logColumns  columns tl_openai_sync_log is reported to have;
     *                                  defaults to the migrated state, so a test that only
     *                                  varies the config columns still describes an install
     *                                  whose sync log is current
     * @param bool         $tablesExist whether both tables exist at all (fresh install)
     */
    private function schemaManager(array $columns, array $logColumns = ['items'], bool $tablesExist = true): AbstractSchemaManager
    {
        $toColumns = static fn (array $names): array => array_combine(
            $names,
            array_map(static fn (string $name): Column => new Column($name, new IntegerType()), $names),
        );

        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn($tablesExist);
        $schemaManager
            ->method('listTableColumns')
            ->willReturnCallback(
                static fn (string $table): array => 'tl_openai_sync_log' === $table
                    ? $toColumns($logColumns)
                    : $toColumns($columns),
            )
        ;

        return $schemaManager;
    }

    private function createService(Connection $connection, ReaderItemCounter|null $readerItems = null, MockHttpClient|null $http = null): VectorStoreAutoUpdateService
    {
        return new VectorStoreAutoUpdateService(
            $connection,
            $http ?? new MockHttpClient(),
            new NullLogger(),
            $this->createMock(EncryptionService::class),
            $this->createMock(ProcessUtil::class),
            $this->createMock(LicenseValidationService::class),
            $this->createMock(BoilerplateFilter::class),
            $this->createMock(VectorStoreFileSync::class),
            $this->createMock(PageLinkRepository::class),
            new PageLinkFilter(),
            new LinkSectionBuilder(),
            new LinkIndexDocumentBuilder(),
            $readerItems ?? $this->createMock(ReaderItemCounter::class),
            $this->createMock(EscargotFactory::class),
        );
    }
}
