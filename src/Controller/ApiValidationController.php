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

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Monolog\ContaoContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ApiValidationController
{
    private $httpClient;

    private $logger;

    private $csrfTokenManager;

    private $csrfTokenName;

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        ContaoCsrfTokenManager $csrfTokenManager,
        string $csrfTokenName = 'contao_csrf_token'
    ) {
        $this->httpClient       = $httpClient;
        $this->logger           = $logger;
        $this->csrfTokenManager = $csrfTokenManager;
        $this->csrfTokenName    = $csrfTokenName;
    }

    #[Route('/contao/api-key-validate', name: 'contao_api_key_validate', methods: ['POST'])]
    public function validateApiKey(Request $request): JsonResponse
    {
        // Check CSRF token using Symfony's CSRF token manager
        $submittedToken = $request->request->get('REQUEST_TOKEN');
        $token          = new CsrfToken($this->csrfTokenName, $submittedToken);

        if (! $this->csrfTokenManager->isTokenValid($token)) {
            return new JsonResponse([
                'valid'   => false,
                'message' => 'Invalid request token',
            ], Response::HTTP_FORBIDDEN);
        }

        $apiKey  = $request->request->get('key');
        $valid   = false;
        $message = '';

        try {
            // Make a request to the OpenAI API to validate the key
            $response = $this->httpClient->request('GET', 'https://api.openai.com/v1/models', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'timeout' => 10,
            ]);

            // If we get here, the request was successful
            $valid = $response->getStatusCode() === 200;

            // Log successful validation
            if ($valid) {
                $this->logger->info(
                    'OpenAI API key validation successful',
                    [
                        'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                    ]
                );
            }
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $this->logger->error(
                'OpenAI API key validation failed: ' . $message,
                [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                ]
            );
        }

        // Return JSON response
        return new JsonResponse([
            'valid'   => $valid,
            'message' => $message,
        ]);
    }
}
