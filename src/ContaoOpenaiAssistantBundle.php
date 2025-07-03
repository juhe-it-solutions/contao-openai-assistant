<?php

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\FileLocator;

class ContaoOpenaiAssistantBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function getPublicDir(): string
    {
        return 'public';
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        
        // Load services configuration
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../config'));
        $loader->load('services.yaml');
    }
} 