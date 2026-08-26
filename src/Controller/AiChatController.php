<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Controller;

use Contao\CoreBundle\Controller\AbstractController;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use JuheItSolutions\ContaoOpenaiAssistant\Exception\UnbilledRequestException;
use JuheItSolutions\ContaoOpenaiAssistant\Service\ChatRateLimiter;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\OpenAiResponder;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;

class AiChatController extends AbstractController
{
    /**
     * Hard ceiling for one chat message, in characters.
     *
     * The endpoint is anonymous and every request is billed by input token, while
     * the IP and daily limits count *messages* - so without a length cap a single
     * caller can spend far more than "messages per day" suggests. 4000 characters
     * are roughly 1000 tokens: enough for a pasted paragraph or a long question,
     * far too little to be worth abusing.
     */
    public const MAX_MESSAGE_LENGTH = 4000;

    public function __construct(
        private readonly OpenAiResponder $responder,
        private readonly EncryptionService $encryption,
        private readonly ContaoFramework $framework,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly string $csrfTokenName,
        private readonly LoggerInterface $logger,
        private readonly ChatRateLimiter $rateLimiter,
    ) {
    }

    public function send(Request $request): JsonResponse
    {
        $this->framework->initialize();

        // Detect user language
        $language = $this->detectLanguage($request);

        // Validate AJAX request
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(
                [
                    'error' => $this->getErrorMessage('invalid_request', $language),
                ],
                400,
            );
        }

        // CSRF Token Validation
        $submittedToken = $request->request->get('REQUEST_TOKEN') ??
                         $request->headers->get('X-CSRF-Token');

        if (!$submittedToken) {
            return new JsonResponse(
                [
                    'error' => $this->getErrorMessage('csrf_token_missing', $language),
                ],
                400,
            );
        }

        $token = new CsrfToken($this->csrfTokenName, $submittedToken);
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new JsonResponse(
                [
                    'error' => $this->getErrorMessage('invalid_csrf_token', $language),
                ],
                403,
            );
        }

        // Get and validate message
        $message = trim($request->request->get('message', ''));
        if (empty($message)) {
            return new JsonResponse(
                [
                    'error' => $this->getErrorMessage('empty_message', $language),
                ],
                400,
            );
        }

        // Rejected before the rate limiters below on purpose: an oversized message
        // must not consume the IP or daily budget it is meant to protect. Counted in
        // characters, not bytes, so umlauts do not shorten the limit.
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return new JsonResponse(
                [
                    'error' => $this->getErrorMessage('message_too_long', $language),
                ],
                400,
            );
        }

        // Both abuse limits are configured on the active config row; read it once here
        // (the responder re-resolves it later) so they are enforced before any paid
        // call. Missing column (pre-migration) or missing config falls back to the
        // hard default for the IP limit and "uncapped" for the daily ceiling.
        $activeConfig = $this->responder->getActiveConfig();
        $ipLimit = null !== $activeConfig && \array_key_exists('chat_ip_rate_limit', $activeConfig)
            ? (int) $activeConfig['chat_ip_rate_limit']
            : ChatRateLimiter::DEFAULT_IP_LIMIT;

        // Per-IP rate limit: the endpoint is anonymous and spends the owner's OpenAI
        // credits, so the session throttle below (bypassable by dropping the cookie) is
        // backed by a cache-based IP limiter that survives cookie rotation. Configurable
        // (0 = off) for installations where many users share one egress IP.
        if (!$this->rateLimiter->acceptClientIp((string) $request->getClientIp(), $ipLimit)) {
            return new JsonResponse(
                [
                    'error' => $this->getErrorMessage('please_wait', $language),
                ],
                429,
            );
        }

        // Rate limiting check
        $session = $request->getSession();
        $lastRequest = $session->get('ai_chat_last_request', 0);
        $currentTime = time();
        if ($currentTime - $lastRequest < 2) {
            return new JsonResponse(
                [
                    'error' => $this->getErrorMessage('please_wait', $language),
                ],
                429,
            );
        }

        $session->set('ai_chat_last_request', $currentTime);

        // Per-configuration daily ceiling: an absolute cap on completions one config can
        // spend per day, bounding worst-case API cost even under a distributed attack.
        //
        // RESERVED here, in one atomic step, rather than checked here and booked afterwards.
        // The old check-then-consume pair left a window in which any number of concurrent
        // requests could all see the same last token and all pay for a completion - on the
        // very cap that exists to bound a distributed attack. The slot is handed back below
        // if the call provably never reached OpenAI, so a bad key or an outage still cannot
        // spend the day's budget.
        $dailyLimit = $activeConfig ? (int) ($activeConfig['chat_daily_limit'] ?? 0) : 0;

        if ($activeConfig && !$this->rateLimiter->reserveConfigDaily((int) $activeConfig['id'], $dailyLimit)) {
            return new JsonResponse(
                [
                    'error' => $this->getErrorMessage('daily_limit_reached', $language),
                ],
                429,
            );
        }

        try {
            // Send the message as-is without automatic language instructions The prompt
            // should be configured with appropriate system instructions
            $reply = $this->responder->processMessage($message, $session);

            return new JsonResponse([
                'reply' => $reply,
                'timestamp' => date('Y-m-d H:i:s'),
            ]);
        } catch (UnbilledRequestException $e) {
            // Provably not charged: the request never reached OpenAI, or OpenAI rejected it
            // before processing. Give the slot back, then fall through to the normal error
            // handling - the visitor sees exactly what they saw before.
            //
            // A truthy $activeConfig is precisely the condition under which a slot was
            // reserved above, so it is also the condition for releasing one.
            if ($activeConfig) {
                $this->rateLimiter->releaseConfigDaily((int) $activeConfig['id'], $dailyLimit);
            }

            return $this->handleChatFailure($e, $message, $language);
        } catch (\Exception $e) {
            return $this->handleChatFailure($e, $message, $language);
        }
    }

    public function getToken(Request $request): JsonResponse
    {
        // Detect user language
        $language = $this->detectLanguage($request);

        // Rate limiting for token requests (max 1 per 10 seconds)
        $session = $request->getSession();
        $lastTokenRequest = $session->get('ai_chat_last_token_request', 0);
        $currentTime = time();
        if ($currentTime - $lastTokenRequest < 10) {
            return new JsonResponse(
                [
                    'error' => $this->getErrorMessage('token_requests_too_frequent', $language),
                ],
                429,
            );
        }

        $session->set('ai_chat_last_token_request', $currentTime);

        $token = $this->csrfTokenManager->getDefaultTokenValue();

        return new JsonResponse([
            'token' => $token,
        ]);
    }

    public function getHistory(Request $request): JsonResponse
    {
        $this->framework->initialize();

        // Detect user language
        $language = $this->detectLanguage($request);

        // Validate AJAX request
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(
                [
                    'error' => $this->getErrorMessage('invalid_request', $language),
                ],
                400,
            );
        }

        // Defence in depth. Contao's _token_check never fires here - it only covers
        // POST requests - and a cross-origin caller cannot read this response anyway,
        // so the transcript was never exposed across sites. Requiring the token
        // nevertheless means a same-origin script has to hold a valid token to read
        // the visitor's conversation, instead of the session cookie alone being enough.
        $submittedToken = $request->headers->get('X-CSRF-Token') ?? $request->query->get('REQUEST_TOKEN');

        if (!$submittedToken || !$this->csrfTokenManager->isTokenValid(new CsrfToken($this->csrfTokenName, (string) $submittedToken))) {
            return new JsonResponse(
                [
                    'error' => $this->getErrorMessage('invalid_csrf_token', $language),
                ],
                403,
            );
        }

        $session = $request->getSession();

        // Silently drop any legacy openai_thread_id left over from a 1.x session.
        if ($session->has('openai_thread_id')) {
            $session->remove('openai_thread_id');
        }

        $conversationId = $session->get('openai_conversation_id');

        if (!$conversationId) {
            return new JsonResponse([
                'history' => [],
            ]);
        }

        try {
            $config = $this->responder->getActiveConfig();
            if (!$config) {
                return new JsonResponse([
                    'history' => [],
                ]);
            }

            $apiKey = $this->encryption->getApiKeyForConfig((int) $config['id'])
                ?? $this->encryption->processApiKey((string) ($config['api_key'] ?? ''));

            if (!$apiKey) {
                $this->logger->error(
                    'No valid API key found for chat history',
                    [
                        'config_id' => $config['id'] ?? null,
                    ],
                );

                return new JsonResponse([
                    'history' => [],
                ]);
            }

            $history = $this->responder->getConversationHistory((string) $conversationId, $apiKey);

            return new JsonResponse([
                'history' => $history,
            ]);
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to get chat history: '.$e->getMessage(),
                [
                    'exception' => $e,
                    'conversation_id' => $conversationId,
                ],
            );

            return new JsonResponse([
                'history' => [],
            ]);
        }
    }

    /**
     * Log a failed chat turn and answer with the generic service error.
     *
     * Shared by both catch blocks so that whether a slot was handed back is invisible to the
     * visitor: a failure looks and reads exactly the same either way.
     */
    private function handleChatFailure(\Exception $e, string $message, string $language): JsonResponse
    {
        $this->logger->error(
            'Error processing chat message: '.$e->getMessage(),
            [
                'exception' => $e,
                // Do not log message content to avoid persisting potentially sensitive user input.
                'message_length' => mb_strlen($message),
            ],
        );

        return new JsonResponse(
            [
                'error' => $this->getErrorMessage('service_unavailable', $language),
            ],
            500,
        );
    }

    /**
     * Detect user language from Accept-Language header.
     */
    private function detectLanguage(Request $request): string
    {
        $acceptLanguage = $request->headers->get('Accept-Language', '');

        // Only the FIRST tag decides. Accept-Language is an ordered preference list, and
        // the previous pattern (/^de|de-/) read as "^de" OR "de-" anywhere, so
        // "en-US,en;q=0.9,de-DE;q=0.8" matched on the fallback and answered an English
        // visitor in German - contradicting the ranking they sent us.
        $primary = strtolower(trim(explode(',', $acceptLanguage)[0]));
        $primary = trim(explode(';', $primary)[0]);

        // Check if German is preferred
        if ('de' === $primary || str_starts_with($primary, 'de-')) {
            return 'de';
        }

        // Default to English
        return 'en';
    }

    /**
     * Get translated error message.
     */
    private function getErrorMessage(string $key, string $language): string
    {
        $messages = [
            'de' => [
                'invalid_request' => 'Ungültige Anfrage',
                'csrf_token_missing' => 'CSRF-Token fehlt',
                'invalid_csrf_token' => 'Ungültiger CSRF-Token. Bitte laden Sie die Seite neu und versuchen Sie es erneut.',
                'empty_message' => 'Leere Nachricht',
                'message_too_long' => 'Ihre Nachricht ist zu lang. Bitte kürzen Sie sie auf höchstens '.self::MAX_MESSAGE_LENGTH.' Zeichen.',
                'please_wait' => 'Bitte warten Sie, bevor Sie eine weitere Nachricht senden',
                'service_unavailable' => 'Service vorübergehend nicht verfügbar',
                'token_requests_too_frequent' => 'Token-Anfragen zu häufig',
                'daily_limit_reached' => 'Das tägliche Nachrichtenlimit für den Chatbot wurde erreicht. Bitte versuchen Sie es morgen erneut.',
            ],
            'en' => [
                'invalid_request' => 'Invalid request',
                'csrf_token_missing' => 'CSRF token missing',
                'invalid_csrf_token' => 'Invalid CSRF token. Please reload the page and try again.',
                'empty_message' => 'Empty message',
                'message_too_long' => 'Your message is too long. Please shorten it to at most '.self::MAX_MESSAGE_LENGTH.' characters.',
                'please_wait' => 'Please wait before sending another message',
                'service_unavailable' => 'Service temporarily unavailable',
                'token_requests_too_frequent' => 'Token requests too frequent',
                'daily_limit_reached' => 'The chatbot has reached its daily message limit. Please try again tomorrow.',
            ],
        ];

        return $messages[$language][$key] ?? $messages['en'][$key] ?? $key;
    }
}
