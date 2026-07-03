<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) JUHE IT-solutions
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

/*
 * This file is part of Contao Open Source CMS.
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
use Symfony\Component\Routing\Attribute\Route;
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

    #[Route('%contao.backend.route_prefix%/api-key-validate', name: 'contao_api_key_validate', methods: ['POST'])]
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

        $apiKey = $request->request->get('key');
        $valid = false;
        $message = '';

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
            $message = $e->getMessage();
            $this->logger->error(
                'OpenAI API key validation failed: '.$message,
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
