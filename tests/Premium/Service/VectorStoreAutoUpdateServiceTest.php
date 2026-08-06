<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Premium\Service;

use Contao\CoreBundle\Util\ProcessUtil;
use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\BoilerplateFilter;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\LicenseValidationService;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\LinkIndexDocumentBuilder;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\LinkSectionBuilder;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageLink;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageLinkFilter;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageLinkRepository;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreAutoUpdateService;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreFileSync;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

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
            ->method('fetchOne')
            ->willReturnCallback(
                static function (string $sql, array $params = []) use (&$captured): int {
                    $captured = [$sql, $params];

                    return 2;
                },
            )
        ;

        $count = $this->createService($connection)->countScopePages([1, 2, 3]);

        $this->assertSame(2, $count);
        $this->assertNotNull($captured);
        [$sql, $params] = $captured;
        $this->assertStringContainsString("published = '1'", $sql);
        $this->assertStringContainsString('type NOT IN', $sql);
        $this->assertSame([[1, 2, 3]], $params);
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

    private function createService(Connection $connection): VectorStoreAutoUpdateService
    {
        return new VectorStoreAutoUpdateService(
            $connection,
            new MockHttpClient(),
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
        );
    }
}
