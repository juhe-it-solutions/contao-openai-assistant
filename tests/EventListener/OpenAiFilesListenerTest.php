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
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class OpenAiFilesListenerTest extends TestCase
{
    public function testResolveParentConfigIdUsesDataContainerCurrentPid(): void
    {
        $dc = $this->getMockBuilder(DataContainer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPalette', 'save'])
            ->getMockForAbstractClass()
        ;
        $property = new \ReflectionProperty(DataContainer::class, 'intCurrentPid');
        $property->setValue($dc, 7);

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

    /**
     * A Files API upload can succeed while the vector-store attachment fails, and File Search
     * cannot use an unattached document. The failure therefore has to reach the caller, which
     * records the row as failed - previously it was swallowed, and the back end announced
     * "File uploaded successfully" next to the attachment error on the same screen.
     */
    public function testAFailedVectorStoreAttachmentIsReportedToTheCaller(): void
    {
        $requests = [];
        $client = new MockHttpClient(
            static function (string $method, string $url) use (&$requests): MockResponse {
                $requests[] = $method.' '.$url;

                if ('POST' === $method) {
                    return new MockResponse('{"error":{"message":"vector store is full"}}', ['http_code' => 500]);
                }

                return new MockResponse('{"deleted":true}');
            },
        );

        $listener = $this->createListener($this->createMock(Connection::class), null, $client);

        $method = new \ReflectionMethod(OpenAiFilesListener::class, 'addFileToVectorStore');
        $method->setAccessible(true);

        try {
            $method->invoke($listener, 'sk-test', 'vs_123', 'file_abc');
            $this->fail('The attachment failure must not be swallowed.');
        } catch (\Exception) {
            // Expected: the outer upload handler records the row as failed.
        }

        $this->assertSame(
            ['POST https://api.openai.com/v1/vector_stores/vs_123/files'],
            $requests,
            'Cleanup belongs to the outer transaction, which also covers a later database failure.',
        );
    }

    /**
     * Cleanup is best effort: the upload has already failed, and a second failure must not
     * replace the real error with one about the cleanup.
     */
    public function testAFailedOrphanCleanupStillReportsTheAttachmentFailure(): void
    {
        $client = new MockHttpClient(
            static fn (string $method): MockResponse => 'POST' === $method
                ? new MockResponse('{"error":{"message":"nope"}}', ['http_code' => 500])
                : new MockResponse('', ['error' => 'connection reset']),
        );

        $listener = $this->createListener($this->createMock(Connection::class), null, $client);

        $method = new \ReflectionMethod(OpenAiFilesListener::class, 'addFileToVectorStore');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $method->invoke($listener, 'sk-test', 'vs_123', 'file_abc');
    }

    public function testFailedUploadCleanupDetachesBeforeDeletingAndChecksBothOutcomes(): void
    {
        $requests = [];
        $client = new MockHttpClient(
            static function (string $method, string $url) use (&$requests): MockResponse {
                $requests[] = $method.' '.$url;

                return new MockResponse('{}', ['http_code' => 204]);
            },
        );

        $listener = $this->createListener($this->createMock(Connection::class), null, $client);
        $method = new \ReflectionMethod(OpenAiFilesListener::class, 'cleanupFailedUpload');
        $method->invoke($listener, 'sk-test', 'vs_123', 'file_abc', true);

        $this->assertSame(
            [
                'DELETE https://api.openai.com/v1/vector_stores/vs_123/files/file_abc',
                'DELETE https://api.openai.com/v1/files/file_abc',
            ],
            $requests,
        );
    }

    public function testCleanupDoesNotMistakeA500ResponseForDeletion(): void
    {
        $client = new MockHttpClient(
            static fn (): MockResponse => new MockResponse('{}', ['http_code' => 500]),
        );

        $listener = $this->createListener($this->createMock(Connection::class), null, $client);
        $method = new \ReflectionMethod(OpenAiFilesListener::class, 'confirmCleanupDelete');

        $this->assertFalse($method->invoke($listener, 'sk-test', 'https://api.openai.com/v1/files/file_abc'));
    }

    private function invokeResolveParentConfigId(OpenAiFilesListener $listener, DataContainer|null $dc): int|null
    {
        $method = new \ReflectionMethod(OpenAiFilesListener::class, 'resolveParentConfigId');
        $method->setAccessible(true);

        return $method->invoke($listener, $dc);
    }

    private function createListener(Connection $connection, RequestStack|null $requestStack = null, MockHttpClient|null $client = null): OpenAiFilesListener
    {
        return new OpenAiFilesListener(
            $client ?? new MockHttpClient(),
            '/tmp/project',
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
