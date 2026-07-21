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

namespace JuheItSolutions\ContaoOpenaiAssistant\Premium\Controller\BackendModule;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\Image;
use Contao\Message;
use Cron\CronExpression;
use Cron\FieldFactory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\CronHealthService;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\LicensePortalUrlService;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\LicenseValidationService;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreAutoUpdateService;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreSyncMessageTranslator;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly LicensePortalUrlService $licensePortalUrls,
        private readonly VectorStoreSyncMessageTranslator $syncMessages,
        private readonly CronHealthService $cronHealth,
        private readonly EncryptionService $encryption,
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
            // what unticking "Synchronisierung aktivieren" does, but
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

            // Force a fresh remote revalidation without re-entering the key (UX-06).
            // Result is passed as a query param so it renders inline next to the button
            // rather than in Contao's session-message queue (which only surfaces on the
            // backend dashboard, not on our own page).
            if ('refresh_license' === $request->request->get('action')) {
                $refreshData = $this->licenseValidation->forceRevalidate($configId);

                // Resolve a human-readable plan name server-side so the template stays
                // free of dynamic translation key construction.
                $planSlug = $refreshData['plan'];
                $planName = '' !== $planSlug
                    ? $this->translator->trans('MSC.vsau_plan_'.$planSlug, [], 'contao_default')
                    : '';
                // Fall back to the raw slug when no translation exists for it.
                if ('' !== $planSlug && $planName === 'MSC.vsau_plan_'.$planSlug) {
                    $planName = $planSlug;
                }

                if (!$refreshData['active']) {
                    $refreshResult = 'inactive';
                } elseif ($refreshData['plan_changed']) {
                    $refreshResult = 'ok_changed';
                } else {
                    $refreshResult = 'ok_same';
                }

                return $this->redirectToRoute('vector_store_auto_update', [
                    'refresh_result' => $refreshResult,
                    'refresh_config' => $configId,
                    'refresh_plan' => $planName,
                ]);
            }

            if (!$this->licenseValidation->isLicenseActive($configId)) {
                Message::addError($this->translator->trans('MSC.vsau_err_no_license', [], 'contao_default'));

                return $this->redirectToRoute('vector_store_auto_update');
            }

            // Check proc_open at dispatch time, not just at render time — so a hosting
            // config change after page load surfaces a clear error rather than a silent
            // failure (UX-05).
            if (!$this->processSpawningAvailable()) {
                Message::addError($this->translator->trans('MSC.vsau_err_no_proc_open', [], 'contao_default'));

                return $this->redirectToRoute('vector_store_auto_update');
            }

            try {
                $this->service->dispatchRun($configId);
                Message::addConfirmation($this->translator->trans('MSC.vsau_queued_confirm', [], 'contao_default'));
            } catch (\Throwable $e) {
                Message::addError($this->syncMessages->translate($e->getMessage()) ?? $e->getMessage());
            }

            return $this->redirectToRoute('vector_store_auto_update');
        }

        // Persist dead runs ('queued'/'running' with a stale heartbeat lease) as errors
        // before rendering — otherwise the badge and the disabled "Run sync now" button
        // would be stuck on a run that will never report back.
        $this->service->reconcileStaleRuns();

        $configs = $this->connection->fetchAllAssociative(
            "SELECT * FROM tl_openai_config WHERE auto_update_enabled = '1' ORDER BY id",
        );

        // Real heartbeat: when did contao:cron last run at all? Contao records each
        // cron job's lastRun in tl_cron_job, updated every minute. This reflects the
        // server cron liveness — unlike auto_update_last_run, which only changes on a
        // sync (daily). MAX(lastRun) is the most recent heartbeat tick.
        $heartbeatLastRun = $this->cronHealth->heartbeatLastRun();

        $hasActiveConfig = false;

        foreach ($configs as &$config) {
            // Cache-only check on render: never block the dashboard load on a licensing
            // HTTP call. Every POST action above re-checks with the authoritative
            // isLicenseActive() before doing anything, and the "Refresh license status"
            // button forces a live revalidation on demand.
            $config['license_active'] = $this->licenseValidation->isLicenseActiveCached((int) $config['id']);
            // Manual-only configs ignore the cron entirely, so the dashboard suppresses cron
            // health warnings for them and shows a "manual only" indicator instead.
            $config['manual_mode'] = 'manual' === (string) ($config['auto_update_trigger'] ?? 'scheduled');
            $config['cron_status'] = $this->cronHealth->status($heartbeatLastRun);
            $config['heartbeat_last_run'] = $heartbeatLastRun;
            $config['next_run'] = $this->nextRun($config);
            $config['warnings'] = $this->prerequisiteWarnings($config);
            // A manual sync can run without the server cron, but not without a vector
            // store or selected pages. Those prerequisite warnings block it. Notices
            // (e.g. an empty search index, which the sync's own crawl rebuilds) do not.
            $config['blocking'] = [] !== $config['warnings'];
            $config['notices'] = $this->setupNotices($config);
            $config['plan_label'] = $this->planLabel($config);
            $schedule = (string) ($config['auto_update_schedule'] ?? '') ?: '0 2 * * *';
            $config['schedule_label'] = $this->humanReadableSchedule($schedule);
            // Display-ready "Last sync" box fields; the same struct is served by the JSON
            // status endpoint, so the initial render and the poller can never disagree.
            $config['status_view'] = $this->statusView($config);
            $hasActiveConfig = $hasActiveConfig || $config['license_active'];
        }
        unset($config);

        // Do not select the (potentially large) document blob for the list — only a
        // flag of whether a downloadable copy exists for each row.
        $log = $this->connection->fetchAllAssociative(
            "SELECT id, pid, run_at, status, trigger_source, model, pages, tokens_in, tokens_out, file_id, duration, message,
                    (document IS NOT NULL AND document <> '') AS has_document
             FROM tl_openai_sync_log ORDER BY run_at DESC LIMIT 10",
        );

        // Determine which log rows are the first-ever sync for their config — no DB
        // column needed; derive from MIN(id) per pid at render time.
        $firstLogIds = array_map(
            'intval',
            $this->connection->fetchFirstColumn('SELECT MIN(id) FROM tl_openai_sync_log GROUP BY pid'),
        );

        foreach ($log as &$row) {
            $row['message'] = $this->syncMessages->translate(isset($row['message']) ? (string) $row['message'] : null);
            $row['is_initial'] = \in_array((int) $row['id'], $firstLogIds, true);
        }
        unset($row);

        return $this->render('@Contao/backend/vector_store_auto_update.html.twig', [
            'headline' => $this->translator->trans('MOD.vector_store_auto_update.0', [], 'contao_modules'),
            'configs' => $configs,
            'has_active_config' => $hasActiveConfig,
            'log' => $log,
            'purchase_url' => $this->licensePortalUrls->getProductUrl(),
            'help_url' => $this->licensePortalUrls->getHelpUrl(),
            'manage_url' => $this->licensePortalUrls->getManageUrl(),
            'request_token' => $this->csrfTokenManager->getToken($this->csrfTokenName)->getValue(),
            'manage_log_url' => $this->generateUrl('contao_backend', ['do' => 'openai_sync_log']),
            // The "Run sync now" button spawns a CLI process via proc_open. Some shared hosts
            // disable it; warn up front so the user isn't surprised by a failed click.
            'process_spawning_available' => $this->processSpawningAvailable(),
            // Session messages (stop confirmation, errors, queue result) rendered here
            // so they appear on our own page rather than on the Contao backend dashboard.
            'backend_messages' => Message::generate(),
            // Inline result of "Lizenz aktualisieren" — passed as query params so it
            // renders beside the button without going through the session-message queue.
            'refresh_result' => $request->query->get('refresh_result'),
            'refresh_config_id' => (int) $request->query->get('refresh_config', 0),
            'refresh_plan' => $request->query->get('refresh_plan', ''),
            // Polled by the inline status script for live badge/progress updates.
            'status_url' => $this->generateUrl('vector_store_auto_update_status'),
            // Backend "show" icon path (Contao 6 serves it from bundles/contaocore/icons/).
            'show_icon' => Image::getPath('show.svg'),
        ]);
    }

    /**
     * Lightweight JSON status for the dashboard poller. Returns display-ready translated
     * strings per enabled config so the client script only ever assigns textContent —
     * all translation and formatting stays server-side, and no secrets are exposed.
     */
    public function status(): JsonResponse
    {
        $this->initializeContaoFramework();

        // Same gate as the dashboard page itself.
        $this->denyAccessUnlessGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'vector_store_auto_update');

        // Flip dead runs to "error" so the poller resolves them without a manual reload.
        $this->service->reconcileStaleRuns();

        // SELECT * (not the progress columns explicitly) so the endpoint keeps answering
        // between a bundle update and contao:migrate; statusView() defaults missing fields.
        $configs = $this->connection->fetchAllAssociative(
            "SELECT * FROM tl_openai_config WHERE auto_update_enabled = '1' ORDER BY id",
        );

        $payload = [];

        foreach ($configs as $config) {
            $payload[(string) (int) $config['id']] = $this->statusView($config);
        }

        $response = new JsonResponse(['configs' => $payload]);
        // Status must always be live — never let the browser or a proxy cache a poll.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Display-ready view of one config's "Last sync" box, shared verbatim by the page
     * render and the JSON status endpoint so the two can never disagree. All strings
     * are translated and formatted here; consumers (Twig and the poller JS) only print.
     *
     * @param array<string, mixed> $config
     *
     * @return array{active: bool, badge_label: string, badge_class: string, spinner: bool, progress_text: string|null, activity_text: string|null, last_run_formatted: string|null, message: string|null}
     */
    private function statusView(array $config): array
    {
        $status = (string) ($config['auto_update_last_status'] ?? '');
        $lastRun = (int) ($config['auto_update_last_run'] ?? 0);
        $active = \in_array($status, ['queued', 'running'], true);
        // Age of an in-flight run in whole minutes, shown next to the queued/running
        // badge so the user can tell a fresh dispatch from one going a while.
        $ageMinutes = (int) floor(max(0, time() - $lastRun) / 60);
        [$badgeLabel, $badgeClass] = $this->statusBadge($status);

        return [
            'active' => $active,
            'badge_label' => $badgeLabel,
            'badge_class' => $badgeClass,
            'spinner' => 'running' === $status,
            'progress_text' => $this->progressText($config),
            'activity_text' => $active ? $this->translator->trans('MSC.vsau_status_last_activity', [$ageMinutes], 'contao_default') : null,
            // While running, auto_update_last_run is the heartbeat, not a completion time —
            // still shown (matches the pre-live-update behavior), but hidden while queued.
            'last_run_formatted' => $lastRun > 0 && 'queued' !== $status ? date('d.m.Y H:i:s', $lastRun) : null,
            'message' => $this->syncMessages->translate(isset($config['auto_update_last_message']) ? (string) $config['auto_update_last_message'] : null),
        ];
    }

    /**
     * Translated badge label + color class for a sync status, matching the badge
     * markup rendered by the template.
     *
     * @return array{0: string, 1: string}
     */
    private function statusBadge(string $status): array
    {
        [$key, $class] = match ($status) {
            'success' => ['MSC.vsau_sync_success', 'green'],
            'partial' => ['MSC.vsau_sync_partial', 'amber'],
            'error' => ['MSC.vsau_sync_error', 'red'],
            'running' => ['MSC.vsau_sync_running', 'slate'],
            'queued' => ['MSC.vsau_sync_queued', 'blue'],
            'skipped' => ['MSC.vsau_sync_skipped', 'yellow'],
            default => ['MSC.vsau_sync_never', 'grey'],
        };

        return [$this->translator->trans($key, [], 'contao_default'), $class];
    }

    /**
     * Human-readable live-progress line for a running sync ("Crawling…",
     * "AI processing: X of Y pages"), or null when there is nothing to show.
     *
     * @param array<string, mixed> $config
     */
    private function progressText(array $config): string|null
    {
        if ('running' !== (string) ($config['auto_update_last_status'] ?? '')) {
            return null;
        }

        $phase = (string) ($config['auto_update_progress_phase'] ?? '');
        $current = (int) ($config['auto_update_progress_current'] ?? 0);
        $total = (int) ($config['auto_update_progress_total'] ?? 0);

        return match (true) {
            'crawl' === $phase => $this->translator->trans('MSC.vsau_progress_crawl', [], 'contao_default'),
            'polish' === $phase && $total > 0 => $this->translator->trans('MSC.vsau_progress_polish', [$current, $total], 'contao_default'),
            'upload' === $phase && $total > 0 => $this->translator->trans('MSC.vsau_progress_upload', [$current, $total], 'contao_default'),
            default => null,
        };
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
        $filename = 'vector-store-manifest_'.$date.'.md';

        return new Response(
            (string) $row['document'],
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/markdown; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Human-readable subscription label, e.g. "Business (up to 50 pages)" or
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
     * Whether PHP can spawn a CLI process (proc_open) — required by the manual "Run sync now"
     * button. function_exists() returns false when proc_open is listed in disable_functions,
     * which is common on locked-down shared hosting.
     */
    private function processSpawningAvailable(): bool
    {
        return \function_exists('proc_open');
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
     * Prerequisite warnings for the sync dashboard. Any non-empty list blocks the
     * manual "Run sync now" button (see $config['blocking'] in __invoke()).
     *
     * @param array<string, mixed> $config
     *
     * @return array<int, string>
     */
    private function prerequisiteWarnings(array $config): array
    {
        $warnings = [];

        // The sync runs in a CLI process and must be able to resolve the API key there.
        // Resolving it here (web context) also lazily re-encrypts legacy values with the
        // app-secret key, so upgraded installs are healed before the first CLI run; the
        // warning only remains when no context can produce a usable key.
        if (null === $this->encryption->getApiKeyForConfig((int) $config['id'], false)) {
            $warnings[] = $this->translator->trans('MSC.vsau_warn_no_api_key', [], 'contao_default');
        }

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

        // One license covers one domain. Warn (and thus block the run) when the selected
        // scope spans more than one root-page domain - only distinct, non-empty domains of
        // the selected pages' own roots count, so an unrelated second website in the same
        // install and domain-less roots never trigger this.
        if (\count($this->service->resolveScopeRootDomains($config['auto_update_site_root'] ?? null)) > 1) {
            $warnings[] = $this->translator->trans('MSC.vsau_warn_multi_domain', [], 'contao_default');
        }

        return $warnings;
    }

    /**
     * Non-blocking setup notices. Unlike prerequisiteWarnings(), these never disable
     * the "Run sync now" button: every sync run (manual, scheduled or CLI) starts the
     * Contao crawler itself and rebuilds the search index before reading it, so an
     * empty tl_search is self-healing — worth pointing out, but no reason to block
     * (e.g. after the operator truncated tl_search, or before the very first crawl).
     *
     * @param array<string, mixed> $config
     *
     * @return array<int, string>
     */
    private function setupNotices(array $config): array
    {
        $notices = [];

        $hasStartPage = [] !== VectorStoreAutoUpdateService::parseConfiguredPageIds($config['auto_update_site_root'] ?? null);

        // Scope the index check to what the sync would actually read (selected pages,
        // or the single-domain-root subtree) - a globally non-empty tl_search says
        // nothing when none of its rows belong to the effective scope. Falls back to
        // the global count when the scope is unresolvable (that state is already a
        // blocking warning in prerequisiteWarnings()).
        $scopeIds = $this->service->resolveScopePageIds($config['auto_update_site_root'] ?? null);

        if ([] !== $scopeIds) {
            $indexed = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM tl_search WHERE pid IN (?)',
                [$scopeIds],
                [ArrayParameterType::INTEGER],
            );
        } else {
            $indexed = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_search');
        }

        if (0 === $indexed) {
            $key = $hasStartPage ? 'MSC.vsau_notice_selected_not_indexed' : 'MSC.vsau_notice_no_indexed_pages';
            $notices[] = $this->translator->trans($key, [], 'contao_default');
        }

        return $notices;
    }
}
