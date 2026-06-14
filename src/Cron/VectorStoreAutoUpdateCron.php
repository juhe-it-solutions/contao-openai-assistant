<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) JUHE IT-solutions
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

/*
 * This file is part of Contao Open Source CMS.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Cron;

use Contao\CoreBundle\Cron\Cron;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Contao\CoreBundle\Exception\CronExecutionSkippedException;
use Cron\CronExpression;
use Cron\FieldFactory;
use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Service\VectorStoreAutoUpdateService;
use Psr\Log\LoggerInterface;

/**
 * Runs every minute but exits immediately unless a config's per-record cron
 * expression (auto_update_schedule) says it is due. This supports per-config
 * schedules without registering multiple cron jobs.
 *
 * The job throws CronExecutionSkippedException in web scope on purpose: the crawl
 * and LLM call can take minutes and must never run inside a web request (C4). A
 * real server cron must run "contao:cron" on the CLI for this to fire.
 */
#[AsCronJob('minutely')]
class VectorStoreAutoUpdateCron
{
    public function __construct(
        private readonly Connection $connection,
        private readonly VectorStoreAutoUpdateService $service,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $scope): void
    {
        if (Cron::SCOPE_WEB === $scope) {
            throw new CronExecutionSkippedException();
        }

        $configs = $this->connection->fetchAllAssociative(
            "SELECT * FROM tl_openai_config WHERE auto_update_enabled = '1'",
        );

        foreach ($configs as $config) {
            if (!$this->isDue($config)) {
                continue;
            }

            $this->service->run((int) $config['id']);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isDue(array $config): bool
    {
        $lastRun = (int) ($config['auto_update_last_run'] ?? 0);
        $schedule = (string) ($config['auto_update_schedule'] ?? '') ?: '0 2 * * *';
        $status = (string) ($config['auto_update_last_status'] ?? '');

        // Stale-run guard: skip while a run is still in progress (<30 min). A "queued"
        // status does NOT block — only "running" does.
        if ('running' === $status && time() - $lastRun < 1800) {
            return false;
        }

        // Never ran yet → run immediately.
        if (0 === $lastRun) {
            return true;
        }

        // A malformed schedule (or a cron-expression library version that does not
        // auto-create its FieldFactory) must never bubble up — that would abort the
        // whole contao:cron run and break the heartbeat for every cron job. Pass an
        // explicit FieldFactory for cross-version compatibility and catch everything.
        try {
            $expression = new CronExpression($schedule, new FieldFactory());
            $lastRunDate = new \DateTimeImmutable('@'.$lastRun);
            $nextRun = $expression->getNextRunDate($lastRunDate);

            return new \DateTimeImmutable() >= \DateTimeImmutable::createFromInterface($nextRun);
        } catch (\Throwable $e) {
            $this->logger->error('Invalid auto-update schedule "'.$schedule.'": '.$e->getMessage());

            return false;
        }
    }
}
