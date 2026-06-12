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
 */
#[AsEventListener(ContaoCoreEvents::BACKEND_MENU_BUILD, priority: -255)]
class BackendMenuListener
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(MenuEvent $event): void
    {
        $tree = $event->getTree();

        if ('mainMenu' !== $tree->getName()) {
            return;
        }

        if (!$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'vector_store_auto_update')) {
            return;
        }

        $categoryNode = $tree->getChild('ai_tools');
        if (null === $categoryNode) {
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
}
