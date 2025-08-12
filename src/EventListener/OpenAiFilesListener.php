<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) JUHE IT-solutions
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\EventListener;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\DataContainer;
use Contao\FilesModel;
use Contao\Message;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAiFilesListener
{
    private static $processedRecords = [];

    private HttpClientInterface $httpClient;

    private string $projectDir;

    private LoggerInterface $logger;

    private OpenAiConfigListener $configListener;

    private $requestStack;

    private Connection $connection;

    private ContaoCsrfTokenManager $csrfTokenManager;

    private string $csrfTokenName;

    public function __construct(
        HttpClientInterface $httpClient,
        string $projectDir,
        LoggerInterface $logger,
        OpenAiConfigListener $configListener,
        RequestStack $requestStack,
        Connection $connection,
        ContaoCsrfTokenManager $csrfTokenManager,
        string $csrfTokenName
    ) {
        $this->httpClient       = $httpClient;
        $this->projectDir       = $projectDir;
        $this->logger           = $logger;
        $this->configListener   = $configListener;
        $this->requestStack     = $requestStack;
        $this->connection       = $connection;
        $this->csrfTokenManager = $csrfTokenManager;
        $this->csrfTokenName    = $csrfTokenName;
    }

    public function uploadToOpenAI($value, ?DataContainer $dc)
    {
        // In mass actions Contao only passes the table name → just bail out
        if (! $dc instanceof DataContainer) {
            return $value;
        }

        if (empty($value)) {
            return $value;
        }

        $operationId = $dc->table . '_' . $dc->id . '_' . md5(serialize($value));
        if (isset(self::$processedRecords[$operationId])) {
            return $value;
        }
        self::$processedRecords[$operationId] = true;

        // Get API key from parent config and validate it
        $config = $this->connection->fetchAssociative(
            'SELECT api_key FROM tl_openai_config WHERE id = ?',
            [$dc->activeRecord->pid]
        );

        if (! $config || ! $config['api_key']) {
            $msg = 'No API key found in parent configuration';
            $this->logger->error($msg, [
                'contao'    => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                'parent_id' => $dc->activeRecord->pid ?? null,
            ]);
            Message::addError($msg);

            return $value;
        }

        // Get the API key directly from the config
        $apiKey = $this->processApiKey($config['api_key']);
        if (! $apiKey || ! str_starts_with($apiKey, 'sk-')) {
            $msg = 'Invalid or missing API key in configuration';
            $this->logger->error($msg, [
                'contao'    => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                'parent_id' => $dc->activeRecord->pid ?? null,
            ]);
            Message::addError($msg);

            return $value;
        }

        // Ensure vector store exists
        $vectorStoreId = $this->ensureVectorStore($apiKey, (int) $dc->activeRecord->pid);

        // Process files - handle both single files and arrays
        if (is_string($value) && str_starts_with($value, 'a:')) {
            // probably a serialized value
            $files = @unserialize($value, [
                'allowed_classes' => false,
            ]) ?: [];
        } elseif (is_string($value) && ! preg_match('/^[0-9a-f-]{36}$/i', $value)) {
            // This is likely a serialized array
            $files = StringUtil::deserialize($value, true);
        } elseif (is_array($value)) {
            $files = $value;
        } else {
            // Single file UUID
            $files = [$value];
        }

        if (empty($files)) {
            return $value;
        }

        $uploadedFileIds        = [];
        $currentRecordProcessed = false;
        $successCount           = 0;
        $errorCount             = 0;

        foreach ($files as $index => $fileUuid) {
            // Skip empty values
            if (empty($fileUuid)) {
                continue;
            }

            // Find the file model by UUID
            $file = FilesModel::findByUuid($fileUuid);
            if (! $file || ! file_exists($this->projectDir . '/public/' . $file->path)) {
                $errorMessage = 'File not found: ' . ($file ? $file->path : 'Unknown file');
                Message::addError($errorMessage);
                $this->logger->error(
                    $errorMessage,
                    [
                        'contao'    => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                        'file_uuid' => $fileUuid,
                        'file_path' => $file ? $file->path : null,
                    ]
                );
                $errorCount++;

                continue;
            }

            $filePath         = $this->projectDir . '/public/' . $file->path;
            $originalFilename = basename($file->path);
            $fileExtension    = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
            $fileSize         = filesize($filePath);

            // Validate file extension
            $allowedExtensions = ['pdf', 'txt', 'md', 'docx', 'xlsx', 'pptx', 'json', 'csv'];
            if (! in_array($fileExtension, $allowedExtensions, true)) {
                $errorMessage = 'File type not supported: ' . $originalFilename;
                Message::addError($errorMessage);
                $this->logger->error(
                    $errorMessage,
                    [
                        'contao'             => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                        'filename'           => $originalFilename,
                        'extension'          => $fileExtension,
                        'allowed_extensions' => $allowedExtensions,
                    ]
                );
                $errorCount++;

                continue;
            }

            // Validate file size (512MB limit for OpenAI)
            $maxFileSize = 512 * 1024 * 1024; // 512MB in bytes
            if ($fileSize > $maxFileSize) {
                $errorMessage = 'File too large: ' . $originalFilename . ' (' . number_format($fileSize / 1024 / 1024, 2) . 'MB). Maximum size is 512MB.';
                Message::addError($errorMessage);
                $this->logger->error(
                    $errorMessage,
                    [
                        'contao'    => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                        'filename'  => $originalFilename,
                        'file_size' => $fileSize,
                        'max_size'  => $maxFileSize,
                    ]
                );
                $errorCount++;

                continue;
            }

            try {
                $this->logger->info(
                    'Uploading file to OpenAI',
                    [
                        'contao'    => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                        'filename'  => $originalFilename,
                        'config_id' => $dc->activeRecord->pid,
                        'file_size' => $fileSize,
                    ]
                );

                // Upload file to OpenAI with proper error handling
                $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/files', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                    ],
                    'body' => [
                        'file'    => fopen($filePath, 'r'),
                        'purpose' => 'assistants',
                    ],
                    'timeout'      => 120, // Increased timeout for file uploads
                    'max_duration' => 180, // Additional safety timeout
                ]);

                $result            = $response->toArray();
                $uploadedFileIds[] = $result['id'];

                $this->logger->info(
                    'File successfully uploaded to OpenAI',
                    [
                        'contao'         => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                        'filename'       => $originalFilename,
                        'openai_file_id' => $result['id'],
                        'file_size'      => $result['bytes'],
                        'upload_status'  => $result['status'] ?? 'unknown',
                    ]
                );

                // For the first file, update the current record
                if (! $currentRecordProcessed) {
                    $this->connection->executeQuery('
                        UPDATE tl_openai_files SET filename=?, openai_file_id=?, file_size=?, status=? WHERE id=?
                    ', [
                        $originalFilename,
                        $result['id'],
                        $result['bytes'],
                        'uploaded', // Ensure this is always a string
                        $dc->id,
                    ]);

                    $this->logger->debug(
                        'Updated existing database record',
                        [
                            'contao'    => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                            'record_id' => $dc->id,
                            'filename'  => $originalFilename,
                        ]
                    );
                    $currentRecordProcessed = true;
                } else {
                    // For additional files, create new records
                    $this->connection->executeQuery('
                        INSERT INTO tl_openai_files (pid, tstamp, filename, openai_file_id, file_size, status) VALUES (?, ?, ?, ?, ?, ?)
                    ', [
                        $dc->activeRecord->pid,
                        time(),
                        $originalFilename,
                        $result['id'],
                        $result['bytes'],
                        'uploaded',
                    ]);

                    $this->logger->debug(
                        'Created new database record for additional file',
                        [
                            'contao'    => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                            'parent_id' => $dc->activeRecord->pid,
                            'filename'  => $originalFilename,
                        ]
                    );
                }

                // Add file to vector store if available
                if ($vectorStoreId) {
                    $this->logger->debug(
                        'Adding file to vector store',
                        [
                            'contao'          => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                            'vector_store_id' => $vectorStoreId,
                            'openai_file_id'  => $result['id'],
                        ]
                    );

                    if ($vectorStoreId) {
                        $this->addFileToVectorStore($apiKey, $vectorStoreId, $result['id']);
                    }
                } else {
                    $this->logger->warning(
                        'No vector store ID found, skipping vector store addition',
                        [
                            'contao'    => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                            'config_id' => $dc->activeRecord->pid,
                        ]
                    );
                }

                Message::addConfirmation('File uploaded successfully: ' . $originalFilename . ' (ID: ' . $result['id'] . ')');
                $successCount++;

            } catch (\Exception $e) {
                $errorMessage = 'Failed to upload file ' . $originalFilename . ': ' . $e->getMessage();
                $errorCode    = $e->getCode();
                Message::addError($errorMessage);

                $this->logger->error(
                    $errorMessage,
                    [
                        'contao'          => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                        'filename'        => $originalFilename,
                        'exception'       => $e->getMessage(),
                        'exception_code'  => $errorCode,
                        'file_path'       => $filePath,
                        'file_size'       => $fileSize,
                        'exception_trace' => $e->getTraceAsString(),
                    ]
                );

                // Update current record with error status (only for first file)
                if (! $currentRecordProcessed) {
                    $this->connection->executeQuery('
                        UPDATE tl_openai_files SET filename=?, status=? WHERE id=?
                    ', [
                        $originalFilename,
                        'error',
                        $dc->id,
                    ]);

                    $this->logger->debug(
                        'Updated record with error status',
                        [
                            'contao'    => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                            'record_id' => $dc->id,
                            'filename'  => $originalFilename,
                        ]
                    );
                    $currentRecordProcessed = true;
                }
                $errorCount++;

                continue;
            }
        }

        $this->logger->info(
            'File upload process completed',
            [
                'contao'             => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                'total_files'        => count($files),
                'successful_uploads' => $successCount,
                'failed_uploads'     => $errorCount,
                'uploaded_file_ids'  => $uploadedFileIds,
            ]
        );

        // Return the processed value for DCA save callback
        return $value;
    }

    #[AsCallback(table: 'tl_openai_files', target: 'list.child_record')]
    public function listFiles($row): string
    {
        // Handle null or empty row
        if (! $row || ! is_array($row)) {
            return '<div class="tl_content_left">No file data available</div>';
        }

        // Enhanced status handling with proper type checking
        $statusValue = 'pending';
        if (isset($row['status'])) {
            if (is_array($row['status'])) {
                // Handle legacy array values
                $statusValue = ! empty($row['status']) ? (string) $row['status'][0] : 'pending';
            } elseif (is_string($row['status']) && ! empty($row['status'])) {
                $statusValue = $row['status'];
            }
        }

        $status = match ($statusValue) {
            'uploaded'   => '<span style="color: green;">✓ Uploaded</span>',
            'completed'  => '<span style="color: green;">✓ Completed</span>',
            'failed'     => '<span style="color: red;">✗ Failed</span>',
            'processing' => '<span style="color: orange;">⟳ Processing</span>',
            'error'      => '<span style="color: red;">✗ Error</span>',
            default      => '<span style="color: gray;">⏳ Pending</span>',
        };

        // Safe handling of all fields with type checking
        $fileId = '';
        if (isset($row['openai_file_id']) && is_string($row['openai_file_id']) && ! empty($row['openai_file_id'])) {
            $fileId = 'ID: ' . $row['openai_file_id'];
        } else {
            $fileId = 'No OpenAI ID';
        }

        $fileSize = '';
        if (isset($row['file_size']) && (is_numeric($row['file_size']) || is_int($row['file_size'])) && $row['file_size'] > 0) {
            $fileSize = 'Size: ' . $this->formatFileSize((int) $row['file_size']);
        } else {
            $fileSize = 'Size: Unknown';
        }

        return sprintf(
            '<div class="tl_content_left"><span style="color:#999;">%s | %s</span> %s</div>',
            htmlspecialchars($fileId, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($fileSize, ENT_QUOTES, 'UTF-8'),
            $status
        );
    }

    /**
     * @Hook("ondelete_callback")
     */
    public function deleteFromOpenAI($dc): void
    {
        if (! $dc->activeRecord) {
            return;
        }

        $fileId = $dc->activeRecord->openai_file_id;
        if (! $fileId) {
            return;
        }

        try {
            // Get the API key from the parent config
            $config = $this->connection->fetchAssociative(
                'SELECT api_key FROM tl_openai_config WHERE id = ?',
                [$dc->activeRecord->pid]
            );

            if (! $config || ! $config['api_key']) {
                $this->logger->warning(
                    'No API key found for parent configuration',
                    [
                        'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                    ]
                );

                return;
            }

            try {
                $apiKey = $this->processApiKey($config['api_key']);
                if (! $apiKey) {
                    $this->logger->warning(
                        'No valid API key found for parent configuration',
                        [
                            'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                        ]
                    );

                    return;
                }

                $response = $this->httpClient->request('DELETE', "https://api.openai.com/v1/files/{$fileId}", [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type'  => 'application/json',
                    ],
                ]);

                // If we get a 404, the file doesn't exist anymore, which is fine
                if ($response->getStatusCode() === 404) {
                    $this->logger->info(
                        'File already deleted from OpenAI platform',
                        [
                            'contao'  => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                            'file_id' => $fileId,
                        ]
                    );

                    return;
                }

                if ($response->getStatusCode() !== 200) {
                    $this->logger->error(
                        'Failed to delete file from OpenAI',
                        [
                            'contao'   => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                            'file_id'  => $fileId,
                            'status'   => $response->getStatusCode(),
                            'response' => $response->getContent(false),
                        ]
                    );

                    // Don't throw exception, just log the error
                    return;
                }

                $this->logger->info(
                    'File deleted from OpenAI: ' . $fileId,
                    [
                        'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                    ]
                );
            } catch (\Exception $e) {
                $this->logger->error(
                    'Error deleting file from OpenAI: ' . $e->getMessage(),
                    [
                        'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                    ]
                );
                // Don't throw exception, just log the error
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Error in deleteFromOpenAI: ' . $e->getMessage(),
                [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                ]
            );
            // Don't throw exception, just log the error
        }
    }

    /**
     * Called when the DCA is loaded
     */
    public function onLoadCallback(DataContainer $dc): void
    {
        // Add API status indicator to the page
        $configId = null;

        // Try to get parent config ID from different sources
        if ($dc && $dc->pid) {
            $configId = $dc->pid;
        } elseif ($dc && $dc->activeRecord && $dc->activeRecord->pid) {
            $configId = $dc->activeRecord->pid;
        } elseif (isset($_GET['pid'])) {
            $configId = (int) $_GET['pid'];
        }

        // For creation screens, try to get config ID from URL or check for existing configs
        if (! $configId) {
            $request = $this->requestStack->getCurrentRequest();
            if ($request && ($request->get('act') === 'create' || $request->get('act') === '')) {
                // Try to get from URL parameters
                $configId = $request->get('pid') ? (int) $request->get('pid') : null;

                // If still no config ID, check if there's only one config available
                if (! $configId) {
                    $existingConfig = $this->connection->fetchAssociative(
                        'SELECT id FROM tl_openai_config LIMIT 1'
                    );
                    if ($existingConfig) {
                        $configId = (int) $existingConfig['id'];
                    }
                }
            }
        }

        if ($configId) {
            // Show a neutral status indicator when no config exists yet
            $neutralIndicator = '<div class="api-status-indicator unavailable fade-in">
                <span class="status-icon">?</span>No parent configuration found
            </div>';

            $script = '<script>
                document.addEventListener("DOMContentLoaded", function() {
                    // Try multiple selectors to find the best place to insert the status indicator
                    var header = document.querySelector(".tl_header");
                    var title = document.querySelector(".tl_title");
                    var container = document.querySelector(".tl_content");
                    
                    var targetElement = header || title || container;
                    
                    if (targetElement) {
                        // Create a wrapper div for the status indicator
                        var statusWrapper = document.createElement("div");
                        statusWrapper.style.cssText = "margin: 10px 0; padding: 10px; border-radius: 4px;";
                        statusWrapper.innerHTML = \'' . addslashes($neutralIndicator) . '\';
                        
                        // Insert at the beginning of the target element
                        targetElement.insertBefore(statusWrapper, targetElement.firstChild);
                    }
                });
            </script>';

            // Add the script to the page
            $GLOBALS['TL_BODY'][] = $script;
        }
    }

    /**
     * Adds the header to the list view
     */
    #[AsCallback(table: 'tl_openai_files', target: 'list.header')]
    public function addHeader(): string
    {
        return '<div class="tl_header">' . $GLOBALS['TL_LANG']['tl_openai_files']['header'] . '</div>';
    }

    /**
     * Process API key
     */
    private function processApiKey(string $storedApiKey): ?string
    {
        if (empty($storedApiKey)) {
            return null;
        }

        // Check if this is an encrypted key (longer than 100 chars) or legacy base64
        if (strlen($storedApiKey) > 100) {
            // This is an encrypted key
            $apiKey = $this->decryptApiKey($storedApiKey);
        } else {
            // This is a legacy base64 encoded key
            $apiKey = base64_decode($storedApiKey, true);
        }

        if (! $apiKey || ! $this->isValidApiKeyFormat($apiKey)) {
            $this->logger->error('Invalid API key format detected', [
                'contao'         => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                'api_key_length' => strlen($storedApiKey),
                'api_key_prefix' => substr($storedApiKey, 0, 10),
            ]);

            return null;
        }

        return $apiKey;
    }

    /**
     * Check if API key format is valid
     */
    private function isValidApiKeyFormat(string $apiKey): bool
    {
        return str_starts_with($apiKey, 'sk-');
    }

    /**
     * Generate encryption key (same as other services)
     */
    private function getEncryptionKey(): string
    {
        // Generate the same encryption key as in other services
        $serverName   = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '/';

        return hash('sha256', $serverName . $documentRoot, true);
    }

    /**
     * Decrypt API key from storage
     */
    private function decryptApiKey(string $encryptedData): ?string
    {
        try {
            $key    = $this->getEncryptionKey();
            $method = 'aes-256-cbc';

            $data      = base64_decode($encryptedData, true);
            $ivLength  = openssl_cipher_iv_length($method);
            $iv        = substr($data, 0, $ivLength);
            $encrypted = substr($data, $ivLength);

            $decrypted = openssl_decrypt($encrypted, $method, $key, 0, $iv);

            return $decrypted !== false ? $decrypted : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function ensureVectorStore(string $apiKey, int $configId): string
    {
        $config = $this->connection->fetchAssociative(
            'SELECT vector_store_id, title FROM tl_openai_config WHERE id=?',
            [$configId]
        );

        if ($config['vector_store_id']) {
            return $config['vector_store_id'];
        }

        // Create new vector store
        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/vector_stores', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                    'OpenAI-Beta'   => 'assistants=v2',
                ],
                'json' => [
                    'name' => $config['title'],
                ],
                'timeout' => 30,
            ]);

            $result        = $response->toArray();
            $vectorStoreId = $result['id'];

            $this->connection->executeQuery('
                UPDATE tl_openai_config SET vector_store_id=? WHERE id=?
            ', [
                $vectorStoreId,
                $configId,
            ]);

            $this->logger->info(
                'Created new vector store',
                [
                    'contao'          => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                    'vector_store_id' => $vectorStoreId,
                    'config_id'       => $configId,
                ]
            );

            return $vectorStoreId;

        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to create vector store: ' . $e->getMessage(),
                [
                    'contao'    => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                    'config_id' => $configId,
                ]
            );

            throw new \RuntimeException('Failed to create vector store: ' . $e->getMessage());
        }
    }

    /**
     * Add uploaded file to the vector store with improved error handling
     */
    private function addFileToVectorStore(string $apiKey, string $vectorStoreId, string $fileId): void
    {
        $this->logger->debug(
            'Starting vector store file addition',
            [
                'contao'          => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                'vector_store_id' => $vectorStoreId,
                'file_id'         => $fileId,
            ]
        );

        try {
            $response = $this->httpClient->request('POST', "https://api.openai.com/v1/vector_stores/{$vectorStoreId}/files", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                    'OpenAI-Beta'   => 'assistants=v2',
                ],
                'json' => [
                    'file_id' => $fileId,
                ],
                'timeout'      => 60, // Increased timeout for vector store operations
                'max_duration' => 90,
            ]);

            $result = $response->toArray();

            $this->logger->info(
                'File successfully added to vector store',
                [
                    'contao'               => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                    'vector_store_id'      => $vectorStoreId,
                    'file_id'              => $fileId,
                    'vector_store_file_id' => $result['id'] ?? null,
                    'status'               => $result['status'] ?? 'unknown',
                ]
            );

            Message::addConfirmation('File added to vector store successfully');

        } catch (\Exception $e) {
            $errorMessage = 'Failed to add file to vector store: ' . $e->getMessage();
            $errorCode    = $e->getCode();
            Message::addError($errorMessage);

            $this->logger->error(
                $errorMessage,
                [
                    'contao'          => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                    'vector_store_id' => $vectorStoreId,
                    'file_id'         => $fileId,
                    'exception'       => $e->getMessage(),
                    'exception_code'  => $errorCode,
                    'exception_trace' => $e->getTraceAsString(),
                ]
            );
        }
    }

    private function formatFileSize(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i     = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2) . ' ' . $units[$i];
    }

    private function getStatusLabel(string $status): string
    {
        $statusLabels = [
            'pending'    => '<span style="color:#f90;">Pending</span>',
            'processing' => '<span style="color:#0f0;">Processing</span>',
            'uploaded'   => '<span style="color:#0f0;">Uploaded</span>',
            'completed'  => '<span style="color:#0f0;">Completed</span>',
            'failed'     => '<span style="color:#f00;">Failed</span>',
            'error'      => '<span style="color:#f00;">Error</span>',
        ];

        return $statusLabels[$status] ?? $status;
    }
}
