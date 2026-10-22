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
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\AdminNotificationTestResult;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\AdminNotifier;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Backend endpoint of the "Send test email" button.
 *
 * Only the configuration ID comes from the request. Recipients, language and everything
 * else are read from the SAVED row, so the endpoint cannot be used as a mail relay; the
 * notifier throttles it to one mail per 30 seconds and configuration.
 *
 * Routed from config/routes.yaml, like the other backend endpoints of this bundle.
 */
class AdminNotificationTestController
{
    public function __construct(
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly Connection $connection,
        private readonly AdminNotifier $adminNotifier,
        private readonly TranslatorInterface $translator,
        private readonly string $csrfTokenName = 'contao_csrf_token',
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $token = new CsrfToken($this->csrfTokenName, (string) $request->request->get('REQUEST_TOKEN'));

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid request token'], Response::HTTP_FORBIDDEN);
        }

        // Same module gate as the other configuration endpoints.
        if (!$this->authorizationChecker->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'openai_dashboard')) {
            return new JsonResponse(['success' => false, 'message' => 'access_denied'], Response::HTTP_FORBIDDEN);
        }

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM tl_openai_config WHERE id = ?',
            [(int) $request->request->get('id')],
        );

        if (false === $row) {
            return new JsonResponse(['success' => false, 'message' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        // Never throws, and never returns exception text: the result is one of a fixed set
        // of outcomes, and only their translated messages reach the browser.
        $result = $this->adminNotifier->sendTestNotification($row, $request);

        return new JsonResponse([
            'success' => AdminNotificationTestResult::Sent === $result,
            'message' => $this->translator->trans($result->translationKey(), [], 'contao_tl_openai_config'),
        ]);
    }
}
