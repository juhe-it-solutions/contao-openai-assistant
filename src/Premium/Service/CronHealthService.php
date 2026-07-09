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

/*
 * This file is part of the JUHE Contao OpenAI Assistant premium add-on.
 *
 * (c) JUHE IT-solutions
 *
 * @license Proprietary - see LICENSE-PREMIUM. Usage of the premium add-on
 *          requires a valid premium subscription from JUHE IT-solutions.
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Premium\Service;

use Doctrine\DBAL\Connection;

/**
 * Detects whether a real server cron runs contao:cron on the CLI, via Contao's
 * own CLI marker job (updateMinutelyCliCron). Shared by the auto-sync dashboard
 * and the config-form first-sync hint. tl_cron_job exists unchanged in Contao
 * 5.3 and 5.7.
 */
class CronHealthService
{
    public const STATUS_NEVER = 'never';

    public const STATUS_NO_CLI_CRON = 'no_cli_cron';

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_STALE = 'stale';

    public function __construct(private readonly Connection $connection)
    {
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
     * never | no_cli_cron | healthy | stale.
     *
     * contao:cron runs every minute in CLI scope, so two missed ticks (120 s) is
     * a reliable "cron stopped" signal. The no_cli_cron state means Contao is
     * running cron jobs via web visits only — the CLI marker job is absent.
     */
    public function status(int $lastRun): string
    {
        return match (true) {
            0 === $lastRun => self::STATUS_NEVER,
            -1 === $lastRun => self::STATUS_NO_CLI_CRON,
            time() - $lastRun < 120 => self::STATUS_HEALTHY,
            default => self::STATUS_STALE,
        };
    }
}
