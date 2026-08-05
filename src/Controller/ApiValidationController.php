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

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ApiValidationController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly string $csrfTokenName = 'contao_csrf_token',
    ) {
    }

    /**
     * Routed from config/routes.yaml (this bundle does not import controller route
     * attributes, so an attribute here would be silently inert and a second, drifting
     * source of truth for the path and the _scope/_token_check defaults).
     */
    public function validateApiKey(Request $request): JsonResponse
    {
        // Check CSRF token using Symfony's CSRF token manager
        $submittedToken = $request->request->get('REQUEST_TOKEN');
        $token = new CsrfToken($this->csrfTokenName, $submittedToken);

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new JsonResponse(
                [
                    'valid' => false,
                    'message' => 'Invalid request token',
                ],
                Response::HTTP_FORBIDDEN,
            );
        }

        // Same module gate as the license-key endpoint: only users who may manage the
        // OpenAI configuration can use this key-validation proxy.
        if (!$this->authorizationChecker->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'openai_dashboard')) {
            return new JsonResponse(
                [
                    'valid' => false,
                    'message' => 'access_denied',
                ],
                Response::HTTP_FORBIDDEN,
            );
        }

        $apiKey = trim((string) $request->request->get('key', ''));
        $valid = false;
        $message = '';

        // Reject an empty key up front rather than sending "Bearer " to OpenAI; mirrors
        // the format guard in LicenseValidationController.
        if ('' === $apiKey) {
            return new JsonResponse([
                'valid' => false,
                'message' => 'empty',
            ]);
        }

        try {
            // Make a request to the OpenAI API to validate the key
            $response = $this->httpClient->request(
                'GET',
                'https://api.openai.com/v1/models',
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'timeout' => 10,
                ],
            );

            // If we get here, the request was successful
            $valid = 200 === $response->getStatusCode();

            // Log successful validation
            if ($valid) {
                $this->logger->info(
                    'OpenAI API key validation successful',
                    [
                        'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                    ],
                );
            }
        } catch (\Exception $e) {
            // The exception text can carry transport details, resolved hosts and
            // proxy internals. It belongs in the log, not in a JSON body that ends
            // up in every browser network panel and error report - the caller only
            // needs to know that the check could not be completed.
            $message = 'request_failed';
            $this->logger->error(
                'OpenAI API key validation failed: '.$e->getMessage(),
                [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                ],
            );
        }

        // Return JSON response
        return new JsonResponse([
            'valid' => $valid,
            'message' => $message,
        ]);
    }
}
