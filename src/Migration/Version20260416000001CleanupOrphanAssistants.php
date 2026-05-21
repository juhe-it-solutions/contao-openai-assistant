<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) JUHE IT-solutions
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * v2.0 upgrade — Step 2 of 2.
 *
 * One-shot cleanup migration: delete orphaned OpenAI Assistants that were
 * created by this extension's 1.x line via POST /v1/assistants.
 *
 * The Assistants API has been deprecated by OpenAI and is slated for sunset
 * on 2026-08-26. Leaving these assistant objects behind would only mean
 * stale records on the user's OpenAI account. We therefore attempt one best
 * effort delete per row, then NULL the legacy openai_assistant_id column.
 *
 * The migration NEVER throws on HTTP failures: it is safe to re-run and to
 * run in environments where the API key is no longer valid.
 */
class Version20260416000001CleanupOrphanAssistants extends AbstractMigration
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly Connection $connection,
        private readonly HttpClientInterface $httpClient,
        private readonly EncryptionService $encryption,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function getName(): string
    {
        return 'v2.0 Step 2: Clean up orphaned OpenAI Assistants (Assistants API sunset)';
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (! $schemaManager->tablesExist(['tl_openai_prompts'])) {
            return false;
        }

        $columns = $schemaManager->listTableColumns('tl_openai_prompts');
        if (! isset($columns['openai_assistant_id'])) {
            return false;
        }

        $count = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM tl_openai_prompts WHERE openai_assistant_id IS NOT NULL AND openai_assistant_id <> ''"
        );

        return $count > 0;
    }

    public function run(): MigrationResult
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, pid, openai_assistant_id FROM tl_openai_prompts WHERE openai_assistant_id IS NOT NULL AND openai_assistant_id <> ''"
        );

        $total   = count($rows);
        $deleted = 0;
        $missing = 0;
        $failed  = 0;
        $cleared = 0;

        foreach ($rows as $row) {
            $assistantId = trim((string) $row['openai_assistant_id']);
            $configId    = (int) $row['pid'];
            $rowId       = (int) $row['id'];

            if ($assistantId === '') {
                continue;
            }

            $apiKey = $this->resolveApiKeyForCleanup($configId);

            if (! $apiKey) {
                $this->logger->warning('OpenAI assistant must be deleted manually on the OpenAI platform dashboard.', [
                    'config_id'    => $configId,
                    'assistant_id' => $assistantId,
                ]);
                $failed++;
            } else {
                $status = $this->deleteAssistant($apiKey, $assistantId);
                if ($status === 'deleted') {
                    $deleted++;
                } elseif ($status === 'missing') {
                    $missing++;
                } else {
                    $failed++;
                }
            }

            try {
                $affected = $this->connection->executeStatement(
                    "UPDATE tl_openai_prompts SET openai_assistant_id = '' WHERE id = ?",
                    [$rowId]
                );

                if ($affected > 0) {
                    $cleared++;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Cleanup migration: failed to clear openai_assistant_id', [
                    'row_id' => $rowId,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        $summary = sprintf(
            'Orphan assistant cleanup: %d row(s) processed — %d deleted, %d already gone, %d skipped/failed; local references cleared for %d row(s)',
            $total,
            $deleted,
            $missing,
            $failed,
            $cleared
        );

        return $this->createResult(true, $summary);
    }

    /**
     * Attempt to delete a single OpenAI Assistant. Returns one of:
     *   - 'deleted' on 2xx
     *   - 'missing' on 404 / 410 / 401 (key invalid: treat as non-actionable)
     *   - 'failed'  on any other status or network error
     *
     * This is the LAST remaining usage of "OpenAI-Beta: assistants=v2" in
     * the codebase, by design — it has to be to reach the legacy resource.
     */
    private function deleteAssistant(string $apiKey, string $assistantId): string
    {
        try {
            $response = $this->httpClient->request('DELETE', 'https://api.openai.com/v1/assistants/' . $assistantId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'OpenAI-Beta'   => 'assistants=v2',
                ],
                'timeout' => 15,
            ]);

            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                $this->logger->info('Cleanup migration: deleted orphan assistant', [
                    'assistant_id' => $assistantId,
                    'status'       => $status,
                ]);

                return 'deleted';
            }

            if (in_array($status, [401, 404, 410], true)) {
                $this->logger->info('Cleanup migration: assistant already gone or key revoked', [
                    'assistant_id' => $assistantId,
                    'status'       => $status,
                ]);

                return 'missing';
            }

            $this->logger->warning('OpenAI assistant must be deleted manually on the OpenAI platform dashboard.', [
                'assistant_id' => $assistantId,
                'status'       => $status,
            ]);

            return 'failed';
        } catch (\Throwable $e) {
            $this->logger->warning('OpenAI assistant must be deleted manually on the OpenAI platform dashboard.', [
                'assistant_id' => $assistantId,
                'error'        => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    /**
     * Resolve API key for cleanup.
     *
     * Uses EncryptionService precedence:
     *   1) OPENAI_API_KEY_{configId}
     *   2) OPENAI_API_KEY
     *   3) Database (encrypted or legacy base64)
     */
    private function resolveApiKeyForCleanup(int $configId): ?string
    {
        return $this->encryption->getApiKeyForConfig($configId, false);
    }
}
