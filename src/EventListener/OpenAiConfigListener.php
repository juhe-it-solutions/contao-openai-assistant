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
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAiConfigListener
{
    /**
     * Rendered in place of a stored license key; posted back verbatim when the admin
     * leaves it untouched.
     */
    private const LICENSE_KEY_MASK = '••••••••••••••••';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly string $csrfTokenName,
        private readonly RequestStack $requestStack,
        private readonly Connection $connection,
        private readonly EncryptionService $encryption,
        private readonly LicenseValidationService $licenseValidation,
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
        $posted = trim((string) $value);
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

    /**
     * options_callback for auto_update_site_root (blank option = auto-detect single root).
     *
     * @return array<int, string>
     */
    public function getSiteRootOptions($dc = null): array
    {
        $roots = $this->connection->fetchAllAssociative(
            "SELECT id, title, dns FROM tl_page WHERE type = 'root' AND dns != '' ORDER BY title",
        );

        $options = [];

        foreach ($roots as $root) {
            $options[(int) $root['id']] = $root['title'].' ('.$root['dns'].')';
        }

        return $options;
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
    }

    /**
     * @deprecated Since 2.0, use EncryptionService::decryptApiKey()
     */
    public function decryptApiKey(string $encryptedData): string|null
    {
        return $this->encryption->decryptApiKey($encryptedData);
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
