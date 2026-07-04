<?php

declare(strict_types=1);

/*
 * This file is part of Contao Open Source CMS.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Service;

use Contao\CoreBundle\Util\ProcessUtil;
use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Service\BoilerplateFilter;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\LicenseValidationService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\VectorStoreAutoUpdateService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\VectorStoreFileSync;
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
                }
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
                }
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
