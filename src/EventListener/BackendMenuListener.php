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
 * Both this entry and the "openai_sync_log" entry are hidden entirely only when no
 * license key has ever been stored — they are meaningless then. Once a key exists they
 * stay visible even if the subscription has lapsed, because the dashboard is where the
 * "Refresh license status" button and the "why did sync stop" explanation live; hiding
 * them would strip a paying-but-lapsed customer of the very path back to an active state.
 */
#[AsEventListener(ContaoCoreEvents::BACKEND_MENU_BUILD, priority: -255)]
class BackendMenuListener
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly Connection $connection,
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

        if (!$this->hasStoredLicenseKey()) {
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

    private function hasStoredLicenseKey(): bool
    {
        try {
            $schemaManager = $this->connection->createSchemaManager();
            if (!$schemaManager->tablesExist(['tl_openai_config'])) {
                return false;
            }

            // A stored key (active OR lapsed) keeps the menu entries reachable. No remote
            // call and no decryption — a non-empty ciphertext column is enough. The strict
            // isLicenseActive() check still gates every action on the dashboard itself.
            $key = $this->connection->fetchOne(
                "SELECT premium_license_key FROM tl_openai_config WHERE premium_license_key <> '' ORDER BY id LIMIT 1",
            );

            return \is_string($key) && '' !== $key;
        } catch (\Throwable) {
            return false;
        }
    }
}
