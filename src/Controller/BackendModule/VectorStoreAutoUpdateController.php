<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) JUHE IT-solutions
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Controller\BackendModule;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\Message;
use Cron\CronExpression;
use Cron\FieldFactory;
use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Service\LicenseValidationService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\VectorStoreAutoUpdateService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Backend status dashboard for the automatic vector store sync.
 *
 * The route is declared explicitly in config/routes.yaml (this bundle does not
 * import controller route attributes). The POST handler dispatches a CLI sync
 * (non-blocking) — it never runs the sync inline (constraint C4).
 */
class VectorStoreAutoUpdateController extends AbstractBackendController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly VectorStoreAutoUpdateService $service,
        private readonly LicenseValidationService $licenseValidation,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly string $csrfTokenName,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $this->initializeContaoFramework();

        // Per-group access control (BE_MOD does not auto-gate custom routes).
        $this->denyAccessUnlessGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'vector_store_auto_update');

        // Download the generated markdown for one sync-log row. OpenAI blocks
        // downloading purpose=assistants files, so we serve our local copy.
        if ($request->isMethod('GET') && null !== $request->query->get('download')) {
            return $this->downloadDocument((int) $request->query->get('download'));
        }

        // Manual trigger (PRG) — the route's _token_check validates REQUEST_TOKEN.
        if ($request->isMethod('POST')) {
            $configId = (int) $request->request->get('config_id');

            $config = $this->connection->fetchAssociative(
                "SELECT id FROM tl_openai_config WHERE id = ? AND auto_update_enabled = '1'",
                [$configId],
            );

            if (!$config) {
                Message::addError($this->translator->trans('MSC.vsau_err_invalid_config', [], 'contao_default'));

                return $this->redirectToRoute('vector_store_auto_update');
            }

            // Stop automatic sync: unset auto_update_enabled on the config — exactly
            // what unticking "Automatische Synchronisierung aktivieren" does, but
            // reachable straight from this dashboard. Re-enable in the OpenAI config.
            if ('stop' === $request->request->get('action')) {
                // auto_update_enabled is a boolean/TINYINT column — write integer 0,
                // not '' (an empty string errors under MySQL strict mode).
                $this->connection->executeStatement(
                    'UPDATE tl_openai_config SET auto_update_enabled = 0, tstamp = ? WHERE id = ?',
                    [time(), $configId],
                );
                Message::addConfirmation($this->translator->trans('MSC.vsau_stopped_confirm', [], 'contao_default'));

                return $this->redirectToRoute('vector_store_auto_update');
            }

            if (!$this->licenseValidation->isLicenseActive($configId)) {
                Message::addError($this->translator->trans('MSC.vsau_err_no_license', [], 'contao_default'));

                return $this->redirectToRoute('vector_store_auto_update');
            }

            try {
                $this->service->dispatchRun($configId);
                Message::addConfirmation($this->translator->trans('MSC.vsau_queued_confirm', [], 'contao_default'));
            } catch (\Throwable $e) {
                Message::addError($e->getMessage());
            }

            return $this->redirectToRoute('vector_store_auto_update');
        }

        $configs = $this->connection->fetchAllAssociative(
            "SELECT * FROM tl_openai_config WHERE auto_update_enabled = '1' ORDER BY id",
        );

        // Real heartbeat: when did contao:cron last run at all? Contao records each
        // cron job's lastRun in tl_cron_job, updated every minute. This reflects the
        // server cron liveness — unlike auto_update_last_run, which only changes on a
        // sync (daily). MAX(lastRun) is the most recent heartbeat tick.
        $heartbeatLastRun = $this->heartbeatLastRun();

        $hasActiveConfig = false;
        foreach ($configs as &$config) {
            $config['license_active'] = $this->licenseValidation->isLicenseActive((int) $config['id']);
            $config['cron_status'] = $this->cronStatus($heartbeatLastRun);
            $config['heartbeat_last_run'] = $heartbeatLastRun;
            $config['next_run'] = $this->nextRun($config);
            $config['warnings'] = $this->prerequisiteWarnings($config);
            // A manual sync can run without the server cron, but not without a vector
            // store, selected pages and an index. Those prerequisite warnings block it.
            $config['blocking'] = [] !== $config['warnings'];
            $config['plan_label'] = $this->planLabel($config);
            $schedule = (string) ($config['auto_update_schedule'] ?? '') ?: '0 2 * * *';
            $config['schedule_label'] = $this->humanReadableSchedule($schedule);
            $hasActiveConfig = $hasActiveConfig || $config['license_active'];
        }
        unset($config);

        // Do not select the (potentially large) document blob for the list — only a
        // flag of whether a downloadable copy exists for each row.
        $log = $this->connection->fetchAllAssociative(
            "SELECT id, pid, run_at, status, trigger_source, model, pages, tokens_in, tokens_out, file_id, duration, message,
                    (document IS NOT NULL AND document <> '') AS has_document
             FROM tl_openai_sync_log ORDER BY run_at DESC LIMIT 20",
        );

        return $this->render('@Contao/backend/vector_store_auto_update.html.twig', [
            'headline' => $this->translator->trans('MOD.vector_store_auto_update.0', [], 'contao_modules'),
            'configs' => $configs,
            'has_active_config' => $hasActiveConfig,
            'log' => $log,
            'purchase_url' => 'https://licenses.juhe-it-solutions.at/openai-assistant',
            'request_token' => $this->csrfTokenManager->getToken($this->csrfTokenName)->getValue(),
            'manage_log_url' => $this->generateUrl('contao_backend', ['do' => 'openai_sync_log']),
        ]);
    }

    /**
     * Stream the stored markdown of one sync-log row as a file download. Redirects
     * back with an error if the row or its document is missing.
     */
    private function downloadDocument(int $logId): Response
    {
        $row = $logId > 0
            ? $this->connection->fetchAssociative(
                'SELECT run_at, file_id, document FROM tl_openai_sync_log WHERE id = ?',
                [$logId],
            )
            : null;

        if (empty($row) || '' === (string) ($row['document'] ?? '')) {
            Message::addError($this->translator->trans('MSC.vsau_download_missing', [], 'contao_default'));

            return $this->redirectToRoute('vector_store_auto_update');
        }

        $date = date('Y-m-d_His', (int) $row['run_at']);
        $filename = 'vector-store-document_'.$date.'.md';

        return new Response((string) $row['document'], Response::HTTP_OK, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Human-readable subscription label, e.g. "Business (up to 100 pages)" or
     * "Enterprise (unlimited)". Empty when no plan was stored yet.
     *
     * @param array<string, mixed> $config
     */
    private function planLabel(array $config): string
    {
        $plan = (string) ($config['premium_license_plan'] ?? '');
        if ('' === $plan) {
            return '';
        }

        $name = $this->translator->trans('MSC.vsau_plan_'.$plan, [], 'contao_default');

        if ('enterprise' === $plan) {
            $limit = $this->translator->trans('MSC.vsau_plan_unlimited', [], 'contao_default');
        } elseif ((int) ($config['premium_license_max_pages'] ?? 0) > 0) {
            $limit = $this->translator->trans('MSC.vsau_plan_pages', [(int) $config['premium_license_max_pages']], 'contao_default');
        } else {
            return $name;
        }

        return $name.' ('.$limit.')';
    }

    /**
     * Most recent contao:cron execution (heartbeat), read from Contao's tl_cron_job
     * table. Returns 0 when the cron has never run (or the table is unavailable).
     */
    private function heartbeatLastRun(): int
    {
        try {
            // Read the raw datetime and parse it in PHP (same timezone Doctrine used to
            // store the datetime_immutable). Avoids MySQL UNIX_TIMESTAMP() session-timezone
            // skew. tl_cron_job exists unchanged in Contao 5.3 and 5.7.
            $raw = $this->connection->fetchOne('SELECT lastRun FROM tl_cron_job ORDER BY lastRun DESC LIMIT 1');

            if (empty($raw)) {
                return 0;
            }

            return (new \DateTimeImmutable((string) $raw))->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * never | healthy | stale - see §10.5. contao:cron runs every minute, so two
     * missed ticks (120 s) is a reliable "cron stopped" signal.
     */
    private function cronStatus(int $lastRun): string
    {
        if (0 === $lastRun) {
            return 'never';
        }

        return time() - $lastRun < 120 ? 'healthy' : 'stale';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function nextRun(array $config): int|null
    {
        $lastRun = (int) ($config['auto_update_last_run'] ?? 0);
        if (0 === $lastRun) {
            return null;
        }

        $schedule = (string) ($config['auto_update_schedule'] ?? '') ?: '0 2 * * *';

        try {
            $expression = new CronExpression($schedule, new FieldFactory());
            // Evaluate the schedule in the app timezone (not UTC); a '@'-epoch
            // DateTime is always UTC, which would offset "nächster" by the local
            // UTC offset (e.g. +2h in CEST). Must match VectorStoreAutoUpdateCron.
            $tz = new \DateTimeZone(date_default_timezone_get());
            $from = (new \DateTimeImmutable('@'.$lastRun))->setTimezone($tz);

            return $expression->getNextRunDate($from)->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    private function humanReadableSchedule(string $schedule): string
    {
        $parts = preg_split('/\s+/', trim($schedule));
        if (5 !== \count($parts)) {
            return $schedule;
        }

        [$minute, $hour, $dom, $month, $dow] = $parts;

        $h = ctype_digit($hour) ? \sprintf('%02d', (int) $hour) : $hour;
        $m = ctype_digit($minute) ? \sprintf('%02d', (int) $minute) : $minute;
        $t = 'contao_default';

        if ('*' === $dom && '*' === $month && '*' === $dow && '*' === $minute && '*' === $hour) {
            return $this->translator->trans('MSC.vsau_schedule_every_minute', [], $t);
        }

        if ('*' === $dom && '*' === $month && '*' === $dow && ctype_digit($minute) && ctype_digit($hour)) {
            return $this->translator->trans('MSC.vsau_schedule_daily', [$h, $m], $t);
        }

        if ('*' === $dom && '*' === $month && ctype_digit($dow) && ctype_digit($minute) && ctype_digit($hour)) {
            $day = $this->translator->trans('MSC.vsau_weekday_'.(int) $dow, [], $t);

            return $this->translator->trans('MSC.vsau_schedule_weekday', [$day, $h, $m], $t);
        }

        if (1 === preg_match('/^\*\/(\d+)$/', $minute, $mt) && '*' === $hour && '*' === $dom && '*' === $month && '*' === $dow) {
            return $this->translator->trans('MSC.vsau_schedule_every_minutes', [(int) $mt[1]], $t);
        }

        if ('*' === $hour && '*' === $dom && '*' === $month && '*' === $dow && ctype_digit($minute)) {
            return $this->translator->trans('MSC.vsau_schedule_hourly', [$m], $t);
        }

        if ('*' === $minute && '*' === $dom && '*' === $month && '*' === $dow && ctype_digit($hour)) {
            return $this->translator->trans('MSC.vsau_schedule_every_minute_in_hour', [$h], $t);
        }

        if ('*' === $month && '*' === $dow && ctype_digit($dom) && ctype_digit($minute) && ctype_digit($hour)) {
            return $this->translator->trans('MSC.vsau_schedule_monthly', [(int) $dom, $h, $m], $t);
        }

        return $schedule;
    }

    /**
     * Non-blocking prerequisite warnings (§10.5). Returned as a list of
     * translated strings.
     *
     * @param array<string, mixed> $config
     *
     * @return array<int, string>
     */
    private function prerequisiteWarnings(array $config): array
    {
        $warnings = [];

        if ('' === (string) ($config['vector_store_id'] ?? '')) {
            $warnings[] = $this->translator->trans('MSC.vsau_warn_no_vector_store', [], 'contao_default');
        }

        $hasStartPage = [] !== VectorStoreAutoUpdateService::parseConfiguredPageIds($config['auto_update_site_root'] ?? null);
        $hasDomain = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM tl_page WHERE type = 'root' AND dns != ''",
        );
        if (!$hasStartPage && 1 !== $hasDomain) {
            $warnings[] = $this->translator->trans('MSC.vsau_warn_no_crawl_page', [], 'contao_default');
        }

        $indexed = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_search');
        if (0 === $indexed) {
            $warnings[] = $this->translator->trans('MSC.vsau_warn_no_indexed_pages', [], 'contao_default');
        }

        return $warnings;
    }
}
