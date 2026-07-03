<?php

declare(strict_types=1);

/*
 * This file is part of Contao Open Source CMS.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\EventListener;

use Contao\CoreBundle\Event\ContaoCoreEvents;
use Contao\CoreBundle\Event\MenuEvent;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Service\LicenseValidationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adds the "OpenAI vector store auto-update" navigation entry under the AI
 * Tools category.
 *
 * The BE_MOD array alone cannot link to a custom route; navigation must come from
 * the MenuEvent. The entry is hidden for users without module access (the entry
 * is also enforced server-side in the controller).
 *
 * Both this entry and the "openai_sync_log" entry are hidden entirely when no
 * valid premium license is active — they are meaningless without it.
 */
#[AsEventListener(ContaoCoreEvents::BACKEND_MENU_BUILD, priority: -255)]
class BackendMenuListener
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly Connection $connection,
        private readonly LicenseValidationService $licenseValidation,
    ) {
    }

    public function __invoke(MenuEvent $event): void
    {
        $tree = $event->getTree();

        if ('mainMenu' !== $tree->getName()) {
            return;
        }

        $categoryNode = $tree->getChild('ai_tools');
        if (null === $categoryNode) {
            return;
        }

        if (!$this->hasAnyActiveLicense()) {
            $categoryNode->removeChild('vector_store_auto_update');
            $categoryNode->removeChild('openai_sync_log');

            return;
        }

        if (!$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'vector_store_auto_update')) {
            return;
        }

        $factory = $event->getFactory();

        $categoryNode->addChild(
            $factory->createItem('vector_store_auto_update')
                ->setLabel('MOD.vector_store_auto_update.0')
                ->setExtra('translation_domain', 'contao_modules')
                ->setUri($this->router->generate('vector_store_auto_update'))
                ->setLinkAttribute('title', $this->translator->trans('MOD.vector_store_auto_update.1', [], 'contao_modules'))
                ->setLinkAttribute('class', 'navigation vector_store_auto_update'),
        );
    }

    private function hasAnyActiveLicense(): bool
    {
        try {
            $schemaManager = $this->connection->createSchemaManager();
            if (!$schemaManager->tablesExist(['tl_openai_config'])) {
                return false;
            }

            $configId = $this->connection->fetchOne('SELECT id FROM tl_openai_config ORDER BY id LIMIT 1');
            if (!$configId) {
                return false;
            }

            // Cache-only check: this listener runs on every backend request, so it must
            // never trigger the (blocking) remote revalidation. The authoritative check
            // still runs on the dashboard, on save and before every sync.
            return $this->licenseValidation->isLicenseActiveCached((int) $configId);
        } catch (\Throwable) {
            return false;
        }
    }
}
