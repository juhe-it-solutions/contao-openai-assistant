<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) JUHE IT-solutions
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Controller\BackendModule;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\Message;
use Cron\CronExpression;
use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Service\LicenseValidationService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\VectorStoreAutoUpdateService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Backend status dashboard for the automatic vector store sync.
 *
 * The route is declared explicitly in config/routes.yaml (this bundle does not
 * import controller route attributes). The POST handler dispatches a CLI sync
 * (non-blocking) — it never runs the sync inline (constraint C4).
 */
class VectorStoreAutoUpdateController extends AbstractBackendController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly VectorStoreAutoUpdateService $service,
        private readonly LicenseValidationService $licenseValidation,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly string $csrfTokenName,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $this->initializeContaoFramework();

        // Per-group access control (BE_MOD does not auto-gate custom routes).
        $this->denyAccessUnlessGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'vector_store_auto_update');

        // Manual trigger (PRG) — the route's _token_check validates REQUEST_TOKEN.
        if ($request->isMethod('POST')) {
            $configId = (int) $request->request->get('config_id');

            try {
                $this->service->dispatchRun($configId);
                Message::addConfirmation('Sync queued. Refresh this page in a few minutes to see the result.');
            } catch (\Throwable $e) {
                Message::addError($e->getMessage());
            }

            return $this->redirectToRoute('vector_store_auto_update');
        }

        $configs = $this->connection->fetchAllAssociative(
            "SELECT * FROM tl_openai_config WHERE auto_update_enabled = '1' ORDER BY id",
        );

        foreach ($configs as &$config) {
            $config['license_active'] = $this->licenseValidation->isLicenseActive((int) $config['id']);
            $config['cron_status'] = $this->cronStatus((int) $config['auto_update_last_run']);
            $config['next_run'] = $this->nextRun($config);
            $config['warnings'] = $this->prerequisiteWarnings($config);
        }
        unset($config);

        $log = $this->connection->fetchAllAssociative(
            'SELECT * FROM tl_openai_sync_log ORDER BY run_at DESC LIMIT 20',
        );

        return $this->render('@Contao/backend/vector_store_auto_update.html.twig', [
            'headline' => $this->translator->trans('MOD.vector_store_auto_update.0', [], 'contao_modules'),
            'configs' => $configs,
            'log' => $log,
            'purchase_url' => 'https://licenses.juhe-it-solutions.at/ai-assistant',
            'request_token' => $this->csrfTokenManager->getToken($this->csrfTokenName)->getValue(),
        ]);
    }

    /**
     * never | healthy | stale — see §10.5. contao:cron runs every minute, so two
     * missed ticks (120 s) is a reliable "cron stopped" signal.
     */
    private function cronStatus(int $lastRun): string
    {
        if (0 === $lastRun) {
            return 'never';
        }

        return time() - $lastRun < 120 ? 'healthy' : 'stale';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function nextRun(array $config): int|null
    {
        $lastRun = (int) ($config['auto_update_last_run'] ?? 0);
        if (0 === $lastRun) {
            return null;
        }

        $schedule = (string) ($config['auto_update_schedule'] ?? '') ?: '0 2 * * *';

        try {
            $expression = new CronExpression($schedule);

            return $expression->getNextRunDate(new \DateTimeImmutable('@'.$lastRun))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Non-blocking prerequisite warnings (§10.5). Returned as a list of strings.
     *
     * @param array<string, mixed> $config
     *
     * @return array<int, string>
     */
    private function prerequisiteWarnings(array $config): array
    {
        $warnings = [];

        if ('' === (string) ($config['vector_store_id'] ?? '')) {
            $warnings[] = 'No vector store configured. Add a vector store ID to this configuration record first.';
        }

        $hasDomain = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM tl_page WHERE type = 'root' AND dns != ''",
        );
        if (0 === $hasDomain) {
            $warnings[] = 'No domain configured on the site root page. The crawler needs a domain to work.';
        }

        $indexed = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_search');
        if (0 === $indexed) {
            $warnings[] = 'No pages indexed. Run a search re-index (System → Maintenance) before the first sync.';
        }

        if (!($config['license_active'] ?? false)) {
            $warnings[] = 'No active premium license. Enter a valid license key in the OpenAI Configuration record to enable sync.';
        }

        return $warnings;
    }
}
