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
use Contao\System;
use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiConfigListener;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\CronHealthService;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\LicensePortalUrlService;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\LicenseValidationService;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreAutoUpdateService;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreFileSync;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\OpenAiModelCatalogService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

class OpenAiConfigListenerTest extends TestCase
{
    public function testConfigListAllowsCreatingFirstRecord(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT COUNT(*) FROM tl_openai_config')
            ->willReturn(0)
        ;

        $previousConfig = $GLOBALS['TL_DCA']['tl_openai_config']['config'] ?? null;
        $GLOBALS['TL_DCA']['tl_openai_config']['config'] = ['notCreatable' => true];

        try {
            $this->createListener($connection)->onLoadCallback(null);

            $this->assertArrayNotHasKey(
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
            ->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT COUNT(*) FROM tl_openai_config')
            ->willReturn(1)
        ;

        $previousConfig = $GLOBALS['TL_DCA']['tl_openai_config']['config'] ?? null;
        $GLOBALS['TL_DCA']['tl_openai_config']['config'] = [];

        try {
            $this->createListener($connection)->onLoadCallback(null);

            $this->assertTrue(
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

    public function testApiKeySaveKeepsStoredKeyWhenFieldLeftBlank(): void
    {
        // The exact scenario an admin hits when saving an existing config without
        // re-typing the secret: the Password widget submits empty, and the callback
        // must return the already-stored ciphertext rather than wiping it to ''.
        $listener = $this->createListener($this->createMock(Connection::class));

        $dc = (object) [
            'id' => 7,
            'activeRecord' => (object) ['api_key' => 'STORED_CIPHERTEXT'],
        ];

        $previousPost = $_POST;
        $_POST['api_key'] = '';

        try {
            $this->assertSame(
                'STORED_CIPHERTEXT',
                $listener->processApiKeyForStorage(null, $dc),
                'Leaving the API key field blank on an existing config must preserve the stored key.',
            );
        } finally {
            $_POST = $previousPost;
        }
    }

    public function testConfigFormRemovesCreateAndDuplicateButtons(): void
    {
        $listener = $this->createListener($this->createMock(Connection::class));

        $this->assertSame(
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

    public function testAutoUpdateStateMarkupHidesBlockWithoutActiveLicense(): void
    {
        $listener = $this->createListener($this->createMock(Connection::class));

        $markup = $listener->renderAutoUpdateBackendState(0, false);

        $this->assertStringContainsString('data-license-active="0"', $markup);
        $this->assertStringContainsString('data-config-id="0"', $markup);
        $this->assertStringContainsString(
            '<style>#pal_auto_update_legend{display:none}</style>',
            $markup,
            'Without a validated license the Vector-Store-Synchronisierung fieldset must be hidden before first paint.',
        );
    }

    public function testAutoUpdateStateMarkupShowsBlockWithActiveLicense(): void
    {
        $listener = $this->createListener($this->createMock(Connection::class));

        $markup = $listener->renderAutoUpdateBackendState(7, true);

        $this->assertStringContainsString('data-license-active="1"', $markup);
        $this->assertStringContainsString('data-config-id="7"', $markup);
        $this->assertStringNotContainsString('#pal_auto_update_legend', $markup);
    }

    public function testConfigDeletePurgesAutoSyncFilesBeforeRemovingLocalTrackingRows(): void
    {
        $executedStatements = [];

        $connection = $this->createMock(Connection::class);
        $connection
            ->method('executeStatement')
            ->willReturnCallback(
                static function (string $sql, array $params = []) use (&$executedStatements): int {
                    $executedStatements[] = [$sql, $params];

                    return 1;
                },
            )
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
            ->expects($this->once())
            ->method('deactivate')
            ->with(7)
        ;

        $fileSync = $this->createMock(VectorStoreFileSync::class);
        $fileSync
            ->expects($this->once())
            ->method('purge')
            ->with('sk-test', 'vs_123', 7)
            ->willReturnCallback(
                static function () use (&$executedStatements): void {
                    self::assertNotContains(
                        ['DELETE FROM tl_openai_vector_file WHERE pid = ?', [7]],
                        $executedStatements,
                        'Auto-sync file ids must still be available when remote purge runs.',
                    );
                },
            )
        ;

        $requests = [];
        $httpClient = new MockHttpClient(
            static function (string $method, string $url) use (&$requests): MockResponse {
                $requests[] = $method.' '.$url;

                if ('DELETE' === $method && 'https://api.openai.com/v1/vector_stores/vs_123' === $url) {
                    return new MockResponse('{}');
                }

                self::fail('Unexpected request: '.$method.' '.$url);
            },
        );

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
            $this->createMock(CronHealthService::class),
        );

        $dc = (object) [
            'id' => 7,
            'activeRecord' => (object) [
                'vector_store_id' => 'vs_123',
            ],
        ];

        $listener->deleteVectorStore($dc);

        $this->assertContains('DELETE https://api.openai.com/v1/vector_stores/vs_123', $requests);
        $this->assertContains(['DELETE FROM tl_openai_sync_log WHERE pid = ?', [7]], $executedStatements);
        $this->assertSame(
            ['DELETE FROM tl_openai_vector_file WHERE pid = ?', [7]],
            $executedStatements[array_key_last($executedStatements)],
        );
    }

    public function testValidateAutoUpdateModelRejectsEmptySelectionWhenModelsWereAvailable(): void
    {
        $this->bootMinimalContaoContainer();

        $listener = $this->createModelValidationListener(licenseActive: true, apiKey: 'sk-test');

        $this->expectException(\InvalidArgumentException::class);

        $listener->validateAutoUpdateModel('', $this->createModelValidationDc(autoUpdateEnabled: true));
    }

    public function testValidateAutoUpdateModelKeepsLegacyEmptyValueWithoutActiveLicense(): void
    {
        $listener = $this->createModelValidationListener(licenseActive: false, apiKey: 'sk-test');

        $this->assertSame(
            '',
            $listener->validateAutoUpdateModel('', $this->createModelValidationDc(autoUpdateEnabled: true)),
            'Without an active license the select never offered models, so the legacy empty value must stay saveable.',
        );
    }

    public function testValidateAutoUpdateModelKeepsEmptyValueWhileAutoUpdateIsDisabled(): void
    {
        $listener = $this->createModelValidationListener(licenseActive: true, apiKey: 'sk-test');

        $this->assertSame(
            '',
            $listener->validateAutoUpdateModel('', $this->createModelValidationDc(autoUpdateEnabled: false)),
            'While auto-update is disabled the select never offered models, so an empty value must stay saveable.',
        );
    }

    public function testFaithfulModeDisablesPromptTemplateAndHidesModelField(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchOne')
            ->with('SELECT auto_update_mode FROM tl_openai_config WHERE id = ?', [7])
            ->willReturn('faithful')
        ;

        $previousDca = $GLOBALS['TL_DCA']['tl_openai_config'] ?? null;
        $GLOBALS['TL_DCA']['tl_openai_config'] = [
            'palettes' => ['default' => '{auto_update_legend},auto_update_mode,auto_update_model,auto_update_prompt_template'],
            'fields' => ['auto_update_prompt_template' => ['eval' => []]],
        ];

        try {
            $method = new \ReflectionMethod(OpenAiConfigListener::class, 'configureAutoUpdateModelVisibility');
            $method->invoke($this->createListener($connection), 7);

            $this->assertSame(
                '{auto_update_legend},auto_update_mode,auto_update_prompt_template',
                $GLOBALS['TL_DCA']['tl_openai_config']['palettes']['default'],
                'Faithful mode must remove the generation model from the palette.',
            );
            $this->assertTrue(
                $GLOBALS['TL_DCA']['tl_openai_config']['fields']['auto_update_prompt_template']['eval']['disabled'] ?? false,
                'Faithful mode must disable the prompt template textarea.',
            );
        } finally {
            if (null === $previousDca) {
                unset($GLOBALS['TL_DCA']['tl_openai_config']);
            } else {
                $GLOBALS['TL_DCA']['tl_openai_config'] = $previousDca;
            }
        }
    }

    public function testLlmPolishModeKeepsPromptTemplateEditable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchOne')
            ->with('SELECT auto_update_mode FROM tl_openai_config WHERE id = ?', [7])
            ->willReturn('llm_polish')
        ;

        $previousDca = $GLOBALS['TL_DCA']['tl_openai_config'] ?? null;
        $GLOBALS['TL_DCA']['tl_openai_config'] = [
            'palettes' => ['default' => '{auto_update_legend},auto_update_mode,auto_update_model,auto_update_prompt_template'],
            'fields' => ['auto_update_prompt_template' => ['eval' => []]],
        ];

        try {
            $method = new \ReflectionMethod(OpenAiConfigListener::class, 'configureAutoUpdateModelVisibility');
            $method->invoke($this->createListener($connection), 7);

            $this->assertSame(
                '{auto_update_legend},auto_update_mode,auto_update_model,auto_update_prompt_template',
                $GLOBALS['TL_DCA']['tl_openai_config']['palettes']['default'],
                'LLM polish mode must keep the generation model in the palette.',
            );
            $this->assertArrayNotHasKey(
                'disabled',
                $GLOBALS['TL_DCA']['tl_openai_config']['fields']['auto_update_prompt_template']['eval'],
                'LLM polish mode must leave the prompt template editable.',
            );
        } finally {
            if (null === $previousDca) {
                unset($GLOBALS['TL_DCA']['tl_openai_config']);
            } else {
                $GLOBALS['TL_DCA']['tl_openai_config'] = $previousDca;
            }
        }
    }

    private function createModelValidationListener(bool $licenseActive, string $apiKey): OpenAiConfigListener
    {
        $encryption = $this->createMock(EncryptionService::class);
        $encryption
            ->method('getApiKeyForConfig')
            ->willReturn($apiKey)
        ;

        $licenseValidation = $this->createMock(LicenseValidationService::class);
        $licenseValidation
            ->method('isLicenseActiveCached')
            ->willReturn($licenseActive)
        ;

        return new OpenAiConfigListener(
            new MockHttpClient(),
            new NullLogger(),
            $this->createMock(ContaoCsrfTokenManager::class),
            'REQUEST_TOKEN',
            new RequestStack(),
            $this->createMock(Connection::class),
            $encryption,
            $this->createMock(LicensePortalUrlService::class),
            $licenseValidation,
            $this->createMock(OpenAiModelCatalogService::class),
            $this->createMock(VectorStoreAutoUpdateService::class),
            $this->createMock(VectorStoreFileSync::class),
            $this->createMock(RouterInterface::class),
            $this->createMock(CronHealthService::class),
        );
    }

    /**
     * System::loadLanguageFile() (used to resolve the validation message) needs a
     * container with kernel dirs. A pre-created cache file makes it skip the
     * resource finder, so two parameters are all the container has to provide.
     */
    private function bootMinimalContaoContainer(): void
    {
        $cacheDir = sys_get_temp_dir().'/oaa-test-'.uniqid('', true);
        mkdir($cacheDir.'/contao/languages/en', 0777, true);
        file_put_contents($cacheDir.'/contao/languages/en/tl_openai_config.php', "<?php\n");

        $container = new Container();
        $container->setParameter('kernel.project_dir', $cacheDir);
        $container->setParameter('kernel.cache_dir', $cacheDir);

        System::setContainer($container);
    }

    private function createModelValidationDc(bool $autoUpdateEnabled): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc
            ->method('__get')
            ->willReturnMap([
                ['id', 7],
                ['activeRecord', (object) ['auto_update_enabled' => $autoUpdateEnabled]],
            ])
        ;

        return $dc;
    }

    private function createListener(Connection $connection): OpenAiConfigListener
    {
        return new OpenAiConfigListener(
            new MockHttpClient(),
            new NullLogger(),
            $this->createMock(ContaoCsrfTokenManager::class),
            'REQUEST_TOKEN',
            new RequestStack(),
            $connection,
            $this->createMock(EncryptionService::class),
            $this->createMock(LicensePortalUrlService::class),
            $this->createMock(LicenseValidationService::class),
            $this->createMock(OpenAiModelCatalogService::class),
            $this->createMock(VectorStoreAutoUpdateService::class),
            $this->createMock(VectorStoreFileSync::class),
            $this->createMock(RouterInterface::class),
            $this->createMock(CronHealthService::class),
        );
    }
}
