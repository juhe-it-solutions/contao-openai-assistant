<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\EventListener;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiConfigListener;
use JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiFilesListener;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class OpenAiFilesListenerTest extends TestCase
{
    public function testResolveParentConfigIdUsesDataContainerPid(): void
    {
        $dc = $this->getMockBuilder(DataContainer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPalette', 'save'])
            ->getMockForAbstractClass()
        ;
        $dc->pid = 7;

        $this->assertSame(
            7,
            $this->invokeResolveParentConfigId($this->createListener($this->createMock(Connection::class)), $dc),
        );
    }

    public function testResolveParentConfigIdFallsBackToSingleExistingConfigOnCreate(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->with('SELECT id FROM tl_openai_config LIMIT 1')
            ->willReturn(['id' => 4])
        ;

        $requestStack = new RequestStack();
        $requestStack->push(new Request(['act' => 'create']));

        $this->assertSame(
            4,
            $this->invokeResolveParentConfigId($this->createListener($connection, $requestStack), null),
        );
    }

    public function testResolveParentConfigIdReturnsNullWhenNoConfigCanBeResolved(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request(['act' => 'edit']));

        $this->assertNull(
            $this->invokeResolveParentConfigId(
                $this->createListener($this->createMock(Connection::class), $requestStack),
                null,
            ),
        );
    }

    private function invokeResolveParentConfigId(OpenAiFilesListener $listener, DataContainer|null $dc): int|null
    {
        $method = new \ReflectionMethod(OpenAiFilesListener::class, 'resolveParentConfigId');
        $method->setAccessible(true);

        return $method->invoke($listener, $dc);
    }

    private function createListener(Connection $connection, RequestStack|null $requestStack = null): OpenAiFilesListener
    {
        return new OpenAiFilesListener(
            new MockHttpClient(),
            '/tmp/project',
            'public',
            new NullLogger(),
            $this->createMock(OpenAiConfigListener::class),
            $requestStack ?? new RequestStack(),
            $connection,
            $this->createMock(ContaoCsrfTokenManager::class),
            'REQUEST_TOKEN',
            $this->createMock(EncryptionService::class),
        );
    }
}
