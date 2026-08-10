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

use Contao\CoreBundle\Cron\Cron;
use Cron\CronExpression;
use Cron\FieldFactory;
use Doctrine\DBAL\Connection;

/**
 * Answers two different questions that the dashboard used to conflate:
 *
 *  1. status() — is a server cron waking Contao up at all? (infrastructure)
 *  2. scheduleStatus() — are the scheduled syncs actually happening? (outcome)
 *
 * They are deliberately independent. The heartbeat is an inference from a
 * timestamp Contao writes for its own marker job; the schedule status is
 * EVIDENCE, read from our own sync log. When they disagree the evidence wins,
 * because a row in tl_openai_sync_log with trigger_source='cron' can only have
 * been written by VectorStoreAutoUpdateCron, which refuses web scope — so it
 * proves a CLI contao:cron ran, whatever the heartbeat timestamp claims.
 *
 * The marker job (updateMinutelyCliCron), the tl_cron_job row name and
 * Cron::hasMinutelyCliCron() are identical in Contao 5.3, 5.7 and 6.0.
 */
class CronHealthService
{
    public const STATUS_NEVER = 'never';

    public const STATUS_NO_CLI_CRON = 'no_cli_cron';

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_STALE = 'stale';

    /**
     * The timestamp cannot be trusted — a clock/timezone problem, not a dead cron.
     * Reported separately so an operator is never sent to fix a cron that runs.
     */
    public const STATUS_UNKNOWN = 'unknown';

    public const SCHEDULE_MANUAL = 'manual';

    public const SCHEDULE_RUNNING = 'running';

    /**
     * Never synced at all — the first run is always manual.
     */
    public const SCHEDULE_AWAITING_FIRST = 'awaiting_first';

    /**
     * Synced before, but never by the cron yet; the cron looks alive.
     */
    public const SCHEDULE_PENDING = 'pending';

    /**
     * Cron-triggered runs happened, then stopped.
     */
    public const SCHEDULE_OVERDUE = 'overdue';

    /**
     * No cron evidence and no working cron either.
     */
    public const SCHEDULE_NOT_RUNNING = 'not_running';

    /**
     * How long the heartbeat may go unseen before it counts as stale.
     *
     * Sized for the WORST legitimate tick interval, not the documented one. Three
     * things stretch the gap between two heartbeats on a perfectly healthy install:
     *
     *  - our cron job runs the crawl SYNCHRONOUSLY inside contao:cron, so on a host
     *    that serialises cron runs (flock, panel wrappers) no tick happens for the
     *    whole run, and 14 minutes is an ordinary run;
     *  - many shared hosts do not offer a minutely cron at all — 5, 15 and 30 minute
     *    minimums are common, and Contao works fine on them because a cron job is
     *    re-evaluated whenever contao:cron runs, not on the minute;
     *  - panel schedulers drift.
     *
     * The original 120 s ("two missed minutely ticks") therefore reported healthy
     * installs as broken. 30 minutes covers every one of those cases. The cost is a
     * slower reaction to a genuinely dead cron, which is acceptable: this box is
     * diagnostic, and the actionable statement lives in scheduleStatus(), which is
     * driven by evidence rather than by this timestamp.
     */
    public const IDLE_GRACE_SECONDS = 1800;

    /**
     * Clock disagreement below this is jitter; above it the timestamp is wrong.
     */
    public const CLOCK_SKEW_TOLERANCE = 60;

    /**
     * Added on top of two full schedule intervals before scheduled runs count as
     * overdue. Covers a run that is merely late because the previous one overran.
     */
    public const SCHEDULE_GRACE_SECONDS = 900;

    public const DEFAULT_SCHEDULE = '0 2 * * *';

    public function __construct(
        private readonly Connection $connection,
        private readonly Cron|null $cron = null,
    ) {
    }

    /**
     * Timestamp of the last CLI-scoped contao:cron execution.
     *
     * Returns:
     *  >0 → Unix timestamp of the last CLI run
     *   0 → tl_cron_job is empty or unavailable (cron has never run at all)
     *  -1 → table has entries (web-triggered jobs exist) but the CLI marker is
     *         absent, meaning contao:cron runs only via web visits, not a real
     *         server cron job
     */
    public function heartbeatLastRun(): int
    {
        try {
            // Read the raw datetime and parse it in PHP (same timezone Doctrine used to
            // store the datetime_immutable). Avoids MySQL UNIX_TIMESTAMP() session-timezone
            // skew.
            $raw = $this->connection->fetchOne(
                'SELECT lastRun FROM tl_cron_job WHERE name = ? LIMIT 1',
                ['Contao\\CoreBundle\\Cron\\Cron::updateMinutelyCliCron'],
            );

            if (!empty($raw)) {
                return (new \DateTimeImmutable((string) $raw))->getTimestamp();
            }

            // CLI marker absent — distinguish "cron never ran" from "only web cron runs"
            $hasAny = $this->connection->fetchOne('SELECT 1 FROM tl_cron_job LIMIT 1');

            return $hasAny ? -1 : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * never | no_cli_cron | healthy | stale | unknown.
     *
     * $runInFlight must be true while ANY configuration has a queued/running sync:
     * that run occupies the contao:cron process, so a frozen heartbeat during it is
     * expected behaviour rather than a fault.
     */
    public function status(int $lastRun, bool $runInFlight = false): string
    {
        if (0 === $lastRun) {
            return self::STATUS_NEVER;
        }

        if (-1 === $lastRun) {
            return self::STATUS_NO_CLI_CRON;
        }

        $age = time() - $lastRun;

        // A tick that has not happened yet means the writing (CLI) and reading (web)
        // process disagree about the clock — tl_cron_job.lastRun is a plain datetime
        // column written as a local-time string, so a php.ini timezone mismatch
        // between CLI and FPM shows up here as a constant offset. Never a cron fault.
        if ($age < -self::CLOCK_SKEW_TOLERANCE) {
            return self::STATUS_UNKNOWN;
        }

        if ($age < self::IDLE_GRACE_SECONDS) {
            return self::STATUS_HEALTHY;
        }

        if ($runInFlight) {
            return self::STATUS_HEALTHY;
        }

        // Contao's own liveness flag lives in the cache pool with a 70 s TTL and no
        // date involved at all. If it says the minutely CLI cron is alive while the
        // timestamp says otherwise, the timestamp is the thing that is wrong.
        if ($this->reportsMinutelyCliCron()) {
            return self::STATUS_UNKNOWN;
        }

        return self::STATUS_STALE;
    }

    /**
     * Unix timestamp of the most recent sync this configuration ran FROM THE CRON,
     * or 0 if there has never been one.
     *
     * trigger_source='cron' is written by exactly one caller,
     * VectorStoreAutoUpdateCron, and that job throws CronExecutionSkippedException
     * in web scope. A row is therefore proof that a CLI contao:cron fired.
     */
    public function lastScheduledRun(int $configId): int
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT MAX(run_at) FROM tl_openai_sync_log WHERE pid = ? AND trigger_source = ?',
                [$configId, VectorStoreAutoUpdateService::SOURCE_CRON],
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Seconds between two consecutive firings of a cron expression, or null if the
     * expression is unusable. Derived from the expression itself rather than
     * pattern-matched, so it is correct for every schedule the form can produce.
     */
    public function scheduleInterval(string $schedule): int|null
    {
        try {
            $expression = new CronExpression($schedule ?: self::DEFAULT_SCHEDULE, new FieldFactory());
            // Same timezone the cron job and nextRun() evaluate in, so an interval
            // spanning a DST boundary is measured the way the schedule will fire.
            $tz = new \DateTimeZone(date_default_timezone_get());
            $now = new \DateTimeImmutable('now', $tz);

            $interval = $expression->getNextRunDate($now, 1)->getTimestamp()
                - $expression->getNextRunDate($now, 0)->getTimestamp();

            return $interval > 0 ? $interval : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether the SCHEDULE is doing its job — answered from the sync log first and
     * from the heartbeat only when there is no evidence yet.
     *
     * $configRunInFlight must describe THIS configuration only. It is deliberately not
     * the install-wide flag that status() takes: the heartbeat is shared (one cron
     * process serves every configuration, so any run silences it), but a run belonging
     * to configuration A is no evidence at all that configuration B's schedule still
     * fires — passing the global flag here reports an overdue B as "running".
     *
     * @param array<string, mixed> $config
     */
    public function scheduleStatus(array $config, int $lastScheduledRun, string $cronStatus, bool $configRunInFlight = false): string
    {
        if ('manual' === (string) ($config['auto_update_trigger'] ?? 'scheduled')) {
            return self::SCHEDULE_MANUAL;
        }

        if ($lastScheduledRun > 0) {
            // A run in flight is the strongest evidence there is: it is happening now.
            if ($configRunInFlight) {
                return self::SCHEDULE_RUNNING;
            }

            $interval = $this->scheduleInterval((string) ($config['auto_update_schedule'] ?? ''));

            // Unreadable expression: the evidence still stands, we just cannot say
            // whether the next one is late. Do not turn that into an alarm.
            if (null === $interval) {
                return self::SCHEDULE_RUNNING;
            }

            return time() - $lastScheduledRun <= 2 * $interval + self::SCHEDULE_GRACE_SECONDS
                ? self::SCHEDULE_RUNNING
                : self::SCHEDULE_OVERDUE;
        }

        // No cron-triggered run on record. The first sync of a configuration is always
        // manual, so this is the normal state of a fresh install, not a fault.
        if (0 === (int) ($config['auto_update_last_run'] ?? 0)) {
            return self::SCHEDULE_AWAITING_FIRST;
        }

        return \in_array($cronStatus, [self::STATUS_HEALTHY, self::STATUS_UNKNOWN], true)
            ? self::SCHEDULE_PENDING
            : self::SCHEDULE_NOT_RUNNING;
    }

    /**
     * True when the heartbeat reads badly while the schedule is demonstrably working.
     *
     * Drives the reassurance line in the heartbeat box: the reading stays honest
     * ("Veraltet"), but the operator is told in the same breath that their syncs are
     * running, so they do not go chasing a cron that is not broken.
     */
    public function heartbeatContradicted(string $cronStatus, string $scheduleStatus): bool
    {
        // Only SCHEDULE_RUNNING contradicts a bad heartbeat. SCHEDULE_OVERDUE means the
        // scheduled runs have stopped, which AGREES with a stale heartbeat — claiming
        // "your syncs are running anyway" there would be the same kind of lie in reverse.
        return \in_array($cronStatus, [self::STATUS_STALE, self::STATUS_NO_CLI_CRON, self::STATUS_UNKNOWN], true)
            && self::SCHEDULE_RUNNING === $scheduleStatus;
    }

    private function reportsMinutelyCliCron(): bool
    {
        if (null === $this->cron) {
            return false;
        }

        try {
            return $this->cron->hasMinutelyCliCron();
        } catch (\Throwable) {
            // Catches two things on purpose. An unwritable or missing cache pool must
            // never decide cron health - and hasMinutelyCliCron() only arrived during
            // the 5.3 line, so on an early 5.3 patch release the undefined-method Error
            // lands here too and the service simply falls back to the timestamp alone.
            return false;
        }
    }
}
