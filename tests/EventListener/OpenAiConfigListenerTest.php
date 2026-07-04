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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

class OpenAiConfigListenerTest extends TestCase
{
    public function testConfigListAllowsCreatingFirstRecord(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT COUNT(*) FROM tl_openai_config')
            ->willReturn(0)
        ;

        $previousConfig = $GLOBALS['TL_DCA']['tl_openai_config']['config'] ?? null;
        $GLOBALS['TL_DCA']['tl_openai_config']['config'] = ['notCreatable' => true];

        try {
            $this->createListener($connection)->onLoadCallback(null);

            self::assertArrayNotHasKey(
                'notCreatable',
                $GLOBALS['TL_DCA']['tl_openai_config']['config'],
                'A fresh installation must show Contao\'s "new" action for the first OpenAI config.',
            );
        } finally {
            if (null === $previousConfig) {
                unset($GLOBALS['TL_DCA']['tl_openai_config']['config']);
            } else {
                $GLOBALS['TL_DCA']['tl_openai_config']['config'] = $previousConfig;
            }
        }
    }

    public function testConfigListDisablesCreatingAdditionalRecords(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT COUNT(*) FROM tl_openai_config')
            ->willReturn(1)
        ;

        $previousConfig = $GLOBALS['TL_DCA']['tl_openai_config']['config'] ?? null;
        $GLOBALS['TL_DCA']['tl_openai_config']['config'] = [];

        try {
            $this->createListener($connection)->onLoadCallback(null);

            self::assertTrue(
                $GLOBALS['TL_DCA']['tl_openai_config']['config']['notCreatable'],
                'After the first OpenAI config exists, Contao must hide the "new" action.',
            );
        } finally {
            if (null === $previousConfig) {
                unset($GLOBALS['TL_DCA']['tl_openai_config']['config']);
            } else {
                $GLOBALS['TL_DCA']['tl_openai_config']['config'] = $previousConfig;
            }
        }
    }

    public function testConfigFormRemovesCreateAndDuplicateButtons(): void
    {
        $listener = $this->createListener($this->createMock(Connection::class));

        self::assertSame(
            [
                'save' => '<button>save</button>',
                'saveNclose' => '<button>saveNclose</button>',
            ],
            $listener->removeSingleRecordCreateButtons([
                'save' => '<button>save</button>',
                'saveNclose' => '<button>saveNclose</button>',
                'saveNcreate' => '<button>saveNcreate</button>',
                'saveNduplicate' => '<button>saveNduplicate</button>',
            ]),
        );
    }

    public function testCreateFormHidesAutoUpdateBlockUntilLicenseIsValidated(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchOne')
            ->with('SELECT COUNT(*) FROM tl_openai_config')
            ->willReturn(0)
        ;
        $connection
            ->method('fetchAssociative')
            ->with('SELECT id, title FROM tl_openai_config LIMIT 1')
            ->willReturn(false)
        ;

        $requestStack = new RequestStack();
        $requestStack->push(new Request(['act' => 'create']));

        $previousHead = $GLOBALS['TL_HEAD'] ?? null;
        $previousBody = $GLOBALS['TL_BODY'] ?? null;
        $GLOBALS['TL_HEAD'] = [];
        $GLOBALS['TL_BODY'] = [];

        try {
            $this->createListener($connection, $requestStack)->onLoadCallback(null);

            self::assertContains(
                '<style>#pal_auto_update_legend{display:none}</style>',
                $GLOBALS['TL_HEAD'],
            );
            $hasInactiveCreateState = false;
            foreach ($GLOBALS['TL_BODY'] as $entry) {
                if (str_contains($entry, 'configId: 0, licenseActive: false')) {
                    $hasInactiveCreateState = true;
                    break;
                }
            }

            self::assertTrue($hasInactiveCreateState);
        } finally {
            if (null === $previousHead) {
                unset($GLOBALS['TL_HEAD']);
            } else {
                $GLOBALS['TL_HEAD'] = $previousHead;
            }

            if (null === $previousBody) {
                unset($GLOBALS['TL_BODY']);
            } else {
                $GLOBALS['TL_BODY'] = $previousBody;
            }
        }
    }

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

    private function createListener(Connection $connection, RequestStack|null $requestStack = null): OpenAiConfigListener
    {
        return new OpenAiConfigListener(
            new MockHttpClient(),
            new NullLogger(),
            $this->createMock(ContaoCsrfTokenManager::class),
            'REQUEST_TOKEN',
            $requestStack ?? new RequestStack(),
            $connection,
            $this->createMock(EncryptionService::class),
            $this->createMock(LicensePortalUrlService::class),
            $this->createMock(LicenseValidationService::class),
            $this->createMock(OpenAiModelCatalogService::class),
            $this->createMock(VectorStoreAutoUpdateService::class),
            $this->createMock(VectorStoreFileSync::class),
            $this->createMock(RouterInterface::class),
        );
    }
}
