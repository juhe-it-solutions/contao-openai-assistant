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

            $config = $this->connection->fetchAssociative(
                "SELECT id FROM tl_openai_config WHERE id = ? AND auto_update_enabled = '1'",
                [$configId],
            );

            if (!$config) {
                Message::addError($this->translator->trans('MSC.vsau_err_invalid_config', [], 'contao_default'));

                return $this->redirectToRoute('vector_store_auto_update');
            }

            if (!$this->licenseValidation->isLicenseActive($configId)) {
                Message::addError($this->translator->trans('MSC.vsau_err_no_license', [], 'contao_default'));

                return $this->redirectToRoute('vector_store_auto_update');
            }

            try {
                $this->service->dispatchRun($configId);
                Message::addConfirmation($this->translator->trans('MSC.vsau_queued_confirm', [], 'contao_default'));
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
            $schedule = (string) ($config['auto_update_schedule'] ?? '') ?: '0 2 * * *';
            $config['schedule_label'] = $this->humanReadableSchedule($schedule);
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

    private function humanReadableSchedule(string $schedule): string
    {
        $parts = preg_split('/\s+/', trim($schedule));
        if (5 !== \count($parts)) {
            return $schedule;
        }

        [$minute, $hour, $dom, $month, $dow] = $parts;

        $h = \sprintf('%02d', (int) $hour);
        $m = \sprintf('%02d', (int) $minute);
        $t = 'contao_default';

        if ('*' === $dom && '*' === $month && '*' === $dow && ctype_digit($minute) && ctype_digit($hour)) {
            return $this->translator->trans('MSC.vsau_schedule_daily', [$h, $m], $t);
        }

        if ('*' === $dom && '*' === $month && ctype_digit($dow) && ctype_digit($minute) && ctype_digit($hour)) {
            $day = $this->translator->trans('MSC.vsau_weekday_'.(int) $dow, [], $t);

            return $this->translator->trans('MSC.vsau_schedule_weekday', [$day, $h, $m], $t);
        }

        if (1 === preg_match('/^\*\/(\d+)$/', $minute, $mt) && '*' === $hour && '*' === $dom && '*' === $month && '*' === $dow) {
            return $this->translator->trans('MSC.vsau_schedule_every_minutes', [(int) $mt[1]], $t);
        }

        if ('*' === $hour && '*' === $dom && '*' === $month && '*' === $dow && ctype_digit($minute)) {
            return $this->translator->trans('MSC.vsau_schedule_hourly', [$m], $t);
        }

        if ('*' === $month && '*' === $dow && ctype_digit($dom) && ctype_digit($minute) && ctype_digit($hour)) {
            return $this->translator->trans('MSC.vsau_schedule_monthly', [(int) $dom, $h, $m], $t);
        }

        return $schedule;
    }

    /**
     * Non-blocking prerequisite warnings (§10.5). Returned as a list of
     * translated strings.
     *
     * @param array<string, mixed> $config
     *
     * @return array<int, string>
     */
    private function prerequisiteWarnings(array $config): array
    {
        $warnings = [];

        if ('' === (string) ($config['vector_store_id'] ?? '')) {
            $warnings[] = $this->translator->trans('MSC.vsau_warn_no_vector_store', [], 'contao_default');
        }

        $hasStartPage = (int) ($config['auto_update_site_root'] ?? 0) > 0;
        $hasDomain = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM tl_page WHERE type = 'root' AND dns != ''",
        );
        if (!$hasStartPage && 1 !== $hasDomain) {
            $warnings[] = $this->translator->trans('MSC.vsau_warn_no_crawl_page', [], 'contao_default');
        }

        $indexed = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_search');
        if (0 === $indexed) {
            $warnings[] = $this->translator->trans('MSC.vsau_warn_no_indexed_pages', [], 'contao_default');
        }

        return $warnings;
    }
}
