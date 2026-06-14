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
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Orchestrates an automatic vector store update:
 *   crawl → read search index → LLM summary → replace file in vector store.
 *
 * run() is invoked by the cron job and by the contao:openai-vector-sync command. The
 * backend manual trigger calls dispatchRun() (non-blocking CLI dispatch) only, never
 * run() inline — the crawl + LLM call can take minutes (constraint C4).
 */
class VectorStoreAutoUpdateService
{
    private const OPENAI_BASE = 'https://api.openai.com/v1';

    /** Hard cap on pages crawled per sync to limit DB load and LLM cost abuse. */
    private const MAX_CRAWL_PAGES = 5000;

    /** What triggered a sync — persisted to tl_openai_sync_log.trigger_source. */
    public const SOURCE_CRON = 'cron';     // automatic, via the schedule/heartbeat
    public const SOURCE_MANUAL = 'manual'; // backend "Run sync now" button
    public const SOURCE_CLI = 'cli';       // operator-run console command

    /** @var list<string> */
    public const SOURCES = [self::SOURCE_CRON, self::SOURCE_MANUAL, self::SOURCE_CLI];

    public function __construct(
        private readonly Connection $connection,
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly EncryptionService $encryption,
        private readonly ProcessUtil $processUtil,
        private readonly LicenseValidationService $licenseValidation,
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
            "SELECT auto_update_last_status FROM tl_openai_config WHERE id = ? AND auto_update_enabled = '1'",
            [$configId],
        );

        if (!$config) {
            throw new \RuntimeException('MSC.vsau_err_sync_not_enabled');
        }

        if (!$this->licenseValidation->isLicenseActive($configId)) {
            throw new \RuntimeException('MSC.vsau_err_no_license');
        }

        $status = (string) ($config['auto_update_last_status'] ?? '');
        if (\in_array($status, ['running', 'queued'], true)) {
            throw new \RuntimeException('MSC.vsau_err_sync_already_running');
        }

        $this->connection->executeStatement(
            "UPDATE tl_openai_config SET auto_update_last_status = 'queued', auto_update_last_message = ? WHERE id = ?",
            ['MSC.vsau_dispatched_manual', $configId],
        );

        $process = $this->processUtil->createSymfonyConsoleProcess(
            'contao:openai-vector-sync',
            (string) $configId,
            '--source='.self::SOURCE_MANUAL,
            '--no-interaction',
        );
        $process->start(); // non-blocking — do NOT call wait()
    }

    /**
     * Full sync flow for a single configuration record. Never throws — failures are
     * persisted as an "error" status + message in tl_openai_config / tl_openai_sync_log.
     */
    public function run(int $configId, string $triggerSource = self::SOURCE_CLI): void
    {
        if (!\in_array($triggerSource, self::SOURCES, true)) {
            $triggerSource = self::SOURCE_CLI;
        }

        $start = time();
        $oldFileId = '';
        $model = '';

        try {
            // License gate — silent no-op if not active.
            if (!$this->licenseValidation->isLicenseActive($configId)) {
                $this->logger->notice('VectorStoreAutoUpdate skipped for config '.$configId.': no active premium license.');

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

            $oldFileId = (string) ($config['auto_update_file_id'] ?? '');
            $rawMode = (bool) ($config['auto_update_raw_mode'] ?? false);
            // Raw mode uploads the extracted page text as-is — no model is involved,
            // so record an empty model (the log shows "–" instead of a model name).
            $model = $rawMode ? '' : ((string) ($config['auto_update_model'] ?? '') ?: 'gpt-4o-mini');
            $maxContent = max(1000, min(500000, (int) ($config['auto_update_max_content'] ?? 100000)));
            $promptTpl = $config['auto_update_prompt_template'] ?? null ?: null;

            $this->markRunning($configId);
            $this->spawnCrawl();

            $rows = $this->readSearchIndex($configId);
            if (0 === \count($rows)) {
                throw new \RuntimeException('MSC.vsau_err_no_indexed_pages');
            }

            $input = $this->buildLlmInput($rows, $maxContent);

            if ($rawMode) {
                // Power-user mode: skip the LLM optimisation and push the cleaned-up
                // page text straight to the vector store. No tokens spent.
                $documentText = $input;
                $tokensIn = 0;
                $tokensOut = 0;
            } else {
                $result = $this->generateDocument($apiKey, $model, $input, $promptTpl);
                $documentText = $result['text'];
                $tokensIn = $result['tokens_in'];
                $tokensOut = $result['tokens_out'];
            }

            if ('' === trim($documentText)) {
                throw new \RuntimeException(
                    $rawMode ? 'MSC.vsau_err_empty_document_raw' : 'MSC.vsau_err_empty_document_llm',
                );
            }

            $this->deleteOldFile($apiKey, $vectorStoreId, $oldFileId);
            $newFileId = $this->uploadFile($apiKey, $documentText);
            $this->attachToVectorStore($apiKey, $vectorStoreId, $newFileId);

            $this->persistResult(
                $configId,
                'success',
                $newFileId,
                [
                    'pages' => \count($rows),
                    'tokens_in' => $tokensIn,
                    'tokens_out' => $tokensOut,
                    'duration' => time() - $start,
                    'model' => $model,
                    'document' => $documentText,
                ],
                '',
                $triggerSource,
            );
        } catch (\Throwable $e) {
            $this->logger->error('VectorStoreAutoUpdate failed for config '.$configId.': '.$e->getMessage());
            $this->persistResult(
                $configId,
                'error',
                $oldFileId,
                [
                    'duration' => time() - $start,
                    'model' => $model,
                ],
                $e->getMessage(),
                $triggerSource,
            );
        }
    }

    private function markRunning(int $configId): void
    {
        $this->connection->executeStatement(
            "UPDATE tl_openai_config SET auto_update_last_run = ?, auto_update_last_status = 'running', auto_update_last_message = NULL WHERE id = ?",
            [time(), $configId],
        );
    }

    private function spawnCrawl(): void
    {
        $process = $this->processUtil->createSymfonyConsoleProcess(
            'contao:crawl',
            '--subscribers=search-index',
            '--no-interaction',
        );
        $promise = $this->processUtil->createPromise($process);
        $promise->wait(); // blocks until the crawl finishes

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
    private function readSearchIndex(int $configId): array
    {
        $config = $this->connection->fetchAssociative(
            'SELECT auto_update_site_root FROM tl_openai_config WHERE id = ?',
            [$configId],
        );
        $selectedPageIds = self::parseConfiguredPageIds($config['auto_update_site_root'] ?? null);

        if ([] !== $selectedPageIds) {
            // Exact selection — only the pages the admin picked, no subpages implied.
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
            // Empty selection — fall back to the whole website (single site root + subtree).
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

        return $this->connection->fetchAllAssociative(
            'SELECT s.url, s.title, s.text, s.language
             FROM tl_search s
             WHERE s.pid IN (?)
             ORDER BY s.pid, s.url',
            [$pageIds],
            [ArrayParameterType::INTEGER],
        );
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
     * @param array<int, array<string, mixed>> $rows
     */
    private function buildLlmInput(array $rows, int $maxChars): string
    {
        $parts = [];
        $total = 0;

        foreach ($rows as $row) {
            $block = \sprintf("## %s\nURL: %s\n\n%s", (string) $row['title'], (string) $row['url'], (string) $row['text']);
            $len = mb_strlen($block);

            if ($total + $len > $maxChars) {
                break; // truncate at a page boundary, not mid-text
            }

            $parts[] = $block;
            $total += $len;
        }

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * @return array{text: string, tokens_in: int, tokens_out: int}
     */
    private function generateDocument(string $apiKey, string $model, string $pageContent, string|null $promptTemplate): array
    {
        $systemPrompt = $promptTemplate ?? VectorStoreDocumentPrompt::DEFAULT_TEMPLATE;

        // A low temperature keeps the factual summary deterministic. Reasoning models
        // (o-series, gpt-5 reasoning, …) reject a custom temperature, however. Rather
        // than maintain a model allow-list, send 0.3 and — if the API rejects it —
        // retry once without the parameter, so any current or future model still works.
        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => $pageContent,
                ],
            ],
            'temperature' => 0.3,
        ];

        [$status, $data] = $this->postChatCompletion($apiKey, $payload);

        if ($status >= 400 && $this->isUnsupportedTemperatureError($data)) {
            unset($payload['temperature']);
            [$status, $data] = $this->postChatCompletion($apiKey, $payload);
        }

        if ($status < 200 || $status >= 300) {
            $apiMessage = (string) ($data['error']['message'] ?? 'unknown error');

            throw new \RuntimeException('MSC.vsau_err_openai_chat|'.$status.'|'.$apiMessage);
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

    private function deleteOldFile(string $apiKey, string $vectorStoreId, string $oldFileId): void
    {
        if ('' === $oldFileId) {
            return;
        }

        // Remove from the vector store first.
        try {
            $this->http->request(
                'DELETE',
                self::OPENAI_BASE."/vector_stores/{$vectorStoreId}/files/{$oldFileId}",
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                        'OpenAI-Beta' => 'assistants=v2',
                    ],
                    'timeout' => 30,
                ],
            )->getStatusCode();
        } catch (\Throwable $e) {
            $this->logger->warning('Could not detach old file from vector store: '.$e->getMessage());
        }

        // Then delete the file itself.
        try {
            $this->http->request(
                'DELETE',
                self::OPENAI_BASE."/files/{$oldFileId}",
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                    ],
                    'timeout' => 30,
                ],
            )->getStatusCode();
        } catch (\Throwable $e) {
            $this->logger->warning('Could not delete old file from OpenAI Files: '.$e->getMessage());
        }
    }

    private function uploadFile(string $apiKey, string $markdownContent): string
    {
        // Symfony HttpClient multipart needs a real, READABLE stream — write to a temp
        // file and let the client read it back. Mode 'x+b' = read/write + exclusive
        // create (a local attacker cannot pre-create / symlink the path); the handle must
        // be readable, otherwise the multipart body reader fails with "Bad file
        // descriptor". The .md extension is kept so OpenAI detects the file type.
        $tmpPath = sys_get_temp_dir().'/contao_vs_autoupdate_'.bin2hex(random_bytes(16)).'.md';

        $handle = @fopen($tmpPath, 'x+b');
        if (false === $handle) {
            throw new \RuntimeException('MSC.vsau_err_temp_file');
        }

        try {
            fwrite($handle, $markdownContent);
            rewind($handle);

            $response = $this->http->request(
                'POST',
                self::OPENAI_BASE.'/files',
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                    ],
                    'body' => [
                        'purpose' => 'assistants',
                        'file' => $handle,
                    ],
                    'timeout' => 120,
                ],
            );

            $id = (string) ($response->toArray()['id'] ?? '');
            if ('' === $id) {
                throw new \RuntimeException('MSC.vsau_err_upload_no_id');
            }

            return $id;
        } finally {
            if (\is_resource($handle)) {
                fclose($handle);
            }

            @unlink($tmpPath);
        }
    }

    private function attachToVectorStore(string $apiKey, string $vectorStoreId, string $fileId): void
    {
        $this->http->request(
            'POST',
            self::OPENAI_BASE."/vector_stores/{$vectorStoreId}/files",
            [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                    'OpenAI-Beta' => 'assistants=v2',
                ],
                'json' => [
                    'file_id' => $fileId,
                ],
                'timeout' => 60,
            ],
        )->getStatusCode();
    }

    /**
     * @param array{pages?: int, tokens_in?: int, tokens_out?: int, duration?: int, model?: string, document?: string} $stats
     */
    private function persistResult(int $configId, string $status, string $fileId, array $stats, string $message = '', string $triggerSource = self::SOURCE_CLI): void
    {
        $now = time();

        $this->connection->executeStatement(
            'UPDATE tl_openai_config SET auto_update_last_run = ?, auto_update_last_status = ?, auto_update_file_id = ?, auto_update_last_message = ? WHERE id = ?',
            [$now, $status, $fileId, '' !== $message ? $message : null, $configId],
        );

        $document = (string) ($stats['document'] ?? '');

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
            // The generated markdown, kept so operators can download/inspect exactly
            // what was pushed. OpenAI blocks downloading purpose=assistants files, so
            // this local copy is the only way to see the document content.
            'document' => '' !== $document ? $document : null,
            'message' => '' !== $message ? $message : null,
        ]);
    }
}
