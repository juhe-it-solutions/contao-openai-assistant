<?php

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Service;

use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Exception\ContextWindowExceededException;
use JuheItSolutions\ContaoOpenaiAssistant\Exception\ConversationNotFoundException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
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

    /**
     * The config id the session's conversation was created under. A config
     * switch (possibly a different OpenAI account) invalidates the stored
     * conversation id, so it is dropped instead of producing 404s.
     */
    private const SESSION_CONVERSATION_CONFIG_KEY = 'openai_conversation_config_id';

    private const LEGACY_SESSION_THREAD_KEY = 'openai_thread_id';

    /**
     * Wall-clock cap for one Responses call. The 180s "timeout" option is only
     * an inactivity timeout; without max_duration a slow-dripping response
     * could occupy the PHP worker indefinitely.
     */
    private const RESPONSE_TIMEOUT = 180;

    /**
     * Cache TTL for remembered per-model parameter rejections (seconds).
     */
    private const REJECTED_PARAMS_TTL = 2592000;

    /**
     * How many file_search chunks a single turn may inject when the prompt row
     * has no usable max_num_results value (pre-migration rows, value 0). The
     * OpenAI-side maximum is 50, but retrieved chunks are persisted in the
     * conversation and replayed on every later turn, so the range is capped
     * at 20 (the previous implicit default).
     */
    private const DEFAULT_FILE_SEARCH_RESULTS = 8;

    private const MIN_FILE_SEARCH_RESULTS = 1;

    private const MAX_FILE_SEARCH_RESULTS = 20;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly Connection $connection,
        private readonly EncryptionService $encryption,
        private readonly CacheItemPoolInterface|null $cache = null,
    ) {
    }

    /**
     * Process a single user message and return the assistant reply text.
     */
    public function processMessage(string $message, SessionInterface $session): string
    {
        $config = $this->getActiveConfig();
        if (!$config) {
            throw new \RuntimeException('No OpenAI configuration found');
        }

        $prompt = $this->getActivePrompt((int) $config['id']);
        if (!$prompt) {
            throw new \RuntimeException('No prompt configured');
        }

        $apiKey = $this->encryption->getApiKeyForConfig((int) $config['id'])
            ?? $this->encryption->processApiKey((string) ($config['api_key'] ?? ''));

        if (!$apiKey) {
            throw new \RuntimeException('No valid API key available');
        }

        $this->dropLegacyThreadId($session);
        $conversationId = $this->ensureConversation($apiKey, $session, (int) $config['id']);
        $vectorStoreId = $config['vector_store_id'] ?? null;
        $safetyIdentifier = $this->resolveSafetyIdentifier($session);

        try {
            return $this->sendResponse($apiKey, $conversationId, $message, $prompt, $vectorStoreId, $safetyIdentifier);
        } catch (ContextWindowExceededException|ConversationNotFoundException $e) {
            // Context overflow should not happen with truncation=auto, and a 404
            // means the stored conversation is gone (deleted/expired on OpenAI's
            // side, or the API key now belongs to a different account). Either
            // way: retry once on a fresh conversation so the visitor gets an
            // answer instead of an error.
            $this->logger->warning(
                'Conversation unusable ('.$e::class.'); retrying on a fresh conversation',
                [
                    'conversation_id' => $conversationId,
                    'error' => $e->getMessage(),
                ],
            );

            $this->clearConversation($session);
            $conversationId = $this->ensureConversation($apiKey, $session, (int) $config['id']);

            return $this->sendResponse($apiKey, $conversationId, $message, $prompt, $vectorStoreId, $safetyIdentifier);
        }
    }

    /**
     * Get the active OpenAI configuration (most recent record).
     */
    public function getActiveConfig(): array|null
    {
        $result = $this->connection->fetchAssociative(
            'SELECT * FROM tl_openai_config WHERE api_key IS NOT NULL ORDER BY tstamp DESC LIMIT 1',
        );

        return $result ?: null;
    }

    /**
     * Get the active prompt (formerly assistant) record for a given configuration.
     */
    public function getActivePrompt(int $configId): array|null
    {
        $result = $this->connection->fetchAssociative(
            'SELECT * FROM tl_openai_prompts WHERE pid = ? AND status = ? ORDER BY tstamp DESC LIMIT 1',
            [$configId, 'active'],
        );

        return $result ?: null;
    }

    /**
     * Clear the current conversation (useful for starting fresh).
     */
    public function clearConversation(SessionInterface $session): void
    {
        $session->remove(self::SESSION_CONVERSATION_KEY);
        $session->remove(self::SESSION_CONVERSATION_CONFIG_KEY);
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
                \sprintf('https://api.openai.com/v1/conversations/%s/items', $conversationId),
                [
                    // Newest items first: long conversations exceed the page size,
                    // and a reload must show the most recent turns, not the oldest.
                    // The collected list is reversed below to stay oldest-first.
                    'query' => [
                        'order' => 'desc',
                        'limit' => 100,
                    ],
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                    ],
                    'timeout' => 30,
                ],
            );

            $data = $response->toArray(false);
            $history = [];

            foreach ($data['data'] ?? [] as $item) {
                if (($item['type'] ?? null) !== 'message') {
                    continue;
                }

                $role = $item['role'] ?? null;
                if ('user' !== $role && 'assistant' !== $role) {
                    continue;
                }

                $text = $this->extractTextFromContent($item['content'] ?? [], $role);
                if ('' === $text) {
                    continue;
                }

                $createdAt = $item['created_at'] ?? time();
                $history[] = [
                    'role' => $role,
                    'content' => $text,
                    'timestamp' => date('Y-m-d H:i:s', (int) $createdAt),
                ];
            }

            return array_reverse($history);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get conversation history: '.$e->getMessage());

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
        if (\is_string($conversationId) && '' !== $conversationId) {
            $boundConfigId = $session->get(self::SESSION_CONVERSATION_CONFIG_KEY);

            // Sessions from before the binding existed are adopted instead of
            // discarded, so an upgrade does not reset running chats. If the
            // conversation actually belongs to another account, the 404
            // self-heal in processMessage() recovers on the next message.
            if (null === $boundConfigId) {
                $session->set(self::SESSION_CONVERSATION_CONFIG_KEY, $configId);

                return $conversationId;
            }

            if ((int) $boundConfigId === $configId) {
                return $conversationId;
            }

            // The conversation was created under another config (possibly another
            // OpenAI account); its id would 404 there, so start fresh.
            $this->logger->info(
                'Discarding conversation bound to a different config',
                [
                    'conversation_id' => $conversationId,
                    'bound_config_id' => (int) $boundConfigId,
                    'config_id' => $configId,
                ],
            );
            $this->clearConversation($session);
        }

        try {
            $response = $this->http->request(
                'POST',
                'https://api.openai.com/v1/conversations',
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'metadata' => [
                            'source' => 'contao-openai-assistant',
                            'config_id' => (string) $configId,
                        ],
                    ],
                    'timeout' => 30,
                ],
            );

            $data = $response->toArray();
            $id = (string) ($data['id'] ?? '');

            if ('' === $id) {
                throw new \RuntimeException('OpenAI did not return a conversation id');
            }

            $session->set(self::SESSION_CONVERSATION_KEY, $id);
            $session->set(self::SESSION_CONVERSATION_CONFIG_KEY, $configId);

            $this->logger->info(
                'Created new OpenAI conversation',
                [
                    'conversation_id' => $id,
                    'config_id' => $configId,
                ],
            );

            return $id;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create OpenAI conversation: '.$e->getMessage());

            throw new \RuntimeException('Failed to create conversation: '.$e->getMessage());
        }
    }

    /**
     * Perform the Responses API call and return the assistant's text reply.
     *
     * Bounded self-healing around the single POST:
     *   - sampling parameters some models reject (temperature/top_p) are stripped
     *     and remembered per model id, then the call is repeated;
     *   - one retry for failures where the message provably was not processed
     *     (connect-phase transport errors, HTTP 429/503);
     *   - context-window and conversation-not-found rejections become typed
     *     exceptions so processMessage() can restart on a fresh conversation.
     */
    private function sendResponse(string $apiKey, string $conversationId, string $message, array $prompt, string|null $vectorStoreId, string|null $safetyIdentifier): string
    {
        $modelToUse = $this->resolveModel($prompt);
        $payload = $this->buildResponsePayload($modelToUse, $conversationId, $message, $prompt, $vectorStoreId, $safetyIdentifier);
        $transientRetried = false;

        while (true) {
            try {
                $response = $this->http->request(
                    'POST',
                    'https://api.openai.com/v1/responses',
                    [
                        'headers' => [
                            'Authorization' => 'Bearer '.$apiKey,
                            'Content-Type' => 'application/json',
                        ],
                        'json' => $payload,
                        'timeout' => self::RESPONSE_TIMEOUT,
                        'max_duration' => self::RESPONSE_TIMEOUT,
                    ],
                );

                $statusCode = $response->getStatusCode();

                try {
                    $data = $response->toArray(false);
                } catch (DecodingExceptionInterface) {
                    // Non-JSON body (e.g. an HTML error page from a proxy); the
                    // raw content is used as error message below.
                    $data = [];
                }
            } catch (TransportExceptionInterface $e) {
                if (!$transientRetried && $this->isConnectPhaseError($e)) {
                    $transientRetried = true;
                    $this->logger->warning('Transport error before OpenAI processed the message; retrying once: '.$e->getMessage());
                    usleep(1000000);
                    continue;
                }

                $this->logger->error(
                    'Failed to call Responses API: '.$e->getMessage(),
                    [
                        'conversation_id' => $conversationId,
                    ],
                );

                throw new \RuntimeException('Failed to process message: '.$e->getMessage());
            }

            if (200 !== $statusCode) {
                $error = (string) ($data['error']['message'] ?? $response->getContent(false));

                // Models that do not accept temperature/top_p (reasoning models)
                // reject with a param-specific 400; strip the parameter, remember
                // the rejection for this model and repeat. Bounded by the number
                // of strippable parameters in the payload.
                $rejectedParam = $this->detectRejectedSamplingParam($statusCode, $data, $error);
                if (null !== $rejectedParam && \array_key_exists($rejectedParam, $payload)) {
                    unset($payload[$rejectedParam]);
                    $this->rememberRejectedParam($modelToUse, $rejectedParam);
                    $this->logger->info(
                        \sprintf('Model "%s" rejects "%s"; repeating the call without it', $modelToUse, $rejectedParam),
                    );
                    continue;
                }

                // 429/503 mean the message was rejected before processing; one
                // retry after a short backoff absorbs most transient blips.
                if (!$transientRetried && \in_array($statusCode, [429, 503], true)) {
                    $transientRetried = true;
                    $this->logger->warning('Responses API returned HTTP '.$statusCode.'; retrying once');
                    usleep(1000000);
                    continue;
                }

                if ($this->isContextWindowError($statusCode, $data, $error)) {
                    throw new ContextWindowExceededException('Responses API returned HTTP '.$statusCode.': '.$error);
                }

                if ($this->isConversationNotFoundError($statusCode, $error)) {
                    throw new ConversationNotFoundException('Responses API returned HTTP '.$statusCode.': '.$error);
                }

                throw new \RuntimeException('Responses API returned HTTP '.$statusCode.': '.$error);
            }

            $status = (string) ($data['status'] ?? 'unknown');
            if ('completed' !== $status) {
                $reason = (string) ($data['incomplete_details']['reason']
                    ?? $data['error']['message']
                    ?? $status);

                throw new \RuntimeException('Response did not complete ('.$status.'): '.$reason);
            }

            $text = $this->extractAssistantText($data);
            if ('' === $text) {
                throw new \RuntimeException('No assistant response found');
            }

            return $text;
        }
    }

    /**
     * Assemble the Responses API payload for one user turn.
     */
    private function buildResponsePayload(string $model, string $conversationId, string $message, array $prompt, string|null $vectorStoreId, string|null $safetyIdentifier): array
    {
        $payload = [
            'model' => $model,
            'conversation' => $conversationId,
            'input' => $message,
            'store' => true,
            // The API default is truncation=disabled, which returns HTTP 400 once
            // the replayed conversation exceeds the model's context window. With
            // "auto", OpenAI drops the oldest conversation items instead.
            'truncation' => 'auto',
        ];

        if (null !== $safetyIdentifier) {
            // Pseudonymous per-visitor id so OpenAI can attribute abuse to a
            // single visitor instead of the site owner's whole API key.
            $payload['safety_identifier'] = $safetyIdentifier;
        }

        $promptId = trim((string) ($prompt['prompt_id'] ?? ''));
        if ('' !== $promptId) {
            $promptBlock = [
                'prompt_id' => $promptId,
            ];
            $version = trim((string) ($prompt['prompt_version'] ?? ''));
            if ('' !== $version) {
                $promptBlock['version'] = $version;
            }
            $payload['prompt'] = $promptBlock;
        } else {
            $instructions = trim((string) ($prompt['system_instructions'] ?? ''));
            if ('' !== $instructions) {
                $payload['instructions'] = $instructions;
            }
        }

        $rejectedParams = $this->getRejectedParams($model);

        if (!\in_array('temperature', $rejectedParams, true) && \array_key_exists('temperature', $prompt) && null !== $prompt['temperature']) {
            $payload['temperature'] = (float) $prompt['temperature'];
        }

        if (!\in_array('top_p', $rejectedParams, true) && \array_key_exists('top_p', $prompt) && null !== $prompt['top_p']) {
            $payload['top_p'] = (float) $prompt['top_p'];
        }

        if (!empty($prompt['max_tokens']) && (int) $prompt['max_tokens'] > 0) {
            $payload['max_output_tokens'] = (int) $prompt['max_tokens'];
        }

        if (!empty($vectorStoreId)) {
            $payload['tools'] = [
                [
                    'type' => 'file_search',
                    'vector_store_ids' => [$vectorStoreId],
                    'max_num_results' => $this->resolveFileSearchResults($prompt),
                ],
            ];
        } else {
            $this->logger->warning(
                'No vector store ID available; sending Response without file_search tool',
                [
                    'conversation_id' => $conversationId,
                ],
            );
        }

        return $payload;
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

            if ('' !== $collected) {
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
        $expectedType = 'assistant' === $role ? 'output_text' : 'input_text';
        $text = '';

        foreach ($content as $entry) {
            $type = $entry['type'] ?? null;
            if ($type === $expectedType && isset($entry['text'])) {
                $text .= (string) $entry['text'];
            }
        }

        return $text;
    }

    /**
     * Resolve the number of file_search chunks one turn may retrieve.
     *
     * Prompt rows saved before the max_num_results column existed (or with a
     * value of 0) fall back to the default; stored values are clamped to the
     * range the backend field allows.
     */
    private function resolveFileSearchResults(array $prompt): int
    {
        $value = (int) ($prompt['max_num_results'] ?? 0);
        if ($value < self::MIN_FILE_SEARCH_RESULTS) {
            return self::DEFAULT_FILE_SEARCH_RESULTS;
        }

        return min($value, self::MAX_FILE_SEARCH_RESULTS);
    }

    /**
     * Detect the "input exceeds the context window" rejection of the Responses API.
     */
    private function isContextWindowError(int $statusCode, array $data, string $message): bool
    {
        if (400 !== $statusCode) {
            return false;
        }

        $code = (string) ($data['error']['code'] ?? '');

        return 'context_length_exceeded' === $code
            || false !== stripos($message, 'context window')
            || false !== stripos($message, 'context length');
    }

    /**
     * Detect the rejection of a referenced conversation that no longer exists.
     */
    private function isConversationNotFoundError(int $statusCode, string $message): bool
    {
        return 404 === $statusCode && false !== stripos($message, 'conversation');
    }

    /**
     * Detect a 400 that blames a strippable sampling parameter.
     *
     * Backend validation guarantees temperature/top_p values are in range, so a
     * 400 naming one of them can only mean the model does not support it (e.g.
     * reasoning models): "Unsupported parameter: 'temperature' ..." with
     * error.param set. Returns the parameter name, or null.
     */
    private function detectRejectedSamplingParam(int $statusCode, array $data, string $message): string|null
    {
        if (400 !== $statusCode) {
            return null;
        }

        $param = (string) ($data['error']['param'] ?? '');
        if (\in_array($param, ['temperature', 'top_p'], true)) {
            return $param;
        }

        if (1 === preg_match("/Unsupported (?:parameter|value):? '(temperature|top_p)'/i", $message, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * A transport error during the connect phase (DNS, refused connection, TLS)
     * happened before OpenAI processed anything, so a retry cannot double-process
     * the message. Read timeouts are deliberately NOT matched: the request may
     * already be executing server-side.
     */
    private function isConnectPhaseError(TransportExceptionInterface $e): bool
    {
        return 1 === preg_match(
            '/connection refused|could not resolve|failed to connect|ssl|name or service not known/i',
            $e->getMessage(),
        );
    }

    /**
     * Sampling parameters this model is known to reject (from the shared cache).
     *
     * @return list<string>
     */
    private function getRejectedParams(string $model): array
    {
        if (null === $this->cache || '' === $model) {
            return [];
        }

        try {
            $item = $this->cache->getItem($this->rejectedParamsCacheKey($model));
            $value = $item->isHit() ? $item->get() : [];

            return \is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Remember that a model rejects a sampling parameter, so later turns skip it
     * without paying an extra round-trip.
     */
    private function rememberRejectedParam(string $model, string $param): void
    {
        if (null === $this->cache || '' === $model) {
            return;
        }

        try {
            $item = $this->cache->getItem($this->rejectedParamsCacheKey($model));
            $params = $item->isHit() && \is_array($item->get()) ? $item->get() : [];

            if (!\in_array($param, $params, true)) {
                $params[] = $param;
            }

            $item->set($params);
            $item->expiresAfter(self::REJECTED_PARAMS_TTL);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not cache rejected parameter for model '.$model.': '.$e->getMessage());
        }
    }

    private function rejectedParamsCacheKey(string $model): string
    {
        // PSR-6 keys must not contain the reserved characters {}()/\@: - hash the
        // model id instead of sanitising it.
        return 'openai_assistant_rejected_params_'.sha1($model);
    }

    /**
     * Pseudonymous, stable per-visitor identifier for OpenAI abuse attribution.
     * The SHA-256 hash is not reversible; no personal data leaves the server.
     */
    private function resolveSafetyIdentifier(SessionInterface $session): string|null
    {
        $sessionId = $session->getId();
        if ('' === $sessionId) {
            return null;
        }

        return hash('sha256', $sessionId);
    }

    /**
     * Resolve the actual model id to use (supporting the "manual" override).
     */
    private function resolveModel(array $prompt): string
    {
        $model = (string) ($prompt['model'] ?? '');
        if ('manual' === $model) {
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
