<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) Leo Feyer
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAiAssistant
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly Connection $connection
    ) {
    }

    /**
     * Process a message through the OpenAI Assistant API
     */
    public function processMessage(string $message, SessionInterface $session): string
    {
        $config = $this->getActiveConfig();
        if (! $config) {
            throw new \RuntimeException('No OpenAI configuration found');
        }

        $assistant = $this->getActiveAssistant($config['id']);
        if (! $assistant) {
            throw new \RuntimeException('No assistant configured');
        }

        $apiKey      = $this->processApiKey($config['api_key']);
        $assistantId = $assistant['openai_assistant_id'];

        // Get thread ID from session or create new one
        $threadId = $session->get('openai_thread_id');
        if (! $threadId) {
            $threadId = $this->createThread($apiKey);
            $session->set('openai_thread_id', $threadId);
        }

        return $this->processMessageInThread($apiKey, $threadId, $assistantId, $message, $assistant);
    }

    /**
     * Get the active OpenAI configuration
     */
    public function getActiveConfig(): ?array
    {
        $result = $this->connection->fetchAssociative(
            'SELECT * FROM tl_openai_config WHERE api_key IS NOT NULL ORDER BY tstamp DESC LIMIT 1'
        );

        return $result ?: null;
    }

    /**
     * Get the active assistant for a given configuration
     */
    public function getActiveAssistant(int $configId): ?array
    {
        $result = $this->connection->fetchAssociative(
            'SELECT * FROM tl_openai_assistants WHERE pid = ? AND status = ? ORDER BY tstamp DESC LIMIT 1',
            [$configId, 'active']
        );

        return $result ?: null;
    }

    /**
     * Clear the current thread (useful for starting new conversations)
     */
    public function clearThread(SessionInterface $session): void
    {
        $session->remove('openai_thread_id');
        $this->logger->info('Cleared OpenAI thread from session');
    }

    /**
     * Get available models for assistant configuration
     */
    public function getAvailableModels(string $apiKey): array
    {
        $models = [
            'gpt-4'         => 'GPT-4',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
        ];

        $this->logger->info('Using hardcoded models', [
            'models' => array_keys($models),
        ]);

        return $models;
    }

    /**
     * Validate API key format - supports all OpenAI key formats
     */
    public function isValidApiKeyFormat(string $apiKey): bool
    {
        if (empty($apiKey)) {
            return false;
        }

        // Support all current OpenAI API key formats
        $validPrefixes = ['sk-', 'sk-proj-', 'sk-None-', 'sk-svcacct-'];

        foreach ($validPrefixes as $prefix) {
            if (str_starts_with($apiKey, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the chat history for a thread
     */
    public function getThreadHistory(string $threadId, string $apiKey): array
    {
        try {
            $response = $this->http->request(
                'GET',
                "https://api.openai.com/v1/threads/{$threadId}/messages",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'OpenAI-Beta'   => 'assistants=v2',
                    ],
                    'timeout' => 30,
                ]
            );

            $data    = $response->toArray();
            $history = [];

            if (! empty($data['data'])) {
                foreach ($data['data'] as $message) {
                    if ($message['role'] === 'assistant' || $message['role'] === 'user') {
                        $history[] = [
                            'role'      => $message['role'],
                            'content'   => $message['content'][0]['text']['value'] ?? '',
                            'timestamp' => date('Y-m-d H:i:s', $message['created_at']),
                        ];
                    }
                }
            }

            return $history;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get thread history: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Create a new thread in OpenAI
     */
    private function createThread(string $apiKey): string
    {
        try {
            $response = $this->http->request('POST', 'https://api.openai.com/v1/threads', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                    'OpenAI-Beta'   => 'assistants=v2',
                ],
                'timeout' => 30,
            ]);

            $data = $response->toArray();

            $this->logger->info('Created new OpenAI thread', [
                'thread_id' => $data['id'],
            ]);

            return $data['id'];
        } catch (\Exception $e) {
            $this->logger->error('Failed to create OpenAI thread: ' . $e->getMessage());

            throw new \RuntimeException('Failed to create conversation thread: ' . $e->getMessage());
        }
    }

    /**
     * Process a message within an existing thread
     */
    private function processMessageInThread(
        string $apiKey,
        string $threadId,
        string $assistantId,
        string $message,
        array $assistant
    ): string {
        try {
            // Add message to thread
            $this->addMessageToThread($apiKey, $threadId, $message);

            // Create run with assistant-specific parameters
            $runId = $this->createRun($apiKey, $threadId, $assistantId, $assistant);

            // Wait for completion
            $this->waitForRunCompletion($apiKey, $threadId, $runId);

            // Retrieve the response
            return $this->getLatestResponse($apiKey, $threadId);

        } catch (\Exception $e) {
            $this->logger->error('Failed to process message in thread: ' . $e->getMessage(), [
                'thread_id'    => $threadId,
                'assistant_id' => $assistantId,
            ]);

            throw new \RuntimeException('Failed to process message: ' . $e->getMessage());
        }
    }

    /**
     * Add a user message to the thread
     */
    private function addMessageToThread(string $apiKey, string $threadId, string $message): void
    {
        $this->http->request('POST', "https://api.openai.com/v1/threads/{$threadId}/messages", [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
                'OpenAI-Beta'   => 'assistants=v2',
            ],
            'json' => [
                'role'    => 'user',
                'content' => $message,
            ],
            'timeout' => 30,
        ]);
    }

    /**
     * Create a run with assistant-specific parameters
     */
    private function createRun(string $apiKey, string $threadId, string $assistantId, array $assistant): string
    {
        $runData = [
            'assistant_id' => $assistantId,
        ];

        // Add temperature parameter if available (moved from config to assistant level)
        if (isset($assistant['temperature']) && $assistant['temperature'] !== null) {
            $runData['temperature'] = (float) $assistant['temperature'];
        }

        // Note: max_tokens is not directly supported in Assistants API runs
        // It would need to be handled through other means like post-processing or instructions
        if (isset($assistant['max_tokens']) && $assistant['max_tokens'] > 0) {
            $this->logger->info('Max tokens setting detected but not directly supported in Assistants API', [
                'max_tokens'   => $assistant['max_tokens'],
                'assistant_id' => $assistantId,
            ]);
        }

        $response = $this->http->request('POST', "https://api.openai.com/v1/threads/{$threadId}/runs", [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
                'OpenAI-Beta'   => 'assistants=v2',
            ],
            'json'    => $runData,
            'timeout' => 60,
        ]);

        $run = $response->toArray();

        return $run['id'];
    }

    /**
     * Wait for run completion with timeout
     */
    private function waitForRunCompletion(string $apiKey, string $threadId, string $runId): void
    {
        $maxAttempts = 60; // 60 seconds maximum wait time
        $attempts    = 0;

        do {
            sleep(1);
            $attempts++;

            $statusResponse = $this->http->request(
                'GET',
                "https://api.openai.com/v1/threads/{$threadId}/runs/{$runId}",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'OpenAI-Beta'   => 'assistants=v2',
                    ],
                    'timeout' => 30,
                ]
            );

            $run = $statusResponse->toArray();

            if ($run['status'] === 'completed') {
                return;
            }

            if (in_array($run['status'], ['failed', 'cancelled', 'expired'], true)) {
                throw new \RuntimeException("Assistant run failed with status: {$run['status']}");
            }

            if ($attempts >= $maxAttempts) {
                throw new \RuntimeException('Assistant run timed out');
            }

        } while (in_array($run['status'], ['queued', 'in_progress', 'cancelling'], true));
    }

    /**
     * Get the latest assistant response from the thread
     */
    private function getLatestResponse(string $apiKey, string $threadId): string
    {
        $messagesResponse = $this->http->request(
            'GET',
            "https://api.openai.com/v1/threads/{$threadId}/messages",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'OpenAI-Beta'   => 'assistants=v2',
                ],
                'timeout' => 30,
            ]
        );

        $messages = $messagesResponse->toArray();

        if (empty($messages['data'])) {
            throw new \RuntimeException('No messages found in thread');
        }

        // Get the first message (most recent) that is from the assistant
        foreach ($messages['data'] as $message) {
            if ($message['role'] === 'assistant' && ! empty($message['content'])) {
                // Extract text content from the message
                foreach ($message['content'] as $content) {
                    if ($content['type'] === 'text') {
                        return $content['text']['value'];
                    }
                }
            }
        }

        throw new \RuntimeException('No assistant response found');
    }

    /**
     * Get default model fallbacks
     */
    private function getDefaultModels(): array
    {
        return [
            'gpt-4'         => 'GPT-4',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
        ];
    }

    /**
     * Process API key - decrypt if encrypted, decode if base64
     */
    private function processApiKey(string $storedApiKey): ?string
    {
        if (empty($storedApiKey)) {
            return null;
        }

        // Check if this is an encrypted key (longer than 100 chars) or legacy base64
        if (strlen($storedApiKey) > 100) {
            // This is an encrypted key - we need to get the decryption method
            // For now, we'll use a simple approach since we don't have access to the config listener
            $apiKey = $this->decryptApiKey($storedApiKey);
        } else {
            // This is a legacy base64 encoded key
            $apiKey = base64_decode($storedApiKey, true);
        }

        if (! $apiKey || ! $this->isValidApiKeyFormat($apiKey)) {
            $this->logger->error('Invalid API key format detected', [
                'api_key_length' => strlen($storedApiKey),
                'api_key_prefix' => substr($storedApiKey, 0, 10),
            ]);

            return null;
        }

        return $apiKey;
    }

    /**
     * Decrypt API key using the same method as OpenAiConfigListener
     */
    private function decryptApiKey(string $encryptedData): ?string
    {
        try {
            // Generate the same encryption key as in OpenAiConfigListener
            $serverName    = $_SERVER['SERVER_NAME'] ?? 'localhost';
            $documentRoot  = $_SERVER['DOCUMENT_ROOT'] ?? '/';
            $encryptionKey = hash('sha256', $serverName . $documentRoot, true);

            // Extract IV and encrypted data
            $data = base64_decode($encryptedData, true);
            if (strlen($data) < 16) {
                return null;
            }

            $iv        = substr($data, 0, 16);
            $encrypted = substr($data, 16);

            // Decrypt
            $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $encryptionKey, 0, $iv);

            return $decrypted !== false ? $decrypted : null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to decrypt API key: ' . $e->getMessage());

            return null;
        }
    }
}
