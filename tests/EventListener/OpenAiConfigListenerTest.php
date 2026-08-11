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
use Contao\CoreBundle\Routing\ScopeMatcher;
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
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
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
            $this->createMock(Packages::class),
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

        // Both local tables are cleared, and only AFTER the remote purge: the file rows are
        // what tells us which files to delete at OpenAI, so dropping them first would strand
        // the files there. The rewrite cache holds a copy of every page's text and has no
        // other owner once the configuration is gone - the sync-time prune only ever runs
        // for a configuration that still exists.
        $localDeletes = array_values(array_filter(
            $executedStatements,
            static fn (array $s): bool => \in_array($s[0], [
                'DELETE FROM tl_openai_vector_file WHERE pid = ?',
                'DELETE FROM tl_openai_polish_cache WHERE pid = ?',
            ], true),
        ));

        $this->assertSame(
            [
                ['DELETE FROM tl_openai_vector_file WHERE pid = ?', [7]],
                ['DELETE FROM tl_openai_polish_cache WHERE pid = ?', [7]],
            ],
            $localDeletes,
        );
        $this->assertSame(
            \count($executedStatements) - 2,
            array_search($localDeletes[0], $executedStatements, true),
            'The local tracking rows are the last thing removed.',
        );
    }

    /**
     * tl_openai_polish_cache is newer than the rest, so a code update without
     * contao:migrate can reach the delete with the table missing. This runs in an
     * ondelete_callback: throwing there aborts the deletion after tl_undo was written
     * and after the remote vector store is already gone, leaving a configuration that
     * points at nothing. Orphan cache rows are the cheaper failure.
     */
    public function testConfigDeleteSurvivesAMissingRewriteCacheTable(): void
    {
        $executedStatements = [];

        $connection = $this->createMock(Connection::class);
        $connection
            ->method('executeStatement')
            ->willReturnCallback(
                static function (string $sql, array $params = []) use (&$executedStatements): int {
                    $executedStatements[] = [$sql, $params];

                    if (str_contains($sql, 'tl_openai_polish_cache')) {
                        throw new \RuntimeException("Base table or view not found: 1146 Table 'tl_openai_polish_cache' doesn't exist");
                    }

                    return 1;
                },
            )
        ;

        // No vector store id: the remote branch returns early, leaving the finally block
        // as the whole point of the test.
        $dc = (object) [
            'id' => 7,
            'activeRecord' => (object) ['vector_store_id' => ''],
        ];

        $this->createListener($connection)->deleteVectorStore($dc);

        $this->assertContains(['DELETE FROM tl_openai_sync_log WHERE pid = ?', [7]], $executedStatements);
        $this->assertContains(['DELETE FROM tl_openai_vector_file WHERE pid = ?', [7]], $executedStatements);
        $this->assertContains(['DELETE FROM tl_openai_polish_cache WHERE pid = ?', [7]], $executedStatements);
    }

    public function testConfigDeleteSurvivesAllMissingPostUpgradeHousekeepingTables(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('executeStatement')
            ->willThrowException(new \RuntimeException('table does not exist'))
        ;

        $dc = (object) [
            'id' => 7,
            'activeRecord' => (object) ['vector_store_id' => ''],
        ];

        // A non-premium customer can delete an old configuration after deploying code but
        // before contao:migrate. Missing machine-state tables are housekeeping, not a reason
        // to leave that delete half-finished.
        $this->createListener($connection)->deleteVectorStore($dc);
        $this->addToAssertionCount(1);
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
            'fields' => ['auto_update_prompt_template' => ['eval' => ['tl_class' => 'clr auto-update-license-field']]],
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
            $this->assertSame(
                'clr auto-update-license-field oaa-mode-locked',
                $GLOBALS['TL_DCA']['tl_openai_config']['fields']['auto_update_prompt_template']['eval']['tl_class'],
                'Faithful mode must mark the widget so the license JS keeps it disabled.',
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
            $this->createMock(Packages::class),
        );
    }

    /**
     * System::loadLanguageFile() (used to resolve the validation message) needs a
     * container with kernel dirs. A pre-created cache file makes it skip the
     * resource finder, so two parameters are all the container has to provide.
     *
     * Contao\Message additionally needs a request stack with a session (it writes to
     * the flash bag) and the scope matcher it uses to pick the message scope.
     */
    private function bootMinimalContaoContainer(): RequestStack
    {
        $cacheDir = sys_get_temp_dir().'/oaa-test-'.uniqid('', true);
        mkdir($cacheDir.'/contao/languages/en', 0777, true);
        file_put_contents($cacheDir.'/contao/languages/en/tl_openai_config.php', "<?php\n");

        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $scopeMatcher = $this->createMock(ScopeMatcher::class);
        $scopeMatcher->method('isBackendRequest')->willReturn(true);

        $container = new Container();
        $container->setParameter('kernel.project_dir', $cacheDir);
        $container->setParameter('kernel.cache_dir', $cacheDir);
        $container->set('request_stack', $requestStack);
        $container->set('contao.routing.scope_matcher', $scopeMatcher);

        System::setContainer($container);

        return $requestStack;
    }

    public function testPageOverflowBlocksTheSave(): void
    {
        $listener = $this->createLimitListener(['pages' => 25, 'items' => 0]);

        $this->expectException(\InvalidArgumentException::class);

        $listener->enforceCrawlPageLimit(serialize([1, 2, 3]), $this->createLimitDc());
    }

    public function testItemOverflowOnlyWarnsAndNeverBlocksTheSave(): void
    {
        // This callback runs whenever the page-selection field is submitted, i.e. on every
        // save of a premium configuration. Throwing on an item overflow would lock the
        // customer out of their API key, prompt and schedule as soon as an editor
        // published one news item too many - and unlike pages there is often no selection
        // left to reduce.
        $requestStack = $this->bootMinimalContaoContainer();
        $session = $requestStack->getCurrentRequest()->getSession();
        $listener = $this->createLimitListener(['pages' => 5, 'items' => 63], $requestStack);
        $value = serialize([1, 2, 3]);

        $this->assertSame(
            $value,
            $listener->enforceCrawlPageLimit($value, $this->createLimitDc()),
            'An item overflow must leave the save untouched; the sync reports it instead.',
        );

        // Asserting the notice really fired: without this the branch could silently
        // degrade into a no-op and the test would still pass.
        $this->assertNotSame(
            [],
            $session->getFlashBag()->peekAll(),
            'The customer must be told about the item overflow.',
        );
    }

    public function testStayingWithinBothBudgetsSavesUnchanged(): void
    {
        $requestStack = $this->bootMinimalContaoContainer();
        $session = $requestStack->getCurrentRequest()->getSession();
        $listener = $this->createLimitListener(['pages' => 5, 'items' => 21], $requestStack);
        $value = serialize([1, 2, 3]);

        $this->assertSame($value, $listener->enforceCrawlPageLimit($value, $this->createLimitDc()));
        $this->assertSame([], $session->getFlashBag()->peekAll(), 'No notice while inside both budgets.');
    }

    /**
     * @param array{pages: int, items: int} $scope
     */
    private function createLimitListener(array $scope, RequestStack|null $requestStack = null): OpenAiConfigListener
    {
        $licenseValidation = $this->createMock(LicenseValidationService::class);
        $licenseValidation->method('isLicenseActive')->willReturn(true);

        $autoUpdate = $this->createMock(VectorStoreAutoUpdateService::class);
        $autoUpdate->method('countScopeBreakdown')->willReturn($scope);

        return new OpenAiConfigListener(
            new MockHttpClient(),
            new NullLogger(),
            $this->createMock(ContaoCsrfTokenManager::class),
            'REQUEST_TOKEN',
            $requestStack ?? new RequestStack(),
            $this->createMock(Connection::class),
            $this->createMock(EncryptionService::class),
            $this->createMock(LicensePortalUrlService::class),
            $licenseValidation,
            $this->createMock(OpenAiModelCatalogService::class),
            $autoUpdate,
            $this->createMock(VectorStoreFileSync::class),
            $this->createMock(RouterInterface::class),
            $this->createMock(CronHealthService::class),
            $this->createMock(Packages::class),
        );
    }

    private function createLimitDc(): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc
            ->method('__get')
            ->willReturnMap([
                ['id', 7],
                ['activeRecord', (object) [
                    'premium_license_plan' => 'starter',
                    'premium_license_max_pages' => 20,
                    // Fresh, so the callback does not try to re-fetch the plan remotely.
                    'premium_license_checked_at' => time(),
                ]],
            ])
        ;

        return $dc;
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

    /**
     * A configuration saved before the link feature existed has a NULL column,
     * because a DCA "default" is only written when a record is created. Without
     * the load_callback the form would show no type checked while the sync
     * allows every type.
     */
    public function testLinkTypesShowEveryTypeForConfigurationsSavedBeforeTheFeature(): void
    {
        $listener = $this->createModelValidationListener(true, 'sk-test');

        $this->assertSame(
            OpenAiConfigListener::DEFAULT_LINK_TYPES,
            $listener->prepareLinkTypesField(null, $this->createModelValidationDc(true)),
            'A never-saved link type list must render with every type checked, matching what the sync does.',
        );

        $this->assertSame(
            OpenAiConfigListener::DEFAULT_LINK_TYPES,
            $listener->prepareLinkTypesField('', $this->createModelValidationDc(true)),
            'An empty string is the same "never saved" state as NULL.',
        );
    }

    public function testLinkTypesKeepAStoredSelection(): void
    {
        $stored = serialize(['page', 'file']);

        $this->assertSame(
            $stored,
            $this->createModelValidationListener(true, 'sk-test')->prepareLinkTypesField($stored, $this->createModelValidationDc(true)),
            'An explicit selection must never be widened by the load_callback.',
        );
    }

    /**
     * Contao stores an emptied checkbox group as NULL for a nullable column, so
     * "no type checked" would silently mean "all types". The field is therefore
     * mandatory - but only with an active licence, because the premium fields
     * are disabled (and thus not submitted) without one.
     */
    public function testLinkTypesAreMandatoryOnlyWithAnActiveLicense(): void
    {
        $previousDca = $GLOBALS['TL_DCA']['tl_openai_config'] ?? null;

        try {
            $GLOBALS['TL_DCA']['tl_openai_config']['fields']['auto_update_link_types']['eval'] = [];
            $this->createModelValidationListener(false, 'sk-test')->prepareLinkTypesField(null, $this->createModelValidationDc(true));

            $this->assertArrayNotHasKey(
                'mandatory',
                $GLOBALS['TL_DCA']['tl_openai_config']['fields']['auto_update_link_types']['eval'],
                'Without a licence the field is disabled client-side; making it mandatory would block every save.',
            );

            $GLOBALS['TL_DCA']['tl_openai_config']['fields']['auto_update_link_types']['eval'] = [];
            $this->createModelValidationListener(true, 'sk-test')->prepareLinkTypesField(null, $this->createModelValidationDc(true));

            $this->assertTrue(
                $GLOBALS['TL_DCA']['tl_openai_config']['fields']['auto_update_link_types']['eval']['mandatory'],
                'With an active licence the admin must pick at least one type instead of silently getting all of them.',
            );
        } finally {
            if (null === $previousDca) {
                unset($GLOBALS['TL_DCA']['tl_openai_config']);
            } else {
                $GLOBALS['TL_DCA']['tl_openai_config'] = $previousDca;
            }
        }
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
            $this->createMock(Packages::class),
        );
    }
}
