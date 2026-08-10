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

use Contao\CoreBundle\Cron\Cron;
use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\CronHealthService;
use PHPUnit\Framework\TestCase;

class CronHealthServiceTest extends TestCase
{
    private string|null $previousTimezone = null;

    protected function setUp(): void
    {
        // scheduleInterval() measures the gap between two firings in the app timezone.
        // Pinning it to UTC keeps the daily case at exactly 86400 s on DST changeover
        // days, where a local zone would legitimately report 82800 or 90000.
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone ?? 'UTC');
    }

    public function testReportsNeverWhenTheCronHasNeverRun(): void
    {
        $this->assertSame(CronHealthService::STATUS_NEVER, $this->service()->status(0));
    }

    public function testReportsNoCliCronWhenOnlyWebTriggeredJobsExist(): void
    {
        $this->assertSame(CronHealthService::STATUS_NO_CLI_CRON, $this->service()->status(-1));
    }

    public function testAFreshTickIsHealthy(): void
    {
        $this->assertSame(CronHealthService::STATUS_HEALTHY, $this->service()->status(time() - 30));
    }

    /**
     * The old threshold was 120 s, which is shorter than an ordinary sync. All three of
     * these used to report "stale" on a perfectly healthy install.
     */
    public function testTheIdleWindowToleratesMoreThanTwoMissedTicks(): void
    {
        $service = $this->service();

        $this->assertSame(CronHealthService::STATUS_HEALTHY, $service->status(time() - 121));
        $this->assertSame(CronHealthService::STATUS_HEALTHY, $service->status(time() - 290));
        $this->assertSame(CronHealthService::STATUS_HEALTHY, $service->status(time() - 840));
    }

    /**
     * Shared hosts commonly offer no minutely cron at all. Contao works fine on a 5-,
     * 15- or 30-minute cron - a job is re-evaluated whenever contao:cron runs - so none
     * of those tick intervals may be reported as a stopped cron.
     */
    public function testACoarseHostCronIntervalIsStillHealthy(): void
    {
        $service = $this->service();

        foreach ([300, 900, 1799] as $tickInterval) {
            $this->assertSame(
                CronHealthService::STATUS_HEALTHY,
                $service->status(time() - $tickInterval),
                \sprintf('a %d-second cron interval must not read as stale', $tickInterval),
            );
        }
    }

    public function testReportsStaleOnceTheIdleWindowHasPassed(): void
    {
        $this->assertSame(CronHealthService::STATUS_STALE, $this->service()->status(time() - 5400));
    }

    /**
     * Our cron job runs the crawl synchronously inside contao:cron, so on a host that
     * serialises cron runs the heartbeat legitimately freezes for the whole sync.
     * A 14-minute run must not be reported as a dead cron.
     */
    public function testAFrozenHeartbeatDuringARunIsNotReportedAsStale(): void
    {
        $this->assertSame(
            CronHealthService::STATUS_HEALTHY,
            $this->service()->status(time() - 840, true),
        );
    }

    /**
     * tl_cron_job.lastRun is a plain datetime column written as a local-time string by
     * the CLI process and read back by the web process. A php.ini timezone mismatch
     * between the two shows up as a constant offset - which must never be reported as
     * a cron fault, because it says nothing about the cron.
     */
    public function testATimestampInTheFutureIsReportedAsUnknownRatherThanStale(): void
    {
        $this->assertSame(
            CronHealthService::STATUS_UNKNOWN,
            $this->service()->status(time() + 7200),
        );
    }

    public function testContaosOwnLivenessFlagOverridesAStaleTimestamp(): void
    {
        $cron = $this->createMock(Cron::class);
        $cron->method('hasMinutelyCliCron')->willReturn(true);

        $this->assertSame(
            CronHealthService::STATUS_UNKNOWN,
            $this->service(cron: $cron)->status(time() - 5400),
        );
    }

    public function testAStaleTimestampStandsWhenContaoAgreesTheCronIsNotRunning(): void
    {
        $cron = $this->createMock(Cron::class);
        $cron->method('hasMinutelyCliCron')->willReturn(false);

        $this->assertSame(
            CronHealthService::STATUS_STALE,
            $this->service(cron: $cron)->status(time() - 5400),
        );
    }

    public function testWorksWithoutTheContaoCronServiceAtAll(): void
    {
        $this->assertSame(CronHealthService::STATUS_STALE, $this->service()->status(time() - 5400));
    }

    public function testDerivesTheIntervalFromTheCronExpression(): void
    {
        $service = $this->service();

        $this->assertSame(3600, $service->scheduleInterval('0 * * * *'));
        $this->assertSame(86400, $service->scheduleInterval('0 2 * * *'));
        $this->assertSame(900, $service->scheduleInterval('*/15 * * * *'));
    }

    public function testAnEmptyScheduleFallsBackToTheDailyDefault(): void
    {
        $this->assertSame(86400, $this->service()->scheduleInterval(''));
    }

    public function testAnUnusableScheduleYieldsNoInterval(): void
    {
        $this->assertNull($this->service()->scheduleInterval('not a cron expression'));
    }

    public function testManualConfigurationsHaveNoScheduleStatus(): void
    {
        $this->assertSame(
            CronHealthService::SCHEDULE_MANUAL,
            $this->service()->scheduleStatus(
                ['auto_update_trigger' => 'manual'],
                0,
                CronHealthService::STATUS_STALE,
            ),
        );
    }

    /**
     * The case from the 10.08.2026 customer report: the heartbeat read stale while the
     * sync log showed ten consecutive hourly cron-triggered runs. The evidence wins.
     */
    public function testRecentCronTriggeredRunsBeatAStaleHeartbeat(): void
    {
        $this->assertSame(
            CronHealthService::SCHEDULE_RUNNING,
            $this->service()->scheduleStatus(
                ['auto_update_schedule' => '0 * * * *', 'auto_update_last_run' => time() - 600],
                time() - 600,
                CronHealthService::STATUS_STALE,
            ),
        );
    }

    public function testScheduledRunsThatStoppedAreReportedAsOverdue(): void
    {
        $this->assertSame(
            CronHealthService::SCHEDULE_OVERDUE,
            $this->service()->scheduleStatus(
                ['auto_update_schedule' => '0 * * * *', 'auto_update_last_run' => time() - 86400],
                time() - 86400,
                CronHealthService::STATUS_HEALTHY,
            ),
        );
    }

    public function testALateButNotYetOverdueRunStillCountsAsRunning(): void
    {
        // Hourly schedule: two intervals plus the grace window is 2h15m.
        $this->assertSame(
            CronHealthService::SCHEDULE_RUNNING,
            $this->service()->scheduleStatus(
                ['auto_update_schedule' => '0 * * * *', 'auto_update_last_run' => time() - 7500],
                time() - 7500,
                CronHealthService::STATUS_HEALTHY,
            ),
        );
    }

    public function testARunInFlightIsEvidenceInItsOwnRight(): void
    {
        $this->assertSame(
            CronHealthService::SCHEDULE_RUNNING,
            $this->service()->scheduleStatus(
                ['auto_update_schedule' => '0 * * * *', 'auto_update_last_run' => time() - 86400],
                time() - 86400,
                CronHealthService::STATUS_HEALTHY,
                true,
            ),
        );
    }

    /**
     * The flag must describe THIS configuration. It used to be the install-wide "is any
     * config syncing" flag, which made an overdue configuration report "running" for as
     * long as an unrelated one happened to be syncing.
     */
    public function testAnotherConfigurationsRunIsNoEvidenceForThisOne(): void
    {
        $this->assertSame(
            CronHealthService::SCHEDULE_OVERDUE,
            $this->service()->scheduleStatus(
                ['auto_update_schedule' => '0 * * * *', 'auto_update_last_run' => time() - 86400],
                time() - 86400,
                CronHealthService::STATUS_HEALTHY,
                false,
            ),
        );
    }

    public function testAConfigurationThatNeverSyncedIsWaitingForItsManualFirstRun(): void
    {
        $this->assertSame(
            CronHealthService::SCHEDULE_AWAITING_FIRST,
            $this->service()->scheduleStatus(
                ['auto_update_schedule' => '0 2 * * *', 'auto_update_last_run' => 0],
                0,
                CronHealthService::STATUS_STALE,
            ),
        );
    }

    public function testAHealthyCronWithNoCronRunYetIsPendingRatherThanBroken(): void
    {
        $this->assertSame(
            CronHealthService::SCHEDULE_PENDING,
            $this->service()->scheduleStatus(
                ['auto_update_schedule' => '0 2 * * *', 'auto_update_last_run' => time() - 60],
                0,
                CronHealthService::STATUS_HEALTHY,
            ),
        );
    }

    public function testNoEvidenceAndNoWorkingCronIsReportedAsNotRunning(): void
    {
        $this->assertSame(
            CronHealthService::SCHEDULE_NOT_RUNNING,
            $this->service()->scheduleStatus(
                ['auto_update_schedule' => '0 2 * * *', 'auto_update_last_run' => time() - 60],
                0,
                CronHealthService::STATUS_NEVER,
            ),
        );
    }

    public function testAnUnreadableScheduleDoesNotTurnEvidenceIntoAnAlarm(): void
    {
        $this->assertSame(
            CronHealthService::SCHEDULE_RUNNING,
            $this->service()->scheduleStatus(
                ['auto_update_schedule' => 'nonsense', 'auto_update_last_run' => time() - 99999],
                time() - 99999,
                CronHealthService::STATUS_STALE,
            ),
        );
    }

    public function testABadHeartbeatIsMarkedContradictedOnlyWhenTheScheduleIsWorking(): void
    {
        $service = $this->service();

        $this->assertTrue($service->heartbeatContradicted(
            CronHealthService::STATUS_STALE,
            CronHealthService::SCHEDULE_RUNNING,
        ));

        // Overdue AGREES with a stale heartbeat - reassuring the operator there would be
        // the same false statement in the opposite direction.
        $this->assertFalse($service->heartbeatContradicted(
            CronHealthService::STATUS_STALE,
            CronHealthService::SCHEDULE_OVERDUE,
        ));

        $this->assertFalse($service->heartbeatContradicted(
            CronHealthService::STATUS_HEALTHY,
            CronHealthService::SCHEDULE_RUNNING,
        ));
    }

    public function testTheLastScheduledRunOnlyCountsCronTriggeredSyncs(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->stringContains('trigger_source = ?'),
                [7, 'cron'],
            )
            ->willReturn('1754812457')
        ;

        $this->assertSame(1754812457, $this->service($connection)->lastScheduledRun(7));
    }

    public function testAMissingSyncLogTableIsNotEvidenceOfAnything(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('no such table'));

        $this->assertSame(0, $this->service($connection)->lastScheduledRun(7));
    }

    private function service(Connection|null $connection = null, Cron|null $cron = null): CronHealthService
    {
        return new CronHealthService($connection ?? $this->createMock(Connection::class), $cron);
    }
}
