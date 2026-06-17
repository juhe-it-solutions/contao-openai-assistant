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
     * Unix time of the last lease refresh in the current run; reset by markRunning().
     */
    private int $lastHeartbeatAt = 0;

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

        $config = $this->connection->fetchAssociative(
            "SELECT auto_update_last_status, auto_update_last_run FROM tl_openai_config WHERE id = ? AND auto_update_enabled = '1'",
            [$configId],
        );

        if (!$config) {
            throw new \RuntimeException('MSC.vsau_err_sync_not_enabled');
        }

        if (!$this->licenseValidation->isLicenseActive($configId)) {
            throw new \RuntimeException('MSC.vsau_err_no_license');
        }

        $status = (string) ($config['auto_update_last_status'] ?? '');
        $lastRun = (int) ($config['auto_update_last_run'] ?? 0);

        // Block only while a sync is genuinely in flight. A crashed run can otherwise leave a
        // stale "running"/"queued" status forever - and in manual-only mode there is no cron
        // to clear it - so treat a status older than the stale window as finished.
        if (\in_array($status, ['running', 'queued'], true) && (time() - $lastRun) < self::STALE_RUN_SECONDS) {
            throw new \RuntimeException('MSC.vsau_err_sync_already_running');
        }

        $this->connection->executeStatement(
            "UPDATE tl_openai_config SET auto_update_last_run = ?, auto_update_last_status = 'queued', auto_update_last_message = ? WHERE id = ?",
            [time(), 'MSC.vsau_dispatched_manual', $configId],
        );

        try {
            $process = $this->processUtil->createSymfonyConsoleProcess(
                'contao:openai-vector-sync',
                (string) $configId,
                '--source='.self::SOURCE_MANUAL,
                '--no-interaction',
            );
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
     * Full sync flow for a single configuration record. Never throws - failures are
     * persisted as an "error" status + message in tl_openai_config / tl_openai_sync_log.
     */
    public function run(int $configId, string $triggerSource = self::SOURCE_CLI): void
    {
        if (!\in_array($triggerSource, self::SOURCES, true)) {
            $triggerSource = self::SOURCE_CLI;
        }

        // Guard against running before contao:migrate has created the extension tables
        // (e.g. CLI command invoked on a fresh install before the install wizard finishes).
        if (!$this->connection->createSchemaManager()->tablesExist(['tl_openai_config'])) {
            $this->logger->notice('VectorStoreAutoUpdate skipped for config '.$configId.': extension tables not yet created (run contao:migrate).');

            return;
        }

        $start = time();
        $model = '';

        try {
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

                return;
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

            $this->markRunning($configId);
            $this->spawnCrawl($configId);

            // Plan-based page cap: enforce the subscription limit at runtime so a
            // downgrade immediately shrinks the sync scope without requiring the admin to
            // re-save their site-root selection (BUG-06).
            $planPageLimit = (int) ($config['premium_license_max_pages'] ?? 0);

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

            foreach ($byPage as $page) {
                $content = implode("\n\n", $page['contents']);

                if (self::MODE_LLM_POLISH === $mode) {
                    $polished = $this->polishPage($apiKey, $model, $page['title'], $page['url'], $content, $promptTpl);
                    $tokensIn += $polished['tokens_in'];
                    $tokensOut += $polished['tokens_out'];
                    // Never drop a page: fall back to the faithful text if the LLM returns nothing.
                    $content = '' !== trim($polished['text']) ? $polished['text'] : $content;
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
                function () use ($configId): void {
                    $this->heartbeat($configId);
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
        } catch (\Throwable $e) {
            $this->logger->error('VectorStoreAutoUpdate failed for config '.$configId.': '.$e->getMessage());
            $this->persistResult(
                $configId,
                'error',
                '',
                [
                    'duration' => time() - $start,
                    'model' => $model,
                ],
                $e->getMessage(),
                $triggerSource,
            );
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

    private function markRunning(int $configId): void
    {
        $now = time();
        $this->connection->executeStatement(
            "UPDATE tl_openai_config SET auto_update_last_run = ?, auto_update_last_status = 'running', auto_update_last_message = NULL WHERE id = ?",
            [$now, $configId],
        );
        // The lease was just written; the next refresh is not due for HEARTBEAT_INTERVAL.
        $this->lastHeartbeatAt = $now;
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
            $pageIds = [];

            foreach ($selectedPageIds as $selectedPageId) {
                $page = $this->connection->fetchAssociative(
                    'SELECT id FROM tl_page WHERE id = ?',
                    [$selectedPageId],
                );

                if (!$page) {
                    throw new \RuntimeException('MSC.vsau_err_invalid_page|'.$selectedPageId);
                }

                $pageIds[] = (int) $selectedPageId;
            }
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

        // Hard cap so a large site cannot overflow the MEDIUMTEXT column (~16 MB) and abort
        // the log insert. This document is only an inspection copy; the full content lives in
        // the vector store regardless.
        $maxChars = 8_000_000;

        foreach ($pages as $page) {
            $title = '' !== trim($page['title']) ? $page['title'] : $page['url'];
            $block = '## '.$title."\nURL: ".$page['url']."\n\n".$page['content']."\n\n---\n\n";

            if (mb_strlen($manifest) + mb_strlen($block) > $maxChars) {
                $manifest .= "\n_(Manifest truncated for storage; full content is in the vector store.)_\n";
                break;
            }

            $manifest .= $block;
        }

        return $manifest;
    }

    /**
     * @param array{pages?: int, tokens_in?: int, tokens_out?: int, duration?: int, model?: string, document?: string, sync?: array{added: int, updated: int, removed: int, unchanged: int, files_uploaded: int, files_failed: int, bytes: int}} $stats
     */
    private function persistResult(int $configId, string $status, string $fileId, array $stats, string $message = '', string $triggerSource = self::SOURCE_CLI): void
    {
        $now = time();

        $this->connection->executeStatement(
            'UPDATE tl_openai_config SET auto_update_last_run = ?, auto_update_last_status = ?, auto_update_file_id = ?, auto_update_last_message = ? WHERE id = ?',
            [$now, $status, $fileId, '' !== $message ? $message : null, $configId],
        );

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
            'file_id' => $fileId,
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
