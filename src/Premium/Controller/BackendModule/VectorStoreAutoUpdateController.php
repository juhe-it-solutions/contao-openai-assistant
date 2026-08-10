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

        // Show the indexed text of a single page, linked from the vector-store file list.
        if ($request->isMethod('GET') && null !== $request->query->get('page_content')) {
            return $this->pageContent((int) $request->query->get('page_content'));
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

        // An update deployed without contao:migrate is missing columns this page reads
        // (tl_openai_sync_log.items) and every run writes. Answer that with the one
        // sentence that fixes it, instead of a raw "Unknown column" from the SELECT
        // further down: this is the page an operator opens to find out why the sync
        // stopped, so it has to survive exactly the state it is there to explain.
        //
        // Everything below is skipped rather than guarded individually - a half-rendered
        // dashboard reading a schema it does not match can only mislead.
        if (!$this->service->isSchemaCurrent()) {
            return $this->renderDashboard([], [], false, $request, true);
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

        // Our cron job runs the crawl synchronously inside contao:cron, so while any
        // configuration is syncing, a host that serialises cron runs writes no further
        // heartbeat tick. Evaluated across ALL configs because they share one cron
        // process: a run belonging to config A freezes the heartbeat config B reads.
        $runInFlight = $this->anyRunInFlight($configs);

        $hasActiveConfig = false;

        // The "Show indexed files" button leads into a second backend module, which a user
        // group may not have. Checked once: without access the button (and its count query)
        // is skipped rather than linking into an access-denied screen.
        $canListFiles = $this->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'openai_vector_file');

        foreach ($configs as &$config) {
            // Cache-only check on render: never block the dashboard load on a licensing
            // HTTP call. Every POST action above re-checks with the authoritative
            // isLicenseActive() before doing anything, and the "Refresh license status"
            // button forces a live revalidation on demand.
            $config['license_active'] = $this->licenseValidation->isLicenseActiveCached((int) $config['id']);
            // Manual-only configs ignore the cron entirely, so the dashboard suppresses cron
            // health warnings for them and shows a "manual only" indicator instead.
            $config['manual_mode'] = 'manual' === (string) ($config['auto_update_trigger'] ?? 'scheduled');
            $config['cron_status'] = $this->cronHealth->status($heartbeatLastRun, $runInFlight);
            $config['heartbeat_last_run'] = $heartbeatLastRun;
            // Evidence, not inference: the last sync this config ran FROM the cron.
            // The schedule box is driven by this and falls back to the heartbeat only
            // when there is nothing to go on yet — so a wrong heartbeat can no longer
            // claim that scheduled syncs are not happening while the log shows them.
            $config['last_scheduled_run'] = $this->cronHealth->lastScheduledRun((int) $config['id']);
            // THIS config's own run, not the install-wide flag above. The heartbeat is
            // shared (one cron process serves every config, so any run freezes it), but
            // schedule evidence is not: config A syncing says nothing about whether
            // config B's schedule still fires, and passing the global flag here made an
            // overdue B report "Läuft" for as long as A was running.
            $config['schedule_status'] = $this->cronHealth->scheduleStatus(
                $config,
                $config['last_scheduled_run'],
                $config['cron_status'],
                $this->runInFlight($config),
            );
            $config['heartbeat_contradicted'] = $this->cronHealth->heartbeatContradicted(
                $config['cron_status'],
                $config['schedule_status'],
            );
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
            // "Which OpenAI file holds which page" for THIS config — the file list is the
            // only place that mapping is visible, since the uploaded files carry no index.
            $config['files_url'] = $this->generateUrl('contao_backend', ['do' => 'openai_vector_file', 'pid' => (int) $config['id']]);
            $config['files_count'] = $canListFiles ? $this->indexedFileCount((int) $config['id']) : 0;
            $hasActiveConfig = $hasActiveConfig || $config['license_active'];
        }
        unset($config);

        // Do not select the (potentially large) document blob for the list — only a
        // flag of whether a downloadable copy exists for each row.
        $log = $this->connection->fetchAllAssociative(
            "SELECT id, pid, run_at, status, trigger_source, model, pages, items, tokens_in, tokens_out, file_id, duration, message,
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

        return $this->renderDashboard($configs, $log, $hasActiveConfig, $request, false);
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
     * Render the dashboard. Shared by the normal path and by the outdated-schema path, so
     * the page keeps its toolbar, its session messages and its help links in a state where
     * no configuration could be read - and so a variable added here can never be missing
     * from one of the two.
     *
     * @param list<array<string, mixed>> $configs
     * @param list<array<string, mixed>> $log
     */
    private function renderDashboard(array $configs, array $log, bool $hasActiveConfig, Request $request, bool $schemaOutdated): Response
    {
        return $this->render('@Contao/backend/vector_store_auto_update.html.twig', [
            'headline' => $this->translator->trans('MOD.vector_store_auto_update.0', [], 'contao_modules'),
            'configs' => $configs,
            'has_active_config' => $hasActiveConfig,
            'log' => $log,
            // Blocks the page's own "no configuration yet / buy premium" card: an install
            // whose migration is pending has configurations, they just cannot be read yet,
            // and inviting the operator to buy what they already own would be absurd.
            'schema_outdated' => $schemaOutdated,
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
     * Display-ready view of one config's "Last sync" box, shared verbatim by the page
     * render and the JSON status endpoint so the two can never disagree. All strings
     * are translated and formatted here; consumers (Twig and the poller JS) only print.
     *
     * @param array<string, mixed> $config
     *
     * @return array{active: bool, badge_label: string, badge_class: string, spinner: bool, progress_text: string|null, bar_visible: bool, bar_percent: int|null, bar_text: string, activity_text: string|null, started_text: string|null, last_run_formatted: string|null, message: string|null}
     */
    private function statusView(array $config): array
    {
        $status = (string) ($config['auto_update_last_status'] ?? '');
        $lastRun = (int) ($config['auto_update_last_run'] ?? 0);
        $started = (int) ($config['auto_update_run_started'] ?? 0);
        $active = \in_array($status, ['queued', 'running'], true);
        // Age of an in-flight run in whole minutes, shown next to the queued/running
        // badge so the user can tell a fresh dispatch from one going a while.
        $ageMinutes = (int) floor(max(0, time() - $lastRun) / 60);
        [$badgeLabel, $badgeClass] = $this->statusBadge($status);
        $percent = $this->progressPercent($config);

        return [
            'active' => $active,
            'badge_label' => $badgeLabel,
            'badge_class' => $badgeClass,
            'spinner' => 'running' === $status,
            'progress_text' => $this->progressText($config),
            // Shown for exactly the in-flight statuses, i.e. the same set the poller
            // treats as active. A queued run gets the indeterminate bar too: work IS
            // pending, and the moment after pressing "sync now" is when feedback matters
            // most. Terminal statuses get none - a full bar next to "error" would be a
            // lie, and next to "success" it is noise the finish time already covers.
            'bar_visible' => $active,
            // null = indeterminate. The crawl phase has no page total to divide by (it
            // runs as one blocking subprocess), so the bar animates instead of inventing
            // a percentage that would jump to 100 the moment a total appears.
            'bar_percent' => $percent,
            // Screen readers get the percentage or an explicit "in progress"; the visual
            // percentage sits in progress_text, which already names the phase.
            'bar_text' => null !== $percent
                ? $percent.' %'
                : $this->translator->trans('MSC.vsau_progress_indeterminate', [], 'contao_default'),
            'activity_text' => $active ? $this->translator->trans('MSC.vsau_status_last_activity', [$ageMinutes], 'contao_default') : null,
            'started_text' => $this->startedText($status, $started),
            // While running, auto_update_last_run is the heartbeat, not a completion time,
            // so it is suppressed there: the start line above says when the run began and
            // the activity line says how fresh it is. A terminal status shows the finish
            // time, followed by the duration once a start time is on record.
            'last_run_formatted' => $lastRun > 0 && !$active
                ? date('d.m.Y H:i:s', $lastRun).$this->durationSuffix($started, $lastRun)
                : null,
            'message' => $this->syncMessages->translate(isset($config['auto_update_last_message']) ? (string) $config['auto_update_last_message'] : null),
        ];
    }

    /**
     * "Started HH:MM:SS" / "Queued HH:MM:SS" for an in-flight run, or null.
     *
     * Only in-flight statuses get this line. For a finished run the interesting number
     * is how long it took, which durationSuffix() appends to the completion time instead.
     * Null while the column is still 0 - a run dispatched before the column existed, or
     * before contao:migrate ran.
     */
    private function startedText(string $status, int $started): string|null
    {
        if ($started <= 0 || !\in_array($status, ['queued', 'running'], true)) {
            return null;
        }

        $key = 'queued' === $status ? 'MSC.vsau_status_queued_at' : 'MSC.vsau_status_started_at';

        return $this->translator->trans($key, [date('d.m.Y H:i:s', $started)], 'contao_default');
    }

    /**
     * " (took 12 min 36 s)" for a finished run, or '' when it cannot be computed.
     *
     * Guards against a start that is missing (pre-migration rows) or later than the end
     * (a clock change, or a terminal state written by reconcileStaleRuns() for a run
     * whose heartbeat predates the takeover) - in those cases no duration is better than
     * a nonsensical one.
     */
    private function durationSuffix(int $started, int $finished): string
    {
        if ($started <= 0 || $finished < $started) {
            return '';
        }

        $seconds = $finished - $started;
        $text = $seconds < 60
            ? $this->translator->trans('MSC.vsau_status_duration_seconds', [$seconds], 'contao_default')
            : $this->translator->trans('MSC.vsau_status_duration', [intdiv($seconds, 60), $seconds % 60], 'contao_default');

        return ' ('.$text.')';
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
            // The crawl's number is how many pages Contao has newly indexed, not how many
            // it has visited: an unchanged page is skipped before it is written, so a crawl
            // of a quiet site legitimately reports nothing for minutes. Named for what it
            // is, and never turned into "N of M" - see countIndexedSince() for why there is
            // no honest denominator.
            'crawl' === $phase && $current > 0 => $this->translator->trans('MSC.vsau_progress_crawl_indexed', [$current], 'contao_default'),
            'crawl' === $phase => $this->translator->trans('MSC.vsau_progress_crawl', [], 'contao_default'),
            'read' === $phase => $this->translator->trans('MSC.vsau_progress_read', [], 'contao_default'),
            'polish' === $phase && $total > 0 => $this->translator->trans('MSC.vsau_progress_polish', [$current, $total], 'contao_default'),
            'upload' === $phase && $total > 0 => $this->translator->trans('MSC.vsau_progress_upload', [$current, $total], 'contao_default'),
            default => null,
        };
    }

    /**
     * Completion of the running sync as a whole percentage, or null when it cannot be
     * known and the bar has to stay indeterminate.
     *
     * Only the polish and upload phases can answer this: each counts pages it has finished
     * against a set it holds in memory. The crawl cannot, and an earlier attempt to give it
     * a scale was wrong rather than merely rough - it divided the pages Contao had newly
     * indexed by the total size of the search index, two different populations, which on a
     * site with one edited page and 1072 indexed URLs displayed "1 of about 1072". The
     * crawl now reports a count and no total, so it lands on the null below and the bar
     * stays indeterminate for its whole duration.
     *
     * Capped at 100 rather than trusting the arithmetic: current may briefly exceed total
     * if the page set grows between the total being written and the loop finishing, and a
     * bar wider than its track is a visible glitch.
     *
     * @param array<string, mixed> $config
     */
    private function progressPercent(array $config): int|null
    {
        if ('running' !== (string) ($config['auto_update_last_status'] ?? '')) {
            return null;
        }

        $phase = (string) ($config['auto_update_progress_phase'] ?? '');

        // The crawl is excluded by name, not merely by having no total written: nothing
        // that lands in its progress columns may ever be read as a fraction of the work.
        if (!\in_array($phase, ['polish', 'upload'], true)) {
            return null;
        }

        $total = (int) ($config['auto_update_progress_total'] ?? 0);

        if ($total <= 0) {
            return null;
        }

        $current = max(0, (int) ($config['auto_update_progress_current'] ?? 0));

        return min(100, (int) floor($current / $total * 100));
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
     * Serve the indexed text of one page (one row of tl_openai_vector_file) as plain text.
     *
     * The uploaded files cannot be read back from OpenAI - the Files API refuses to return
     * the content of purpose=assistants files - so the text comes from our own copy: the
     * newest run manifest of that configuration, which contains a block per page. Blocks are
     * matched by page id and, for manifests written before that line existed, by URL.
     */
    private function pageContent(int $vectorFileId): Response
    {
        $file = $vectorFileId > 0
            ? $this->connection->fetchAssociative(
                'SELECT pid, page_id, url FROM tl_openai_vector_file WHERE id = ?',
                [$vectorFileId],
            )
            : null;

        if (empty($file)) {
            Message::addError($this->translator->trans('MSC.vsau_page_content_missing', [], 'contao_default'));

            return $this->redirectToRoute('vector_store_auto_update');
        }

        $manifest = (string) $this->connection->fetchOne(
            "SELECT document FROM tl_openai_sync_log
             WHERE pid = ? AND document IS NOT NULL AND document <> ''
             ORDER BY run_at DESC, id DESC LIMIT 1",
            [(int) $file['pid']],
        );

        $block = '' !== $manifest
            ? $this->extractPageBlock($manifest, (int) $file['page_id'], (string) $file['url'])
            : null;

        if (null === $block) {
            Message::addError($this->translator->trans('MSC.vsau_page_content_missing', [], 'contao_default'));

            return $this->redirectToRoute('vector_store_auto_update');
        }

        return new Response(
            $block,
            Response::HTTP_OK,
            [
                // Plain text, so the browser shows it instead of downloading it; nosniff keeps
                // any markup inside a page's text from ever being rendered as HTML.
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => 'inline',
            ],
        );
    }

    /**
     * Pull one page's block out of a run manifest. buildManifest() joins the blocks with a
     * "\n\n---\n\n" separator and heads each one with "## title", "URL: …" and (since the
     * page-to-file mapping was added) "Page ID: …".
     */
    private function extractPageBlock(string $manifest, int $pageId, string $url): string|null
    {
        // Drop the summary that heads the manifest. Manifests written before the summary was
        // closed by a blank line carry the first page block glued straight onto their closing
        // "---", where the split below cannot separate it - cutting the summary off first is
        // what makes that first page readable in those (already stored) manifests too.
        if (str_starts_with($manifest, '# ') && false !== ($end = strpos($manifest, "\n---\n"))) {
            $manifest = ltrim(substr($manifest, $end + \strlen("\n---\n")), "\n");
        }

        // Split only where the separator is followed by the next page heading: a page whose
        // own text contains a "---" line must not cut its block short.
        $blocks = preg_split('/\n\n---\n\n(?=## )/', $manifest) ?: [];

        foreach ($blocks as $block) {
            if (!str_starts_with($block, '## ')) {
                // The summary header in front of the first page block.
                continue;
            }

            $head = substr($block, 0, (int) strpos($block."\n\n", "\n\n"));

            // (\D|$) so page 7 never matches the block of page 70.
            $matchesPage = $pageId > 0 && 1 === preg_match('/^Page ID: '.$pageId.'(\D|$)/m', $head);
            // Older manifests carry no page id, and the link-directory document has none at
            // all - the URL line identifies those blocks.
            $matchesUrl = '' !== $url && 1 === preg_match('/^URL: '.preg_quote($url, '/').'\s*$/m', $head);

            if ($matchesPage || $matchesUrl) {
                // The last block of a manifest keeps its trailing separator, because nothing
                // follows it to anchor the split - drop it so the output ends with the text.
                $block = rtrim($block);

                return rtrim(preg_replace('/\n+---$/', '', $block) ?? $block)."\n";
            }
        }

        return null;
    }

    /**
     * How many files this config currently keeps in the vector store. Drives the
     * "Show indexed files" button, which is hidden while nothing is indexed.
     *
     * Fails soft: on an install whose schema update has not run yet the table does not
     * exist, and a missing count must never break the dashboard.
     */
    private function indexedFileCount(int $configId): int
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM tl_openai_vector_file WHERE pid = ?',
                [$configId],
            );
        } catch (\Throwable) {
            return 0;
        }
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
        $maxPages = (int) ($config['premium_license_max_pages'] ?? 0);
        // News, FAQ and event entries have their own allowance, so showing only the page
        // cap here would leave an admin guessing where a "plan limit" warning came from.
        $maxItems = LicenseValidationService::resolveItemLimit($plan);

        if ('enterprise' === $plan) {
            $limit = $this->translator->trans('MSC.vsau_plan_unlimited', [], 'contao_default');
        } elseif ($maxPages > 0 && null !== $maxItems) {
            $limit = $this->translator->trans('MSC.vsau_plan_pages_items', [$maxPages, $maxItems], 'contao_default');
        } elseif ($maxPages > 0) {
            $limit = $this->translator->trans('MSC.vsau_plan_pages', [$maxPages], 'contao_default');
        } else {
            return $name;
        }

        // Dash instead of brackets: the limit string carries its own parenthetical
        // ("… Beiträge (News/FAQ/Events)"), and nesting those reads badly in the chip.
        return $name.' - '.$limit;
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
     * Whether any configuration currently has a queued or running sync.
     *
     * All configurations share a single contao:cron process, so one config's run is
     * what silences the heartbeat for every config on the install.
     *
     * @param array<int, array<string, mixed>> $configs
     */
    private function anyRunInFlight(array $configs): bool
    {
        foreach ($configs as $config) {
            if ($this->runInFlight($config)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether THIS configuration has a queued or running sync.
     *
     * Kept separate from anyRunInFlight() because the two answer different questions:
     * the heartbeat is shared across configurations and any run silences it, while
     * schedule evidence belongs to one configuration alone.
     *
     * @param array<string, mixed> $config
     */
    private function runInFlight(array $config): bool
    {
        return \in_array((string) ($config['auto_update_last_status'] ?? ''), ['queued', 'running'], true);
    }

    /**
     * Warns when the schedule fires so often that the site is close to permanently
     * under crawl, using the measured duration of this install's last run.
     *
     * Every sync spawns "contao:crawl --max-depth=0", i.e. a complete crawl of the
     * whole site — not just the pages in the vector store scope. That is deliberate
     * (a store that mirrors the site must not carry a depth cap), but it means the
     * cost of a short interval is a multiple of what the page count suggests, and
     * nothing in the backend said so. Advisory only: a small site can afford it.
     *
     * @param array<string, mixed> $config
     */
    private function crawlCostNotice(array $config): string|null
    {
        if ('manual' === (string) ($config['auto_update_trigger'] ?? 'scheduled')) {
            return null;
        }

        // Only relevant while every single run really does crawl. Under the default
        // "auto" mode the crawl is skipped whenever the site is unchanged, and under
        // "never" it does not happen at all - warning there would be crying wolf.
        if (VectorStoreAutoUpdateService::CRAWL_ALWAYS !== (string) ($config['auto_update_crawl_mode'] ?? VectorStoreAutoUpdateService::CRAWL_AUTO)) {
            return null;
        }

        $interval = $this->cronHealth->scheduleInterval((string) ($config['auto_update_schedule'] ?? ''));

        if (null === $interval) {
            return null;
        }

        $duration = (int) $this->connection->fetchOne(
            'SELECT duration FROM tl_openai_sync_log WHERE pid = ? AND duration > 0 ORDER BY run_at DESC LIMIT 1',
            [(int) $config['id']],
        );

        if ($duration > 0) {
            // Warn once a run occupies more than about a sixth of its own interval.
            // At that point the crawl is a standing load rather than an occasional
            // one, and the next run starts while the effects of the last are fresh.
            if (6 * $duration <= $interval) {
                return null;
            }

            return $this->translator->trans(
                'MSC.vsau_notice_crawl_cost_measured',
                [$this->formatInterval($duration), $this->formatInterval($interval)],
                'contao_default',
            );
        }

        // Nothing measured yet: only flag intervals that are heavy for any site.
        if ($interval >= 86400) {
            return null;
        }

        return $this->translator->trans(
            'MSC.vsau_notice_crawl_cost_generic',
            [$this->formatInterval($interval)],
            'contao_default',
        );
    }

    /**
     * A duration in seconds as "45 Sek." / "14 Min." / "2 Std." for use inside a
     * sentence. Deliberately coarse — this is an order-of-magnitude argument.
     */
    private function formatInterval(int $seconds): string
    {
        $key = match (true) {
            $seconds < 60 => 'MSC.vsau_interval_seconds',
            $seconds < 3600 => 'MSC.vsau_interval_minutes',
            default => 'MSC.vsau_interval_hours',
        };

        $value = match (true) {
            $seconds < 60 => $seconds,
            $seconds < 3600 => (int) round($seconds / 60),
            default => (int) round($seconds / 3600),
        };

        return $this->translator->trans($key, [$value], 'contao_default');
    }

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
        // Ask the sync itself instead of repeating its query here. A duplicated predicate
        // drifted once already: this check counted every published root, while the sync
        // also honours the start/stop window - so a root left behind by a theme import
        // and scheduled out of its window blocked the button on an installation the sync
        // resolves perfectly well, and a single root outside its window passed the check
        // and then failed the run. One definition of "live root", one place.
        $liveRoots = $this->service->liveRootPageCount();

        if (!$hasStartPage && 1 !== $liveRoots) {
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

        // The sync crawls from a CLI process, which has no request to take the host from.
        // Without a domain on the site root, Contao's URL generator falls back to the
        // router default and the crawl ends up at "https://localhost/..." - it then
        // reaches nothing while still exiting successfully.
        //
        // Only ever a notice: an installation can set framework.router.default_uri
        // instead of a page domain, and on a local install "localhost" is correct. The
        // prerequisiteWarnings() counterpart already blocks the case where no pages are
        // selected AND no single domain root exists, so this covers the remaining one -
        // pages selected, but their roots carry no domain.
        if ($hasStartPage && [] === $this->service->resolveScopeRootDomains($config['auto_update_site_root'] ?? null)) {
            $notices[] = $this->translator->trans('MSC.vsau_notice_no_root_domain', [], 'contao_default');
        }

        if (null !== $crawlCost = $this->crawlCostNotice($config)) {
            $notices[] = $crawlCost;
        }

        // Standing reminder while the item allowance is exceeded. The run message alone
        // makes one item over budget look exactly like a thousand: it scrolls away with
        // the run history, and nothing on the dashboard says how far over the site is.
        // Nothing is ever dropped, so this stays a notice rather than a warning.
        $itemLimit = LicenseValidationService::resolveItemLimit((string) ($config['premium_license_plan'] ?? ''));

        if (null !== $itemLimit && !empty($config['license_active'])) {
            $items = $this->service->countScopeBreakdown($config['auto_update_site_root'] ?? null)['items'];

            if ($items > $itemLimit) {
                $notices[] = $this->translator->trans(
                    'MSC.vsau_notice_item_limit_exceeded',
                    [$items, $itemLimit],
                    'contao_default',
                );
            }
        }

        return $notices;
    }
}
