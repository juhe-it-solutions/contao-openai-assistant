<?php

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
use Contao\CoreBundle\Security\ContaoCorePermissions;
use JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiConfigListener;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\LicenseValidationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;

class LicenseValidationController
{
    public function __construct(
        private readonly LicenseValidationService $licenseValidation,
        private readonly EncryptionService $encryption,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly string $csrfTokenName,
    ) {
    }

    #[Route('%contao.backend.route_prefix%/license-key-validate', name: 'contao_license_key_validate', methods: ['POST'])]
    public function validateLicenseKey(Request $request): JsonResponse
    {
        $submittedToken = $request->request->get('REQUEST_TOKEN');
        $token = new CsrfToken($this->csrfTokenName, (string) $submittedToken);

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new JsonResponse(
                ['valid' => false, 'message' => 'Invalid request token'],
                Response::HTTP_FORBIDDEN,
            );
        }

        if (!$this->authorizationChecker->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'openai_dashboard')) {
            return new JsonResponse(
                ['valid' => false, 'message' => 'access_denied'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $postedKey = trim((string) $request->request->get('key', ''));
        $configId = (int) $request->request->get('config_id', 0);

        if (OpenAiConfigListener::LICENSE_KEY_MASK === $postedKey) {
            if ($configId <= 0) {
                return new JsonResponse([
                    'valid' => false,
                    'message' => 'stored_key_requires_config',
                ]);
            }

            $active = $this->licenseValidation->isLicenseActive($configId);

            return new JsonResponse([
                'valid' => $active,
                'message' => $active ? '' : 'inactive',
            ]);
        }

        if ('' === $postedKey) {
            return new JsonResponse([
                'valid' => false,
                'message' => 'empty',
            ]);
        }

        if (!$this->encryption->isValidLicenseKeyFormat($postedKey)) {
            return new JsonResponse([
                'valid' => false,
                'message' => 'invalid_format',
            ]);
        }

        // Do not echo remote error details — boolean result only.
        $active = $this->licenseValidation->validatePlainKey($postedKey);

        return new JsonResponse([
            'valid' => $active,
            'message' => $active ? '' : 'inactive',
        ]);
    }
}
