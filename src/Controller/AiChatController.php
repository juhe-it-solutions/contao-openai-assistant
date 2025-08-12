<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) JUHE IT-solutions
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Controller;

use Contao\CoreBundle\Controller\AbstractController;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use JuheItSolutions\ContaoOpenaiAssistant\Service\OpenAiAssistant;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;

class AiChatController extends AbstractController
{
    public function __construct(
        private readonly OpenAiAssistant $assistant,
        private readonly ContaoFramework $framework,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly string $csrfTokenName,
        private readonly LoggerInterface $logger
    ) {
    }

    public function send(Request $request): JsonResponse
    {
        $this->framework->initialize();

        // Detect user language
        $language = $this->detectLanguage($request);

        // Validate AJAX request
        if (! $request->isXmlHttpRequest()) {
            return new JsonResponse([
                'error' => $this->getErrorMessage('invalid_request', $language),
            ], 400);
        }

        // CSRF Token Validation
        $submittedToken = $request->request->get('REQUEST_TOKEN') ??
                         $request->headers->get('X-CSRF-Token');

        if (! $submittedToken) {
            return new JsonResponse([
                'error' => $this->getErrorMessage('csrf_token_missing', $language),
            ], 400);
        }

        $token = new CsrfToken($this->csrfTokenName, $submittedToken);
        if (! $this->csrfTokenManager->isTokenValid($token)) {
            return new JsonResponse([
                'error' => $this->getErrorMessage('invalid_csrf_token', $language),
            ], 403);
        }

        // Get and validate message
        $message = trim($request->request->get('message', ''));
        if (empty($message)) {
            return new JsonResponse([
                'error' => $this->getErrorMessage('empty_message', $language),
            ], 400);
        }

        // Rate limiting check
        $session     = $request->getSession();
        $lastRequest = $session->get('ai_chat_last_request', 0);
        $currentTime = time();
        if (($currentTime - $lastRequest) < 2) {
            return new JsonResponse([
                'error' => $this->getErrorMessage('please_wait', $language),
            ], 429);
        }

        $session->set('ai_chat_last_request', $currentTime);

        try {
            // Send the message as-is without automatic language instructions
            // The assistant should be configured with appropriate system instructions
            $reply = $this->assistant->processMessage($message, $session);

            return new JsonResponse([
                'reply'     => $reply,
                'timestamp' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error processing chat message: ' . $e->getMessage(), [
                'exception' => $e,
                'message'   => $message,
                'trace'     => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'error' => $this->getErrorMessage('service_unavailable', $language),
            ], 500);
        }
    }

    public function getToken(Request $request): JsonResponse
    {
        // Detect user language
        $language = $this->detectLanguage($request);

        // Rate limiting for token requests (max 1 per 10 seconds)
        $session          = $request->getSession();
        $lastTokenRequest = $session->get('ai_chat_last_token_request', 0);
        $currentTime      = time();
        if (($currentTime - $lastTokenRequest) < 10) {
            return new JsonResponse([
                'error' => $this->getErrorMessage('token_requests_too_frequent', $language),
            ], 429);
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
        if (! $request->isXmlHttpRequest()) {
            return new JsonResponse([
                'error' => $this->getErrorMessage('invalid_request', $language),
            ], 400);
        }

        $session  = $request->getSession();
        $threadId = $session->get('openai_thread_id');

        if (! $threadId) {
            return new JsonResponse([
                'history' => [],
            ]);
        }

        try {
            $config = $this->assistant->getActiveConfig();
            if (! $config) {
                return new JsonResponse([
                    'history' => [],
                ]);
            }

            $apiKey = $this->processApiKey($config['api_key']);
            if (! $apiKey) {
                $this->logger->error('No valid API key found for chat history', [
                    'config_id' => $config['id'] ?? null,
                ]);

                return new JsonResponse([
                    'history' => [],
                ]);
            }

            $history = $this->assistant->getThreadHistory($threadId, $apiKey);

            return new JsonResponse([
                'history' => $history,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get chat history: ' . $e->getMessage(), [
                'exception' => $e,
                'thread_id' => $threadId,
                'trace'     => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'history' => [],
            ]);
        }
    }

    /**
     * Detect user language from Accept-Language header
     */
    private function detectLanguage(Request $request): string
    {
        $acceptLanguage = $request->headers->get('Accept-Language', '');

        // Check if German is preferred
        if (preg_match('/^de|de-/', $acceptLanguage)) {
            return 'de';
        }

        // Default to English
        return 'en';
    }

    /**
     * Get translated error message
     */
    private function getErrorMessage(string $key, string $language): string
    {
        $messages = [
            'de' => [
                'invalid_request'             => 'Ungültige Anfrage',
                'csrf_token_missing'          => 'CSRF-Token fehlt',
                'invalid_csrf_token'          => 'Ungültiger CSRF-Token. Bitte laden Sie die Seite neu und versuchen Sie es erneut.',
                'empty_message'               => 'Leere Nachricht',
                'please_wait'                 => 'Bitte warten Sie, bevor Sie eine weitere Nachricht senden',
                'service_unavailable'         => 'Service vorübergehend nicht verfügbar',
                'token_requests_too_frequent' => 'Token-Anfragen zu häufig',
            ],
            'en' => [
                'invalid_request'             => 'Invalid request',
                'csrf_token_missing'          => 'CSRF token missing',
                'invalid_csrf_token'          => 'Invalid CSRF token. Please reload the page and try again.',
                'empty_message'               => 'Empty message',
                'please_wait'                 => 'Please wait before sending another message',
                'service_unavailable'         => 'Service temporarily unavailable',
                'token_requests_too_frequent' => 'Token requests too frequent',
            ],
        ];

        return $messages[$language][$key] ?? $messages['en'][$key] ?? $key;
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
            // This is an encrypted key
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

    /**
     * Validate API key format - supports all OpenAI key formats
     */
    private function isValidApiKeyFormat(string $apiKey): bool
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
}
