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

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Runtime chat service built on the OpenAI Responses API + Conversations API.
 *
 * Replaces the former OpenAiAssistant service, which relied on the deprecated
 * Assistants API (/v1/threads, /v1/assistants).
 *
 * State model:
 *   - A Conversation (POST /v1/conversations) is created lazily on the first user
 *     turn and its id is stored in the session under "openai_conversation_id".
 *   - Each user turn is a single POST /v1/responses call that references the
 *     conversation, the tool set (file_search + vector stores), and the prompt
 *     configuration (either inline instructions + model + temperature + top_p,
 *     or a dashboard prompt via {prompt_id, version}).
 */
class OpenAiResponder
{
    private const SESSION_CONVERSATION_KEY = 'openai_conversation_id';

    private const LEGACY_SESSION_THREAD_KEY = 'openai_thread_id';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly Connection $connection,
        private readonly EncryptionService $encryption
    ) {
    }

    /**
     * Process a single user message and return the assistant reply text.
     */
    public function processMessage(string $message, SessionInterface $session): string
    {
        $config = $this->getActiveConfig();
        if (! $config) {
            throw new \RuntimeException('No OpenAI configuration found');
        }

        $prompt = $this->getActivePrompt((int) $config['id']);
        if (! $prompt) {
            throw new \RuntimeException('No prompt configured');
        }

        $apiKey = $this->encryption->getApiKeyForConfig((int) $config['id'])
            ?? $this->encryption->processApiKey((string) ($config['api_key'] ?? ''));

        if (! $apiKey) {
            throw new \RuntimeException('No valid API key available');
        }

        $this->dropLegacyThreadId($session);
        $conversationId = $this->ensureConversation($apiKey, $session, (int) $config['id']);
        $vectorStoreId  = $config['vector_store_id'] ?? null;

        return $this->sendResponse($apiKey, $conversationId, $message, $prompt, $vectorStoreId);
    }

    /**
     * Get the active OpenAI configuration (most recent record).
     */
    public function getActiveConfig(): ?array
    {
        $result = $this->connection->fetchAssociative(
            'SELECT * FROM tl_openai_config WHERE api_key IS NOT NULL ORDER BY tstamp DESC LIMIT 1'
        );

        return $result ?: null;
    }

    /**
     * Get the active prompt (formerly assistant) record for a given configuration.
     */
    public function getActivePrompt(int $configId): ?array
    {
        $result = $this->connection->fetchAssociative(
            'SELECT * FROM tl_openai_prompts WHERE pid = ? AND status = ? ORDER BY tstamp DESC LIMIT 1',
            [$configId, 'active']
        );

        return $result ?: null;
    }

    /**
     * Clear the current conversation (useful for starting fresh).
     */
    public function clearConversation(SessionInterface $session): void
    {
        $session->remove(self::SESSION_CONVERSATION_KEY);
        $session->remove(self::LEGACY_SESSION_THREAD_KEY);
        $this->logger->info('Cleared OpenAI conversation from session');
    }

    /**
     * Retrieve the chat history for a conversation as an ordered list.
     *
     * Return shape matches the legacy thread history helper so that the frontend
     * module and history endpoint can consume it without changes:
     *   [ ['role' => 'user'|'assistant', 'content' => string, 'timestamp' => string], ... ]
     */
    public function getConversationHistory(string $conversationId, string $apiKey): array
    {
        try {
            $response = $this->http->request(
                'GET',
                sprintf('https://api.openai.com/v1/conversations/%s/items', $conversationId),
                [
                    'query' => [
                        'order' => 'asc',
                        'limit' => 100,
                    ],
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                    ],
                    'timeout' => 30,
                ]
            );

            $data    = $response->toArray(false);
            $history = [];

            foreach ($data['data'] ?? [] as $item) {
                if (($item['type'] ?? null) !== 'message') {
                    continue;
                }

                $role = $item['role'] ?? null;
                if ($role !== 'user' && $role !== 'assistant') {
                    continue;
                }

                $text = $this->extractTextFromContent($item['content'] ?? [], $role);
                if ($text === '') {
                    continue;
                }

                $createdAt = $item['created_at'] ?? time();
                $history[] = [
                    'role'      => $role,
                    'content'   => $text,
                    'timestamp' => date('Y-m-d H:i:s', (int) $createdAt),
                ];
            }

            return $history;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get conversation history: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Validate API key format - supports all OpenAI key formats.
     */
    public function isValidApiKeyFormat(string $apiKey): bool
    {
        return $this->encryption->isValidApiKeyFormat($apiKey);
    }

    /**
     * Lazily create a Conversation for this session.
     */
    private function ensureConversation(string $apiKey, SessionInterface $session, int $configId): string
    {
        $conversationId = $session->get(self::SESSION_CONVERSATION_KEY);
        if (is_string($conversationId) && $conversationId !== '') {
            return $conversationId;
        }

        try {
            $response = $this->http->request('POST', 'https://api.openai.com/v1/conversations', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'metadata' => [
                        'source'    => 'contao-openai-assistant',
                        'config_id' => (string) $configId,
                    ],
                ],
                'timeout' => 30,
            ]);

            $data = $response->toArray();
            $id   = (string) ($data['id'] ?? '');

            if ($id === '') {
                throw new \RuntimeException('OpenAI did not return a conversation id');
            }

            $session->set(self::SESSION_CONVERSATION_KEY, $id);

            $this->logger->info('Created new OpenAI conversation', [
                'conversation_id' => $id,
                'config_id'       => $configId,
            ]);

            return $id;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create OpenAI conversation: ' . $e->getMessage());

            throw new \RuntimeException('Failed to create conversation: ' . $e->getMessage());
        }
    }

    /**
     * Perform the Responses API call and return the assistant's text reply.
     */
    private function sendResponse(
        string $apiKey,
        string $conversationId,
        string $message,
        array $prompt,
        ?string $vectorStoreId
    ): string {
        $modelToUse = $this->resolveModel($prompt);

        $payload = [
            'model'        => $modelToUse,
            'conversation' => $conversationId,
            'input'        => $message,
            'store'        => true,
        ];

        $promptId = trim((string) ($prompt['prompt_id'] ?? ''));
        if ($promptId !== '') {
            $promptBlock = [
                'prompt_id' => $promptId,
            ];
            $version     = trim((string) ($prompt['prompt_version'] ?? ''));
            if ($version !== '') {
                $promptBlock['version'] = $version;
            }
            $payload['prompt'] = $promptBlock;
        } else {
            $instructions = trim((string) ($prompt['system_instructions'] ?? ''));
            if ($instructions !== '') {
                $payload['instructions'] = $instructions;
            }
        }

        if (array_key_exists('temperature', $prompt) && $prompt['temperature'] !== null) {
            $payload['temperature'] = (float) $prompt['temperature'];
        }

        if (array_key_exists('top_p', $prompt) && $prompt['top_p'] !== null) {
            $payload['top_p'] = (float) $prompt['top_p'];
        }

        if (! empty($prompt['max_tokens']) && (int) $prompt['max_tokens'] > 0) {
            $payload['max_output_tokens'] = (int) $prompt['max_tokens'];
        }

        if (! empty($vectorStoreId)) {
            $payload['tools'] = [
                [
                    'type'             => 'file_search',
                    'vector_store_ids' => [$vectorStoreId],
                ],
            ];
        } else {
            $this->logger->warning('No vector store ID available; sending Response without file_search tool', [
                'conversation_id' => $conversationId,
            ]);
        }

        try {
            $response = $this->http->request('POST', 'https://api.openai.com/v1/responses', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json'    => $payload,
                'timeout' => 180,
            ]);

            $data = $response->toArray(false);

            if ($response->getStatusCode() !== 200) {
                $error = (string) ($data['error']['message'] ?? $response->getContent(false));
                throw new \RuntimeException('Responses API returned HTTP '
                    . $response->getStatusCode() . ': ' . $error);
            }

            $status = (string) ($data['status'] ?? 'unknown');
            if ($status !== 'completed') {
                $reason = (string) ($data['incomplete_details']['reason']
                    ?? $data['error']['message']
                    ?? $status);
                throw new \RuntimeException('Response did not complete (' . $status . '): ' . $reason);
            }

            $text = $this->extractAssistantText($data);
            if ($text === '') {
                throw new \RuntimeException('No assistant response found');
            }

            return $text;
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to call Responses API: ' . $e->getMessage(), [
                'conversation_id' => $conversationId,
            ]);

            throw new \RuntimeException('Failed to process message: ' . $e->getMessage());
        }
    }

    /**
     * Extract the assistant reply text from a Responses API response object.
     */
    private function extractAssistantText(array $responseData): string
    {
        foreach ($responseData['output'] ?? [] as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }
            if (($item['role'] ?? null) !== 'assistant') {
                continue;
            }

            $collected = '';
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'output_text' && isset($content['text'])) {
                    $collected .= (string) $content['text'];
                }
            }

            if ($collected !== '') {
                return $collected;
            }
        }

        return '';
    }

    /**
     * Extract a flat text string from a conversation item's content array.
     *
     * Handles both "input_text" (user messages) and "output_text" (assistant messages).
     */
    private function extractTextFromContent(array $content, string $role): string
    {
        $expectedType = $role === 'assistant' ? 'output_text' : 'input_text';
        $text         = '';

        foreach ($content as $entry) {
            $type = $entry['type'] ?? null;
            if ($type === $expectedType && isset($entry['text'])) {
                $text .= (string) $entry['text'];
            }
        }

        return $text;
    }

    /**
     * Resolve the actual model id to use (supporting the "manual" override).
     */
    private function resolveModel(array $prompt): string
    {
        $model = (string) ($prompt['model'] ?? '');
        if ($model === 'manual') {
            return (string) ($prompt['model_manual'] ?? '');
        }

        return $model;
    }

    /**
     * Silently discard any legacy thread id left over from a pre-2.0 upgrade.
     */
    private function dropLegacyThreadId(SessionInterface $session): void
    {
        if ($session->has(self::LEGACY_SESSION_THREAD_KEY)) {
            $session->remove(self::LEGACY_SESSION_THREAD_KEY);
            $this->logger->info('Dropped legacy openai_thread_id from session (Assistants API sunset)');
        }
    }
}
