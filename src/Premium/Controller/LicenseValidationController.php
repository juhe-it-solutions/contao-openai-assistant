<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant premium add-on.
 *
 * (c) JUHE IT-solutions
 *
 * @license Proprietary - see LICENSE-PREMIUM. Usage of the premium add-on
 *          requires a valid premium subscription from JUHE IT-solutions.
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Premium\Controller;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiConfigListener;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\LicenseValidationService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

    // Route defined in config/routes.yaml (contao_license_key_validate); this bundle does
    // not import controller route attributes, so no #[Route] here.
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
