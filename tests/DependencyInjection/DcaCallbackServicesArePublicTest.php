<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * A class named by STRING inside a DCA callback is instantiated through
 * System::importStatic(), which can only reach the container for PUBLIC service
 * ids. For a private id - which Symfony compiles away when nothing references it -
 * importStatic() throws a ServiceNotFoundException as soon as kernel.debug is on,
 * so the backend form fatals in dev while working in prod.
 *
 * This behaviour is identical on Contao 5.3, 5.7 and 6.0, so the rule is:
 * every class referenced by name from contao/dca/ must be a public service.
 */
class DcaCallbackServicesArePublicTest extends TestCase
{
    public function testEveryClassNamedInADcaCallbackIsAPublicService(): void
    {
        $referenced = $this->classesReferencedFromDcaFiles();

        $this->assertNotEmpty(
            $referenced,
            'Found no class references in contao/dca/ - the scanner is broken, not the DCAs.',
        );

        $container = $this->loadServicesYaml();

        foreach ($referenced as $class => $files) {
            $this->assertTrue(
                $container->hasDefinition($class),
                \sprintf('"%s" is referenced by %s but has no service definition.', $class, implode(', ', $files)),
            );

            $this->assertTrue(
                $container->getDefinition($class)->isPublic(),
                \sprintf(
                    '"%s" is named by string in %s, so System::importStatic() must be able to fetch it from the '
                    .'container. Mark it "public: true" in config/services.yaml, otherwise the backend form fatals '
                    .'with a ServiceNotFoundException whenever kernel.debug is enabled.',
                    $class,
                    implode(', ', $files),
                ),
            );
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function classesReferencedFromDcaFiles(): array
    {
        $referenced = [];

        foreach (glob($this->projectDir().'/contao/dca/*.php') as $file) {
            // The DCA source spells the class as a single-quoted literal with single
            // backslashes, e.g. 'JuheItSolutions\ContaoOpenaiAssistant\...\Listener'.
            preg_match_all('/\'(JuheItSolutions\\\\[A-Za-z0-9_\\\\]+)\'/', (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $class) {
                $referenced[$class][] = basename($file);
            }
        }

        foreach ($referenced as $class => $files) {
            $referenced[$class] = array_values(array_unique($files));
        }

        return $referenced;
    }

    private function loadServicesYaml(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator($this->projectDir().'/config'));
        $loader->load('services.yaml');

        return $container;
    }

    private function projectDir(): string
    {
        return \dirname(__DIR__, 2);
    }
}
