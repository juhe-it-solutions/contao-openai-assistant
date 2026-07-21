<?php

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\EventListener;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\DataContainer\RecordLabel;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\DataContainer;
use Contao\FilesModel;
use Contao\Message;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAiFilesListener
{
    /**
     * @var array<string, bool>
     */
    private static array $processedRecords = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $projectDir,
        private readonly string $webDir,
        private readonly LoggerInterface $logger,
        private readonly OpenAiConfigListener $configListener,
        private readonly RequestStack $requestStack,
        private readonly Connection $connection,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly string $csrfTokenName,
        private readonly EncryptionService $encryption,
    ) {
    }

    public function uploadToOpenAI($value, DataContainer|null $dc)
    {
        // In mass actions Contao only passes the table name → just bail out
        if (!$dc instanceof DataContainer) {
            return $value;
        }

        if (empty($value)) {
            return $value;
        }

        $operationId = $dc->table.'_'.$dc->id.'_'.md5(serialize($value));
        if (isset(self::$processedRecords[$operationId])) {
            return $value;
        }
        self::$processedRecords[$operationId] = true;

        // Resolve API key with env override support (OPENAI_API_KEY_{configId}).
        $configId = (int) $dc->activeRecord->pid;
        $apiKey = $this->encryption->getApiKeyForConfig($configId);

        if (!$apiKey) {
            $msg = 'No API key found in parent configuration';
            $this->logger->error($msg, [
                'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                'parent_id' => $configId ?: null,
            ]);
            Message::addError($msg);

            return $value;
        }

        // Ensure vector store exists
        $vectorStoreId = $this->ensureVectorStore($apiKey, (int) $dc->activeRecord->pid);

        // Process files - handle both single files and arrays
        if (\is_string($value) && str_starts_with($value, 'a:')) {
            // probably a serialized value
            $files = @unserialize($value, [
                'allowed_classes' => false,
            ]) ?: [];
        } elseif (\is_string($value) && !preg_match('/^[0-9a-f-]{36}$/i', $value)) {
            // This is likely a serialized array
            $files = StringUtil::deserialize($value, true);
        } elseif (\is_array($value)) {
            $files = $value;
        } else {
            // Single file UUID
            $files = [$value];
        }

        if (empty($files)) {
            return $value;
        }

        $uploadedFileIds = [];
        $currentRecordProcessed = false;
        $successCount = 0;
        $errorCount = 0;

        foreach ($files as $fileUuid) {
            // Skip empty values
            if (empty($fileUuid)) {
                continue;
            }

            // Find the file model by UUID
            $file = FilesModel::findByUuid($fileUuid);
            $absolutePath = null;
            if ($file && isset($file->path)) {
                $webRoot = $this->webDir;
                // If webDir is not absolute, prefix with projectDir
                if ('' !== $webRoot && !str_starts_with($webRoot, '/')) {
                    $webRoot = rtrim($this->projectDir, '/').'/'.ltrim($webRoot, '/');
                }
                $absolutePath = rtrim($webRoot, '/').'/'.ltrim($file->path, '/');
            }
            if (!$file || !$absolutePath || !file_exists($absolutePath)) {
                $webRootForMessage = $this->webDir;
                if ('' !== $webRootForMessage && !str_starts_with($webRootForMessage, '/')) {
                    $webRootForMessage = rtrim($this->projectDir, '/').'/'.ltrim($webRootForMessage, '/');
                }

                if (!$file) {
                    $errorMessage = 'File not found (invalid or missing file reference). Please reselect the file.';
                } else {
                    $errorMessage = 'File not found: '.$file->path.'. Looked in: '.($absolutePath ?? 'unknown').'. Web root: '.$webRootForMessage.'.';
                }

                Message::addError($errorMessage);
                $this->logger->error($errorMessage, [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                    'file_uuid' => $fileUuid,
                    'file_path' => $file ? $file->path : null,
                    'absolute_path' => $absolutePath,
                    'configured_webdir' => $this->webDir,
                    'resolved_web_root' => $webRootForMessage,
                ]);
                ++$errorCount;

                continue;
            }

            $filePath = $absolutePath;
            $originalFilename = basename($file->path);
            $fileExtension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
            $fileSize = filesize($filePath);

            // Validate file extension
            $allowedExtensions = ['pdf', 'txt', 'md', 'docx', 'pptx', 'json'];
            if (!\in_array($fileExtension, $allowedExtensions, true)) {
                $errorMessage = 'File type not supported: '.$originalFilename;
                Message::addError($errorMessage);
                $this->logger->error($errorMessage, [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                    'filename' => $originalFilename,
                    'extension' => $fileExtension,
                    'allowed_extensions' => $allowedExtensions,
                ]);
                ++$errorCount;

                continue;
            }

            // Validate file size (512MB limit for OpenAI)
            $maxFileSize = 512 * 1024 * 1024; // 512MB in bytes
            if ($fileSize > $maxFileSize) {
                $errorMessage = 'File too large: '.$originalFilename.' ('.number_format($fileSize / 1024 / 1024, 2).'MB). Maximum size is 512MB.';
                Message::addError($errorMessage);
                $this->logger->error($errorMessage, [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                    'filename' => $originalFilename,
                    'file_size' => $fileSize,
                    'max_size' => $maxFileSize,
                ]);
                ++$errorCount;

                continue;
            }

            try {
                $this->logger->info(
                    'Uploading file to OpenAI',
                    [
                        'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                        'filename' => $originalFilename,
                        'config_id' => $dc->activeRecord->pid,
                        'file_size' => $fileSize,
                    ],
                );

                // Upload file to OpenAI with proper error handling
                $response = $this->httpClient->request(
                    'POST',
                    'https://api.openai.com/v1/files',
                    [
                        'headers' => [
                            'Authorization' => 'Bearer '.$apiKey,
                        ],
                        'body' => [
                            'file' => fopen($filePath, 'r'),
                            'purpose' => 'assistants',
                        ],
                        'timeout' => 120, // Increased timeout for file uploads
                        'max_duration' => 180, // Additional safety timeout
                    ],
                );

                $result = $response->toArray();
                $uploadedFileIds[] = $result['id'];

                $this->logger->info(
                    'File successfully uploaded to OpenAI',
                    [
                        'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                        'filename' => $originalFilename,
                        'openai_file_id' => $result['id'],
                        'file_size' => $result['bytes'],
                        'upload_status' => $result['status'] ?? 'unknown',
                    ],
                );

                // For the first file, update the current record
                if (!$currentRecordProcessed) {
                    $this->connection->executeQuery(
                        '
                        UPDATE tl_openai_files SET filename=?, openai_file_id=?, file_size=?, status=? WHERE id=?
                    ',
                        [
                            $originalFilename,
                            $result['id'],
                            $result['bytes'],
                            'uploaded', // Ensure this is always a string
                            $dc->id,
                        ],
                    );

                    $this->logger->debug(
                        'Updated existing database record',
                        [
                            'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                            'record_id' => $dc->id,
                            'filename' => $originalFilename,
                        ],
                    );
                    $currentRecordProcessed = true;
                } else {
                    // For additional files, create new records
                    $this->connection->executeQuery(
                        '
                        INSERT INTO tl_openai_files (pid, tstamp, filename, openai_file_id, file_size, status) VALUES (?, ?, ?, ?, ?, ?)
                    ',
                        [
                            $dc->activeRecord->pid,
                            time(),
                            $originalFilename,
                            $result['id'],
                            $result['bytes'],
                            'uploaded',
                        ],
                    );

                    $this->logger->debug(
                        'Created new database record for additional file',
                        [
                            'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                            'parent_id' => $dc->activeRecord->pid,
                            'filename' => $originalFilename,
                        ],
                    );
                }

                // Add file to vector store if available
                if ($vectorStoreId) {
                    $this->logger->debug(
                        'Adding file to vector store',
                        [
                            'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                            'vector_store_id' => $vectorStoreId,
                            'openai_file_id' => $result['id'],
                        ],
                    );

                    if ($vectorStoreId) {
                        $this->addFileToVectorStore($apiKey, $vectorStoreId, $result['id']);
                    }
                } else {
                    $this->logger->warning(
                        'No vector store ID found, skipping vector store addition',
                        [
                            'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                            'config_id' => $dc->activeRecord->pid,
                        ],
                    );
                }

                Message::addConfirmation('File uploaded successfully: '.$originalFilename.' (ID: '.$result['id'].')');
                ++$successCount;
            } catch (\Exception $e) {
                $errorMessage = 'Failed to upload file '.$originalFilename.': '.$e->getMessage();
                $errorCode = $e->getCode();
                Message::addError($errorMessage);

                $this->logger->error($errorMessage, [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                    'filename' => $originalFilename,
                    'exception' => $e->getMessage(),
                    'exception_code' => $errorCode,
                    'file_path' => $filePath,
                    'file_size' => $fileSize,
                    'exception_trace' => $e->getTraceAsString(),
                ]);

                // Update current record with error status (only for first file)
                if (!$currentRecordProcessed) {
                    $this->connection->executeQuery(
                        '
                        UPDATE tl_openai_files SET filename=?, status=? WHERE id=?
                    ',
                        [
                            $originalFilename,
                            'error',
                            $dc->id,
                        ],
                    );

                    $this->logger->debug(
                        'Updated record with error status',
                        [
                            'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                            'record_id' => $dc->id,
                            'filename' => $originalFilename,
                        ],
                    );
                    $currentRecordProcessed = true;
                }
                ++$errorCount;

                continue;
            }
        }

        $this->logger->info(
            'File upload process completed',
            [
                'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                'total_files' => \count($files),
                'successful_uploads' => $successCount,
                'failed_uploads' => $errorCount,
                'uploaded_file_ids' => $uploadedFileIds,
            ],
        );

        // Return the processed value for DCA save callback
        return $value;
    }

    /**
     * Registered as the label_callback (Contao 6 removed child_record_callback).
     * Returns a RecordLabel so the HTML markup is rendered raw instead of being
     * auto-encoded as a plain-text string.
     */
    #[AsCallback(table: 'tl_openai_files', target: 'list.label.label_callback')]
    public function listFiles($row): RecordLabel
    {
        // Contao auto-loads the tl_openai_files language file for this DCA, so the
        // status/ID/size labels follow the backend locale (fallbacks are English).
        System::loadLanguageFile('tl_openai_files');
        $lang = $GLOBALS['TL_LANG']['tl_openai_files'] ?? [];

        // Handle null or empty row
        if (!$row || !\is_array($row)) {
            return RecordLabel::fromHtml('<div class="tl_content_left">'.htmlspecialchars((string) ($lang['list_no_data'] ?? 'No file data available'), ENT_QUOTES, 'UTF-8').'</div>');
        }

        // Enhanced status handling with proper type checking
        $statusValue = 'pending';
        if (isset($row['status'])) {
            if (\is_array($row['status'])) {
                // Handle legacy array values
                $statusValue = !empty($row['status']) ? (string) $row['status'][0] : 'pending';
            } elseif (\is_string($row['status']) && !empty($row['status'])) {
                $statusValue = $row['status'];
            }
        }

        $statusColors = [
            'uploaded' => 'green',
            'completed' => 'green',
            'failed' => 'red',
            'error' => 'red',
            'processing' => 'orange',
            'pending' => 'gray',
        ];
        $statusIcons = [
            'uploaded' => '✓',
            'completed' => '✓',
            'failed' => '✗',
            'error' => '✗',
            'processing' => '⟳',
            'pending' => '⏳',
        ];
        $statusLabel = $lang['status_options'][$statusValue] ?? ucfirst($statusValue);
        $status = \sprintf(
            '<span style="color: %s;">%s %s</span>',
            $statusColors[$statusValue] ?? 'gray',
            $statusIcons[$statusValue] ?? '⏳',
            htmlspecialchars((string) $statusLabel, ENT_QUOTES, 'UTF-8'),
        );

        // Safe handling of all fields with type checking
        $fileId = '';
        if (isset($row['openai_file_id']) && \is_string($row['openai_file_id']) && !empty($row['openai_file_id'])) {
            $fileId = ($lang['list_id'] ?? 'ID').': '.$row['openai_file_id'];
        } else {
            $fileId = (string) ($lang['list_no_id'] ?? 'No OpenAI ID');
        }

        $fileSize = '';
        if (isset($row['file_size']) && (is_numeric($row['file_size']) || \is_int($row['file_size'])) && $row['file_size'] > 0) {
            $fileSize = ($lang['list_size'] ?? 'Size').': '.$this->formatFileSize((int) $row['file_size']);
        } else {
            $fileSize = ($lang['list_size'] ?? 'Size').': '.($lang['list_size_unknown'] ?? 'Unknown');
        }

        return RecordLabel::fromHtml(\sprintf(
            '<div class="tl_content_left"><span style="color:#999;">%s | %s</span> %s</div>',
            htmlspecialchars($fileId, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($fileSize, ENT_QUOTES, 'UTF-8'),
            $status,
        ));
    }

    /**
     * Registered as config.ondelete callback for tl_openai_files in config/services.yaml.
     */
    public function deleteFromOpenAI($dc): void
    {
        if (!$dc->activeRecord) {
            return;
        }

        $fileId = $dc->activeRecord->openai_file_id;
        if (!$fileId) {
            return;
        }

        try {
            $configId = (int) $dc->activeRecord->pid;
            $apiKey = $this->encryption->getApiKeyForConfig($configId);
            if (!$apiKey) {
                $this->logger->warning(
                    'No API key found for parent configuration',
                    [
                        'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                    ],
                );

                return;
            }

            try {
                $response = $this->httpClient->request(
                    'DELETE',
                    "https://api.openai.com/v1/files/{$fileId}",
                    [
                        'headers' => [
                            'Authorization' => 'Bearer '.$apiKey,
                            'Content-Type' => 'application/json',
                        ],
                    ],
                );

                // If we get a 404, the file doesn't exist anymore, which is fine
                if (404 === $response->getStatusCode()) {
                    $this->logger->info(
                        'File already deleted from OpenAI platform',
                        [
                            'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                            'file_id' => $fileId,
                        ],
                    );

                    return;
                }

                if (200 !== $response->getStatusCode()) {
                    $this->logger->error(
                        'Failed to delete file from OpenAI',
                        [
                            'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                            'file_id' => $fileId,
                            'status' => $response->getStatusCode(),
                            'response' => $response->getContent(false),
                        ],
                    );

                    // Don't throw exception, just log the error
                    return;
                }

                $this->logger->info(
                    'File deleted from OpenAI: '.$fileId,
                    [
                        'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                    ],
                );
            } catch (\Exception $e) {
                $this->logger->error(
                    'Error deleting file from OpenAI: '.$e->getMessage(),
                    [
                        'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                    ],
                );
                // Don't throw exception, just log the error
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Error in deleteFromOpenAI: '.$e->getMessage(),
                [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                ],
            );
            // Don't throw exception, just log the error
        }
    }

    /**
     * Called when the DCA is loaded.
     */
    public function onLoadCallback(DataContainer $dc): void
    {
        if ($this->resolveParentConfigId($dc)) {
            return;
        }

        $language = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';
        System::loadLanguageFile('tl_openai_files', $language);

        $lang = $GLOBALS['TL_LANG']['tl_openai_files'] ?? [];

        Message::addError(
            (string) ($lang['no_parent_config'] ?? 'No parent OpenAI configuration found. Please configure OpenAI first.'),
        );
    }

    private function resolveParentConfigId(DataContainer|null $dc): int|null
    {
        if ($dc && $dc->pid) {
            return (int) $dc->pid;
        }

        if ($dc && $dc->activeRecord && $dc->activeRecord->pid) {
            return (int) $dc->activeRecord->pid;
        }

        if (isset($_GET['pid'])) {
            return (int) $_GET['pid'];
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request && $request->query->get('pid')) {
            return (int) $request->query->get('pid');
        }

        $existingConfig = $this->connection->fetchAssociative(
            'SELECT id FROM tl_openai_config LIMIT 1',
        );

        if ($existingConfig) {
            return (int) $existingConfig['id'];
        }

        return null;
    }

    private function ensureVectorStore(string $apiKey, int $configId): string
    {
        $config = $this->connection->fetchAssociative(
            'SELECT vector_store_id, title FROM tl_openai_config WHERE id=?',
            [$configId],
        );

        if ($config['vector_store_id']) {
            return $config['vector_store_id'];
        }

        // Create new vector store
        try {
            // TODO: Drop the "OpenAI-Beta: assistants=v2" header once /v1/vector_stores
            // leaves beta. As of April 2026 it is still required for vector store creation
            // even though Assistants API itself is deprecated.
            $response = $this->httpClient->request(
                'POST',
                'https://api.openai.com/v1/vector_stores',
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                        'Content-Type' => 'application/json',
                        'OpenAI-Beta' => 'assistants=v2',
                    ],
                    'json' => [
                        'name' => $config['title'],
                    ],
                    'timeout' => 30,
                ],
            );

            $result = $response->toArray();
            $vectorStoreId = $result['id'];

            $this->connection->executeQuery(
                '
                UPDATE tl_openai_config SET vector_store_id=? WHERE id=?
            ',
                [
                    $vectorStoreId,
                    $configId,
                ],
            );

            $this->logger->info(
                'Created new vector store',
                [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                    'vector_store_id' => $vectorStoreId,
                    'config_id' => $configId,
                ],
            );

            return $vectorStoreId;
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to create vector store: '.$e->getMessage(),
                [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                    'config_id' => $configId,
                ],
            );

            throw new \RuntimeException('Failed to create vector store: '.$e->getMessage());
        }
    }

    /**
     * Add uploaded file to the vector store with improved error handling.
     */
    private function addFileToVectorStore(string $apiKey, string $vectorStoreId, string $fileId): void
    {
        $this->logger->debug(
            'Starting vector store file addition',
            [
                'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                'vector_store_id' => $vectorStoreId,
                'file_id' => $fileId,
            ],
        );

        try {
            // TODO: Drop the "OpenAI-Beta: assistants=v2" header once
            // /v1/vector_stores/{id}/files leaves beta.
            $response = $this->httpClient->request(
                'POST',
                "https://api.openai.com/v1/vector_stores/{$vectorStoreId}/files",
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                        'Content-Type' => 'application/json',
                        'OpenAI-Beta' => 'assistants=v2',
                    ],
                    'json' => [
                        'file_id' => $fileId,
                    ],
                    'timeout' => 60, // Increased timeout for vector store operations
                    'max_duration' => 90,
                ],
            );

            $result = $response->toArray();

            $this->logger->info(
                'File successfully added to vector store',
                [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                    'vector_store_id' => $vectorStoreId,
                    'file_id' => $fileId,
                    'vector_store_file_id' => $result['id'] ?? null,
                    'status' => $result['status'] ?? 'unknown',
                ],
            );

            Message::addConfirmation('File added to vector store successfully');
        } catch (\Exception $e) {
            $errorMessage = 'Failed to add file to vector store: '.$e->getMessage();
            $errorCode = $e->getCode();
            Message::addError($errorMessage);

            $this->logger->error($errorMessage, [
                'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                'vector_store_id' => $vectorStoreId,
                'file_id' => $fileId,
                'exception' => $e->getMessage(),
                'exception_code' => $errorCode,
                'exception_trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function formatFileSize(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($size >= 1024 && $i < \count($units) - 1) {
            $size /= 1024;
            ++$i;
        }

        return round($size, 2).' '.$units[$i];
    }
}
