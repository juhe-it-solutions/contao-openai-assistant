<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) Leo Feyer
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Config\ConfigPluginInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;

class ContaoManagerPlugin implements BundlePluginInterface, ConfigPluginInterface, RoutingPluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(ContaoOpenaiAssistantBundle::class)
                ->setLoadAfter([ContaoCoreBundle::class])
                ->setReplace(['contao-openai-assistant']),
        ];
    }

    public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader, array $managerConfig): void
    {
        // Services are now loaded by the bundle class
    }

    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): ?RouteCollection
    {
        $file = '@ContaoOpenaiAssistantBundle/config/routes.yaml';

        if (! $resolver->resolve($file)) {
            return null;
        }

        return $resolver->resolve($file)->load($file);
    }
}
