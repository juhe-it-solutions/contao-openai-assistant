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

use Contao\CoreBundle\Crawl\Escargot\Factory as EscargotFactory;
use Contao\CoreBundle\Util\ProcessUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Orchestrates an automatic vector store update:
 *   crawl -> read search index -> LLM summary -> replace file in vector store.
 *
 * run() is invoked by the cron job and by the contao:openai-vector-sync command. The
 * backend manual trigger calls dispatchRun() (non-blocking CLI dispatch) only, never
 * run() inline - the crawl + LLM call can take minutes (constraint C4).
 */
class VectorStoreAutoUpdateService
{
    /**
     * What triggered a sync - persisted to tl_openai_sync_log.trigger_source.
     */
    public const SOURCE_CRON = 'cron'; // automatic, via the schedule/heartbeat

    public const SOURCE_MANUAL = 'manual'; // backend "Run sync now" button

    public const SOURCE_CLI = 'cli'; // operator-run console command

    public const SOURCES = [self::SOURCE_CRON, self::SOURCE_MANUAL, self::SOURCE_CLI];

    /**
     * Sync modes (tl_openai_config.auto_update_mode).
     */
    public const MODE_FAITHFUL = 'faithful'; // upload cleaned page text as-is (default, no LLM)

    public const MODE_LLM_POLISH = 'llm_polish'; // per-page LLM rewrite before upload (premium)

    /**
     * Lease window: a "running"/"queued" run is considered alive only while its
     * auto_update_last_run is younger than this. A live run keeps refreshing that
     * timestamp (see heartbeat()), so a long but healthy sync is never mistaken for a
     * crashed one; if the timestamp goes this stale the run is assumed dead and a new one
     * may take over. Must comfortably exceed HEARTBEAT_INTERVAL plus the slowest single
     * page (upload + retries + ingest). Shared with the cron and the manual dispatch guard.
     */
    public const STALE_RUN_SECONDS = 900;

    /**
     * Shorter lease for the "queued" state, used by reconcileStaleRuns() only. A queued
     * run never heartbeats — dispatchRun() writes auto_update_last_run exactly once and
     * the spawned process flips to "running" within seconds. If it is still "queued"
     * after this window, the process died on startup; waiting the full STALE_RUN_SECONDS
     * would keep the dashboard button disabled for no reason. Worst case (a host that
     * takes longer than this to boot the CLI process): a transient spurious error row
     * that the late-starting run overwrites, since acquireRunLock() still succeeds on
     * an "error" status.
     */
    public const STALE_QUEUED_SECONDS = 180;

    /**
     * Crawl the site before reading the index only when something changed ('auto', the
     * default), on every run ('always', the pre-2.2 behaviour), or never ('never', for
     * installs that run their own contao:crawl).
     */
    public const CRAWL_AUTO = 'auto';

    public const CRAWL_ALWAYS = 'always';

    public const CRAWL_NEVER = 'never';

    /**
     * The longest a run may go without a full crawl in 'auto' mode.
     *
     * The change signature cannot see everything: a page, element or news item with
     * start/stop dates changes what it publishes without any row being written, and an
     * insert tag can render differently on its own. This bound means such a change is
     * picked up within six hours whatever the signature says, so the gate can never
     * hold a stale knowledge base indefinitely.
     */
    public const CRAWL_MAX_AGE_SECONDS = 21600;

    private const OPENAI_BASE = 'https://api.openai.com/v1';

    /**
     * Hard cap on pages crawled per sync to limit DB load and LLM cost abuse.
     */
    private const MAX_CRAWL_PAGES = 5000;

    /**
     * How often a live run refreshes its lease (auto_update_last_run). Throttled so the
     * heartbeat does not write to the DB on every page; must be well below STALE_RUN_SECONDS.
     */
    private const HEARTBEAT_INTERVAL = 60;

    /**
     * How often live progress (auto_update_progress_*) may be written, in seconds.
     * Unchanged pages iterate without any HTTP call, so an unthrottled write per page
     * could hammer the DB; a phase change always writes regardless.
     */
    private const PROGRESS_INTERVAL = 1;

    /**
     * How often the crawl counts the pages it has indexed so far, in seconds. Matched to
     * the dashboard's poll interval: counting more often is work nobody ever sees, and
     * the count is a table scan rather than a single-row read.
     */
    private const CRAWL_PROGRESS_INTERVAL = 5;

    /**
     * How many sync-log rows to keep per configuration. Each row can hold a multi-MB
     * inspection manifest, so unbounded history would bloat the database; the dashboard
     * only ever displays the newest 20. 30 ≈ one month of daily syncs. Pruned after every
     * inserted log row (successful runs and stale-run bookkeeping alike).
     */
    private const SYNC_LOG_KEEP_ROWS = 30;

    /**
     * Clears the live progress columns; appended to run-state UPDATEs when a run
     * starts or reaches a terminal state, so the dashboard never shows a stale counter.
     */
    private const PROGRESS_RESET_SQL = "auto_update_progress_phase = '', auto_update_progress_current = 0, auto_update_progress_total = 0";

    /**
     * Every tl_openai_config column the run-state UPDATEs write, and therefore every
     * column contao:migrate must have created before a sync may start. Reads elsewhere
     * tolerate a missing column (SELECT * plus a null coalesce); writes cannot.
     */
    private const RUN_STATE_COLUMNS = [
        'auto_update_progress_phase',
        'auto_update_progress_current',
        'auto_update_progress_total',
        'auto_update_run_started',
        'auto_update_last_crawl',
        'auto_update_crawl_signature',
    ];

    /**
     * Tables whose contents can end up in Contao's search index, and therefore in the
     * knowledge base. Deliberately site-wide rather than scoped to the selected pages:
     * the sync's crawl is also what keeps Contao's OWN site search current on most
     * installs, and narrowing it would silently degrade that.
     *
     * Optional bundles are simply absent - existence is checked before each is read.
     */
    private const CONTENT_TABLES = [
        'tl_page',
        'tl_content',
        'tl_article',
        'tl_news',
        'tl_faq',
        'tl_calendar_events',
    ];

    /**
     * Every tl_openai_sync_log column written by this version that did not exist in an
     * earlier one. Checked by the same gate as the run-state columns, because the row is
     * inserted at the very END of a run: without this, an install whose schema update was
     * only partially applied (the Contao Manager lets the operator run a subset of the
     * statements) passes the gate, crawls the whole site, pays the full rewrite bill,
     * uploads every file - and only then fails on an unknown column, reporting the run as
     * an error and repeating the same spend on the next schedule.
     */
    private const SYNC_LOG_COLUMNS = [
        'items',
    ];

    /**
     * Crawler summary of the current run ("Indexed N URI(s)...", broken-link notices).
     * Quoted in the "nothing was indexed" errors, where it is the single most useful
     * piece of information and otherwise only reachable from the log.
     */
    private string $lastCrawlSummary = '';

    /**
     * Unix time of the last lease refresh in the current run; reset by markRunning().
     */
    private int $lastHeartbeatAt = 0;

    /**
     * Unix time of the last progress write in the current run.
     */
    private int $lastProgressAt = 0;

    /**
     * Phase written by the last progress() call, used to force a write on phase change.
     */
    private string $lastProgressPhase = '';

    public function __construct(
        private readonly Connection $connection,
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly EncryptionService $encryption,
        private readonly ProcessUtil $processUtil,
        private readonly LicenseValidationService $licenseValidation,
        private readonly BoilerplateFilter $boilerplate,
        private readonly VectorStoreFileSync $fileSync,
        private readonly PageLinkRepository $pageLinks,
        private readonly PageProtectionResolver $pageProtection,
        private readonly PageLinkFilter $linkFilter,
        private readonly LinkSectionBuilder $linkSection,
        private readonly LinkIndexDocumentBuilder $linkIndex,
        private readonly ReaderItemCounter $readerItems,
        private readonly EscargotFactory $escargotFactory,
    ) {
    }

    /**
     * Dispatch a sync to the CLI (non-blocking). Used by the backend manual trigger only.
     */
    public function dispatchRun(int $configId): void
    {
        if ($configId <= 0) {
            throw new \InvalidArgumentException('Invalid configuration ID.');
        }

        // Before anything else, for the same reason run() checks it first: the UPDATE
        // below writes the run-state columns, so on a code update without contao:migrate
        // the button would answer with a raw SQL error about an unknown column.
        if (!$this->isSchemaCurrent()) {
            throw new \RuntimeException('MSC.vsau_err_schema_outdated');
        }

        if (!$this->licenseValidation->isLicenseActive($configId)) {
            throw new \RuntimeException('MSC.vsau_err_no_license');
        }

        $now = time();
        $queued = $this->connection->executeStatement(
            "UPDATE tl_openai_config
                SET auto_update_last_run = ?, auto_update_run_started = ?, auto_update_last_status = 'queued', auto_update_last_message = ?
                WHERE id = ?
                    AND auto_update_enabled = '1'
                    AND (
                        COALESCE(auto_update_last_status, '') NOT IN ('running', 'queued')
                        OR COALESCE(auto_update_last_run, 0) < ?
                    )",
            [$now, $now, 'MSC.vsau_dispatched_manual', $configId, $now - self::STALE_RUN_SECONDS],
        );

        if (0 === $queued) {
            $config = $this->connection->fetchAssociative(
                "SELECT auto_update_last_status, auto_update_last_run FROM tl_openai_config WHERE id = ? AND auto_update_enabled = '1'",
                [$configId],
            );

            if (!$config) {
                throw new \RuntimeException('MSC.vsau_err_sync_not_enabled');
            }

            throw new \RuntimeException('MSC.vsau_err_sync_already_running');
        }

        try {
            // Fire-and-forget requires a plain Process, NOT ProcessUtil's PhpSubprocess:
            // that one runs the child via a temporary php.ini which the PARENT deletes in
            // a shutdown function — the web request ends right after this dispatch, so a
            // child that has not booted yet would start with "-n" and no ini at all
            // (no DB extension → instant crash, status stuck on "queued").
            $process = new Process([
                $this->processUtil->getPhpBinary(),
                $this->processUtil->getConsolePath(),
                'contao:openai-vector-sync',
                (string) $configId,
                '--source='.self::SOURCE_MANUAL,
                '--no-interaction',
            ]);

            // Detach the child from this request: Process::__destruct() calls stop(0)
            // (SIGKILL) unless create_new_console is set — and the destructor runs at
            // request shutdown, killing the just-started sync. With output disabled,
            // stdout/stderr point to /dev/null, so closing the remaining stdin pipe on
            // destruct cannot hurt the child. Identical behavior on Symfony 6.4 (Contao
            // 5.3) and 7.x (Contao 5.7).
            $process->disableOutput();
            $process->setOptions(['create_new_console' => true]);
            $process->setTimeout(null);
            $process->start(); // non-blocking - do NOT call wait()
        } catch (\Throwable $e) {
            // Spawning a CLI process can fail on locked-down hosts (proc_open disabled) - the
            // very hosts likely to use manual mode. Reset the status so the button is not stuck
            // on "queued", and surface a clear error pointing to the CLI fallback.
            $this->connection->executeStatement(
                "UPDATE tl_openai_config SET auto_update_last_status = 'error', auto_update_last_message = ? WHERE id = ?",
                ['MSC.vsau_err_dispatch_failed', $configId],
            );
            $this->logger->error('Manual sync dispatch failed for config '.$configId.': '.$e->getMessage());

            throw new \RuntimeException('MSC.vsau_err_dispatch_failed');
        }
    }

    /**
     * Persist dead runs as errors. A "queued"/"running" status whose lease
     * (auto_update_last_run) has gone stale means the process died without ever
     * reporting back — e.g. it was killed, crashed on startup, or the CLI dispatch
     * silently failed. Without this, the dashboard badge stays "queued" and the
     * manual-sync button stays disabled forever (there is no cron takeover in
     * manual trigger mode). Called by the dashboard on render.
     *
     * A healthy long run is never affected: it refreshes its lease every
     * HEARTBEAT_INTERVAL seconds, so its timestamp is always fresh. The guarded
     * UPDATE re-checks the stale predicate, so a run that finished (or
     * heartbeated) between SELECT and UPDATE is left untouched.
     */
    public function reconcileStaleRuns(): void
    {
        $now = time();
        // "running" heartbeats every HEARTBEAT_INTERVAL, so only a long gap means dead;
        // "queued" never heartbeats, so a much shorter silence is already conclusive.
        $staleRunning = $now - self::STALE_RUN_SECONDS;
        $staleQueued = $now - self::STALE_QUEUED_SECONDS;

        $stalePredicate = "(
            (auto_update_last_status = 'running' AND COALESCE(auto_update_last_run, 0) < ?)
            OR (auto_update_last_status = 'queued' AND COALESCE(auto_update_last_run, 0) < ?)
        )";

        $stale = $this->connection->fetchAllAssociative(
            'SELECT id, auto_update_last_run FROM tl_openai_config WHERE '.$stalePredicate,
            [$staleRunning, $staleQueued],
        );

        foreach ($stale as $row) {
            // No progress reset needed here: stale progress is only displayed while the
            // status is 'running', and the next acquireRunLock() clears it anyway.
            $updated = $this->connection->executeStatement(
                "UPDATE tl_openai_config
                    SET auto_update_last_status = 'error', auto_update_last_message = ?
                    WHERE id = ? AND ".$stalePredicate,
                ['MSC.vsau_err_run_stale', (int) $row['id'], $staleRunning, $staleQueued],
            );

            if (0 === $updated) {
                continue;
            }

            // Log the dead run so the history shows why it vanished instead of a gap.
            // run_at = the last heartbeat, i.e. the last moment the run showed life.
            $this->connection->insert('tl_openai_sync_log', [
                'pid' => (int) $row['id'],
                'tstamp' => $now,
                'run_at' => (int) $row['auto_update_last_run'],
                'status' => 'error',
                'trigger_source' => '',
                'message' => 'MSC.vsau_err_run_stale',
            ]);

            $this->pruneSyncLog((int) $row['id']);
        }
    }

    /**
     * Full sync flow for a single configuration record. Never throws - failures are
     * persisted as an "error" status + message in tl_openai_config / tl_openai_sync_log.
     */
    public function run(int $configId, string $triggerSource = self::SOURCE_CLI): string
    {
        if (!\in_array($triggerSource, self::SOURCES, true)) {
            $triggerSource = self::SOURCE_CLI;
        }

        // Per-run state, and the cron walks every enabled configuration in one process:
        // without this reset a config that skipped its own crawl inherits the previous
        // config's summary and shows it inside its own "no indexed pages" error - a wrong
        // explanation in the one place an operator goes looking for the right one.
        $this->lastCrawlSummary = '';

        // Guard against running before contao:migrate has created the extension tables
        // (e.g. CLI command invoked on a fresh install before the install wizard finishes)
        // or before it has added the run-state columns after a bundle update — the
        // UPDATEs below reference them, and run() must never throw.
        if (!$this->isSchemaCurrent()) {
            $this->logger->notice('VectorStoreAutoUpdate skipped for config '.$configId.': database schema not up to date (run contao:migrate).');

            return 'skipped';
        }

        $start = time();
        $model = '';

        try {
            if (!$this->acquireRunLock($configId, $triggerSource)) {
                $this->logger->notice('VectorStoreAutoUpdate skipped for config '.$configId.': another sync is already running or queued.');

                return 'skipped';
            }

            // License gate — write a skipped log entry so the run-history table shows why
            // syncs stopped, rather than leaving an unexplained gap (UX-10).
            if (!$this->licenseValidation->isLicenseActive($configId)) {
                $this->logger->notice('VectorStoreAutoUpdate skipped for config '.$configId.': no active premium license.');
                $this->connection->insert('tl_openai_sync_log', [
                    'pid' => $configId,
                    'tstamp' => $start,
                    'run_at' => $start,
                    'status' => 'skipped',
                    'trigger_source' => $triggerSource,
                    'message' => 'MSC.vsau_sync_skipped_license',
                ]);
                // Prune here too. This path returns before the terminal persistResult(),
                // so without it an enabled schedule with a lapsed license appends a row
                // per cron tick forever and the documented 30-row cap silently stops
                // holding - on exactly the configuration nobody is watching any more.
                $this->pruneSyncLog($configId);
                // Clear the 'queued' status written by dispatchRun() so the dashboard
                // "Run sync now" button becomes re-clickable immediately (REV-02).
                $this->connection->executeStatement(
                    "UPDATE tl_openai_config SET auto_update_last_status = 'skipped', auto_update_last_run = ? WHERE id = ?",
                    [$start, $configId],
                );

                return 'skipped';
            }

            $config = $this->connection->fetchAssociative('SELECT * FROM tl_openai_config WHERE id = ?', [$configId]);
            if (!$config) {
                throw new \RuntimeException('MSC.vsau_err_config_not_found|'.$configId);
            }

            $apiKey = $this->encryption->getApiKeyForConfig($configId);
            if (!$apiKey) {
                throw new \RuntimeException('MSC.vsau_err_no_api_key|'.$configId);
            }

            $vectorStoreId = (string) ($config['vector_store_id'] ?? '');
            if ('' === $vectorStoreId) {
                throw new \RuntimeException('MSC.vsau_err_no_vector_store_sync');
            }

            $mode = $this->resolveMode($config);
            $model = self::MODE_LLM_POLISH === $mode ? ((string) ($config['auto_update_model'] ?? '') ?: 'gpt-4o-mini') : '';
            $promptTpl = self::decodeStoredText($config['auto_update_prompt_template'] ?? null) ?: null;
            $legacyFileId = (string) ($config['auto_update_file_id'] ?? '');

            $signature = $this->siteContentSignature();
            $crawlSkipped = false;

            if ($this->shouldCrawl($config, $signature)) {
                // Announced only once the decision is made. Setting it beforehand made the
                // dashboard claim "Website wird gecrawlt" through a run that then skipped
                // the crawl entirely - the common case on a quiet site in "auto" mode.
                // Crawling has no page total yet — phase-only progress ("crawling…").
                $this->progress($configId, 'crawl', 0, 0);
                $this->spawnCrawl($configId);
                // Recorded AFTER the crawl, and only if it did not throw: a crawl that
                // failed must not satisfy the next run's freshness check. The signature is
                // the one taken BEFORE the crawl on purpose - an edit made while the crawl
                // was running may not have been seen by it, so it has to still count as
                // pending change for the next run.
                $this->connection->executeStatement(
                    'UPDATE tl_openai_config SET auto_update_last_crawl = ?, auto_update_crawl_signature = ? WHERE id = ?',
                    [time(), $signature, $configId],
                );
            } else {
                $crawlSkipped = true;
                $this->logger->info(\sprintf(
                    'VectorStoreAutoUpdate: skipped the crawl for config %d - the website is unchanged since the last one.',
                    $configId,
                ));
            }

            // Everything from here to the first AI/upload step reads and prepares the
            // indexed pages, which on a large site is not instantaneous. Naming that phase
            // keeps the dashboard honest in BOTH paths: it used to keep saying "crawling"
            // after a real crawl had already finished, too. No total exists - the page set
            // is not known until readAllPages() returns - so the bar stays indeterminate,
            // which progressPercent() enforces by only ever measuring polish and upload.
            $this->progress($configId, 'read', 0, 0);

            // Drop collected links whose source document has vanished from the
            // search index (page deleted, 404, excluded from indexing). Runs after
            // the crawl so it sees the fresh index, and never fails the run.
            $this->pageLinks->pruneOrphans();

            // Plan-based page cap: enforce the subscription limit at runtime so a
            // downgrade immediately shrinks the sync scope without requiring the admin to
            // re-save their site-root selection (BUG-06). Resolved through the same
            // helper as the save-time enforcement, so a missing max_crawl_pages value
            // falls back to the plan default instead of silently meaning "unlimited".
            // The cap itself is applied below, on the actual content pages, not on the
            // raw page-id list — so quota is spent on real indexed documents.
            $planPageLimit = LicenseValidationService::resolvePageLimit(
                (string) ($config['premium_license_plan'] ?? ''),
                (int) ($config['premium_license_max_pages'] ?? 0),
            ) ?? 0;

            $rows = $this->readAllPages($configId);

            // Remove what tl_page proves is gone, BEFORE the empty-index check below can
            // abort the run.
            //
            // The two questions are separated by authority on purpose. "tl_search returned
            // nothing" is not authoritative - it is far more often a failed crawl than a
            // genuinely empty site, and deleting on that signal would wipe a healthy store.
            // So that check keeps aborting. But a page the page tree says is deleted,
            // unpublished or protected is authoritative regardless of what the crawl did,
            // and its document has to go even when the run is about to abort - otherwise the
            // one case the upgrade guide promises to handle (a page turned member-only) is
            // exactly the case where nothing happens.
            $authoritativeRemoval = $this->removeAuthoritativelyGonePages($apiKey, $vectorStoreId, $configId, $config);

            if (0 === \count($rows)) {
                // With an explicit selection, "tl_search is empty" would often be false -
                // the index may have rows, just none for the picked pages. Report the
                // selection-scoped cause (usually a missing root domain name) instead.
                $hasSelection = [] !== self::parseConfiguredPageIds($config['auto_update_site_root'] ?? null);
                $reason = $hasSelection ? 'MSC.vsau_err_selected_not_indexed' : 'MSC.vsau_err_no_indexed_pages';

                // Append what the crawler itself reported. "Indexed 0 URI(s), 1 skipped"
                // is the decisive clue for the usual causes (a domain that does not match
                // the address the site really runs on, robots.txt, noindex), and without
                // this it is only visible to someone reading the log.
                if ('' !== $this->lastCrawlSummary) {
                    $reason .= VectorStoreSyncMessageTranslator::COMPOUND_SEPARATOR.'MSC.vsau_crawl_result|'.$this->lastCrawlSummary;
                }

                // On a site whose crawl keeps failing this abort is EVERY run, so without
                // this the deletion debt from earlier runs would never be worked off.
                // The key and store id are guaranteed non-empty by the guards above.
                $this->fileSync->retryPendingDeletions($apiKey, $vectorStoreId, $configId);

                throw new \RuntimeException($reason);
            }

            // Safe boilerplate removal: only strips text repeated across many pages.
            $texts = [];

            // How many URLs each page has in the freshly refreshed search index. Counted
            // from the raw rows (before boilerplate cleaning drops chrome-only ones), so
            // the reader-item cap below measures what Contao really indexed.
            $indexedRows = [];

            foreach ($rows as $i => $row) {
                $texts[$i] = self::decodeBasicEntities((string) $row['text']);
                $pageId = (int) $row['page_id'];
                $indexedRows[$pageId] = ($indexedRows[$pageId] ?? 0) + 1;
            }
            $clean = $this->boilerplate->clean($texts);

            // Aggregate by page id: a page can be indexed under several URLs (e.g. paginated
            // readers), producing multiple tl_search rows. We want exactly one document per
            // page, so the cleaned text of all its rows is concatenated.
            $byPage = $this->aggregateByPage($rows, $clean['texts']);

            // Enforce the subscription page cap on the actual content pages (after
            // boilerplate cleaning, so pages that collapsed to nothing are already gone).
            // Deterministic by page id so the surviving set is stable from run to run;
            // pages beyond the cap are dropped here AND their vector-store files are
            // removed by the reconcile below, so the run is reported "partial" with the
            // skipped count rather than a silent, arbitrary "success".
            // Counted once, before the cap, so the items lost with a dropped page can be
            // named in the result message: "1 page not synced" badly understates the loss
            // when that page was a reader page carrying three hundred news entries.
            $itemCounts = $this->readerItems->countByPage(array_keys($byPage), $indexedRows);

            $capped = $this->applyPlanPageLimit($byPage, $planPageLimit);
            $planLimitSkipped = $capped['skipped'];
            $byPage = $capped['pages'];

            $droppedItems = 0;

            foreach ($capped['dropped'] as $pageId) {
                $droppedItems += $itemCounts[$pageId] ?? 0;
            }

            if ($planLimitSkipped > 0) {
                $this->logger->notice(\sprintf(
                    'VectorStoreAutoUpdate: plan page limit %d applied for config %d; %d content page(s) and %d reader item(s) skipped.',
                    $planPageLimit,
                    $configId,
                    $planLimitSkipped,
                    $droppedItems,
                ));
            }

            // News/FAQ/event items have their own budget. Exceeding it never removes
            // content - it only marks the run "partial" and asks for an upgrade, so a
            // customer who publishes one item too many does not lose the whole news
            // section from the chatbot.
            $planItemLimit = LicenseValidationService::resolveItemLimit((string) ($config['premium_license_plan'] ?? '')) ?? 0;
            $itemsInScope = array_sum(array_intersect_key($itemCounts, $byPage));
            $itemBudgetExceeded = $planItemLimit > 0 && $itemsInScope > $planItemLimit;

            if ($itemBudgetExceeded) {
                $this->logger->notice(\sprintf(
                    'VectorStoreAutoUpdate: plan item limit %d exceeded for config %d; %d item(s) present, nothing was dropped.',
                    $planItemLimit,
                    $configId,
                    $itemsInScope,
                ));
            }

            // Links of the pages in scope. They were collected while Contao indexed
            // each page (SearchIndexLinkListener), so this is a read-only step: no
            // crawling, no network, no page parsing here.
            $linksEnabled = (bool) ($config['auto_update_include_links'] ?? false);
            $linkStats = ['total' => 0, 'dropped_policy' => 0, 'dropped_boilerplate' => 0];
            $linksByPage = $linksEnabled ? $this->collectLinks($config, array_keys($byPage), $linkStats) : [];

            $tokensIn = 0;
            $tokensOut = 0;
            $pages = [];
            $polishTotal = \count($byPage);
            $polishDone = 0;

            // Announce the phase up front ("0 of N") so the dashboard switches away from
            // "crawling" before the first — possibly slow — LLM call completes.
            if (self::MODE_LLM_POLISH === $mode && $polishTotal > 0) {
                $this->progress($configId, 'polish', 0, $polishTotal);
            }

            // Identifies the rewrite parameters, so a changed model or prompt invalidates
            // every cached document at once. Computed here rather than per page: it is the
            // same for the whole run.
            $polishFingerprint = self::MODE_LLM_POLISH === $mode
                ? hash('sha256', $model."\0".($promptTpl ?? VectorStoreDocumentPrompt::DEFAULT_TEMPLATE))
                : '';

            foreach ($byPage as $page) {
                $content = implode("\n\n", $page['contents']);

                if (self::MODE_LLM_POLISH === $mode) {
                    // Hash the text that is about to be sent, not the page's search-index
                    // checksums: BoilerplateFilter decides what counts as chrome from how
                    // often a block occurs across the pages in scope, so this text can
                    // change when a DIFFERENT page joins or leaves the selection, with this
                    // page's own checksums untouched. Hashing the input covers that and
                    // every other cause at once.
                    $sourceHash = hash('sha256', $content);
                    $cached = $this->cachedPolish($configId, (int) $page['page_id'], $sourceHash, $polishFingerprint);

                    if (null !== $cached) {
                        // Nothing that could change the rewrite has changed, so re-running it
                        // would only spend tokens to reproduce this text - and any drift in
                        // the reproduction would re-upload an unchanged page.
                        $content = $cached;
                    } else {
                        $polished = $this->polishPage($apiKey, $model, $page['title'], $page['url'], $content, $promptTpl);
                        $tokensIn += $polished['tokens_in'];
                        $tokensOut += $polished['tokens_out'];

                        // Never drop a page: fall back to the faithful text if the LLM returns
                        // nothing (an error, or a response truncated at the output limit).
                        if ('' !== trim($polished['text'])) {
                            $content = $polished['text'];
                            $this->storePolish($configId, (int) $page['page_id'], $sourceHash, $polishFingerprint, $content);
                        }
                    }

                    // Progress doubles as the lease refresh here (it writes the heartbeat too).
                    ++$polishDone;
                    $this->progress($configId, 'polish', $polishDone, $polishTotal);
                }

                // Appended AFTER the optional LLM rewrite on purpose: the model
                // never sees a URL, so it cannot truncate, reword or invent one.
                // The block is byte-deterministic, so it costs no tokens and keeps
                // the incremental content hash meaningful - a page is re-uploaded
                // exactly when its links (or its text) really changed.
                $content = $this->appendLinkSection($content, $page, $linksByPage, $linksEnabled);

                $pages[] = [
                    'page_id' => $page['page_id'],
                    'url' => $page['url'],
                    'title' => $page['title'],
                    'language' => $page['language'],
                    'content' => $content,
                    // Hash of the contributing row checksums - changes if any row changes.
                    'search_checksum' => substr(md5(implode(',', $page['checksums'])), 0, 32),
                ];
            }

            if (0 === \count($pages)) {
                throw new \RuntimeException('MSC.vsau_err_empty_document_raw');
            }

            // Forget rewrites of pages that have left this configuration's scope. Done
            // here, where the processed set is known and complete, and in EVERY mode: a
            // site switched to "Originalgetreu" would otherwise keep the cached text of
            // every page it later drops, with nothing to clean it up.
            $this->prunePolishCache($configId, array_map(static fn (array $p): int => (int) $p['page_id'], $pages));

            // Number of real content pages, captured BEFORE the optional directory
            // document is appended: it is what the sync log, the dashboard and the
            // plan-limit wording refer to, and it must not be inflated by a
            // synthetic document.
            $contentPageCount = \count($pages);

            // Site-wide directory of documents and pages, uploaded as one extra
            // file with page_id = 0. Built AFTER the plan cap was applied to
            // $byPage, so it never consumes a page of the customer's quota. When
            // the option is switched off the entry simply disappears from $pages
            // and VectorStoreFileSync removes its file like any other page that
            // dropped out of scope.
            $pages = $this->appendLinkIndexDocument(
                $pages,
                $linksByPage,
                $linksEnabled && (bool) ($config['auto_update_link_index'] ?? false),
            );

            $syncStats = $this->fileSync->sync(
                $apiKey,
                $vectorStoreId,
                $configId,
                $pages,
                $legacyFileId,
                function (int $done, int $total) use ($configId): void {
                    // Live "X of Y pages" for the dashboard; also refreshes the run lease.
                    $this->progress($configId, 'upload', $done, $total);
                },
            );

            // Pages removed by the authoritative pass above never enter sync()'s own scope
            // reconciliation - it works from what tl_search returned, and these pages are
            // precisely the ones that are no longer there. Without this they would vanish
            // from the store while the run reported "removed: 0".
            //
            // Only "removed" is added. Files the authoritative pass could NOT delete are
            // already rows in pending_delete status by the time sync() starts, and sync()'s
            // retry pass counts every one of those - adding them here as well would report a
            // single stuck file twice.
            $syncStats['removed'] += $authoritativeRemoval['removed'];

            // Per-page outcome + file ids: manifest material only, never persisted as sync
            // counters, so it is split off before the stats array reaches persistResult().
            $pageStates = $syncStats['page_states'];
            unset($syncStats['page_states']);

            [$status, $resultMessage] = $this->summariseRun([
                'files_failed' => $syncStats['files_failed'],
                'pages_skipped' => $planLimitSkipped,
                'page_limit' => $planPageLimit,
                'dropped_items' => $droppedItems,
                'items_in_scope' => $itemsInScope,
                'item_limit' => $itemBudgetExceeded ? $planItemLimit : 0,
                'files_uploaded' => $syncStats['files_uploaded'],
                'removed' => $syncStats['removed'],
                'deletes_pending' => $syncStats['deletes_pending'],
            ]);

            $this->persistResult(
                $configId,
                $status,
                // Per-page mode has no single file id, so '' clears the legacy one - but only
                // once that legacy bulk file is provably gone from the vector store. Clearing
                // it after an unconfirmed deletion would strand the file: still attached,
                // still answering, and no longer referenced by anything that could retry.
                $syncStats['legacy_file_removed'] ? '' : null,
                [
                    'pages' => $contentPageCount,
                    'items' => $itemsInScope,
                    'tokens_in' => $tokensIn,
                    'tokens_out' => $tokensOut,
                    'duration' => time() - $start,
                    'model' => $model,
                    'document' => $this->buildManifest($pages, $syncStats, $pageStates, $linksEnabled ? $linkStats : null, $crawlSkipped),
                    'sync' => $syncStats,
                ],
                $resultMessage,
                $triggerSource,
            );

            return $status;
        } catch (\Throwable $e) {
            $this->logger->error('VectorStoreAutoUpdate failed for config '.$configId.': '.$e->getMessage());
            $this->persistResult(
                $configId,
                'error',
                // null = keep auto_update_file_id untouched: a run that failed before the
                // file sync must not discard the legacy bulk-file id, or the old file
                // could never be cleaned from the vector store by a later successful run.
                null,
                [
                    'duration' => time() - $start,
                    'model' => $model,
                ],
                $e->getMessage(),
                $triggerSource,
            );

            return 'error';
        }
    }

    /**
     * Count the tl_page rows in scope for a given page selection, used by the
     * backend to enforce the subscription page limit before saving.
     *
     * Explicitly selected pages are counted exactly (no subpages implied). An empty
     * selection resolves to the whole website (single site root + subtree) when
     * exactly one root exists, else returns 0.
     */
    /**
     * Size of the configured sync scope, in the two units the subscription budgets
     * separately: content pages, and the news/FAQ/event items rendered on them.
     *
     * They are kept apart rather than added up because they behave differently. A
     * page is added deliberately and rarely; items accumulate on their own as an
     * editor keeps publishing. A single combined number would also read like a bug
     * in the back end - "301" next to a page tree showing one page.
     *
     * @return array{pages: int, items: int}
     */
    public function countScopeBreakdown(mixed $configValue): array
    {
        $pageIds = $this->contentPageIds($this->resolveScopePageIds($configValue));

        return [
            'pages' => \count($pageIds),
            'items' => array_sum($this->readerItems->countByPage($pageIds)),
        ];
    }

    /**
     * Final status of a run plus the message explaining it.
     *
     * A run is "partial" when some files failed to upload, the plan cap dropped pages
     * the customer expected to be synced, or the item budget is exceeded. The first two
     * mean the store is not a complete mirror of the selected scope; the third does not
     * remove anything, but the customer still has to act, so it must not be reported as
     * a plain "success" either.
     *
     * EVERY reason is surfaced, not just the first: a run can hit the plan cap AND have
     * upload failures at once. Each note is a keyed message the translator expands, and
     * they are joined with the translator's compound separator so the dashboard and the
     * log show one line per reason.
     *
     * A run where nothing went wrong AND nothing changed gets an informational message
     * instead of an empty one. That state is otherwise indistinguishable from a broken
     * run in the backend tables: no message, no uploads, a duration of 00:00. It is
     * produced AFTER the problem notes and never joins them, because the status is
     * derived from that array - an informational note in there would report a perfectly
     * healthy no-op as "partial".
     *
     * Public so the status/message combinations can be tested without a database, an
     * HTTP client and a crawl subprocess.
     *
     * @param array{files_failed: int, pages_skipped: int, page_limit: int, dropped_items: int, items_in_scope: int, item_limit: int, files_uploaded?: int, removed?: int, deletes_pending?: int} $run
     *
     * @return array{0: string, 1: string}
     */
    public function summariseRun(array $run): array
    {
        $notes = [];

        if ($run['pages_skipped'] > 0) {
            // The variant naming the lost items is used only when there were any, so an
            // ordinary page overflow keeps its shorter, unchanged wording.
            $notes[] = $run['dropped_items'] > 0
                ? 'MSC.vsau_plan_limit_truncated_items|'.$run['pages_skipped'].'|'.$run['page_limit'].'|'.$run['dropped_items']
                : 'MSC.vsau_plan_limit_truncated|'.$run['pages_skipped'].'|'.$run['page_limit'];
        }

        if ($run['item_limit'] > 0) {
            $notes[] = 'MSC.vsau_plan_item_limit_exceeded|'.$run['items_in_scope'].'|'.$run['item_limit'];
        }

        if ($run['files_failed'] > 0) {
            $notes[] = 'MSC.vsau_partial_files_failed|'.$run['files_failed'];
        }

        // A file we could not delete is the one failure the operator cannot see anywhere
        // else: the website looks right, the page is gone from the selection, and the
        // chatbot still answers from it. It has to be said out loud, every run, until the
        // deletion is confirmed.
        if (($run['deletes_pending'] ?? 0) > 0) {
            $notes[] = 'MSC.vsau_partial_deletes_pending|'.$run['deletes_pending'];
        }

        if ([] !== $notes) {
            return ['partial', implode(VectorStoreSyncMessageTranslator::COMPOUND_SEPARATOR, $notes)];
        }

        // "Nothing changed" means nothing reached the vector store in either direction:
        // a run that uploaded no file but DELETED one did change the knowledge base, and
        // saying otherwise would hide a removal - the one change nobody notices by
        // looking at their website.
        $changed = ($run['files_uploaded'] ?? 0) > 0 || ($run['removed'] ?? 0) > 0;

        return ['success', $changed ? '' : 'MSC.vsau_no_changes'];
    }

    /**
     * Drop the pages beyond the subscription's page limit.
     *
     * Deterministic by page id so the surviving set is stable from run to run.
     * Only pages are capped here - news/FAQ/event items have their own budget and
     * are never dropped; exceeding it just marks the run "partial".
     *
     * Public so the behaviour can be tested without running a full sync.
     *
     * @param array<int, mixed> $byPage page id => page data
     *
     * @return array{pages: array<int, mixed>, skipped: int, dropped: list<int>}
     */
    public function applyPlanPageLimit(array $byPage, int $limit): array
    {
        if ($limit <= 0 || \count($byPage) <= $limit) {
            return ['pages' => $byPage, 'skipped' => 0, 'dropped' => []];
        }

        ksort($byPage);

        $kept = \array_slice($byPage, 0, $limit, true);

        return [
            'pages' => $kept,
            'skipped' => \count($byPage) - $limit,
            // Reported so the caller can say how many reader items went with them.
            'dropped' => array_values(array_diff(array_keys($byPage), array_keys($kept))),
        ];
    }

    /**
     * Resolve the effective sync scope to page ids: the explicit selection, or the
     * whole subtree when exactly one domain root exists. Empty when the scope
     * cannot be determined (no selection and not exactly one root with a domain).
     *
     * @return list<int>
     */
    public function resolveScopePageIds(mixed $configValue): array
    {
        $selectedPageIds = self::parseConfiguredPageIds($configValue);

        if ([] !== $selectedPageIds) {
            return $selectedPageIds;
        }

        $roots = $this->publishedRootPageIds();

        if (1 !== \count($roots)) {
            return [];
        }

        return array_values(array_unique($this->collectPageSubtreeIds($roots[0])));
    }

    /**
     * Distinct non-empty root-page domains (tl_page.dns) that the effective sync scope
     * spans. Used to detect a page selection covering more than one domain under a
     * single-domain license. Resolves the scope through resolveScopePageIds(), so an
     * empty selection uses the same single-root fallback as everywhere else.
     *
     * Roots without a domain name contribute nothing here (they are handled by the
     * "not indexed / needs domain" prerequisite instead), so this never double-flags a
     * domain-less setup as multi-domain.
     *
     * @return list<string>
     */
    public function resolveScopeRootDomains(mixed $configValue): array
    {
        $pageIds = $this->resolveScopePageIds($configValue);

        if ([] === $pageIds) {
            return [];
        }

        $domains = [];
        $rootDnsCache = [];

        foreach ($pageIds as $pageId) {
            $dns = $this->rootDnsForPage((int) $pageId, $rootDnsCache);

            if (null !== $dns && '' !== $dns) {
                $domains[$dns] = true;
            }
        }

        return array_keys($domains);
    }

    /**
     * @return list<int>
     */
    public static function parseConfiguredPageIds(mixed $value): array
    {
        if (null === $value || '' === $value || 0 === $value || '0' === $value) {
            return [];
        }

        if (\is_array($value)) {
            return array_values(array_unique(array_filter(array_map(intval(...), $value))));
        }

        if (is_numeric($value)) {
            return [(int) $value];
        }

        $raw = (string) $value;
        $unserialized = @unserialize($raw, ['allowed_classes' => false]);

        if (\is_array($unserialized)) {
            return array_values(array_unique(array_filter(array_map(intval(...), $unserialized))));
        }

        return array_values(array_unique(array_filter(array_map(intval(...), explode(',', $raw)))));
    }

    /**
     * Append the deterministic link section to one page's content.
     *
     * Called AFTER the optional LLM rewrite, so the model never sees a URL and can
     * neither truncate, reword nor invent one. With the feature switched off the
     * content is returned byte-identical, which is what keeps an installation that
     * does not want links exactly where it was.
     *
     * Public so the behaviour the acceptance criteria describe can be tested
     * without running the whole sync (which needs a database, HTTP and a
     * subprocess).
     *
     * @param array{page_id: int, title?: string, language?: string} $page
     * @param array<int, list<PageLink>>                             $linksByPage
     */
    public function appendLinkSection(string $content, array $page, array $linksByPage, bool $enabled): string
    {
        if (!$enabled) {
            return $content;
        }

        $block = $this->linkSection->build(
            $linksByPage[$page['page_id']] ?? [],
            (string) ($page['title'] ?? ''),
            (string) ($page['language'] ?? ''),
        );

        return '' !== $block ? $content."\n\n".$block : $content;
    }

    /**
     * Append the site-wide directory of documents and pages as one extra document.
     *
     * It is uploaded with page_id = 0 and is added AFTER the plan cap was applied,
     * so it never consumes a page of the customer's quota. Switching the option
     * off simply omits the entry, and VectorStoreFileSync then removes its file
     * like any other page that dropped out of scope.
     *
     * @param list<array<string, mixed>> $pages
     * @param array<int, list<PageLink>> $linksByPage
     *
     * @return list<array<string, mixed>>
     */
    public function appendLinkIndexDocument(array $pages, array $linksByPage, bool $enabled): array
    {
        if (!$enabled || [] === $pages) {
            return $pages;
        }

        $language = (string) ($pages[0]['language'] ?? '');
        $siteRoot = $this->siteRootUrl((string) ($pages[0]['url'] ?? ''));
        $document = $this->linkIndex->build(
            $pages,
            $linksByPage,
            $language,
            (string) parse_url($siteRoot, PHP_URL_HOST),
        );

        if ('' === $document) {
            return $pages;
        }

        $pages[] = [
            'page_id' => 0,
            // The directory belongs to the site as a whole, so it cites the site
            // root rather than an arbitrary page.
            'url' => $siteRoot,
            'title' => $this->linkIndex->title($language),
            'language' => $language,
            'content' => $document,
            'search_checksum' => '',
        ];

        return $pages;
    }

    /**
     * Whether the database carries every column this version writes during a run.
     *
     * All three entry points check this - the CLI run, the backend dispatch and the
     * dashboard - because none of them may answer a pending migration with a raw SQL
     * error. The lists are exhaustive on purpose: checking only one column was enough
     * while they all arrived together, but auto_update_run_started shipped after the
     * progress columns, so an install updated from 2.1.x has the latter and not the
     * former - exactly the case a single-column check waves through.
     *
     * Deliberately NOT memoised: a CLI worker can outlive the migration it is waiting
     * for, and a cached "outdated" would keep it skipping until the process restarts.
     * Two schema introspections are nothing next to a crawl.
     *
     * Only tables and columns whose absence would THROW belong here. tl_openai_page_link
     * and tl_openai_polish_cache are guarded by their own try/catch (a run without them
     * simply collects no links and caches no rewrites), so requiring them would turn a
     * graceful degradation into a hard stop.
     */
    /**
     * How many site roots are live right now, by the sync's own definition (see
     * publishedRootPageIds()).
     *
     * The dashboard blocks "Run sync now" when an empty page selection cannot resolve to
     * exactly one website, and it has to answer that question the same way the run does.
     * It used to carry its own copy of the query, counting every published root and
     * ignoring the start/stop window - which disagreed with the sync in both directions:
     * a second root scheduled outside its window blocked a run that would have succeeded,
     * and a single root outside its window passed the check and then failed the run.
     *
     * Only the count is exposed, not the ids: deciding what a scope resolves to stays in
     * this service, where resolveScopePageIds() applies the same predicate.
     */
    public function liveRootPageCount(): int
    {
        return \count($this->publishedRootPageIds());
    }

    public function isSchemaCurrent(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['tl_openai_config', 'tl_openai_sync_log'])) {
            return false;
        }

        $columns = $schemaManager->listTableColumns('tl_openai_config');

        foreach (self::RUN_STATE_COLUMNS as $column) {
            if (!isset($columns[$column])) {
                return false;
            }
        }

        $logColumns = $schemaManager->listTableColumns('tl_openai_sync_log');

        foreach (self::SYNC_LOG_COLUMNS as $column) {
            if (!isset($logColumns[$column])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether this run has to refresh Contao's search index before reading it.
     *
     * Public so the decision can be tested on its own: it is the one place where a wrong
     * answer is invisible - too eager only costs time, too lazy serves stale answers.
     *
     * @param array<string, mixed> $config
     */
    public function shouldCrawl(array $config, string $signature): bool
    {
        $mode = (string) ($config['auto_update_crawl_mode'] ?? self::CRAWL_AUTO);

        if (self::CRAWL_NEVER === $mode) {
            return false;
        }

        if (self::CRAWL_AUTO !== $mode) {
            return true;
        }

        $lastCrawl = (int) ($config['auto_update_last_crawl'] ?? 0);

        // Never crawled by this version - includes every installation updating into the
        // feature, which must crawl once before its signature means anything.
        if ($lastCrawl <= 0) {
            return true;
        }

        // The safety net. Also covers a clock that jumped backwards: a lastCrawl in the
        // future makes the age negative, which is not "recent enough" by this test.
        $age = time() - $lastCrawl;

        if ($age >= self::CRAWL_MAX_AGE_SECONDS || $age < 0) {
            return true;
        }

        // An unreadable signature (no tables, database error) must never be mistaken for
        // "nothing changed" - siteContentSignature() returns '' in that case.
        if ('' === $signature) {
            return true;
        }

        return $signature !== (string) ($config['auto_update_crawl_signature'] ?? '');
    }

    /**
     * A short hash describing the current state of everything that can reach the search
     * index, or '' when it cannot be determined.
     *
     * Per table: MAX(tstamp) catches edits and additions, COUNT(*) catches deletions -
     * deleting a record bumps no timestamp anywhere, so a timestamp alone would let a
     * removed page linger in the knowledge base until the next forced crawl.
     */
    public function siteContentSignature(): string
    {
        try {
            $schemaManager = $this->connection->createSchemaManager();
            $parts = [];

            foreach (self::CONTENT_TABLES as $table) {
                // Optional bundles (news, calendar, FAQ) may not be installed at all.
                if (!$schemaManager->tablesExist([$table])) {
                    continue;
                }

                $row = $this->connection->fetchAssociative(
                    \sprintf('SELECT MAX(tstamp) AS ts, COUNT(*) AS rows_count FROM %s', $table),
                );

                $parts[] = $table.':'.(int) ($row['ts'] ?? 0).':'.(int) ($row['rows_count'] ?? 0);
            }

            if ([] === $parts) {
                return '';
            }

            return hash('sha256', implode('|', $parts));
        } catch (\Throwable $e) {
            // Reported rather than swallowed: a permanently unreadable signature means
            // every run crawls, which is correct but silently costs what this feature
            // exists to save.
            $this->logger->warning('VectorStoreAutoUpdate: could not determine the site content signature: '.$e->getMessage());

            return '';
        }
    }

    /**
     * Keep only the pages that can actually produce an indexed document: published, not
     * one of the structural/utility page types (site root, forward, redirect, logout,
     * error pages) that never carry standalone body content, and not protected. This keeps
     * the save-time plan limit aligned with what the sync really uploads, so a customer is
     * not blocked by pages that would never become vector-store documents anyway.
     *
     * The protection rule mirrors readAllPages(), which excludes protected search rows
     * outright - without it the two disagreed, and because enforceCrawlPageLimit() THROWS,
     * that gap did not merely miscount: on the smallest plan (20 pages) a handful of
     * member-only pages inside the selection could refuse the save of a configuration
     * whose real upload stays well under the limit, with nothing in the back end
     * explaining where the surplus pages went.
     *
     * Read from tl_search rather than tl_page.protected on purpose. Contao inherits
     * protection down the page tree: a child of a protected page carries an empty
     * "protected" flag of its own and is protected all the same. tl_search.protected is
     * the RESOLVED flag Contao's own indexer wrote, which is exactly the signal the sync
     * filters on - so this cannot drift from what actually gets uploaded.
     *
     * A page is only dropped when the index proves it is protected: it has search rows and
     * every one of them is protected. Pages absent from tl_search keep counting, which
     * matters before the first crawl - an empty index must not silently reduce every plan
     * count to zero and thereby disable the limit. Counting an unknown page is the
     * conservative direction; not counting it would give away scope for free.
     *
     * Returns the ids rather than a count because the reader items rendered on them have
     * to be counted per page afterwards.
     *
     * @param array<int, int> $pageIds
     *
     * @return list<int>
     */
    private function contentPageIds(array $pageIds): array
    {
        $pageIds = array_values(array_filter(array_map(intval(...), $pageIds)));

        // Same authoritative exclusion readAllPages() applies. If the two disagreed, a page
        // that is no longer uploaded would still consume a plan slot - and because
        // enforceCrawlPageLimit() throws, that can refuse the save of a configuration whose
        // real upload sits well under the limit.
        $pageIds = array_values(array_diff($pageIds, array_keys($this->pageProtection->protectedPageIds())));

        if ([] === $pageIds) {
            return [];
        }

        return array_map(
            intval(...),
            $this->connection->fetchFirstColumn(
                "SELECT p.id FROM tl_page p
                 WHERE p.id IN (?)
                   AND p.published = '1'
                   AND p.type NOT IN ('root', 'forward', 'redirect', 'logout', 'error_401', 'error_403', 'error_404', 'error_503')
                   AND NOT (
                       EXISTS (SELECT 1 FROM tl_search s WHERE s.pid = p.id AND s.protected = 1)
                       AND NOT EXISTS (SELECT 1 FROM tl_search s WHERE s.pid = p.id AND COALESCE(s.protected, 0) = 0)
                   )",
                [$pageIds],
                [ArrayParameterType::INTEGER],
            ),
        );
    }

    /**
     * Resolve the sync mode, defaulting to faithful (no LLM). Falls back to the legacy
     * auto_update_raw_mode flag for configs saved before auto_update_mode existed:
     * raw_mode = 1 -> faithful, raw_mode = 0 (old LLM default) -> llm_polish.
     *
     * @param array<string, mixed> $config
     */
    private function resolveMode(array $config): string
    {
        $mode = (string) ($config['auto_update_mode'] ?? '');
        if (\in_array($mode, [self::MODE_FAITHFUL, self::MODE_LLM_POLISH], true)) {
            return $mode;
        }

        if (\array_key_exists('auto_update_raw_mode', $config) && null !== $config['auto_update_raw_mode']) {
            return (bool) $config['auto_update_raw_mode'] ? self::MODE_FAITHFUL : self::MODE_LLM_POLISH;
        }

        return self::MODE_FAITHFUL;
    }

    private function acquireRunLock(int $configId, string $triggerSource): bool
    {
        $now = time();
        $staleBefore = $now - self::STALE_RUN_SECONDS;
        $statusPredicate = self::SOURCE_MANUAL === $triggerSource
            ? "(auto_update_last_status = 'queued' OR COALESCE(auto_update_last_status, '') NOT IN ('running', 'queued') OR COALESCE(auto_update_last_run, 0) < ?)"
            : "(COALESCE(auto_update_last_status, '') NOT IN ('running', 'queued') OR COALESCE(auto_update_last_run, 0) < ?)";

        // auto_update_run_started is (re)stamped here, not carried over from the queued
        // dispatch: this is when work actually begins, so a run that sat in the queue
        // does not inflate its own duration.
        $updated = $this->connection->executeStatement(
            "UPDATE tl_openai_config
                SET auto_update_last_run = ?, auto_update_run_started = ?, auto_update_last_status = 'running', auto_update_last_message = NULL, ".self::PROGRESS_RESET_SQL.'
                WHERE id = ? AND '.$statusPredicate,
            [$now, $now, $configId, $staleBefore],
        );

        if (0 === $updated) {
            return false;
        }

        // The lease was just written; the next refresh is not due for HEARTBEAT_INTERVAL.
        $this->lastHeartbeatAt = $now;
        $this->lastProgressAt = 0;
        $this->lastProgressPhase = '';

        return true;
    }

    /**
     * Refresh the run lease (auto_update_last_run) so a long but healthy sync is not treated
     * as crashed by the cron/manual stale-run guard. Throttled to HEARTBEAT_INTERVAL and
     * scoped to status='running' so it never resurrects a run that already finished or errored.
     */
    private function heartbeat(int $configId): void
    {
        $now = time();
        if ($now - $this->lastHeartbeatAt < self::HEARTBEAT_INTERVAL) {
            return;
        }

        $this->lastHeartbeatAt = $now;
        $this->connection->executeStatement(
            "UPDATE tl_openai_config SET auto_update_last_run = ? WHERE id = ? AND auto_update_last_status = 'running'",
            [$now, $configId],
        );
    }

    /**
     * Persist live progress of the running sync (polled by the dashboard status endpoint)
     * and refresh the run lease in the same write. Throttled to PROGRESS_INTERVAL; only a
     * phase change forces an immediate write (a possibly skipped final count is invisible
     * anyway — the terminal state clears the progress right after). Scoped to
     * status='running' like heartbeat(), so a finished/errored run is never resurrected.
     */
    private function progress(int $configId, string $phase, int $current, int $total): void
    {
        $now = time();
        if ($phase === $this->lastProgressPhase && $now - $this->lastProgressAt < self::PROGRESS_INTERVAL) {
            return;
        }

        $this->lastProgressAt = $now;
        $this->lastProgressPhase = $phase;
        $this->lastHeartbeatAt = $now;
        $this->connection->executeStatement(
            "UPDATE tl_openai_config
                SET auto_update_last_run = ?, auto_update_progress_phase = ?, auto_update_progress_current = ?, auto_update_progress_total = ?
                WHERE id = ? AND auto_update_last_status = 'running'",
            [$now, $phase, $current, $total, $configId],
        );
    }

    /**
     * How many pages the running crawl has written to Contao's search index - which is
     * NOT how many pages it has visited, and the difference is the whole story here.
     *
     * Search::indexPage() computes a checksum of the page text and returns early when a
     * row with that checksum already exists for the URL, before writing anything at all
     * (core-bundle/contao/library/Contao/Search.php:173-182, identical in 5.3, 5.7 and
     * 6.0). Only a page whose content actually changed reaches the INSERT/UPDATE that
     * stamps tstamp = time() (:42, :220, :229). So on a crawl of a site nobody has
     * edited, this counts zero however many thousand URLs the crawler fetches.
     *
     * That makes it a fair "new or changed pages so far" reading and a false one for
     * anything else - in particular it must never be divided by the size of the index to
     * form a percentage.
     *
     * Site-wide, like the crawl itself, so two configurations crawling at the same moment
     * share the number. Acceptable for a display, and it cannot affect what gets synced.
     *
     * Never fails the run: a progress number is not worth an exception.
     *
     * @param int $since unix time to count from
     */
    private function countIndexedSince(int $since): int
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM tl_search WHERE tstamp >= ?',
                [$since],
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    private function spawnCrawl(int $configId): void
    {
        // The exact start URLs contao:crawl will use - resolved here, in the same CLI
        // process, so this is what the crawl really sees rather than a guess made from
        // the page tree. Logged because a crawl that reaches nothing still exits 0:
        // Escargot reports "Finished crawling! Sent 1 request(s)." after a refused
        // connection, so without this line a completely failed crawl is invisible and
        // the run goes on to upload whatever stale rows tl_search happens to hold.
        //
        // The classic cause is a root page without a domain name: with no request
        // context, Contao's URL generator falls back to the router default and the
        // crawl ends up at "https://localhost/...". Not turned into a hard failure on
        // purpose - "localhost" is legitimate on a local install, and an installation
        // may set framework.router.default_uri instead of a page domain - so this
        // reports rather than blocks.
        $baseUris = $this->escargotFactory->getCrawlUriCollection();

        if (0 === \count($baseUris)) {
            throw new \RuntimeException('MSC.vsau_err_no_crawl_uri');
        }

        $this->logger->notice(\sprintf(
            'VectorStoreAutoUpdate: crawl for config %d starts at %s.',
            $configId,
            implode(', ', array_map(strval(...), $baseUris->all())),
        ));

        $process = $this->processUtil->createSymfonyConsoleProcess(
            'contao:crawl',
            '--subscribers=search-index',
            // Contao's own default is "--max-depth=3" (CrawlCommand::configure()), which
            // stops the crawl three link hops from the site root. That is fine for the
            // back end's crawl tool, but not here: the second page of a news list sits at
            // depth 3 and every item behind it at depth 4, so those items never reach
            // tl_search and therefore never reach the vector store - while the run still
            // reports "success". A store that is meant to mirror the site must not carry
            // a depth cap. Deliberately NOT paired with "--max-requests": that would only
            // trade one silent truncation for another. The crawl is bounded by the site
            // itself, and Contao already marks the endless link sources (the mini
            // calendar's month arrows) with data-skip-search-index.
            '--max-depth=0',
            // Nothing reads a progress bar in a detached background process, and it
            // would drown the summary this method logs below.
            '--no-progress',
            '--no-interaction',
        );

        // Poll instead of a blocking wait() so the lease keeps refreshing during a long crawl
        // (a few thousand pages can take many minutes). No process timeout: the crawl must run
        // to completion, however long it legitimately takes.
        $process->setTimeout(null);
        // Read BEFORE the process starts, so no page the crawl indexes can slip in ahead
        // of the reference point and go uncounted.
        //
        // No total accompanies this count, and there is no honest way to produce one: what
        // it measures is pages Contao actually (re)wrote, which on a crawl of an unchanged
        // site is nothing at all - see countIndexedSince(). Measuring that against the size
        // of the index produced "1 von ca. 1072 Seiten" on a site where exactly one page
        // had been edited: a true numerator and a true denominator that count different
        // populations. The crawl bar therefore stays indeterminate.
        $crawlStartedAt = time();
        $process->start();

        $lastReport = 0;

        while ($process->isRunning()) {
            $now = time();

            // Counted no more often than the dashboard polls; heartbeat() keeps the lease
            // alive on the ticks in between.
            if ($now - $lastReport >= self::CRAWL_PROGRESS_INTERVAL) {
                $lastReport = $now;
                $this->progress($configId, 'crawl', $this->countIndexedSince($crawlStartedAt), 0);
            } else {
                $this->heartbeat($configId);
            }

            usleep(2_000_000);
        }

        if (!$process->isSuccessful()) {
            // Logged as well as stored: the backend renders some crawl failures as a named
            // cause rather than as raw output (see VectorStoreSyncMessageTranslator), so the
            // log is where the full text stays readable for diagnosis.
            $this->logger->error(\sprintf(
                'VectorStoreAutoUpdate: contao:crawl failed for config %d (exit code %s): %s',
                $configId,
                var_export($process->getExitCode(), true),
                trim($process->getErrorOutput()),
            ));

            throw new \RuntimeException('MSC.vsau_err_crawl_failed|'.$process->getErrorOutput());
        }

        // Logged on success too, not just on failure: the crawl's own summary ("Sent N
        // request(s)", broken-link notices) is the only place a connection problem shows
        // up, and a zero exit code hides it completely. Capped so a large crawl cannot
        // flood the log.
        $summary = trim($process->getOutput()."\n".$process->getErrorOutput());
        $this->lastCrawlSummary = $summary;

        if ('' !== $summary) {
            $this->logger->notice(\sprintf(
                'VectorStoreAutoUpdate: crawl finished for config %d: %s',
                $configId,
                mb_substr($summary, 0, 4000),
            ));
        }
    }

    /**
     * Load, filter and group the links of the pages in scope.
     *
     * Three filtering stages, in this order:
     *   1. the operator's policy (allowed types, exclude patterns) plus the hard
     *      rule that a link to a protected page is never advertised,
     *   2. the cross-page frequency filter, which removes site chrome no matter
     *      what the theme's markup looks like,
     *   3. (implicitly) the extractor's own per-page cap, applied at collection time.
     *
     * @param array<string, mixed>                                             $config
     * @param list<int|string>                                                 $pageIds
     * @param array{total: int, dropped_policy: int, dropped_boilerplate: int} $stats   by reference
     *
     * @return array<int, list<PageLink>>
     */
    private function collectLinks(array $config, array $pageIds, array &$stats): array
    {
        $links = $this->pageLinks->findForPages(array_map(intval(...), $pageIds));

        if ([] === $links) {
            return [];
        }

        $policy = $this->linkFilter->applyPolicy(
            $links,
            // NULL when the field was never saved (every type allowed, which is
            // what an installation upgrading into this feature sees); an empty
            // list when the admin unchecked every type, which must then mean
            // "no links" rather than "all links".
            self::parseStringList($config['auto_update_link_types'] ?? null),
            preg_split('/\r\n|\r|\n/', (string) ($config['auto_update_link_exclude'] ?? '')) ?: [],
            // Both sets are "URLs no answer may point at", so they are merged into the
            // one policy argument: member-only targets (never public) and unpublished
            // ones (public until an editor retired them). Union via "+" - the values are
            // just `true` and the keys are already comparison keys, so a URL that is both
            // collapses into one entry.
            $this->pageLinks->protectedUrls() + $this->pageLinks->unpublishedUrls(),
        );

        // The frequency denominator is the whole sync scope, not just the pages that
        // happen to contain links.
        $cleaned = $this->linkFilter->removeBoilerplate($policy['links'], \count($pageIds));

        $stats['dropped_policy'] = $policy['dropped'];
        $stats['dropped_boilerplate'] = $cleaned['dropped'];
        $stats['total'] = array_sum(array_map('count', $cleaned['links']));

        if ([] !== $cleaned['samples']) {
            $this->logger->info(
                'VectorStoreAutoUpdate: dropped '.$cleaned['dropped'].' boilerplate link(s), e.g. '
                .implode(', ', \array_slice($cleaned['samples'], 0, 3)),
            );
        }

        return $cleaned['links'];
    }

    /**
     * Scheme + host of a page URL, used as the citation URL of the site-wide
     * directory document. Falls back to the input when it cannot be parsed.
     */
    private function siteRootUrl(string $pageUrl): string
    {
        $scheme = parse_url($pageUrl, PHP_URL_SCHEME);
        $host = parse_url($pageUrl, PHP_URL_HOST);

        if (!\is_string($scheme) || !\is_string($host) || '' === $scheme || '' === $host) {
            return $pageUrl;
        }

        $port = parse_url($pageUrl, PHP_URL_PORT);

        return $scheme.'://'.$host.(\is_int($port) ? ':'.$port : '').'/';
    }

    /**
     * Read a DCA multi-value field (stored serialised by Contao) as a plain list of
     * strings. Object deserialisation is disabled - the value comes from the
     * database and must never be able to instantiate a class.
     *
     * Returns NULL when the field was never saved (column missing, NULL or empty
     * string), which the caller must treat differently from an explicitly saved
     * empty selection ("a:0:{}").
     *
     * @return list<string>|null
     */
    private static function parseStringList(mixed $value): array|null
    {
        if (\is_array($value)) {
            return array_values(array_filter(array_map(strval(...), $value)));
        }

        if (null === $value || '' === $value) {
            return null;
        }

        $raw = (string) $value;
        $unserialized = @unserialize($raw, ['allowed_classes' => false]);

        if (\is_array($unserialized)) {
            return array_values(array_filter(array_map(strval(...), $unserialized)));
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $raw))));
    }

    /**
     * Decode Contao "basic entities" left unresolved in rendered page text.
     *
     * Since Contao 5.0, [&], [lt], [gt], [nbsp], [-] and [zwsp] are no longer
     * converted automatically when a page is rendered (see UPGRADE.md), so they
     * can reach tl_search - and thus the vector store - literally. Inside URLs
     * a literal "[&]" makes the model truncate or bracket-mangle the link, so
     * they are decoded to their plain-text equivalents before the LLM rewrite.
     *
     * Tag list mirrors StringUtil::restoreBasicEntities() of Contao 5.3 AND
     * 5.7: both know [&], [&amp;], [lt], [gt], [nbsp], [-], [zwsp]; 5.7 added
     * [lsqb]/[rsqb] (escaped square brackets), which are a no-op in 5.3
     * content. The bracket tags are decoded LAST so their literal "["/"]"
     * output can never combine with adjacent text into another tag.
     */
    private static function decodeBasicEntities(string $text): string
    {
        return str_replace(
            ['[&]', '[&amp;]', '[lt]', '[gt]', '[nbsp]', '[-]', '[zwsp]', '[lsqb]', '[rsqb]'],
            ['&', '&', '<', '>', ' ', '', '', '[', ']'],
            $text,
        );
    }

    /**
     * Undo Contao's input encoding on a stored free-text field.
     *
     * Contao 5.3 and 5.7 encode every posted value on save - "#", "<", ">", "(",
     * ")", "\", "=", '"' and "'" become numeric entities (Input::encodeInput(),
     * InputEncodingMode::encodeAll; the ampersand is left alone). Contao 6 dropped
     * input encoding and stores the raw text. Without this, an admin's prompt
     * reaches the model as "Fasse den Text zusammen &#40;kurz&#41;" on the whole
     * 2.x line, which quietly degrades every AI-polished document.
     *
     * A no-op on Contao 6, where the stored value contains no entities.
     */
    private static function decodeStoredText(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));

        return '' === $text ? '' : html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Delete the vector-store documents of pages the PAGE TREE says are no longer eligible.
     *
     * Deliberately independent of the search index: this is the half of reconciliation that
     * must not depend on a crawl having worked. A page that was deleted, unpublished or
     * protected is gone by the authority of tl_page alone, and its content must stop being
     * answerable whether or not tl_search still has rows to upload.
     *
     * Never removes page_id 0 - that is the synthetic site-wide link directory, not a page.
     *
     * @param array<string, mixed> $config
     *
     * @return array{removed: int, deletes_pending: int}
     */
    private function removeAuthoritativelyGonePages(string $apiKey, string $vectorStoreId, int $configId, array $config): array
    {
        $none = ['removed' => 0, 'deletes_pending' => 0];

        if ('' === $apiKey || '' === $vectorStoreId) {
            return $none;
        }

        try {
            $tracked = array_values(array_filter(array_map(
                intval(...),
                $this->connection->fetchFirstColumn(
                    'SELECT DISTINCT page_id FROM tl_openai_vector_file WHERE pid = ? AND page_id > 0',
                    [$configId],
                ),
            )));
        } catch (\Throwable $e) {
            $this->logger->warning('VectorStoreAutoUpdate could not read tracked pages for config '.$configId.': '.$e->getMessage());

            return $none;
        }

        if ([] === $tracked) {
            return $none;
        }

        $now = time();
        $time = $now - $now % 60;

        // Contao's own published predicate, including the minute rounding used elsewhere in
        // this class. Anything the query does NOT return is deleted or not currently live.
        $live = array_map(
            intval(...),
            $this->connection->fetchFirstColumn(
                "SELECT id FROM tl_page
                 WHERE id IN (?)
                   AND published = '1'
                   AND (COALESCE(start, '') = '' OR start <= ?)
                   AND (COALESCE(stop, '') = '' OR stop > ?)",
                [$tracked, $time, $time],
                [ArrayParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
            ),
        );

        $gone = array_values(array_diff($tracked, $live));

        // Protected, with inheritance resolved from the tree rather than the stale index.
        $gone = array_values(array_unique(array_merge(
            $gone,
            array_values(array_intersect($tracked, array_keys($this->pageProtection->protectedPageIds()))),
        )));

        // Pages the admin removed from an explicit selection. Only with an explicit
        // selection: under the whole-website fallback the scope is the page tree itself, and
        // "deleted from tl_page" above already covers it.
        $selected = self::parseConfiguredPageIds($config['auto_update_site_root'] ?? null);

        if ([] !== $selected) {
            $gone = array_values(array_unique(array_merge($gone, array_values(array_diff($tracked, $selected)))));
        }

        if ([] === $gone) {
            return $none;
        }

        $this->logger->notice(\sprintf(
            'VectorStoreAutoUpdate: removing %d page document(s) for config %d that the page tree no longer covers (IDs: %s).',
            \count($gone),
            $configId,
            implode(', ', $gone),
        ));

        return $this->fileSync->removePages($apiKey, $vectorStoreId, $configId, $gone);
    }

    /**
     * Read tl_search rows scoped to the configured page selection.
     *
     * Explicitly selected pages are used as-is (no subpages implied). An empty
     * selection falls back to the whole website when exactly one site root exists.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readAllPages(int $configId): array
    {
        $config = $this->connection->fetchAssociative(
            'SELECT auto_update_site_root FROM tl_openai_config WHERE id = ?',
            [$configId],
        );
        $selectedPageIds = self::parseConfiguredPageIds($config['auto_update_site_root'] ?? null);

        if ([] !== $selectedPageIds) {
            // Exact selection - only the pages the admin picked, no subpages implied.
            // Pages deleted since the selection was saved are skipped (with a log
            // notice) instead of failing the whole sync; only an entirely stale
            // selection aborts the run.
            $existingIds = array_map(
                'intval',
                $this->connection->fetchFirstColumn(
                    'SELECT id FROM tl_page WHERE id IN (?)',
                    [$selectedPageIds],
                    [ArrayParameterType::INTEGER],
                ),
            );

            $missing = array_values(array_diff($selectedPageIds, $existingIds));
            if ([] !== $missing) {
                $this->logger->notice(\sprintf(
                    'VectorStoreAutoUpdate: skipping %d deleted page(s) in the selection for config %d (IDs: %s). Update the page selection in the OpenAI configuration.',
                    \count($missing),
                    $configId,
                    implode(', ', $missing),
                ));
            }

            if ([] === $existingIds) {
                throw new \RuntimeException('MSC.vsau_err_invalid_page|'.(string) $missing[0]);
            }

            $pageIds = $existingIds;
        } else {
            // Empty selection - fall back to the whole website (single site root + subtree).
            $roots = $this->publishedRootPageIds();

            if (1 === \count($roots)) {
                $pageIds = $this->collectPageSubtreeIds($roots[0]);
            } elseif (\count($roots) > 1) {
                throw new \RuntimeException('MSC.vsau_err_multiple_roots');
            } else {
                return [];
            }
        }

        $pageIds = array_values(array_unique($pageIds));

        // Protection resolved from tl_page, on top of the tl_search.protected filter below.
        // The index flag is only as fresh as the last crawl, and Contao never purges a search
        // row when a page becomes protected - so without this a page an editor closed off
        // yesterday is still uploaded to a store an anonymous chat endpoint answers from.
        $pageIds = array_values(array_diff($pageIds, array_keys($this->pageProtection->protectedPageIds())));

        if ([] === $pageIds) {
            return [];
        }

        // The plan page cap is applied later in run(), on the actual content pages, not on
        // this raw id list — so a non-content page (root/forward/redirect) never consumes
        // a slot that a real indexed page should have.
        //
        // Protected pages are excluded unconditionally. With contao.search.index_protected
        // enabled, member-only page bodies DO reach tl_search, and the chat endpoint that
        // answers from the vector store is anonymous — so uploading them would hand
        // member-only content to any visitor. Contao's own search module filters those
        // rows by member group at query time; we have no equivalent, so we never take
        // them in the first place.
        //
        // COALESCE, not "s.protected != 1": a NULL would make the comparison NULL, drop
        // the row here, and the reconcile in VectorStoreFileSync would then DELETE that
        // page's document from the customer's vector store. The column is
        // "boolean NOT NULL default false" in Contao 5.3, 5.7 and 6.0 alike, so NULL
        // should be impossible — this is a guard against schema drift, not a hypothesis.
        // p.title is joined so a merged document can be named after the page itself. The
        // indexed s.title is the <title> tag of one crawled URL - on a reader page that is
        // one news/FAQ/event entry, i.e. an arbitrary one of many, and it survives in the
        // index after that entry is gone. LEFT JOIN: a search row whose page row vanished
        // still carries its own title as a fallback.
        // Unpublished pages are excluded, and so are pages outside their start/stop
        // window. Contao does NOT purge tl_search when a page is unpublished - its
        // PageSearchListener only reacts to an alias change, noSearch, robots=noindex
        // and deletion - and an unpublished page is no longer linked from anywhere, so
        // the crawler never requests it and never gets the 404 that would delete the
        // row. Without this filter the stale row lives on and the chatbot keeps
        // answering from a page that 404s for every visitor, and linking to it.
        //
        // The predicate is Contao's own (see tl_page usage in Messages.php:27), including
        // the minute-rounding of the timestamp that publishedRootPageIds() already uses.
        //
        // COALESCE guards the LEFT JOIN, and deliberately keeps a search row whose page
        // row has vanished: that is the fallback the join exists for (a title of its
        // own), and dropping it here would make VectorStoreFileSync reconcile the
        // document away. Deleting a page purges tl_search through core anyway.
        $now = time();
        $time = $now - $now % 60;

        return $this->connection->fetchAllAssociative(
            "SELECT s.pid AS page_id, s.url, s.title, s.text, s.language, s.checksum, p.title AS page_title
             FROM tl_search s
             LEFT JOIN tl_page p ON p.id = s.pid
             WHERE s.pid IN (?)
               AND COALESCE(s.protected, 0) = 0
               AND COALESCE(p.published, '1') = '1'
               AND (COALESCE(p.start, '') = '' OR p.start <= ?)
               AND (COALESCE(p.stop, '') = '' OR p.stop > ?)
             ORDER BY s.pid, s.url",
            [$pageIds, $time, $time],
            [ArrayParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }

    /**
     * Merge the search-index rows of a page into one document per page.
     *
     * A page can hold several indexed URLs - paginated lists, and above all a reader page,
     * where every news/FAQ/event entry is its own URL under the same page id. Two values
     * therefore cannot simply be taken from the first row:
     *
     * - the title comes from the page itself (tl_page.title), because the indexed <title>
     *   of the first row names one arbitrary entry and keeps naming it after that entry is
     *   deleted, until the index is rebuilt;
     * - the URL is the shortest of the page's indexed URLs, which is the page itself: an
     *   entry only ever adds a path segment or a query parameter to it.
     *
     * @param list<array<string, mixed>> $rows  search-index rows, ordered by page and URL
     * @param array<int, string>         $texts boilerplate-cleaned text per row index
     *
     * @return array<int, array{page_id: int, url: string, title: string, language: string, contents: list<string>, checksums: list<string>}>
     */
    private function aggregateByPage(array $rows, array $texts): array
    {
        $byPage = [];

        foreach ($rows as $i => $row) {
            $content = trim($texts[$i] ?? '');

            if ('' === $content) {
                // Pure chrome collapses to nothing after de-dup - carries no information.
                continue;
            }

            $pageId = (int) $row['page_id'];
            $url = (string) $row['url'];

            if (!isset($byPage[$pageId])) {
                $byPage[$pageId] = [
                    'page_id' => $pageId,
                    'url' => $url,
                    'title' => '' !== trim((string) ($row['page_title'] ?? ''))
                        ? (string) $row['page_title']
                        : (string) $row['title'],
                    'language' => (string) $row['language'],
                    'contents' => [],
                    'checksums' => [],
                ];
            }

            if (mb_strlen($url) < mb_strlen($byPage[$pageId]['url'])) {
                $byPage[$pageId]['url'] = $url;
            }

            $byPage[$pageId]['contents'][] = $content;
            $byPage[$pageId]['checksums'][] = (string) ($row['checksum'] ?? '');
        }

        return $byPage;
    }

    /**
     * Climb the page tree from $pageId up to its type='root' ancestor and return that
     * root's dns: the trimmed domain, '' when the root has no domain, or null when the
     * chain is broken (missing row / no root reached). Guards against pid cycles and
     * orphaned chains, and memoizes every id visited on the way so a large selection
     * sharing ancestors does not re-query the same path.
     *
     * @param array<int, string|null> $cache page id => resolved root dns (by reference)
     */
    private function rootDnsForPage(int $pageId, array &$cache): string|null
    {
        $chain = [];
        $currentId = $pageId;

        while ($currentId > 0) {
            if (\array_key_exists($currentId, $cache)) {
                $resolved = $cache[$currentId];

                foreach (array_keys($chain) as $id) {
                    $cache[$id] = $resolved;
                }

                return $resolved;
            }

            if (isset($chain[$currentId])) {
                break; // cycle guard: a pid pointing back into the chain
            }

            $chain[$currentId] = true;

            $row = $this->connection->fetchAssociative(
                'SELECT pid, type, dns FROM tl_page WHERE id = ?',
                [$currentId],
            );

            if (false === $row) {
                break; // orphaned pid: parent no longer exists
            }

            if ('root' === $row['type']) {
                $resolved = trim((string) ($row['dns'] ?? ''));

                foreach (array_keys($chain) as $id) {
                    $cache[$id] = $resolved;
                }

                return $resolved;
            }

            $currentId = (int) $row['pid'];
        }

        foreach (array_keys($chain) as $id) {
            $cache[$id] = null;
        }

        return null;
    }

    /**
     * Ids of the site roots that carry a domain name and are actually live.
     *
     * "Live" follows Contao's own definition of a published page
     * (PageModel::findPublishedById): the published flag AND the optional
     * start/stop window, with the current time floored to the minute exactly as
     * Date::floorToMinute() does it, and with "stop" compared strictly greater.
     * Checking only the flag would still count a root whose stop date has passed -
     * or whose start date has not been reached yet - as a second live site, which
     * is enough to block the whole-website fallback on an installation that really
     * has just one.
     *
     * The minute is floored in PHP rather than through Contao's Date class so this
     * service keeps needing nothing but the database connection.
     *
     * Exposed to the dashboard through liveRootPageCount(), which is the only question
     * it has to ask.
     *
     * @return list<int>
     */
    private function publishedRootPageIds(): array
    {
        $now = time();
        $time = $now - $now % 60;

        return array_map(
            intval(...),
            $this->connection->fetchFirstColumn(
                "SELECT id FROM tl_page
                 WHERE type = 'root'
                   AND dns != ''
                   AND published = '1'
                   AND (start = '' OR start <= ?)
                   AND (stop = '' OR stop > ?)",
                [$time, $time],
            ),
        );
    }

    /**
     * @return list<int>
     */
    private function collectPageSubtreeIds(int $rootPageId): array
    {
        $ids = [$rootPageId];
        $queue = [$rootPageId];

        while ([] !== $queue) {
            if (\count($ids) >= self::MAX_CRAWL_PAGES) {
                break;
            }

            $parentId = array_pop($queue);
            $children = $this->connection->fetchFirstColumn(
                'SELECT id FROM tl_page WHERE pid = ?',
                [$parentId],
            );

            // Cut the batch to the headroom that is actually left. The cap was checked at the
            // top of the loop and the WHOLE batch appended after it, so a single parent with
            // thousands of direct children could overshoot the documented hard limit by the
            // size of that batch - the one shape of page tree where the check does nothing.
            $headroom = self::MAX_CRAWL_PAGES - \count($ids);

            if (\count($children) > $headroom) {
                $children = \array_slice($children, 0, $headroom);
            }

            foreach ($children as $childId) {
                $childId = (int) $childId;
                $ids[] = $childId;
                $queue[] = $childId;
            }
        }

        return $ids;
    }

    /**
     * Premium "LLM polish" mode: rewrite ONE page into a clean, dense knowledge-base
     * document. Because the model only ever sees a single page, it cannot drop or confuse
     * content from other pages - the fidelity problem of the old bulk call is gone.
     *
     * @return array{text: string, tokens_in: int, tokens_out: int}
     */
    /**
     * A previously rewritten document for this page, or null when it must be rewritten.
     *
     * Returned only when nothing that could change the output has changed: the page's
     * source text AND the rewrite parameters (model + prompt). That second half matters
     * as much as the first - without it a corrected prompt would never reach the pages it
     * was written to fix.
     *
     * Never fails the run: an unreadable cache just means the page is polished again,
     * which is exactly what used to happen every time anyway.
     */
    private function cachedPolish(int $configId, int $pageId, string $sourceHash, string $fingerprint): string|null
    {
        if ($pageId <= 0 || '' === $sourceHash || '' === $fingerprint) {
            return null;
        }

        try {
            $content = $this->connection->fetchOne(
                'SELECT content FROM tl_openai_polish_cache WHERE pid = ? AND page_id = ? AND source_hash = ? AND fingerprint = ?',
                [$configId, $pageId, $sourceHash, $fingerprint],
            );
        } catch (\Throwable) {
            return null;
        }

        if (!\is_string($content) || '' === trim($content)) {
            return null;
        }

        return $content;
    }

    /**
     * Remember one rewritten document. Only complete responses reach this method -
     * polishPage() discards a rewrite the model cut off at its output limit, because
     * caching a truncated document would make the truncation permanent.
     *
     * The upsert is keyed on the unique (pid, page_id) index, so a page that is
     * re-polished replaces its own row instead of accumulating one per revision.
     */
    private function storePolish(int $configId, int $pageId, string $sourceHash, string $fingerprint, string $content): void
    {
        if ($pageId <= 0 || '' === $sourceHash || '' === $fingerprint) {
            return;
        }

        try {
            $this->connection->executeStatement(
                'INSERT INTO tl_openai_polish_cache (pid, tstamp, page_id, source_hash, fingerprint, content)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE tstamp = VALUES(tstamp), source_hash = VALUES(source_hash),
                        fingerprint = VALUES(fingerprint), content = VALUES(content)',
                [$configId, time(), $pageId, $sourceHash, $fingerprint, $content],
            );
        } catch (\Throwable $e) {
            // A cache that cannot be written costs tokens on the next run; it never costs
            // correctness, so it must not end the sync.
            $this->logger->warning('VectorStoreAutoUpdate: could not cache the rewritten document for page '.$pageId.': '.$e->getMessage());
        }
    }

    /**
     * Drop cached documents for pages that are no longer in this configuration's scope,
     * so a de-selected or deleted page does not keep a copy of its text forever.
     *
     * @param list<int> $pageIds the pages this run actually processed
     */
    private function prunePolishCache(int $configId, array $pageIds): void
    {
        try {
            if ([] === $pageIds) {
                $this->connection->executeStatement('DELETE FROM tl_openai_polish_cache WHERE pid = ?', [$configId]);

                return;
            }

            $this->connection->executeStatement(
                'DELETE FROM tl_openai_polish_cache WHERE pid = ? AND page_id NOT IN (?)',
                [$configId, $pageIds],
                [ParameterType::INTEGER, ArrayParameterType::INTEGER],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('VectorStoreAutoUpdate: could not prune the rewrite cache for config '.$configId.': '.$e->getMessage());
        }
    }

    private function polishPage(string $apiKey, string $model, string $title, string $url, string $content, string|null $promptTemplate): array
    {
        $systemPrompt = $promptTemplate ?? VectorStoreDocumentPrompt::DEFAULT_TEMPLATE;
        $pageContent = \sprintf("## %s\nURL: %s\n\n%s", $title, $url, $content);

        // A low temperature keeps the rewrite deterministic. Reasoning models (o-series,
        // gpt-5 reasoning, ...) reject a custom temperature, however. Rather than maintain a
        // model allow-list, send 0.2 and - if the API rejects it - retry once without the
        // parameter, so any current or future model still works.
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $pageContent],
            ],
            'temperature' => 0.2,
        ];

        [$status, $data] = $this->postChatCompletion($apiKey, $payload);

        if ($status >= 400 && $this->isUnsupportedTemperatureError($data)) {
            unset($payload['temperature']);
            [$status, $data] = $this->postChatCompletion($apiKey, $payload);
        }

        if ($status < 200 || $status >= 300) {
            // Non-fatal: the caller falls back to the faithful text so the page is never lost.
            $this->logger->warning('LLM polish failed ('.$status.') for '.$url.': '.(string) ($data['error']['message'] ?? 'unknown'));

            return ['text' => '', 'tokens_in' => 0, 'tokens_out' => 0];
        }

        $tokensIn = (int) ($data['usage']['prompt_tokens'] ?? 0);
        $tokensOut = (int) ($data['usage']['completion_tokens'] ?? 0);
        $finishReason = (string) ($data['choices'][0]['finish_reason'] ?? '');

        // A rewrite that hit the model's output limit comes back as a valid 200 with a
        // document cut off mid-sentence - the failure mode of a long page, e.g. a reader
        // page carrying many news or FAQ entries. Accepting it would upload a truncated
        // knowledge document and, worse, cache it. Discard it and let the caller fall back
        // to the faithful text, which is complete by construction. The tokens were still
        // spent, so they are still reported.
        if ('length' === $finishReason) {
            $this->logger->warning(\sprintf(
                'VectorStoreAutoUpdate: LLM polish for %s was truncated at the model output limit (%d completion tokens); using the unmodified page text instead.',
                $url,
                $tokensOut,
            ));

            return ['text' => '', 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut];
        }

        return [
            'text' => (string) ($data['choices'][0]['message']['content'] ?? ''),
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
        ];
    }

    /**
     * POST a chat-completion payload. Reads 4xx/5xx bodies instead of throwing
     * (throw: false) so the caller can inspect the error and decide whether to retry.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function postChatCompletion(string $apiKey, array $payload): array
    {
        $response = $this->http->request(
            'POST',
            self::OPENAI_BASE.'/chat/completions',
            [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 120,
            ],
        );

        return [$response->getStatusCode(), $response->toArray(throw: false)];
    }

    /**
     * Detects the specific 400 OpenAI returns when a model does not accept a custom
     * "temperature" (reasoning models only allow the default value).
     *
     * @param array<string, mixed> $data
     */
    private function isUnsupportedTemperatureError(array $data): bool
    {
        if ('temperature' === ($data['error']['param'] ?? null)) {
            return true;
        }

        $message = strtolower((string) ($data['error']['message'] ?? ''));

        return str_contains($message, 'temperature')
            && (
                str_contains($message, 'unsupported')
                || str_contains($message, 'does not support')
                || str_contains($message, 'not support')
                || str_contains($message, 'only the default')
            );
    }

    /**
     * Build the downloadable inspection document: a summary header plus every page's
     * uploaded content concatenated. This is NOT what gets uploaded (each page is its own
     * vector-store file now) - it exists only so operators can review what was indexed.
     *
     * Every page block names the vector-store file(s) that hold it and what happened to the
     * page in this run, so a file id seen in the OpenAI platform can be traced back to a
     * page (and vice versa) - the uploaded files themselves carry no such index.
     *
     * @param list<array{page_id: int, url: string, title: string, content: string}>                                                                   $pages
     * @param array{added: int, updated: int, removed: int, unchanged: int, files_uploaded: int, files_failed: int, deletes_pending?: int, bytes: int} $sync
     * @param array<int, array{state: string, files: list<string>}>                                                                                    $pageStates page_id => outcome + file ids of this run
     * @param array{total: int, dropped_policy: int, dropped_boilerplate: int}|null                                                                    $links      null = link collection disabled
     */
    private function buildManifest(array $pages, array $sync, array $pageStates, array|null $links = null, bool $crawlSkipped = false): string
    {
        $lines = [
            '# Vector store sync manifest',
            '',
            \sprintf(
                '- Pages indexed: %d | added: %d, updated: %d, unchanged: %d, removed: %d',
                \count($pages),
                $sync['added'],
                $sync['updated'],
                $sync['unchanged'],
                $sync['removed'],
            ),
            \sprintf('- Files uploaded: %d, failed: %d, bytes: %d', $sync['files_uploaded'], $sync['files_failed'], $sync['bytes']),
        ];

        // The manifest is where a file id is traced back to a page, which makes it the one
        // document worth reading when a file could not be deleted: it names the pages whose
        // content is still in the store despite having left the scope.
        if (($sync['deletes_pending'] ?? 0) > 0) {
            $lines[] = \sprintf(
                '- Files awaiting removal: %d - still attached to the vector store and still answerable. The next run retries them.',
                $sync['deletes_pending'],
            );
        }

        // Without this, an all-unchanged manifest with no uploads reads like a run that
        // failed to do anything, and there is nothing in the document to say otherwise.
        if ($crawlSkipped) {
            $lines[] = '- Search index: not rebuilt - the website was unchanged since the last crawl.';
        }

        if (null !== $links) {
            $lines[] = \sprintf(
                '- Links embedded: %d | removed as site chrome: %d, removed by type/exclude rules: %d',
                $links['total'],
                $links['dropped_boilerplate'],
                $links['dropped_policy'],
            );
        }

        $lines = [
            ...$lines,
            '',
            '_Every page below names the OpenAI vector-store file that holds it. Unchanged pages keep '
                .'the file an earlier run uploaded, which is why the file count is usually lower than '
                .'the page count. The same mapping is browsable in the backend under "OpenAI vector '
                .'store files"._',
            '',
            '---',
            '',
            // Blank line: the summary must be closed by the same "\n\n---\n\n" that separates
            // two page blocks, otherwise the first block is glued to the summary and cannot be
            // cut out again (that is how the backend serves a single page's indexed text).
            '',
        ];

        $manifest = implode("\n", $lines);

        // Hard cap so a large site cannot overflow the MEDIUMTEXT column (16,777,215
        // BYTES) and abort the log insert. Measured in bytes (strlen), not characters:
        // multi-byte UTF-8 content would otherwise blow past the column limit long
        // before the character count does. This document is only an inspection copy;
        // the full content lives in the vector store regardless.
        $maxBytes = 8_000_000;

        foreach ($pages as $page) {
            $title = '' !== trim($page['title']) ? $page['title'] : $page['url'];
            $block = '## '.$title."\nURL: ".$page['url']."\n"
                .$this->manifestFileLine($page['page_id'], $pageStates)
                ."\n\n".$page['content']."\n\n---\n\n";

            if (\strlen($manifest) + \strlen($block) > $maxBytes) {
                $manifest .= "\n_(Manifest truncated for storage; full content is in the vector store.)_\n";
                break;
            }

            $manifest .= $block;
        }

        return $manifest;
    }

    /**
     * One manifest line per page: what happened to it and which vector-store file(s) hold it.
     * A page with no recorded state (only possible if the sync never reached it) still shows
     * its id, so the block is never silently ambiguous.
     *
     * @param array<int, array{state: string, files: list<string>}> $pageStates
     */
    private function manifestFileLine(int $pageId, array $pageStates): string
    {
        // page_id 0 is the synthetic site-wide link directory, not a real Contao page.
        $line = 0 === $pageId ? 'Page ID: - (link directory)' : 'Page ID: '.$pageId;

        $state = $pageStates[$pageId] ?? null;

        if (null === $state) {
            return $line;
        }

        $line .= ' | Status: '.$state['state'];

        if ([] === $state['files']) {
            return $line."\nVector store file: - (not indexed)";
        }

        $count = \count($state['files']);

        if (1 === $count) {
            return $line."\nVector store file: ".$state['files'][0];
        }

        $parts = [];

        foreach ($state['files'] as $i => $fileId) {
            $parts[] = \sprintf('%s (part %d/%d)', $fileId, $i + 1, $count);
        }

        return $line."\nVector store files: ".implode(', ', $parts);
    }

    /**
     * @param string|null                                                                                                                                                                                                                                                            $fileId null = leave auto_update_file_id unchanged (failed runs must not
     *                                                                                                                                                                                                                                                                                       discard a still-uncleaned legacy file id)
     * @param array{pages?: int, items?: int, tokens_in?: int, tokens_out?: int, duration?: int, model?: string, document?: string, sync?: array{added: int, updated: int, removed: int, unchanged: int, files_uploaded: int, files_failed: int, deletes_pending?: int, bytes: int}} $stats
     */
    private function persistResult(int $configId, string $status, string|null $fileId, array $stats, string $message = '', string $triggerSource = self::SOURCE_CLI): void
    {
        $now = time();

        // Terminal state — clear the live progress so the dashboard never shows a stale
        // counter next to a finished run.
        if (null === $fileId) {
            $this->connection->executeStatement(
                'UPDATE tl_openai_config SET auto_update_last_run = ?, auto_update_last_status = ?, auto_update_last_message = ?, '.self::PROGRESS_RESET_SQL.' WHERE id = ?',
                [$now, $status, '' !== $message ? $message : null, $configId],
            );
        } else {
            $this->connection->executeStatement(
                'UPDATE tl_openai_config SET auto_update_last_run = ?, auto_update_last_status = ?, auto_update_file_id = ?, auto_update_last_message = ?, '.self::PROGRESS_RESET_SQL.' WHERE id = ?',
                [$now, $status, $fileId, '' !== $message ? $message : null, $configId],
            );
        }

        $document = (string) ($stats['document'] ?? '');
        $sync = $stats['sync'] ?? ['added' => 0, 'updated' => 0, 'removed' => 0, 'unchanged' => 0, 'files_uploaded' => 0, 'files_failed' => 0, 'bytes' => 0];

        $this->connection->insert('tl_openai_sync_log', [
            'pid' => $configId,
            'tstamp' => $now,
            'run_at' => $now,
            'status' => $status,
            'trigger_source' => $triggerSource,
            'model' => (string) ($stats['model'] ?? ''),
            'pages' => $stats['pages'] ?? 0,
            'items' => $stats['items'] ?? 0,
            'tokens_in' => $stats['tokens_in'] ?? 0,
            'tokens_out' => $stats['tokens_out'] ?? 0,
            'file_id' => $fileId ?? '',
            'duration' => $stats['duration'] ?? 0,
            'pages_added' => $sync['added'],
            'pages_updated' => $sync['updated'],
            'pages_removed' => $sync['removed'],
            'pages_unchanged' => $sync['unchanged'],
            'files_uploaded' => $sync['files_uploaded'],
            'files_failed' => $sync['files_failed'],
            'bytes' => $sync['bytes'],
            // The inspection manifest, kept so operators can download/review exactly what
            // was indexed. OpenAI blocks downloading purpose=assistants files, so this
            // local copy is the only way to see the indexed content.
            'document' => '' !== $document ? $document : null,
            'message' => '' !== $message ? $message : null,
        ]);

        $this->pruneSyncLog($configId);
    }

    /**
     * Trim tl_openai_sync_log to the newest SYNC_LOG_KEEP_ROWS rows for one config,
     * deleting older rows (and their large manifest blobs). Uses a single OFFSET probe
     * plus a bounded DELETE so it is cheap even on long histories; a no-op while the
     * config has fewer rows than the cap.
     */
    private function pruneSyncLog(int $configId): void
    {
        // OFFSET cannot use a bound parameter on MySQL/MariaDB (it is quoted as a string).
        $cutoffId = $this->connection->fetchOne(
            \sprintf(
                'SELECT id FROM tl_openai_sync_log WHERE pid = ? ORDER BY id DESC LIMIT 1 OFFSET %d',
                self::SYNC_LOG_KEEP_ROWS,
            ),
            [$configId],
        );

        if (false === $cutoffId || null === $cutoffId) {
            return;
        }

        $this->connection->executeStatement(
            'DELETE FROM tl_openai_sync_log WHERE pid = ? AND id <= ?',
            [$configId, (int) $cutoffId],
        );
    }
}
