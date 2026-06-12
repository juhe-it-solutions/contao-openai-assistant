<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) JUHE IT-solutions
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\EventListener;

use Contao\CoreBundle\Event\ContaoCoreEvents;
use Contao\CoreBundle\Event\MenuEvent;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\RouterInterface;

/**
 * Adds the "Vector Store Auto-Update" navigation entry under the AI Tools category.
 *
 * The BE_MOD array alone cannot link to a custom route; navigation must come from
 * the MenuEvent. The entry is hidden for users without module access (the entry is
 * also enforced server-side in the controller).
 */
#[AsEventListener(ContaoCoreEvents::BACKEND_MENU_BUILD, priority: -255)]
class BackendMenuListener
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly Security $security,
    ) {
    }

    public function __invoke(MenuEvent $event): void
    {
        $tree = $event->getTree();

        if ($tree->getName() !== 'mainMenu') {
            return;
        }

        if (! $this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'vector_store_auto_update')) {
            return;
        }

        $categoryNode = $tree->getChild('ai_tools');
        if ($categoryNode === null) {
            return;
        }

        $factory = $event->getFactory();

        $categoryNode->addChild(
            $factory->createItem('vector_store_auto_update')
                ->setLabel('Vector Store Auto-Update')
                ->setUri($this->router->generate('vector_store_auto_update'))
                ->setLinkAttribute('title', 'Status and log for automatic vector store sync')
                ->setLinkAttribute('class', 'navigation')
                ->setAttribute('class', 'group-ai_tools')
        );
    }
}
