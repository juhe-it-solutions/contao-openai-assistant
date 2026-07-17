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
        );
    }
}
