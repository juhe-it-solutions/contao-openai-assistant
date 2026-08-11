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
use JuheItSolutions\ContaoOpenaiAssistant\Exception\UnbilledRequestException;
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
     * Wall-clock cap for the WHOLE Responses exchange, retries included.
     *
     * Deliberately below the 120 seconds after which the chat widget aborts and tells the
     * visitor to try again (public/js/ai-chat.js). The order matters: aborting in the browser
     * does not cancel PHP's upstream work, so while the server budget was the larger of the
     * two, a visitor could be invited to retry while the first call was still running - and
     * that first call could then complete, be billed, and append its answer to the
     * conversation, so the retry arrived into a conversation that had silently moved on.
     *
     * The retry lives INSIDE this budget rather than on top of it, which is why every attempt
     * computes its own timeout from the time left: two attempts of the full budget would put
     * the server back above the browser and reopen the same gap.
     *
     * The remaining 10 seconds of headroom are for returning a controlled error response
     * before the browser gives up on its own.
     */
    private const RESPONSE_TIMEOUT = 110;

    /**
     * Least time an attempt is worth starting with. Below this a retry cannot plausibly
     * finish inside the budget, so the failure is reported instead.
     */
    private const MIN_ATTEMPT_SECONDS = 15;

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

    /**
     * Upstream statuses that mean the message was NOT processed, so repeating it
     * cannot produce a second answer or a second charge.
     *
     * 429/503 are rejections before processing. 502 and the Cloudflare origin
     * family 520-523 ("unknown error", "origin down", "connection timed out",
     * "origin unreachable") belong in the same class: api.openai.com sits behind
     * Cloudflare, so this is what a brief OpenAI wobble looks like from here -
     * observed live on 2026-08-04, where a single 520 surfaced to the visitor as
     * a hard error although the next attempt would have succeeded.
     *
     * Deliberately NOT retried: 500 and 524 (origin timeout). There the request
     * may well have reached the model and only the answer was lost, so a repeat
     * would charge twice - the same reasoning that keeps request timeouts out of
     * the retry path.
     */
    private const TRANSIENT_STATUS_CODES = [429, 502, 503, 520, 521, 522, 523];

    /**
     * How much of an upstream error body is kept for the exception message.
     */
    private const MAX_ERROR_LENGTH = 300;

    /**
     * Keeps the "more than one configuration" warning to once per PHP process
     * instead of once per chat message.
     */
    private static bool $multiConfigWarningLogged = false;

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
        // These three fail before any HTTP request exists, so nothing can have been billed -
        // and a misconfigured installation must not burn its daily budget on requests that
        // never reach OpenAI, which is exactly how the chatbot could be taken offline for a
        // whole day for free.
        $config = $this->getActiveConfig();
        if (!$config) {
            throw new UnbilledRequestException('No OpenAI configuration found');
        }

        $prompt = $this->getActivePrompt((int) $config['id']);
        if (!$prompt) {
            throw new UnbilledRequestException('No prompt configured');
        }

        $apiKey = $this->encryption->getApiKeyForConfig((int) $config['id'])
            ?? $this->encryption->processApiKey((string) ($config['api_key'] ?? ''));

        if (!$apiKey) {
            throw new UnbilledRequestException('No valid API key available');
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
     * Get the active OpenAI configuration.
     *
     * Ordered by id, not by tstamp. The backend allows exactly one configuration
     * (OpenAiConfigListener::checkSingleRecordLimitation()) and edits the lowest-id
     * row, so this picks the row an operator actually sees. With "newest tstamp
     * wins", an installation that still carries a second row from an older version
     * silently switched API key, vector store and prompt whenever that other row
     * was saved - including by a save that changed nothing.
     */
    public function getActiveConfig(): array|null
    {
        // LIMIT 2 rather than 1: the second row is only ever used to notice that a
        // surplus configuration exists, which costs nothing here and saves a COUNT(*)
        // on every chat message. (A static flag would not help - PHP-FPM resets
        // statics between requests, so "once per process" is once per request there.)
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM tl_openai_config WHERE api_key IS NOT NULL ORDER BY id ASC LIMIT 2',
        );

        if ([] === $rows) {
            return null;
        }

        if (\count($rows) > 1 && !self::$multiConfigWarningLogged) {
            self::$multiConfigWarningLogged = true;
            $this->logger->warning(\sprintf(
                'More than one usable OpenAI configuration exists; only one is supported. The chat uses configuration ID %s. Remove the surplus row in the backend.',
                (string) $rows[0]['id'],
            ));
        }

        return $rows[0];
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
     * Reduce an upstream error body to something a log line can carry.
     *
     * When the response is not JSON, the "error message" is the entire HTML error
     * page of whichever proxy answered - Cloudflare's 520 page is roughly 10 KB -
     * and it would be embedded in the exception message and written to the log
     * twice, once as the message and once in the trace. Status code plus the
     * first sentence is what an operator needs.
     */
    private static function summariseError(string $error): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($error)));

        if ('' === $text) {
            return '(empty response body)';
        }

        return mb_strlen($text) > self::MAX_ERROR_LENGTH
            ? mb_substr($text, 0, self::MAX_ERROR_LENGTH).' […]'
            : $text;
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
    /**
     * Seconds left of the exchange budget, floored so an attempt is never started with a
     * nonsensical timeout.
     */
    private function secondsLeft(float $deadline): int
    {
        // floor, not ceil, and clamped to the budget. A Unix timestamp plus 110 does not land
        // on an exactly representable double, and the rounding can go UP - so ceil() handed
        // out 111 seconds against a 110-second budget on the very first attempt. One second
        // is harmless in itself; a deadline that quietly exceeds its own bound is not, since
        // being strictly below the browser is the entire point of it.
        $left = (int) floor($deadline - microtime(true));

        return max(self::MIN_ATTEMPT_SECONDS, min(self::RESPONSE_TIMEOUT, $left));
    }

    /**
     * Is there enough of the budget left to be worth a second attempt?
     *
     * Retrying with a couple of seconds left only guarantees a second failure, later - and it
     * is precisely the overrun that would push the server past the browser's deadline. The
     * one-second backoff before the retry is counted in.
     */
    private function canRetryWithin(float $deadline): bool
    {
        return $deadline - microtime(true) >= self::MIN_ATTEMPT_SECONDS + 1;
    }

    private function sendResponse(string $apiKey, string $conversationId, string $message, array $prompt, string|null $vectorStoreId, string|null $safetyIdentifier): string
    {
        $modelToUse = $this->resolveModel($prompt);
        $payload = $this->buildResponsePayload($modelToUse, $conversationId, $message, $prompt, $vectorStoreId, $safetyIdentifier);
        $transientRetried = false;

        // One budget for the whole exchange. Every attempt draws from what is left of it, so
        // a repeat can never push the server past the browser's own deadline.
        $deadline = microtime(true) + self::RESPONSE_TIMEOUT;

        while (true) {
            $remaining = $this->secondsLeft($deadline);

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
                        'timeout' => $remaining,
                        'max_duration' => $remaining,
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
                if (!$transientRetried && $this->isConnectPhaseError($e) && $this->canRetryWithin($deadline)) {
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

                // A connect-phase failure means the message was never delivered, so nothing
                // was charged. A failure later in the exchange (a read timeout, say) may
                // still have produced a billed completion, so it stays a plain failure.
                if ($this->isConnectPhaseError($e)) {
                    throw new UnbilledRequestException('Failed to process message: '.$e->getMessage());
                }

                throw new \RuntimeException('Failed to process message: '.$e->getMessage());
            }

            if (200 !== $statusCode) {
                $error = (string) ($data['error']['message'] ?? $response->getContent(false));

                // Models that do not accept temperature/top_p (reasoning models)
                // reject with a param-specific 400; strip the parameter, remember
                // the rejection for this model and repeat. Bounded by the number
                // of strippable parameters in the payload.
                // The budget guard applies here too, or the exact bound the deadline exists to
                // give would be "110 seconds plus one more attempt". In practice this never
                // bites: a rejected parameter is a validation error OpenAI returns at once, so
                // the budget is still almost untouched when we get here.
                $rejectedParam = $this->detectRejectedSamplingParam($statusCode, $data, $error);
                if (null !== $rejectedParam && \array_key_exists($rejectedParam, $payload) && $this->canRetryWithin($deadline)) {
                    unset($payload[$rejectedParam]);
                    $this->rememberRejectedParam($modelToUse, $rejectedParam);
                    $this->logger->info(
                        \sprintf('Model "%s" rejects "%s"; repeating the call without it', $modelToUse, $rejectedParam),
                    );
                    continue;
                }

                // One retry after a short backoff absorbs the transient blips of
                // OpenAI and the CDN in front of it (see TRANSIENT_STATUS_CODES).
                if (!$transientRetried && \in_array($statusCode, self::TRANSIENT_STATUS_CODES, true) && $this->canRetryWithin($deadline)) {
                    $transientRetried = true;
                    $this->logger->warning('Responses API returned HTTP '.$statusCode.'; retrying once');
                    usleep(1000000);
                    continue;
                }

                // The detectors below read the FULL error text; only what ends up
                // in the exception (and therefore in the log) is shortened.
                if ($this->isContextWindowError($statusCode, $data, $error)) {
                    throw new ContextWindowExceededException('Responses API returned HTTP '.$statusCode.': '.self::summariseError($error));
                }

                if ($this->isConversationNotFoundError($statusCode, $error)) {
                    throw new ConversationNotFoundException('Responses API returned HTTP '.$statusCode.': '.self::summariseError($error));
                }

                // 429 and 503 are rejections BEFORE processing, so nothing was charged and
                // the caller may hand its daily-budget slot back. Every other status stays a
                // plain failure: the completion may have been produced and billed, and we
                // cannot tell from here.
                if (\in_array($statusCode, [429, 503], true)) {
                    throw new UnbilledRequestException('Responses API returned HTTP '.$statusCode.': '.self::summariseError($error));
                }

                throw new \RuntimeException('Responses API returned HTTP '.$statusCode.': '.self::summariseError($error));
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
