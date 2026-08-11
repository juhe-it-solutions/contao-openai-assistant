<?php

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
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
 * One-shot cleanup migration: delete orphaned OpenAI Assistants that were created
 * by this extension's 1.x line via POST /v1/assistants.
 *
 * The Assistants API has been deprecated by OpenAI and is slated for sunset on
 * 2026-08-26. Leaving these assistant objects behind would only mean stale
 * records on the user's OpenAI account. We therefore attempt one delete per row
 * and NULL the legacy openai_assistant_id column once the outcome is conclusive.
 *
 * "Conclusive" is the important word. The id is the only handle on the remote object, and
 * shouldRun() fires solely on rows that still carry one, so clearing it is what retires a row
 * for good. It is therefore cleared on 2xx (deleted) and on 404/410 (provably absent), and
 * kept on everything else - a missing or revoked key, a transport failure, a 5xx - so the
 * next contao:migrate simply tries again instead of the row silently becoming unfixable.
 *
 * The migration NEVER throws on HTTP failures: it is safe to re-run and to run in
 * environments where the API key is no longer valid.
 */
class Version20260416000001CleanupOrphanAssistants extends AbstractMigration
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly Connection $connection,
        private readonly HttpClientInterface $httpClient,
        private readonly EncryptionService $encryption,
        LoggerInterface|null $logger = null,
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

        if (!$schemaManager->tablesExist(['tl_openai_prompts'])) {
            return false;
        }

        $columns = $schemaManager->listTableColumns('tl_openai_prompts');
        if (!isset($columns['openai_assistant_id'])) {
            return false;
        }

        $count = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM tl_openai_prompts WHERE openai_assistant_id IS NOT NULL AND openai_assistant_id <> ''",
        );

        return $count > 0;
    }

    public function run(): MigrationResult
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, pid, openai_assistant_id FROM tl_openai_prompts WHERE openai_assistant_id IS NOT NULL AND openai_assistant_id <> ''",
        );

        $total = \count($rows);
        $deleted = 0;
        $missing = 0;
        $failed = 0;
        $cleared = 0;

        foreach ($rows as $row) {
            $assistantId = trim((string) $row['openai_assistant_id']);
            $configId = (int) $row['pid'];
            $rowId = (int) $row['id'];

            if ('' === $assistantId) {
                continue;
            }

            $apiKey = $this->resolveApiKeyForCleanup($configId);

            if (!$apiKey) {
                $this->logger->warning(
                    'OpenAI assistant must be deleted manually on the OpenAI platform dashboard.',
                    [
                        'config_id' => $configId,
                        'assistant_id' => $assistantId,
                    ],
                );
                ++$failed;
                $outcome = 'failed';
            } else {
                $outcome = $this->deleteAssistant($apiKey, $assistantId);

                if ('deleted' === $outcome) {
                    ++$deleted;
                } elseif ('missing' === $outcome) {
                    ++$missing;
                } else {
                    ++$failed;
                }
            }

            // Keep the reference when the outcome was not conclusive.
            //
            // The id is the only handle on the remote assistant, and shouldRun() fires solely
            // on rows that still carry one - so clearing it after a transport failure, a 5xx
            // or a missing key retired the row permanently on the strength of an attempt that
            // established nothing. The operator still got the id in the log, but the migration
            // itself could never help again. A run that reached no verdict now leaves the row
            // exactly as it found it, and the next contao:migrate tries once more.
            if ('failed' === $outcome) {
                continue;
            }

            try {
                $affected = $this->connection->executeStatement(
                    "UPDATE tl_openai_prompts SET openai_assistant_id = '' WHERE id = ?",
                    [$rowId],
                );

                if ($affected > 0) {
                    ++$cleared;
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Cleanup migration: failed to clear openai_assistant_id',
                    [
                        'row_id' => $rowId,
                        'error' => $e->getMessage(),
                    ],
                );
            }
        }

        $summary = \sprintf(
            'Orphan assistant cleanup: %d row(s) processed - %d deleted, %d already gone, %d skipped/failed; local references cleared for %d row(s)',
            $total,
            $deleted,
            $missing,
            $failed,
            $cleared,
        );

        return $this->createResult(true, $summary);
    }

    /**
     * Attempt to delete a single OpenAI Assistant. Returns one of:
     *   - 'deleted' on 2xx
     *   - 'missing' on 404 / 410 (the assistant is provably not there)
     *   - 'failed' on any other status or network error
     *
     * 401 counts as 'failed', not 'missing'. A revoked or wrong key says nothing at all about
     * whether the assistant still exists, and the caller only forgets the id on a conclusive
     * outcome - so calling this "already gone" would retire the row for good on the strength
     * of an answer about the key rather than about the assistant.
     *
     * This is the LAST remaining usage of "OpenAI-Beta: assistants=v2" in the
     * codebase, by design — it has to be to reach the legacy resource.
     */
    private function deleteAssistant(string $apiKey, string $assistantId): string
    {
        try {
            $response = $this->httpClient->request(
                'DELETE',
                'https://api.openai.com/v1/assistants/'.$assistantId,
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                        'OpenAI-Beta' => 'assistants=v2',
                    ],
                    'timeout' => 15,
                ],
            );

            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                $this->logger->info(
                    'Cleanup migration: deleted orphan assistant',
                    [
                        'assistant_id' => $assistantId,
                        'status' => $status,
                    ],
                );

                return 'deleted';
            }

            if (\in_array($status, [404, 410], true)) {
                $this->logger->info(
                    'Cleanup migration: assistant already gone',
                    [
                        'assistant_id' => $assistantId,
                        'status' => $status,
                    ],
                );

                return 'missing';
            }

            $this->logger->warning(
                'OpenAI assistant must be deleted manually on the OpenAI platform dashboard.',
                [
                    'assistant_id' => $assistantId,
                    'status' => $status,
                ],
            );

            return 'failed';
        } catch (\Throwable $e) {
            $this->logger->warning(
                'OpenAI assistant must be deleted manually on the OpenAI platform dashboard.',
                [
                    'assistant_id' => $assistantId,
                    'error' => $e->getMessage(),
                ],
            );

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
    private function resolveApiKeyForCleanup(int $configId): string|null
    {
        return $this->encryption->getApiKeyForConfig($configId, false);
    }
}
