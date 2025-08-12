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
use Contao\Environment;
use Contao\System;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAiConfigListener
{
    private HttpClientInterface $httpClient;

    private LoggerInterface $logger;

    private ContaoCsrfTokenManager $csrfTokenManager;

    private string $csrfTokenName;

    private $requestStack;

    private Connection $connection;

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        ContaoCsrfTokenManager $csrfTokenManager,
        string $csrfTokenName,
        RequestStack $requestStack,
        Connection $connection
    ) {
        $this->httpClient       = $httpClient;
        $this->logger           = $logger;
        $this->csrfTokenManager = $csrfTokenManager;
        $this->csrfTokenName    = $csrfTokenName;
        $this->requestStack     = $requestStack;
        $this->connection       = $connection;
    }

    public function processApiKeyForStorage($value, $dc): string
    {
        // Get the raw API key from POST data first (user input)
        $apiKey = $_POST['api_key'] ?? '';

        if (empty($apiKey) && $dc->activeRecord && $dc->activeRecord->api_key) {
            // If we have an existing key, use it
            $apiKey = $dc->activeRecord->api_key;
        }

        if (empty($apiKey)) {
            \Contao\Message::addError('API key is required and cannot be empty.');

            return '';
        }

        // Validate API key format
        if (! str_starts_with($apiKey, 'sk-')) {
            \Contao\Message::addError('Invalid API key format. OpenAI API keys must start with "sk-".');

            return '';
        }

        // Validate API key with OpenAI
        try {
            $response = $this->httpClient->request('GET', 'https://api.openai.com/v1/models', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'timeout' => 15,
            ]);

            if ($response->getStatusCode() !== 200) {
                \Contao\Message::addError('API key validation failed. Please check your API key.');

                return '';
            }

            $this->logger->info(
                'API key validation successful for config save',
                [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                ]
            );

            // Hash the API key before storing
            return $this->encryptApiKey(trim($apiKey));

        } catch (\Exception $e) {
            $this->logger->error(
                'API key validation failed during save: ' . $e->getMessage(),
                [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                ]
            );

            \Contao\Message::addError('Invalid API key. Please verify your OpenAI API key is correct and has proper permissions.');

            return '';
        }
    }

    public function processApiKeyForDisplay($value, $dc = null): string
    {
        // For display, mask the key but return actual value for processing
        if (empty($value)) {
            return '';
        }

        // If we're in the backend and this is a password field, mask the value
        if ($dc && $dc->field === 'api_key') {
            return str_repeat('*', strlen($value));
        }

        // For processing (like file uploads), return the actual value
        return trim($value);
    }

    public function addIcon($row, $label): string
    {
        return $row['title'];
    }

    /**
     * Add a "Key prüfen" button next to the API key field
     */
    public function apiKeyWizard(DataContainer $dc): string
    {
        // Generate CSRF token server-side
        $csrfToken = $this->csrfTokenManager->getToken($this->csrfTokenName)->getValue();

        $buttonId = 'apiKeyCheck_' . $dc->field;

        return ' <button type="button" id="' . $buttonId . '" class="tl_submit">Key prüfen</button>
        <span id="apiKeyResult" style="margin-left:10px;"></span>
        <style>
        .processing-spinner {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid #ccc;
            border-top: 2px solid #007acc;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 5px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        button:has(.processing-spinner) {
            display: flex;
            flex-flow: row nowrap;
            justify-content: space-between;
            align-items: center;
            column-gap: 5px;
        }
        </style>
        <script>
        document.getElementById("' . $buttonId . '").addEventListener("click", function() {
            var apiKey = document.getElementById("ctrl_' . $dc->field . '").value;
            var button = document.getElementById("' . $buttonId . '");
            var resultSpan = document.getElementById("apiKeyResult");
            
            if (!apiKey) {
                alert("Bitte geben Sie zuerst einen API-Schlüssel ein.");
                return;
            }
            
            // Disable button and show processing state
            button.disabled = true;
            button.innerHTML = \'<span class="processing-spinner"></span> Validiere...\';
            resultSpan.innerHTML = \'\';
            
            var xhr = new XMLHttpRequest();
            xhr.open("POST", "' . Environment::get('base') . 'contao/api-key-validate", true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
            
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    // Reset button state
                    button.disabled = false;
                    button.innerHTML = \'Key prüfen\';  // Escaped single quotes
                    
                    try {
                        var result = JSON.parse(xhr.responseText);
                        if (result.valid) {
                            resultSpan.innerHTML = \'<span style="color:green;">✓ API-Schlüssel ist gültig!</span>\';
                            document.getElementById("ctrl_' . $dc->field . '").style.backgroundColor = "lightgreen";
                            document.getElementById("ctrl_' . $dc->field . '").style.color = "#121212";
                        } else {
                            resultSpan.innerHTML = \'<span style="color:red;">✗ API-Schlüssel ist ungültig! \' + (result.message || \'\') + \'</span>\';
                            document.getElementById("ctrl_' . $dc->field . '").style.backgroundColor = "lightcoral";
                            document.getElementById("ctrl_' . $dc->field . '").style.color = "#121212";
                        }
                    } catch (e) {
                        resultSpan.innerHTML = \'<span style="color:red;">✗ Fehler bei der Validierung</span>\';
                    }
                }
            };
            
            xhr.send("action=validateApiKey&key=" + encodeURIComponent(apiKey) + "&REQUEST_TOKEN=' . $csrfToken . '");
        });
        </script>';
    }

    /**
     * Create vector store when submitting the form
     */
    public function createVectorStore($dc): void
    {
        // Implement vector store creation logic here
        $this->logger->info(
            'Vector store created for config ID ' . $dc->id,
            [
                'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
            ]
        );
    }

    /**
     * Delete vector store when deleting the config
     */
    public function deleteVectorStore($dc): void
    {
        if (! $dc->activeRecord) {
            return;
        }

        $vectorStoreId = $dc->activeRecord->vector_store_id;
        if (! $vectorStoreId) {
            return;
        }

        try {
            // First, delete all associated assistants
            $assistants = $this->connection->fetchAllAssociative('
                SELECT id, openai_assistant_id 
                FROM tl_openai_assistants 
                WHERE pid = ?
            ', [$dc->id]);

            foreach ($assistants as $assistant) {
                if ($assistant['openai_assistant_id']) {
                    try {
                        $apiKey = $this->getApiKeyFromDatabase((int) $dc->id);
                        if (! $apiKey) {
                            $this->logger->warning(
                                'No valid API key found for config',
                                [
                                    'contao'    => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                                    'config_id' => $dc->id,
                                ]
                            );

                            continue;
                        }

                        $response = $this->httpClient->request('DELETE', "https://api.openai.com/v1/assistants/{$assistant['openai_assistant_id']}", [
                            'headers' => [
                                'Authorization' => 'Bearer ' . $apiKey,
                                'Content-Type'  => 'application/json',
                                'OpenAI-Beta'   => 'assistants=v2',
                            ],
                        ]);

                        // If we get a 404, the assistant doesn't exist anymore, which is fine
                        if ($response->getStatusCode() === 404) {
                            $this->logger->info(
                                'Assistant already deleted from OpenAI platform',
                                [
                                    'contao'       => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                                    'assistant_id' => $assistant['openai_assistant_id'],
                                ]
                            );

                            continue;
                        }

                        if ($response->getStatusCode() !== 200) {
                            $this->logger->warning(
                                'Failed to delete assistant from OpenAI platform',
                                [
                                    'contao'       => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                                    'assistant_id' => $assistant['openai_assistant_id'],
                                    'status'       => $response->getStatusCode(),
                                ]
                            );

                            continue;
                        }

                        $this->logger->info(
                            'Assistant deleted from OpenAI platform',
                            [
                                'contao'       => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                                'assistant_id' => $assistant['openai_assistant_id'],
                            ]
                        );
                    } catch (\Exception $e) {
                        $this->logger->error(
                            'Error deleting assistant from OpenAI platform: ' . $e->getMessage(),
                            [
                                'contao'       => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                                'assistant_id' => $assistant['openai_assistant_id'],
                            ]
                        );
                    }
                }
            }

            // Then, delete all associated files
            $files = $this->connection->fetchAllAssociative('
                SELECT id, openai_file_id 
                FROM tl_openai_files 
                WHERE pid = ?
            ', [$dc->id]);

            foreach ($files as $file) {
                if ($file['openai_file_id']) {
                    try {
                        $apiKey = $this->getApiKeyFromDatabase((int) $dc->id);
                        if (! $apiKey) {
                            $this->logger->warning(
                                'No valid API key found for config',
                                [
                                    'contao'    => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                                    'config_id' => $dc->id,
                                ]
                            );

                            continue;
                        }

                        $response = $this->httpClient->request('DELETE', "https://api.openai.com/v1/files/{$file['openai_file_id']}", [
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
                                    'file_id' => $file['openai_file_id'],
                                ]
                            );

                            continue;
                        }

                        if ($response->getStatusCode() !== 200) {
                            $this->logger->warning(
                                'Failed to delete file from OpenAI platform',
                                [
                                    'contao'  => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                                    'file_id' => $file['openai_file_id'],
                                    'status'  => $response->getStatusCode(),
                                ]
                            );

                            continue;
                        }

                        $this->logger->info(
                            'File deleted from OpenAI platform',
                            [
                                'contao'  => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                                'file_id' => $file['openai_file_id'],
                            ]
                        );
                    } catch (\Exception $e) {
                        $this->logger->error(
                            'Error deleting file from OpenAI platform: ' . $e->getMessage(),
                            [
                                'contao'  => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                                'file_id' => $file['openai_file_id'],
                            ]
                        );
                    }
                }
            }

            // Finally, delete the vector store
            try {
                $apiKey = $this->getApiKeyFromDatabase((int) $dc->id);
                if (! $apiKey) {
                    $this->logger->warning(
                        'No valid API key found for config',
                        [
                            'contao'    => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                            'config_id' => $dc->id,
                        ]
                    );

                    return;
                }

                $response = $this->httpClient->request('DELETE', "https://api.openai.com/v1/vector_stores/{$vectorStoreId}", [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type'  => 'application/json',
                        'OpenAI-Beta'   => 'assistants=v2',
                    ],
                ]);

                // If we get a 404, the vector store doesn't exist anymore, which is fine
                if ($response->getStatusCode() === 404) {
                    $this->logger->info(
                        'Vector store already deleted from OpenAI platform',
                        [
                            'contao'          => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                            'vector_store_id' => $vectorStoreId,
                        ]
                    );

                    return;
                }

                if ($response->getStatusCode() !== 200) {
                    $this->logger->warning(
                        'Failed to delete vector store from OpenAI platform',
                        [
                            'contao'          => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                            'vector_store_id' => $vectorStoreId,
                            'status'          => $response->getStatusCode(),
                        ]
                    );

                    return;
                }

                $this->logger->info(
                    'Vector store deleted from OpenAI platform',
                    [
                        'contao'          => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                        'vector_store_id' => $vectorStoreId,
                    ]
                );
            } catch (\Exception $e) {
                $this->logger->error(
                    'Error deleting vector store from OpenAI platform: ' . $e->getMessage(),
                    [
                        'contao'          => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                        'vector_store_id' => $vectorStoreId,
                    ]
                );
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Error in deleteVectorStore: ' . $e->getMessage(),
                [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                ]
            );
        }
    }

    /**
     * Copy vector store when copying the config
     */
    public function copyVectorStore($insertId, $dc): void
    {
        // Implement vector store copying logic here
        $this->logger->info(
            'Vector store copied for config ID ' . $insertId,
            [
                'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
            ]
        );
    }

    /**
     * Validate API key
     */
    public function validateApiKey($value, DataContainer $dc)
    {
        if (! $value) {
            return $value;
        }

        try {
            $response = $this->httpClient->request('GET', 'https://api.openai.com/v1/models', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $value,
                    'Content-Type'  => 'application/json',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \Exception('Invalid API key');
            }

            return $value;
        } catch (\Exception $e) {
            throw new \Exception('Invalid API key: ' . $e->getMessage());
        }
    }

    /**
     * Adds the header to the list view
     */
    #[AsCallback(table: 'tl_openai_config', target: 'list.header')]
    public function addHeader(): string
    {
        return '<div class="tl_header">' . $GLOBALS['TL_LANG']['tl_openai_config']['header'] . '</div>';
    }

    public function onLoadCallback($dc): void
    {
        // Check for single record limitation only on create action
        $request = $this->requestStack->getCurrentRequest();
        if ($request && ($request->get('act') === 'create' || $request->get('act') === '')) {
            $this->checkSingleRecordLimitation($dc);
        }
    }

    /**
     * Decrypt API key from storage
     */
    public function decryptApiKey(string $encryptedData): ?string
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

    /**
     * Checks if there's already a config record and prevents creation of additional ones
     */
    private function checkSingleRecordLimitation($dc): void
    {
        // Check if there's already a config record
        $existingConfig = $this->connection->fetchAssociative(
            'SELECT id, title FROM tl_openai_config LIMIT 1'
        );

        if ($existingConfig) {
            // Show message and redirect to the existing config
            \Contao\Message::addInfo($this->getTranslatedString('single_config_redirect', 'Only one OpenAI configuration is allowed. You are being redirected to the existing configuration.'));
            $url = \Contao\Controller::addToUrl('act=edit&id=' . $existingConfig['id']);
            \Contao\Controller::redirect($url);
        }
    }

    /**
     * Get API key from environment variable or database
     * Environment variable takes precedence for security
     */
    private function getApiKeyFromEnvironment(int $configId): ?string
    {
        // Try environment variable first (most secure)
        $envKey = sprintf('OPENAI_API_KEY_%d', $configId);
        if (isset($_ENV[$envKey])) {
            return $_ENV[$envKey];
        }

        // Fallback to database (encrypted)
        return $this->getApiKeyFromDatabase($configId);
    }

    /**
     * Check if API key is stored in environment variable
     */
    private function isApiKeyInEnvironment(int $configId): bool
    {
        $envKey = sprintf('OPENAI_API_KEY_%d', $configId);

        return isset($_ENV[$envKey]);
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
     * Encrypt API key for storage
     */
    private function encryptApiKey(string $apiKey): string
    {
        $key    = $this->getEncryptionKey();
        $method = 'aes-256-cbc';
        $iv     = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));

        $encrypted = openssl_encrypt($apiKey, $method, $key, 0, $iv);

        // Combine IV and encrypted data
        return base64_encode($iv . $encrypted);
    }

    /**
     * Get API key from database (encrypted storage)
     */
    private function getApiKeyFromDatabase(int $configId): ?string
    {
        $config = $this->connection->fetchAssociative(
            'SELECT api_key FROM tl_openai_config WHERE id = ?',
            [$configId]
        );

        if ($config && $config['api_key']) {
            // Check if it's encrypted (base64 encoded and longer than typical API key)
            if (strlen($config['api_key']) > 100) {
                // Try to decrypt it
                return $this->decryptApiKey($config['api_key']);
            }
            // It's still in old base64 format, decode it
            return base64_decode($config['api_key'], true);

        }

        return null;
    }

    /**
     * Get translated string with fallback
     */
    private function getTranslatedString(string $key, string $fallback): string
    {
        // Load language file if not already loaded
        $language = $GLOBALS['TL_LANGUAGE'] ?? 'en';
        System::loadLanguageFile('tl_openai_config', $language);

        // Get translated strings
        $lang = $GLOBALS['TL_LANG']['tl_openai_config'] ?? [];

        return $lang[$key] ?? $fallback;
    }
}
