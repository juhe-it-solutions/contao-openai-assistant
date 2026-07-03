<?php

declare(strict_types=1);

/*
 * This file is part of Contao Open Source CMS.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\EventListener;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiConfigListener;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\LicensePortalUrlService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\LicenseValidationService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\OpenAiModelCatalogService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\VectorStoreAutoUpdateService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\VectorStoreFileSync;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

class OpenAiConfigListenerTest extends TestCase
{
    public function testConfigDeletePurgesAutoSyncFilesBeforeRemovingLocalTrackingRows(): void
    {
        $executedStatements = [];

        $connection = $this->createMock(Connection::class);
        $connection
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$executedStatements): int {
                $executedStatements[] = [$sql, $params];

                return 1;
            })
        ;
        $connection
            ->method('fetchAllAssociative')
            ->with('SELECT id, openai_file_id FROM tl_openai_files WHERE pid = ?', [7])
            ->willReturn([])
        ;

        $encryption = $this->createMock(EncryptionService::class);
        $encryption
            ->method('getApiKeyForConfig')
            ->with(7)
            ->willReturn('sk-test')
        ;

        $licenseValidation = $this->createMock(LicenseValidationService::class);
        $licenseValidation
            ->expects(self::once())
            ->method('deactivate')
            ->with(7)
        ;

        $fileSync = $this->createMock(VectorStoreFileSync::class);
        $fileSync
            ->expects(self::once())
            ->method('purge')
            ->with('sk-test', 'vs_123', 7)
            ->willReturnCallback(static function () use (&$executedStatements): void {
                self::assertNotContains(
                    ['DELETE FROM tl_openai_vector_file WHERE pid = ?', [7]],
                    $executedStatements,
                    'Auto-sync file ids must still be available when remote purge runs.',
                );
            })
        ;

        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $method.' '.$url;

            if ('DELETE' === $method && 'https://api.openai.com/v1/vector_stores/vs_123' === $url) {
                return new MockResponse('{}');
            }

            self::fail('Unexpected request: '.$method.' '.$url);
        });

        $listener = new OpenAiConfigListener(
            $httpClient,
            new NullLogger(),
            $this->createMock(ContaoCsrfTokenManager::class),
            'REQUEST_TOKEN',
            new RequestStack(),
            $connection,
            $encryption,
            $this->createMock(LicensePortalUrlService::class),
            $licenseValidation,
            $this->createMock(OpenAiModelCatalogService::class),
            $this->createMock(VectorStoreAutoUpdateService::class),
            $fileSync,
            $this->createMock(RouterInterface::class),
        );

        $dc = (object) [
            'id' => 7,
            'activeRecord' => (object) [
                'vector_store_id' => 'vs_123',
            ],
        ];

        $listener->deleteVectorStore($dc);

        self::assertContains('DELETE https://api.openai.com/v1/vector_stores/vs_123', $requests);
        self::assertContains(['DELETE FROM tl_openai_sync_log WHERE pid = ?', [7]], $executedStatements);
        self::assertSame(
            ['DELETE FROM tl_openai_vector_file WHERE pid = ?', [7]],
            $executedStatements[array_key_last($executedStatements)],
        );
    }
}
