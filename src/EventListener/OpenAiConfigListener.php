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

use Contao\Controller;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\DataContainer;
use Contao\Environment;
use Contao\Message;
use Contao\System;
use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\LicenseValidationService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\OpenAiModelCatalogService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\VectorStoreAutoUpdateService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\VectorStoreDocumentPrompt;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAiConfigListener
{
    /**
     * Rendered in place of a stored license key; posted back verbatim when the admin
     * leaves it untouched.
     */
    public const LICENSE_KEY_MASK = '••••••••••••••••';

    /** @var list<string> Premium-gated auto-update fields. */
    private const AUTO_UPDATE_LICENSE_FIELDS = [
        'auto_update_enabled',
        'auto_update_schedule_hour',
        'auto_update_schedule_minute',
        'auto_update_schedule_weekday',
        'auto_update_schedule_day',
        'auto_update_model',
        'auto_update_max_content',
        'auto_update_site_root',
        'auto_update_prompt_template',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly string $csrfTokenName,
        private readonly RequestStack $requestStack,
        private readonly Connection $connection,
        private readonly EncryptionService $encryption,
        private readonly LicenseValidationService $licenseValidation,
        private readonly OpenAiModelCatalogService $modelCatalog,
    ) {
    }

    /**
     * load_callback: never expose the stored ciphertext (or the plaintext). Show a
     * fixed mask when a key exists, empty when it does not.
     */
    public function processLicenseKeyForDisplay($value, $dc = null): string
    {
        return $value ? self::LICENSE_KEY_MASK : '';
    }

    /**
     * save_callback: encrypt a newly entered key; keep the existing one when the mask
     * is posted back unchanged; clear when the field is emptied.
     *
     * Returns the value to STORE (ciphertext), so the DB only ever holds encrypted keys.
     */
    public function processLicenseKeyForStorage($value, $dc): string
    {
        // Read from POST first (same pattern as processApiKeyForStorage).
        $posted = trim((string) ($_POST['premium_license_key'] ?? $value));
        $existing = (string) ($dc->activeRecord->premium_license_key ?? ''); // already encrypted

        // Unchanged — admin left the mask in place.
        if (self::LICENSE_KEY_MASK === $posted) {
            return $existing;
        }

        // Cleared.
        if ('' === $posted) {
            return '';
        }

        // New key entered — validate format before encrypting.
        if (!$this->encryption->isValidLicenseKeyFormat($posted)) {
            Message::addError('Invalid license key format. Expected "JH-AI-…". The previous key was kept.');

            return $existing; // do not overwrite a good key with a malformed one
        }

        return $this->encryption->encryptLicenseKey($posted);
    }

    /**
     * config.onsubmit: after the row is saved, validate the key against the licence
     * server so the status badge is correct on redirect. Reads the PLAINTEXT from
     * $_POST (the save callback has already stored the encrypted form).
     */
    public function validatePremiumLicenseOnSave(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $posted = trim((string) ($_POST['premium_license_key'] ?? ''));

        // Unchanged — keep cached status, no network call.
        if (self::LICENSE_KEY_MASK === $posted) {
            return;
        }

        // Cleared — reset cached status, but only when there was prior license state.
        // This keeps the non-premium save path completely free of extra DB writes.
        if ('' === $posted) {
            $record = $dc->activeRecord;
            $hadState = $record && (
                ($record->premium_license_key ?? '') !== ''
                || ($record->premium_license_status ?? '') !== ''
                || (int) ($record->premium_license_checked_at ?? 0) > 0
            );

            if ($hadState) {
                $this->connection->executeStatement(
                    'UPDATE tl_openai_config SET premium_license_status = ?, premium_license_valid_until = 0, premium_license_checked_at = 0 WHERE id = ?',
                    ['', (int) $dc->id],
                );
            }

            return;
        }

        // Malformed keys never reach the endpoint (already rejected in the save callback).
        if (!$this->encryption->isValidLicenseKeyFormat($posted)) {
            return;
        }

        $active = $this->licenseValidation->revalidate((int) $dc->id, $posted);

        if ($active) {
            Message::addConfirmation('Premium license validated successfully.');
        } else {
            Message::addError('Premium license key is invalid or inactive. Auto-update sync will not run until a valid key is entered.');
        }
    }

    public function processApiKeyForStorage($value, $dc): string
    {
        // Get the raw API key from POST data first (user input)
        $apiKey = $_POST['api_key'] ?? '';

        if (empty($apiKey) && $dc->activeRecord && $dc->activeRecord->api_key) {
            // If we have an existing key, use it
            $apiKey = $dc->activeRecord->api_key;
        }

        if (empty($apiKey)) {
            Message::addError('API key is required and cannot be empty.');

            return '';
        }

        if (!$this->encryption->isValidApiKeyFormat((string) $apiKey)) {
            Message::addError('Invalid API key format. OpenAI API keys must start with "sk-".');

            return '';
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                'https://api.openai.com/v1/models',
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'timeout' => 15,
                ],
            );

            if (200 !== $response->getStatusCode()) {
                Message::addError('API key validation failed. Please check your API key.');

                return '';
            }

            $this->logger->info(
                'API key validation successful for config save',
                [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                ],
            );

            return $this->encryption->encryptApiKey(trim((string) $apiKey));
        } catch (\Exception $e) {
            $this->logger->error(
                'API key validation failed during save: '.$e->getMessage(),
                [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                ],
            );

            Message::addError('Invalid API key. Please verify your OpenAI API key is correct and has proper permissions.');

            return '';
        }
    }

    public function processApiKeyForDisplay($value, $dc = null): string
    {
        if (empty($value)) {
            return '';
        }

        if ($dc && 'api_key' === $dc->field) {
            return str_repeat('*', \strlen((string) $value));
        }

        return trim((string) $value);
    }

    public function addIcon($row, $label): string
    {
        return $row['title'];
    }

    /**
     * Add a "Key prüfen" button next to the API key field.
     */
    public function apiKeyWizard(DataContainer $dc): string
    {
        $csrfToken = $this->csrfTokenManager->getToken($this->csrfTokenName)->getValue();

        $buttonId = 'apiKeyCheck_'.$dc->field;
        $resultId = 'apiKeyResult_'.$dc->field;
        $fieldName = $dc->field;
        $postUrl = Environment::get('base').'contao/api-key-validate';

        return \sprintf(
            ' <span class="api-key-check-wrapper">
            <button type="button" id="%1$s" class="tl_submit"
                data-api-key-field="%2$s"
                data-validation-url="%3$s"
                data-request-token="%4$s">Key prüfen</button>
            <span id="%5$s" class="api-key-result"></span>
        </span>
        <script>
        (function () {
            var button = document.getElementById(%6$s);
            if (!button || button.dataset.apiKeyInlineBound === "1") {
                return;
            }

            var fieldName = button.getAttribute("data-api-key-field") || "";
            var input = document.getElementById("ctrl_" + fieldName)
                || document.getElementById(fieldName)
                || document.querySelector(\'input[name="\' + fieldName + \'"]\');
            var resultSpan = document.getElementById(%7$s);
            var wrapper = button.closest(".api-key-check-wrapper");

            if (!input || !resultSpan || !wrapper) {
                return;
            }

            var widget = input.closest(".widget");
            if (widget) {
                var help = widget.querySelector("p.tl_help");
                if (help && help.parentNode === widget) {
                    widget.insertBefore(wrapper, help);
                } else if (input.parentNode) {
                    input.parentNode.insertBefore(wrapper, input.nextSibling);
                }
            }

            button.dataset.apiKeyInlineBound = "1";

            button.addEventListener("click", function () {
                var apiKey = input.value;
                if (!apiKey) {
                    alert("Bitte geben Sie zuerst einen API-Schlüssel ein.");
                    return;
                }

                var url = button.getAttribute("data-validation-url") || "";
                var requestToken = button.getAttribute("data-request-token") || "";

                button.disabled = true;
                button.innerHTML = \'<span class="processing-spinner"></span>Validiere...\';
                resultSpan.innerHTML = "";

                var xhr = new XMLHttpRequest();
                xhr.open("POST", url, true);
                xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

                xhr.onreadystatechange = function () {
                    if (xhr.readyState !== 4) {
                        return;
                    }

                    button.disabled = false;
                    button.textContent = "Key prüfen";

                    try {
                        var result = JSON.parse(xhr.responseText || "{}");
                        if (result.valid) {
                            resultSpan.innerHTML = \'<span style="color:green;">✓ API-Schlüssel ist gültig!</span>\';
                            input.style.backgroundColor = "lightgreen";
                            input.style.color = "#121212";
                            return;
                        }

                        resultSpan.innerHTML = \'<span style="color:red;">✗ API-Schlüssel ist ungültig! \' + (result.message || "") + \'</span>\';
                        input.style.backgroundColor = "lightcoral";
                        input.style.color = "#121212";
                    } catch (e) {
                        resultSpan.innerHTML = \'<span style="color:red;">✗ Fehler bei der Validierung</span>\';
                    }
                };

                xhr.send("action=validateApiKey&key=" + encodeURIComponent(apiKey) + "&REQUEST_TOKEN=" + encodeURIComponent(requestToken));
            });
        })();
        </script>',
            htmlspecialchars($buttonId, ENT_QUOTES),
            htmlspecialchars($fieldName, ENT_QUOTES),
            htmlspecialchars($postUrl, ENT_QUOTES),
            htmlspecialchars($csrfToken, ENT_QUOTES),
            htmlspecialchars($resultId, ENT_QUOTES),
            json_encode($buttonId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '""',
            json_encode($resultId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '""',
        );
    }

    /**
     * Create vector store when submitting the form.
     */
    public function createVectorStore($dc): void
    {
        $this->logger->info(
            'Vector store created for config ID '.$dc->id,
            [
                'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
            ],
        );
    }

    /**
     * Cascade-delete the vector store (plus associated files) when the config record
     * is deleted.
     *
     * Note: child prompt rows (tl_openai_prompts) are purely local
     * configuration and do not require any remote OpenAI call here. The former
     * logic that issued DELETE /v1/assistants/{id} for each prompt is
     * intentionally removed: the Assistants API is sunset on 2026-08-26 and any
     * orphaned assistants are cleaned up once by
     * Version20260416000001CleanupOrphanAssistants.
     */
    public function deleteVectorStore($dc): void
    {
        if (!$dc->activeRecord) {
            return;
        }

        $vectorStoreId = $dc->activeRecord->vector_store_id;
        if (!$vectorStoreId) {
            return;
        }

        try {
            $apiKey = $this->encryption->getApiKeyForConfig((int) $dc->id);
            if (!$apiKey) {
                $this->logger->warning(
                    'No valid API key found for config',
                    [
                        'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                        'config_id' => $dc->id,
                    ],
                );

                return;
            }

            // Delete all associated files from the OpenAI platform
            $files = $this->connection->fetchAllAssociative(
                'SELECT id, openai_file_id FROM tl_openai_files WHERE pid = ?',
                [$dc->id],
            );

            foreach ($files as $file) {
                if (!$file['openai_file_id']) {
                    continue;
                }

                try {
                    $response = $this->httpClient->request(
                        'DELETE',
                        "https://api.openai.com/v1/files/{$file['openai_file_id']}",
                        [
                            'headers' => [
                                'Authorization' => 'Bearer '.$apiKey,
                                'Content-Type' => 'application/json',
                            ],
                        ],
                    );

                    $status = $response->getStatusCode();

                    if (404 === $status) {
                        $this->logger->info(
                            'File already deleted from OpenAI platform',
                            [
                                'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                                'file_id' => $file['openai_file_id'],
                            ],
                        );

                        continue;
                    }

                    if ($status < 200 || $status >= 300) {
                        $this->logger->warning(
                            'Failed to delete file from OpenAI platform',
                            [
                                'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                                'file_id' => $file['openai_file_id'],
                                'status' => $status,
                            ],
                        );

                        continue;
                    }

                    $this->logger->info(
                        'File deleted from OpenAI platform',
                        [
                            'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                            'file_id' => $file['openai_file_id'],
                        ],
                    );
                } catch (\Exception $e) {
                    $this->logger->error(
                        'Error deleting file from OpenAI platform: '.$e->getMessage(),
                        [
                            'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                            'file_id' => $file['openai_file_id'],
                        ],
                    );
                }
            }

            // Finally, delete the vector store itself. Vector stores still require the
            // "assistants=v2" header; a future OpenAI GA of vector stores will let us drop
            // this header entirely.
            try {
                $response = $this->httpClient->request(
                    'DELETE',
                    "https://api.openai.com/v1/vector_stores/{$vectorStoreId}",
                    [
                        'headers' => [
                            'Authorization' => 'Bearer '.$apiKey,
                            'Content-Type' => 'application/json',
                            'OpenAI-Beta' => 'assistants=v2',
                        ],
                    ],
                );

                $status = $response->getStatusCode();
                if (404 === $status) {
                    $this->logger->info(
                        'Vector store already deleted from OpenAI platform',
                        [
                            'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                            'vector_store_id' => $vectorStoreId,
                        ],
                    );

                    return;
                }

                if ($status < 200 || $status >= 300) {
                    $this->logger->warning(
                        'Failed to delete vector store from OpenAI platform',
                        [
                            'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                            'vector_store_id' => $vectorStoreId,
                            'status' => $status,
                        ],
                    );

                    return;
                }

                $this->logger->info(
                    'Vector store deleted from OpenAI platform',
                    [
                        'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                        'vector_store_id' => $vectorStoreId,
                    ],
                );
            } catch (\Exception $e) {
                $this->logger->error(
                    'Error deleting vector store from OpenAI platform: '.$e->getMessage(),
                    [
                        'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                        'vector_store_id' => $vectorStoreId,
                    ],
                );
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Error in deleteVectorStore: '.$e->getMessage(),
                [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                ],
            );
        }
    }

    /**
     * Copy vector store when copying the config.
     */
    public function copyVectorStore($insertId, $dc): void
    {
        $this->logger->info(
            'Vector store copied for config ID '.$insertId,
            [
                'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
            ],
        );
    }

    /**
     * Validate API key.
     */
    public function validateApiKey($value, DataContainer $dc)
    {
        if (!$value) {
            return $value;
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                'https://api.openai.com/v1/models',
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$value,
                        'Content-Type' => 'application/json',
                    ],
                ],
            );

            if (200 !== $response->getStatusCode()) {
                throw new \Exception('Invalid API key');
            }

            return $value;
        } catch (\Exception $e) {
            throw new \Exception('Invalid API key: '.$e->getMessage());
        }
    }

    /**
     * Adds the header to the list view.
     */
    #[AsCallback(table: 'tl_openai_config', target: 'list.header')]
    public function addHeader(): string
    {
        return '<div class="tl_header">'.$GLOBALS['TL_LANG']['tl_openai_config']['header'].'</div>';
    }

    public function onLoadCallback($dc): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request && ('create' === $request->get('act') || '' === $request->get('act'))) {
            $this->checkSingleRecordLimitation($dc);
        }

        if ($dc && $dc->id && 'edit' === ($request?->get('act') ?? '')) {
            $this->migrateScheduleFieldsOnLoad($dc);
            $this->configureAutoUpdateFieldAccess((int) $dc->id);
            $this->injectAutoUpdateBackendScript((int) $dc->id, $this->licenseValidation->isLicenseActive((int) $dc->id));
        }
    }

    public function discardNonPersistedField($value, DataContainer $dc): string
    {
        return '';
    }

    public function premiumLicenseIntroField(DataContainer $dc, string $xlabel = ''): string
    {
        $lang = $this->loadConfigLang();
        $licenseUrl = 'https://licenses.juhe-it-solutions.at';

        $content = \sprintf(
            '<strong style="display: block; font-size: 22px; position: relative; top: -5px;">%s</strong>'
            .'%s<br>'
            .'<span style="color: #f59e0b; line-height: 2">%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></span>'
            .'<div style="background: var(--info-bg); border-left: 4px solid #2196f3; padding: 10px; margin-top: 8px;">'
            .'<strong>ℹ️ %s:</strong> %s'
            .'</div>',
            (string) ($lang['premium_license_info_heading'] ?? 'Premium: automatic vector store sync'),
            (string) ($lang['premium_license_info_text'] ?? ''),
            (string) ($lang['premium_license_info_purchase'] ?? 'Get a license at'),
            htmlspecialchars($licenseUrl, ENT_QUOTES),
            htmlspecialchars($licenseUrl, ENT_QUOTES),
            (string) ($lang['premium_license_info_hint_heading'] ?? 'Note'),
            (string) ($lang['premium_license_info_hint'] ?? 'Enter your license key below and validate it with "Check key" before saving.'),
        );

        return \sprintf(
            '<div class="widget clr premium-license-intro">'
            .'<div class="tl_message"><p class="tl_info">%s</p></div>'
            .'</div>',
            $content,
        );
    }

    public function licenseKeyWizard(DataContainer $dc): string
    {
        $lang = $this->loadConfigLang();
        $csrfToken = $this->csrfTokenManager->getToken($this->csrfTokenName)->getValue();
        $buttonId = 'licenseKeyCheck_'.$dc->field;
        $resultId = 'licenseKeyResult_'.$dc->field;
        $fieldName = $dc->field;
        $postUrl = Environment::get('base').'contao/license-key-validate';
        $configId = (int) ($dc->id ?? 0);
        $checkLabel = (string) ($lang['check_license_key'] ?? 'Check key');
        $validatingLabel = (string) ($lang['license_key_validating'] ?? 'Validating...');
        $noKeyLabel = (string) ($lang['no_license_key'] ?? 'Please enter a license key first.');
        $validLabel = (string) ($lang['license_key_valid'] ?? 'License key is valid!');
        $invalidLabel = (string) ($lang['license_key_invalid'] ?? 'License key is invalid!');
        $errorLabel = (string) ($lang['license_key_error'] ?? 'Validation failed.');

        return \sprintf(
            ' <span class="license-key-check-wrapper api-key-check-wrapper">
            <button type="button" id="%1$s" class="tl_submit license-key-check-button"
                data-license-key-field="%2$s"
                data-validation-url="%3$s"
                data-request-token="%4$s"
                data-config-id="%5$s"
                data-check-label="%6$s"
                data-validating-label="%7$s"
                data-no-key-label="%8$s"
                data-valid-label="%9$s"
                data-invalid-label="%10$s"
                data-error-label="%11$s">%6$s</button>
            <span id="%12$s" class="license-key-result api-key-result"></span>
        </span>',
            htmlspecialchars($buttonId, ENT_QUOTES),
            htmlspecialchars($fieldName, ENT_QUOTES),
            htmlspecialchars($postUrl, ENT_QUOTES),
            htmlspecialchars($csrfToken, ENT_QUOTES),
            $configId,
            htmlspecialchars($checkLabel, ENT_QUOTES),
            htmlspecialchars($validatingLabel, ENT_QUOTES),
            htmlspecialchars($noKeyLabel, ENT_QUOTES),
            htmlspecialchars($validLabel, ENT_QUOTES),
            htmlspecialchars($invalidLabel, ENT_QUOTES),
            htmlspecialchars($errorLabel, ENT_QUOTES),
            htmlspecialchars($resultId, ENT_QUOTES),
        );
    }

    /**
     * @return array<string, string>
     */
    public function getAutoUpdateModelOptions(DataContainer|null $dc = null): array
    {
        $options = [
            '' => $this->getConfigLangString('auto_update_model_placeholder', '-- Select model --'),
        ];

        if (!$dc || !$dc->id) {
            return $options;
        }

        $apiKey = $this->encryption->getApiKeyForConfig((int) $dc->id);
        if (!$apiKey) {
            return $options;
        }

        foreach ($this->modelCatalog->fetchModelOptions($apiKey) as $modelId => $label) {
            $options[$modelId] = $label;
        }

        return $options;
    }

    public function validateAutoUpdateModel($value, DataContainer $dc): string
    {
        $model = trim((string) $value);
        if ('' === $model) {
            return 'gpt-4o-mini';
        }

        if (!$dc->id) {
            return $model;
        }

        if (!$this->licenseValidation->isLicenseActive((int) $dc->id)) {
            return 'gpt-4o-mini';
        }

        $apiKey = $this->encryption->getApiKeyForConfig((int) $dc->id);
        if (!$apiKey) {
            throw new \InvalidArgumentException($this->getConfigLangString('auto_update_model_no_api_key', 'Please configure a valid OpenAI API key first.'));
        }

        if (!$this->modelCatalog->isModelCompatibleWithResponsesApi($model, $apiKey)) {
            throw new \InvalidArgumentException(\sprintf($this->getConfigLangString('auto_update_model_invalid', 'The model "%s" is not compatible with the OpenAI Responses API.'), $model));
        }

        return $model;
    }

    public function loadAutoUpdatePromptTemplate($value, DataContainer|null $dc = null): string
    {
        $value = trim((string) $value);

        return '' !== $value ? $value : VectorStoreDocumentPrompt::DEFAULT_TEMPLATE;
    }

    /**
     * @return array<int, int>
     */
    public function loadAutoUpdateSiteRoots($value, DataContainer|null $dc = null): array
    {
        return VectorStoreAutoUpdateService::parseConfiguredPageIds($value);
    }

    public function saveAutoUpdatePromptTemplate($value, DataContainer $dc): string|null
    {
        if ($dc->id && !$this->licenseValidation->isLicenseActive((int) $dc->id)) {
            $existing = $dc->activeRecord->auto_update_prompt_template ?? null;

            return null === $existing || '' === $existing ? null : (string) $existing;
        }

        $value = trim((string) $value);
        if (mb_strlen($value) > 32000) {
            throw new \InvalidArgumentException($this->getConfigLangString('auto_update_prompt_too_long', 'The custom prompt must not exceed 32,000 characters.'));
        }

        if ($value === trim(VectorStoreDocumentPrompt::DEFAULT_TEMPLATE)) {
            return null;
        }

        return '' === $value ? null : $value;
    }

    public function saveAutoUpdateMaxContent($value, DataContainer $dc): int
    {
        if ($dc->id && !$this->licenseValidation->isLicenseActive((int) $dc->id)) {
            return (int) ($dc->activeRecord->auto_update_max_content ?? 100000);
        }

        return max(1000, min(500000, (int) $value));
    }

    public function guardAutoUpdateFieldWithoutLicense($value, DataContainer $dc)
    {
        if (!$dc->id || !$dc->field || $this->licenseValidation->isLicenseActive((int) $dc->id)) {
            return $value;
        }

        return $dc->activeRecord->{$dc->field} ?? $value;
    }

    public function loadAutoUpdateEnabled($value, DataContainer|null $dc = null): string
    {
        if ($dc?->id && !$this->licenseValidation->isLicenseActive((int) $dc->id)) {
            return '';
        }

        return $value ? '1' : '';
    }

    public function saveAutoUpdateEnabled($value, DataContainer $dc): string
    {
        if (!$value || !$dc->id) {
            return (string) $value;
        }

        if (!$this->licenseValidation->isLicenseActive((int) $dc->id)) {
            Message::addError($this->getConfigLangString(
                'auto_update_requires_license',
                'Automatic sync requires a valid premium license. Use “Check key” in the Premium License section.',
            ));

            return '';
        }

        return (string) $value;
    }

    public function compileAutoUpdateSchedule(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        if (!$this->licenseValidation->isLicenseActive((int) $dc->id)) {
            return;
        }

        $minute = $this->normalizeScheduleMinute($_POST['auto_update_schedule_minute'] ?? '0');
        $hour = $this->normalizeScheduleHour($_POST['auto_update_schedule_hour'] ?? '2');
        $day = (string) ($_POST['auto_update_schedule_day'] ?? '*');
        $weekday = (string) ($_POST['auto_update_schedule_weekday'] ?? '*');

        if (!preg_match('/^(\*|[1-9]|[12][0-9]|3[01])$/', $day)) {
            $day = '*';
        }

        if (!preg_match('/^(\*|[0-6])$/', $weekday)) {
            $weekday = '*';
        }

        $cron = \sprintf('%s %s %s * %s', $minute, $hour, $day, $weekday);

        $this->connection->executeStatement(
            'UPDATE tl_openai_config SET auto_update_schedule = ? WHERE id = ?',
            [$cron, (int) $dc->id],
        );
    }

    /**
     * @deprecated Since 2.0, use EncryptionService::decryptApiKey()
     */
    public function decryptApiKey(string $encryptedData): string|null
    {
        return $this->encryption->decryptApiKey($encryptedData);
    }

    private function migrateScheduleFieldsOnLoad(DataContainer $dc): void
    {
        if (!$dc->activeRecord) {
            return;
        }

        $schedule = trim((string) ($dc->activeRecord->auto_update_schedule ?? ''));
        if ('' === $schedule) {
            return;
        }

        $parts = preg_split('/\s+/', $schedule) ?: [];
        if (5 !== \count($parts)) {
            return;
        }

        $minute = (string) $parts[0];
        $hour = (string) $parts[1];
        $day = (string) $parts[2];
        $weekday = (string) $parts[4];

        if (1 === preg_match('/^\*\/(\d+)$/', $minute)) {
            return;
        }

        if (!\in_array($minute, ['*'], true) && !ctype_digit($minute)) {
            return;
        }

        if (!\in_array($hour, ['*'], true) && !ctype_digit($hour)) {
            return;
        }

        $storedMinute = (string) ($dc->activeRecord->auto_update_schedule_minute ?? '0');
        $storedHour = (string) ($dc->activeRecord->auto_update_schedule_hour ?? '2');
        $storedDay = (string) ($dc->activeRecord->auto_update_schedule_day ?? '*');
        $storedWeekday = (string) ($dc->activeRecord->auto_update_schedule_weekday ?? '*');

        if ($storedMinute === $minute && $storedHour === $hour && $storedDay === $day && $storedWeekday === $weekday) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE tl_openai_config SET auto_update_schedule_minute = ?, auto_update_schedule_hour = ?, auto_update_schedule_day = ?, auto_update_schedule_weekday = ? WHERE id = ?',
            [$minute, $hour, $day, $weekday, (int) $dc->id],
        );
    }

    private function normalizeScheduleMinute(mixed $value): string
    {
        if ('*' === (string) $value) {
            return '*';
        }

        return (string) max(0, min(59, (int) $value));
    }

    private function normalizeScheduleHour(mixed $value): string
    {
        if ('*' === (string) $value) {
            return '*';
        }

        return (string) max(0, min(23, (int) $value));
    }

    private function configureAutoUpdateFieldAccess(int $configId): void
    {
        $licenseActive = $this->licenseValidation->isLicenseActive($configId);

        foreach (self::AUTO_UPDATE_LICENSE_FIELDS as $field) {
            if (!isset($GLOBALS['TL_DCA']['tl_openai_config']['fields'][$field])) {
                continue;
            }

            if (!$licenseActive) {
                $GLOBALS['TL_DCA']['tl_openai_config']['fields'][$field]['eval']['disabled'] = true;
            } else {
                unset($GLOBALS['TL_DCA']['tl_openai_config']['fields'][$field]['eval']['disabled']);
            }
        }
    }

    private function injectAutoUpdateBackendScript(int $configId, bool $licenseActive): void
    {
        $lang = $this->loadConfigLang();
        $labels = json_encode(
            [
                'noKey' => $lang['no_license_key'] ?? 'Please enter a license key first.',
                'valid' => $lang['license_key_valid'] ?? 'License key is valid!',
                'invalid' => $lang['license_key_invalid'] ?? 'License key is invalid!',
                'error' => $lang['license_key_error'] ?? 'Validation failed.',
                'check' => $lang['check_license_key'] ?? 'Check key',
                'validating' => $lang['license_key_validating'] ?? 'Validating...',
            ],
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP,
        );

        $GLOBALS['TL_BODY'][] = \sprintf(
            '<script>window.contaoOpenAiAutoUpdate = { configId: %d, licenseActive: %s, labels: %s };</script>',
            $configId,
            $licenseActive ? 'true' : 'false',
            $labels,
        );
    }

    private function loadConfigLang(): array
    {
        $language = $GLOBALS['TL_LANGUAGE'] ?? 'en';
        System::loadLanguageFile('tl_openai_config', $language);

        return $GLOBALS['TL_LANG']['tl_openai_config'] ?? [];
    }

    private function getConfigLangString(string $key, string $fallback): string
    {
        $lang = $this->loadConfigLang();

        return $lang[$key] ?? $fallback;
    }

    /**
     * Checks if there's already a config record and prevents creation of additional ones.
     */
    private function checkSingleRecordLimitation($dc): void
    {
        $existingConfig = $this->connection->fetchAssociative(
            'SELECT id, title FROM tl_openai_config LIMIT 1',
        );

        if ($existingConfig) {
            Message::addInfo($this->getTranslatedString('single_config_redirect', 'Only one OpenAI configuration is allowed. You are being redirected to the existing configuration.'));
            $url = Controller::addToUrl('act=edit&id='.$existingConfig['id']);
            Controller::redirect($url);
        }
    }

    /**
     * Get translated string with fallback.
     */
    private function getTranslatedString(string $key, string $fallback): string
    {
        $language = $GLOBALS['TL_LANGUAGE'] ?? 'en';
        System::loadLanguageFile('tl_openai_config', $language);

        $lang = $GLOBALS['TL_LANG']['tl_openai_config'] ?? [];

        return $lang[$key] ?? $fallback;
    }
}
