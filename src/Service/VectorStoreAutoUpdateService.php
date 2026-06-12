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
    private const MAX_CRAWL_PAGES = 2000;

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
            throw new \RuntimeException('Automatic sync is not enabled for this configuration.');
        }

        if (!$this->licenseValidation->isLicenseActive($configId)) {
            throw new \RuntimeException('No active premium license.');
        }

        $status = (string) ($config['auto_update_last_status'] ?? '');
        if (\in_array($status, ['running', 'queued'], true)) {
            throw new \RuntimeException('A sync is already queued or running for this configuration.');
        }

        $this->connection->executeStatement(
            "UPDATE tl_openai_config SET auto_update_last_status = 'queued', auto_update_last_message = ? WHERE id = ?",
            ['Manual sync dispatched to CLI. Refresh this page in a few minutes.', $configId],
        );

        $process = $this->processUtil->createSymfonyConsoleProcess(
            'contao:openai-vector-sync',
            (string) $configId,
            '--no-interaction',
        );
        $process->start(); // non-blocking — do NOT call wait()
    }

    /**
     * Full sync flow for a single configuration record. Never throws — failures are
     * persisted as an "error" status + message in tl_openai_config / tl_openai_sync_log.
     */
    public function run(int $configId): void
    {
        $start = time();
        $oldFileId = '';

        try {
            // License gate — silent no-op if not active.
            if (!$this->licenseValidation->isLicenseActive($configId)) {
                $this->logger->notice('VectorStoreAutoUpdate skipped for config '.$configId.': no active premium license.');

                return;
            }

            $config = $this->connection->fetchAssociative('SELECT * FROM tl_openai_config WHERE id = ?', [$configId]);
            if (!$config) {
                throw new \RuntimeException('OpenAI configuration '.$configId.' not found.');
            }

            $apiKey = $this->encryption->getApiKeyForConfig($configId);
            if (!$apiKey) {
                throw new \RuntimeException('No usable OpenAI API key for configuration '.$configId.'.');
            }

            $vectorStoreId = (string) ($config['vector_store_id'] ?? '');
            if ('' === $vectorStoreId) {
                throw new \RuntimeException('No vector store ID configured. Complete the file upload workflow or set a vector store ID first.');
            }

            $oldFileId = (string) ($config['auto_update_file_id'] ?? '');
            $model = (string) ($config['auto_update_model'] ?? '') ?: 'gpt-4o-mini';
            $maxContent = max(1000, min(500000, (int) ($config['auto_update_max_content'] ?? 100000)));
            $promptTpl = $config['auto_update_prompt_template'] ?? null ?: null;

            $this->markRunning($configId);
            $this->spawnCrawl();

            $rows = $this->readSearchIndex($configId);
            if (0 === \count($rows)) {
                throw new \RuntimeException('tl_search is empty. Run a search re-index in the Contao backend (System → Maintenance) before enabling auto-update.');
            }

            $input = $this->buildLlmInput($rows, $maxContent);
            $result = $this->generateDocument($apiKey, $model, $input, $promptTpl);

            if ('' === trim($result['text'])) {
                throw new \RuntimeException('The model returned an empty document; aborting before replacing the existing file.');
            }

            $this->deleteOldFile($apiKey, $vectorStoreId, $oldFileId);
            $newFileId = $this->uploadFile($apiKey, $result['text']);
            $this->attachToVectorStore($apiKey, $vectorStoreId, $newFileId);

            $this->persistResult(
                $configId,
                'success',
                $newFileId,
                [
                    'pages' => \count($rows),
                    'tokens_in' => $result['tokens_in'],
                    'tokens_out' => $result['tokens_out'],
                    'duration' => time() - $start,
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->error('VectorStoreAutoUpdate failed for config '.$configId.': '.$e->getMessage());
            $this->persistResult(
                $configId,
                'error',
                $oldFileId,
                [
                    'duration' => time() - $start,
                ],
                $e->getMessage(),
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
            throw new \RuntimeException('contao:crawl failed: '.$process->getErrorOutput());
        }
    }

    /**
     * Read tl_search rows scoped to the configured (or auto-detected) site root.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readSearchIndex(int $configId): array
    {
        $config = $this->connection->fetchAssociative(
            'SELECT auto_update_site_root FROM tl_openai_config WHERE id = ?',
            [$configId],
        );
        $startPageId = (int) ($config['auto_update_site_root'] ?? 0);

        if ($startPageId <= 0) {
            $roots = $this->connection->fetchAllAssociative(
                "SELECT id FROM tl_page WHERE type = 'root' AND dns != ''",
            );

            if (1 === \count($roots)) {
                $startPageId = (int) $roots[0]['id'];
            } elseif (\count($roots) > 1) {
                throw new \RuntimeException('Multiple site roots detected. Select a crawl start page in OpenAI Configuration → Automatic vector store sync.');
            } else {
                return [];
            }
        }

        $page = $this->connection->fetchAssociative(
            'SELECT id, type FROM tl_page WHERE id = ?',
            [$startPageId],
        );

        if (!$page) {
            throw new \RuntimeException('Invalid crawl start page selected for auto-update.');
        }

        $pageIds = $this->collectPageSubtreeIds($startPageId);

        if ([] === $pageIds) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            'SELECT s.url, s.title, s.text, s.language
             FROM tl_search s
             WHERE s.pid IN (?)
             ORDER BY s.pid, s.url',
            [$pageIds],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
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

        $response = $this->http->request(
            'POST',
            self::OPENAI_BASE.'/chat/completions',
            [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
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
                ],
                'timeout' => 120,
            ],
        );

        $data = $response->toArray();

        return [
            'text' => (string) ($data['choices'][0]['message']['content'] ?? ''),
            'tokens_in' => (int) ($data['usage']['prompt_tokens'] ?? 0),
            'tokens_out' => (int) ($data['usage']['completion_tokens'] ?? 0),
        ];
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
        // Symfony HttpClient multipart needs a real stream — write to a temp file. Use
        // a cryptographically random name and exclusive create ('xb') so a local
        // attacker cannot pre-create / symlink the path (the .md extension is kept so
        // OpenAI detects the file type). The handle is reused for both write and upload.
        $tmpPath = sys_get_temp_dir().'/contao_vs_autoupdate_'.bin2hex(random_bytes(16)).'.md';

        $handle = @fopen($tmpPath, 'x');
        if (false === $handle) {
            throw new \RuntimeException('Could not create a temporary file for the upload.');
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
                throw new \RuntimeException('OpenAI Files upload did not return a file ID.');
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
     * @param array{pages?: int, tokens_in?: int, tokens_out?: int, duration?: int} $stats
     */
    private function persistResult(int $configId, string $status, string $fileId, array $stats, string $message = ''): void
    {
        $now = time();

        $this->connection->executeStatement(
            'UPDATE tl_openai_config SET auto_update_last_run = ?, auto_update_last_status = ?, auto_update_file_id = ?, auto_update_last_message = ? WHERE id = ?',
            [$now, $status, $fileId, '' !== $message ? $message : null, $configId],
        );

        $this->connection->insert('tl_openai_sync_log', [
            'pid' => $configId,
            'tstamp' => $now,
            'run_at' => $now,
            'status' => $status,
            'pages' => $stats['pages'] ?? 0,
            'tokens_in' => $stats['tokens_in'] ?? 0,
            'tokens_out' => $stats['tokens_out'] ?? 0,
            'file_id' => $fileId,
            'duration' => $stats['duration'] ?? 0,
            'message' => '' !== $message ? $message : null,
        ]);
    }
}
