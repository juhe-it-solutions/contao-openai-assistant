<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) JUHE IT-solutions
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Service;

use Contao\CoreBundle\Util\ProcessUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
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
     * Clears the live progress columns; appended to run-state UPDATEs when a run
     * starts or reaches a terminal state, so the dashboard never shows a stale counter.
     */
    private const PROGRESS_RESET_SQL = "auto_update_progress_phase = '', auto_update_progress_current = 0, auto_update_progress_total = 0";

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

        if (!$this->licenseValidation->isLicenseActive($configId)) {
            throw new \RuntimeException('MSC.vsau_err_no_license');
        }

        $now = time();
        $queued = $this->connection->executeStatement(
            "UPDATE tl_openai_config
                SET auto_update_last_run = ?, auto_update_last_status = 'queued', auto_update_last_message = ?
                WHERE id = ?
                    AND auto_update_enabled = '1'
                    AND (
                        COALESCE(auto_update_last_status, '') NOT IN ('running', 'queued')
                        OR COALESCE(auto_update_last_run, 0) < ?
                    )",
            [$now, 'MSC.vsau_dispatched_manual', $configId, $now - self::STALE_RUN_SECONDS],
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

        // Guard against running before contao:migrate has created the extension tables
        // (e.g. CLI command invoked on a fresh install before the install wizard finishes)
        // or before it has added the progress columns after a bundle update — the run-state
        // UPDATEs below reference them, and run() must never throw.
        $schemaManager = $this->connection->createSchemaManager();
        if (
            !$schemaManager->tablesExist(['tl_openai_config'])
            || !isset($schemaManager->listTableColumns('tl_openai_config')['auto_update_progress_phase'])
        ) {
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
            $promptTpl = $config['auto_update_prompt_template'] ?? null ?: null;
            $legacyFileId = (string) ($config['auto_update_file_id'] ?? '');

            // Crawling has no page total yet — phase-only progress ("crawling…").
            $this->progress($configId, 'crawl', 0, 0);
            $this->spawnCrawl($configId);

            // Plan-based page cap: enforce the subscription limit at runtime so a
            // downgrade immediately shrinks the sync scope without requiring the admin to
            // re-save their site-root selection (BUG-06). Resolved through the same
            // helper as the save-time enforcement, so a missing max_crawl_pages value
            // falls back to the plan default instead of silently meaning "unlimited".
            $planPageLimit = LicenseValidationService::resolvePageLimit(
                (string) ($config['premium_license_plan'] ?? ''),
                (int) ($config['premium_license_max_pages'] ?? 0),
            ) ?? 0;

            $rows = $this->readAllPages($configId, $planPageLimit);
            if (0 === \count($rows)) {
                throw new \RuntimeException('MSC.vsau_err_no_indexed_pages');
            }

            // Safe boilerplate removal: only strips text repeated across many pages.
            $texts = [];

            foreach ($rows as $i => $row) {
                $texts[$i] = (string) $row['text'];
            }
            $clean = $this->boilerplate->clean($texts);

            // Aggregate by page id: a page can be indexed under several URLs (e.g. paginated
            // readers), producing multiple tl_search rows. We want exactly one document per
            // page, so the cleaned text of all its rows is concatenated.
            $byPage = [];

            foreach ($rows as $i => $row) {
                $content = trim($clean['texts'][$i] ?? '');
                if ('' === $content) {
                    // Pure chrome collapses to nothing after de-dup - carries no information.
                    continue;
                }

                $pageId = (int) $row['page_id'];
                if (!isset($byPage[$pageId])) {
                    $byPage[$pageId] = [
                        'page_id' => $pageId,
                        'url' => (string) $row['url'],
                        'title' => (string) $row['title'],
                        'language' => (string) $row['language'],
                        'contents' => [],
                        'checksums' => [],
                    ];
                }

                $byPage[$pageId]['contents'][] = $content;
                $byPage[$pageId]['checksums'][] = (string) ($row['checksum'] ?? '');
            }

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

            foreach ($byPage as $page) {
                $content = implode("\n\n", $page['contents']);

                if (self::MODE_LLM_POLISH === $mode) {
                    $polished = $this->polishPage($apiKey, $model, $page['title'], $page['url'], $content, $promptTpl);
                    $tokensIn += $polished['tokens_in'];
                    $tokensOut += $polished['tokens_out'];
                    // Never drop a page: fall back to the faithful text if the LLM returns nothing.
                    $content = '' !== trim($polished['text']) ? $polished['text'] : $content;
                    // Progress doubles as the lease refresh here (it writes the heartbeat too).
                    ++$polishDone;
                    $this->progress($configId, 'polish', $polishDone, $polishTotal);
                }

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

            $status = $syncStats['files_failed'] > 0 ? 'partial' : 'success';

            $this->persistResult(
                $configId,
                $status,
                '',
                // per-page mode has no single file id
                [
                    'pages' => \count($pages),
                    'tokens_in' => $tokensIn,
                    'tokens_out' => $tokensOut,
                    'duration' => time() - $start,
                    'model' => $model,
                    'document' => $this->buildManifest($pages, $syncStats),
                    'sync' => $syncStats,
                ],
                '',
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
    public function countScopePages(mixed $configValue): int
    {
        $selectedPageIds = self::parseConfiguredPageIds($configValue);

        if ([] !== $selectedPageIds) {
            return \count($selectedPageIds);
        }

        $roots = $this->connection->fetchAllAssociative(
            "SELECT id FROM tl_page WHERE type = 'root' AND dns != ''",
        );

        if (1 !== \count($roots)) {
            return 0;
        }

        return \count(array_unique($this->collectPageSubtreeIds((int) $roots[0]['id'])));
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

        $updated = $this->connection->executeStatement(
            "UPDATE tl_openai_config
                SET auto_update_last_run = ?, auto_update_last_status = 'running', auto_update_last_message = NULL, ".self::PROGRESS_RESET_SQL.'
                WHERE id = ? AND '.$statusPredicate,
            [$now, $configId, $staleBefore],
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

    private function spawnCrawl(int $configId): void
    {
        $process = $this->processUtil->createSymfonyConsoleProcess(
            'contao:crawl',
            '--subscribers=search-index',
            '--no-interaction',
        );

        // Poll instead of a blocking wait() so the lease keeps refreshing during a long crawl
        // (a few thousand pages can take many minutes). No process timeout: the crawl must run
        // to completion, however long it legitimately takes.
        $process->setTimeout(null);
        $process->start();

        while ($process->isRunning()) {
            $this->heartbeat($configId);
            usleep(2_000_000);
        }

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('MSC.vsau_err_crawl_failed|'.$process->getErrorOutput());
        }
    }

    /**
     * Read tl_search rows scoped to the configured page selection.
     *
     * Explicitly selected pages are used as-is (no subpages implied). An empty
     * selection falls back to the whole website when exactly one site root exists.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readAllPages(int $configId, int $planPageLimit = 0): array
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
            $roots = $this->connection->fetchAllAssociative(
                "SELECT id FROM tl_page WHERE type = 'root' AND dns != ''",
            );

            if (1 === \count($roots)) {
                $pageIds = $this->collectPageSubtreeIds((int) $roots[0]['id']);
            } elseif (\count($roots) > 1) {
                throw new \RuntimeException('MSC.vsau_err_multiple_roots');
            } else {
                return [];
            }
        }

        $pageIds = array_values(array_unique($pageIds));

        if ([] === $pageIds) {
            return [];
        }

        // Apply the subscription plan page cap. A 0 limit means unlimited (enterprise).
        if ($planPageLimit > 0 && \count($pageIds) > $planPageLimit) {
            $this->logger->notice(\sprintf(
                'VectorStoreAutoUpdate: plan page limit %d applied for config %d (scope was %d pages).',
                $planPageLimit,
                $configId,
                \count($pageIds),
            ));
            $pageIds = \array_slice($pageIds, 0, $planPageLimit);
        }

        return $this->connection->fetchAllAssociative(
            'SELECT s.pid AS page_id, s.url, s.title, s.text, s.language, s.checksum
             FROM tl_search s
             WHERE s.pid IN (?)
             ORDER BY s.pid, s.url',
            [$pageIds],
            [ArrayParameterType::INTEGER],
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

        return [
            'text' => (string) ($data['choices'][0]['message']['content'] ?? ''),
            'tokens_in' => (int) ($data['usage']['prompt_tokens'] ?? 0),
            'tokens_out' => (int) ($data['usage']['completion_tokens'] ?? 0),
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
     * @param list<array{page_id: int, url: string, title: string, content: string}>                                            $pages
     * @param array{added: int, updated: int, removed: int, unchanged: int, files_uploaded: int, files_failed: int, bytes: int} $sync
     */
    private function buildManifest(array $pages, array $sync): string
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
            '',
            '---',
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
            $block = '## '.$title."\nURL: ".$page['url']."\n\n".$page['content']."\n\n---\n\n";

            if (\strlen($manifest) + \strlen($block) > $maxBytes) {
                $manifest .= "\n_(Manifest truncated for storage; full content is in the vector store.)_\n";
                break;
            }

            $manifest .= $block;
        }

        return $manifest;
    }

    /**
     * @param string|null                                                                                                                                                                                                                        $fileId null = leave auto_update_file_id unchanged (failed runs must not
     *                                                                                                                                                                                                                                                   discard a still-uncleaned legacy file id)
     * @param array{pages?: int, tokens_in?: int, tokens_out?: int, duration?: int, model?: string, document?: string, sync?: array{added: int, updated: int, removed: int, unchanged: int, files_uploaded: int, files_failed: int, bytes: int}} $stats
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
    }
}
