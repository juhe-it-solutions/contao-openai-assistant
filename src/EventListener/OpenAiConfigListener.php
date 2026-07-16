<?php

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\EventListener;

use Contao\Controller;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\DataContainer;
use Contao\Message;
use Contao\System;
use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\CronHealthService;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\LicensePortalUrlService;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\LicenseValidationService;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreAutoUpdateService;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreDocumentPrompt;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreFileSync;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\OpenAiModelCatalogService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAiConfigListener
{
    /**
     * Rendered in place of a stored license key; posted back verbatim when the admin
     * leaves it untouched.
     */
    public const LICENSE_KEY_MASK = '••••••••••••••••';

    private const AUTO_UPDATE_LICENSE_FIELDS = [
        'auto_update_enabled',
        'auto_update_schedule_hour',
        'auto_update_schedule_minute',
        'auto_update_schedule_weekday',
        'auto_update_schedule_day',
        'auto_update_trigger',
        'auto_update_mode',
        'auto_update_model',
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
        private readonly LicensePortalUrlService $licensePortalUrls,
        private readonly LicenseValidationService $licenseValidation,
        private readonly OpenAiModelCatalogService $modelCatalog,
        private readonly VectorStoreAutoUpdateService $autoUpdateService,
        private readonly VectorStoreFileSync $fileSync,
        private readonly RouterInterface $router,
        private readonly CronHealthService $cronHealth,
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
            // Release this install's seat while the old key is still readable from the
            // DB (save callbacks run before the row is written). Best-effort — a failed
            // call never blocks removing the key locally.
            if ('' !== $existing) {
                $this->licenseValidation->deactivate((int) $dc->id);
            }

            // Deliberately NOT purging the synced vector-store files here: they live in the
            // customer's own OpenAI account and also back the base chatbot, so removing the
            // premium key stops FUTURE auto-syncs but leaves the already-indexed content in
            // place (it simply stops being refreshed). Full purge happens only on config
            // deletion (deleteVectorStore). The admin note in premiumLicenseIntroField spells
            // this out for the user.
            return '';
        }

        // New key entered — validate format before encrypting.
        if (!$this->encryption->isValidLicenseKeyFormat($posted)) {
            Message::addError($this->getConfigLangString(
                'premium_license_format_invalid',
                'Invalid license key format. Expected "JUHE-AI-…". The previous key was kept.',
            ));

            return $existing; // do not overwrite a good key with a malformed one
        }

        // Switching to a different key: release the seat held under the old key first,
        // otherwise it stays claimed on the licensing server. Re-entering the same key
        // is harmless — the next validation simply re-records the seat.
        if ('' !== $existing) {
            $this->licenseValidation->deactivate((int) $dc->id);
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

        // The OpenAI API key uses Contao's Password widget, which always emits a
        // "password has been changed" confirmation on save. That message is
        // misleading here (it is an API key, not a password), so strip it.
        $this->removePasswordChangedMessage();

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
                    'UPDATE tl_openai_config SET premium_license_status = ?, premium_license_valid_until = 0, premium_license_checked_at = 0, premium_license_last_success = 0, premium_license_plan = ?, premium_license_max_pages = 0, premium_license_cancel_at_period_end = 0, auto_update_enabled = 0 WHERE id = ?',
                    ['', '', (int) $dc->id],
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
            Message::addConfirmation($this->getConfigLangString(
                'premium_license_validated',
                'Premium license validated successfully.',
            ));
        } else {
            Message::addError($this->getConfigLangString(
                'premium_license_invalid',
                'Premium license key is invalid or inactive. Auto-update sync will not run until a valid key is entered.',
            ));
        }
    }

    public function processApiKeyForStorage($value, $dc): string
    {
        // Already-stored ciphertext. Every failure/skip path returns THIS rather than
        // an empty string, so a transient outage (or an untouched Password field) can
        // never destroy a working key — the same guarantee processLicenseKeyForStorage
        // gives. Returning '' is reserved for "there was no key to begin with".
        $existing = (string) ($dc->activeRecord->api_key ?? '');

        // Raw key from POST (user input). Contao's Password widget submits empty when the
        // admin leaves the field untouched; that means "keep the stored key", not "clear it".
        $apiKey = trim((string) ($_POST['api_key'] ?? ''));

        if ('' === $apiKey) {
            if ('' !== $existing) {
                // Field left blank on an existing config — keep the stored key silently.
                return $existing;
            }

            Message::addError($this->getConfigLangString(
                'api_key_required',
                'API key is required and cannot be empty.',
            ));

            return '';
        }

        if (!$this->encryption->isValidApiKeyFormat($apiKey)) {
            Message::addError($this->getConfigLangString(
                'api_key_format_invalid',
                'Invalid API key format. OpenAI API keys must start with "sk-".',
            ));

            // Never overwrite a good stored key with a malformed entry.
            return $existing;
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
                Message::addError($this->getConfigLangString(
                    'api_key_check_failed',
                    'API key validation failed. Please check your API key.',
                ));

                // Rejected key: keep the previously working one instead of wiping it.
                return $existing;
            }

            $this->logger->info(
                'API key validation successful for config save',
                [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                ],
            );

            return $this->encryption->encryptApiKey($apiKey);
        } catch (\Exception $e) {
            $this->logger->error(
                'API key validation failed during save: '.$e->getMessage(),
                [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                ],
            );

            // Transport/network failure — the key may be perfectly valid, OpenAI was just
            // unreachable. Preserve an existing key and tell the admin validation was skipped;
            // only fail hard when there was nothing stored yet.
            if ('' !== $existing) {
                Message::addError($this->getConfigLangString(
                    'api_key_check_unreachable',
                    'Could not reach OpenAI to validate the API key. The previously saved key was kept.',
                ));

                return $existing;
            }

            Message::addError($this->getConfigLangString(
                'api_key_invalid_save',
                'Invalid API key. Please verify your OpenAI API key is correct and has proper permissions.',
            ));

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
     * Add a "Check key" button next to the API key field. All labels come from the
     * language files so the button follows the backend locale.
     */
    public function apiKeyWizard(DataContainer $dc): string
    {
        $lang = $this->loadConfigLang();
        $csrfToken = $this->csrfTokenManager->getToken($this->csrfTokenName)->getValue();

        $buttonId = 'apiKeyCheck_'.$dc->field;
        $resultId = 'apiKeyResult_'.$dc->field;
        $fieldName = $dc->field;
        // Generated from the route so a customised backend route prefix is honoured.
        $postUrl = $this->router->generate('contao_api_key_validate');

        $checkLabel = (string) ($lang['check_api_key'] ?? 'Check key');

        return \sprintf(
            ' <span class="api-key-check-wrapper">
            <button type="button" id="%1$s" class="tl_submit api-key-check-button"
                data-api-key-field="%2$s"
                data-validation-url="%3$s"
                data-request-token="%4$s"
                data-check-label="%6$s"
                data-validating-label="%7$s"
                data-no-key-label="%8$s"
                data-valid-label="%9$s"
                data-invalid-label="%10$s"
                data-error-label="%11$s">%6$s</button>
            <span id="%5$s" class="api-key-result"></span>
        </span>',
            htmlspecialchars($buttonId, ENT_QUOTES),
            htmlspecialchars($fieldName, ENT_QUOTES),
            htmlspecialchars($postUrl, ENT_QUOTES),
            htmlspecialchars($csrfToken, ENT_QUOTES),
            htmlspecialchars($resultId, ENT_QUOTES),
            htmlspecialchars($checkLabel, ENT_QUOTES),
            htmlspecialchars((string) ($lang['api_key_validating'] ?? 'Validating...'), ENT_QUOTES),
            htmlspecialchars((string) ($lang['no_api_key'] ?? 'Please enter an API key first.'), ENT_QUOTES),
            htmlspecialchars((string) ($lang['api_key_valid'] ?? 'API key is valid!'), ENT_QUOTES),
            htmlspecialchars((string) ($lang['api_key_invalid'] ?? 'API key is invalid!'), ENT_QUOTES),
            htmlspecialchars((string) ($lang['api_key_error'] ?? 'Validation failed.'), ENT_QUOTES),
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
     *
     * tl_openai_vector_file and tl_openai_sync_log are not registered as child
     * tables (they have no ptable, so Contao core's ctable cascade does not reach
     * them) - they are deleted explicitly here to avoid leaving permanent orphan
     * rows, including the MEDIUMTEXT sync log document, behind for a config that
     * no longer exists.
     */
    public function deleteVectorStore($dc): void
    {
        if (!$dc->activeRecord) {
            return;
        }

        $configId = (int) $dc->id;

        $this->connection->executeStatement('DELETE FROM tl_openai_sync_log WHERE pid = ?', [$configId]);

        $this->licenseValidation->deactivate($configId);

        $vectorStoreId = $dc->activeRecord->vector_store_id;

        try {
            if (!$vectorStoreId) {
                return;
            }

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

            try {
                $this->fileSync->purge($apiKey, (string) $vectorStoreId, $configId);
            } catch (\Exception $e) {
                $this->logger->error(
                    'Error deleting auto-sync files from OpenAI platform: '.$e->getMessage(),
                    [
                        'contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR),
                        'config_id' => $configId,
                        'vector_store_id' => $vectorStoreId,
                    ],
                );
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
        } finally {
            $this->connection->executeStatement('DELETE FROM tl_openai_vector_file WHERE pid = ?', [$configId]);
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
        $this->configureSingleRecordCreation();

        $request = $this->requestStack->getCurrentRequest();
        if ($request && ('create' === $request->get('act') || '' === $request->get('act'))) {
            $this->checkSingleRecordLimitation($dc);
        }

        if ($dc && $dc->id && 'edit' === ($request?->get('act') ?? '')) {
            $this->migrateScheduleFieldsOnLoad($dc);
            // Render path: use the cache-only check so building the form never blocks
            // on a licensing HTTP call. Save paths keep the authoritative check.
            $licenseActive = $this->licenseValidation->isLicenseActiveCached((int) $dc->id);
            $this->configureAutoUpdateFieldAccess($licenseActive);
            $this->configureAutoUpdateModelVisibility((int) $dc->id);
            $this->addNoFilesNoticeIfNeeded((int) $dc->id);
        }
    }

    /**
     * Keep the initial list-view "new" action available for clean installs, but
     * never offer form buttons that would continue into a second config record.
     *
     * @param array<string, string> $buttons
     *
     * @return array<string, string>
     */
    public function removeSingleRecordCreateButtons(array $buttons): array
    {
        unset($buttons['saveNcreate'], $buttons['saveNduplicate']);

        return $buttons;
    }

    public function premiumLicenseIntroField(DataContainer $dc, string $xlabel = ''): string
    {
        $lang = $this->loadConfigLang();
        $licenseUrl = $this->licensePortalUrls->getProductUrl();
        $helpUrl = $this->licensePortalUrls->getHelpUrl();
        $manageUrl = $this->licensePortalUrls->getManageUrl();
        $logoUrl = '/bundles/contaoopenaiassistant/images/logo_juhe-licenses.svg';

        $licenseActive = $dc->id && $this->licenseValidation->isLicenseActiveCached((int) $dc->id);
        $stateMarkup = $this->renderAutoUpdateBackendState((int) ($dc->id ?? 0), $licenseActive);

        if ($licenseActive) {
            // Active subscriber: replace the sales card with a neutral "about" note that
            // links to manage/help without any purchase CTAs.
            $content = \sprintf(
                '<span style="display: flex; gap: 16px; align-items: center;">'
                .'<a href="%s" target="_blank" rel="noopener noreferrer" style="flex-shrink: 0;">'
                .'<img src="%s" alt="JUHE Licenses" width="90" height="90" style="display: block; width: 90px; height: 90px;"></a>'
                .'<span>'
                .'<strong class="oaa-info-card-heading" style="display: block; font-size: 22px;">%s</strong>'
                .'<span class="openai-license-actions" style="margin-top: 10px;">'
                .'<a class="openai-license-help-link" href="%s" target="_blank" rel="noopener noreferrer">%s</a>'
                .'<a class="openai-license-help-link" href="%s" target="_blank" rel="noopener noreferrer">%s</a>'
                .'</span>'
                .'</span>'
                .'</span>',
                htmlspecialchars($manageUrl, ENT_QUOTES),
                htmlspecialchars($logoUrl, ENT_QUOTES),
                (string) ($lang['premium_license_info_heading'] ?? 'Premium: automatic vector store sync'),
                htmlspecialchars($manageUrl, ENT_QUOTES),
                (string) ($lang['premium_license_info_manage'] ?? 'Manage subscription'),
                htmlspecialchars($helpUrl, ENT_QUOTES),
                (string) ($lang['premium_license_info_docs'] ?? 'Guide & help'),
            );
            $hintText = $this->breakSentences((string) ($lang['premium_license_info_hint_active'] ?? 'To switch to a different key: clear this field, enter the new key, and save. To remove the license entirely: use the "Remove license" button next to the key field.'));
        } else {
            $content = \sprintf(
                '<strong class="oaa-info-card-heading" style="display: block; font-size: 22px; position: relative; top: -5px;">%s</strong>'
                .'<span style="display: flex; gap: 16px; align-items: center; margin-top: 10px;">'
                .'<a href="%s" target="_blank" rel="noopener noreferrer" style="flex-shrink: 0;">'
                .'<img src="%s" alt="JUHE Licenses" width="90" height="90" style="display: block; width: 90px; height: 90px;"></a>'
                .'<span>%s<br>'
                .'<span style="color: #f59e0b; line-height: 2">%s <a href="%s" target="_blank" rel="noopener noreferrer" class="oaa-license-url-link">%s</a></span>'
                .'<br><span class="openai-license-actions">'
                .'<a class="openai-license-help-link" href="%s" target="_blank" rel="noopener noreferrer">%s</a>'
                .'<a class="openai-license-help-link" href="%s" target="_blank" rel="noopener noreferrer">%s</a>'
                .'</span>'
                .'</span></span>',
                (string) ($lang['premium_license_info_heading'] ?? 'Premium: automatic vector store sync'),
                htmlspecialchars($licenseUrl, ENT_QUOTES),
                htmlspecialchars($logoUrl, ENT_QUOTES),
                (string) ($lang['premium_license_info_text'] ?? ''),
                (string) ($lang['premium_license_info_purchase'] ?? 'Get a license at'),
                htmlspecialchars($licenseUrl, ENT_QUOTES),
                htmlspecialchars($licenseUrl, ENT_QUOTES),
                htmlspecialchars($manageUrl, ENT_QUOTES),
                (string) ($lang['premium_license_info_manage'] ?? 'Manage subscription'),
                htmlspecialchars($helpUrl, ENT_QUOTES),
                (string) ($lang['premium_license_info_docs'] ?? 'Guide & help'),
            );
            $hintText = $this->breakSentences((string) ($lang['premium_license_info_hint'] ?? 'Enter your license key below and validate it with "Check key" before saving.'));
        }

        // The hint box must be a sibling of the <p class="tl_info">, not a child:
        // a <div> inside <p> is invalid HTML and browsers auto-close the paragraph,
        // leaving a stray empty <p></p> in the DOM.
        $hintMarkup = \sprintf(
            '<div style="background: var(--info-bg); border-left: 4px solid #2196f3; padding: 10px; margin-top: 8px; margin-left: 11px; line-height: 1.3;">'
            .'<strong>ℹ️ %s:</strong><br>%s'
            .'</div>',
            (string) ($lang['premium_license_info_hint_heading'] ?? 'Notes'),
            $hintText,
        );

        return $stateMarkup.\sprintf(
            '<div class="widget clr premium-license-intro">'
            .'<style>'
            .'.premium-license-intro .openai-license-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:6px}'
            .'.premium-license-intro .openai-license-help-link,.premium-license-intro .openai-license-help-link:link,.premium-license-intro .openai-license-help-link:visited{display:inline-flex;align-items:center;justify-content:center;margin-top:6px;padding:4px 10px;border-radius:6px;font-size:13px;font-weight:600;line-height:1.25;text-decoration:none;border:1px solid #c5eb52;background:#d7ff64;color:#1a1a1a;cursor:pointer;transition:background-color .15s ease,border-color .15s ease,color .15s ease}'
            .'.premium-license-intro .openai-license-help-link:hover,.premium-license-intro .openai-license-help-link:focus-visible,.premium-license-intro .openai-license-help-link:active{background:#c5eb52;border-color:#b3d94a;color:#1a1a1a;outline:2px solid #4ea1ff;outline-offset:2px}'
            .'@media (max-width:576px){.premium-license-intro .openai-license-help-link{width:100%%}}'
            .'</style>'
            .'<div class="tl_message oaa-info-card oaa-info-card--premium">'
            .'<p class="tl_info" style="background: transparent url(system/themes/flexible/icons/show.svg) no-repeat 11px 12px;">%s</p>'
            .'%s'
            .'</div>'
            .'</div>',
            $content,
            $hintMarkup,
        );
    }

    public function licenseKeyWizard(DataContainer $dc): string
    {
        $lang = $this->loadConfigLang();
        $csrfToken = $this->csrfTokenManager->getToken($this->csrfTokenName)->getValue();
        $buttonId = 'licenseKeyCheck_'.$dc->field;
        $resultId = 'licenseKeyResult_'.$dc->field;
        $fieldName = $dc->field;
        // Generated from the route so a customised backend route prefix is honoured.
        $postUrl = $this->router->generate('contao_license_key_validate');
        $configId = (int) ($dc->id ?? 0);
        $checkLabel = (string) ($lang['check_license_key'] ?? 'Check key');
        $validatingLabel = (string) ($lang['license_key_validating'] ?? 'Validating...');
        $noKeyLabel = (string) ($lang['no_license_key'] ?? 'Please enter a license key first.');
        $validLabel = (string) ($lang['license_key_valid'] ?? 'License key is valid!');
        $invalidLabel = (string) ($lang['license_key_invalid'] ?? 'License key is invalid!');
        $errorLabel = (string) ($lang['license_key_error'] ?? 'Validation failed.');

        $hasStoredKey = '' !== ($dc->activeRecord->premium_license_key ?? '');

        $removeButton = '';
        if ($hasStoredKey) {
            $removeLabel = htmlspecialchars(
                (string) ($lang['remove_license_key'] ?? 'Remove license'),
                ENT_QUOTES,
            );
            $confirmLabel = htmlspecialchars(
                (string) ($lang['remove_license_key_confirm'] ?? 'License key cleared. Save to remove the license.'),
                ENT_QUOTES,
            );
            $removeButton = \sprintf(
                '<button type="button" class="tl_submit license-key-remove-button"
                    data-license-key-field="%1$s"
                    data-result-id="%2$s"
                    data-confirm-label="%3$s">%4$s</button>',
                htmlspecialchars($fieldName, ENT_QUOTES),
                htmlspecialchars($resultId, ENT_QUOTES),
                $confirmLabel,
                $removeLabel,
            );
        }

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
            %13$s
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
            $removeButton,
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

        // Only fetch live models when the license is active AND auto-update is enabled.
        // This avoids unnecessary API calls and prevents "Unknown option" display when
        // the feature is not yet configured. Cache-only check: this runs while the
        // form is rendered and must not block on a licensing HTTP call.
        if (!$this->licenseValidation->isLicenseActiveCached((int) $dc->id)) {
            return $options;
        }

        if (!$dc->activeRecord || !(bool) ($dc->activeRecord->auto_update_enabled ?? false)) {
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
            // This field is only in the palette in "llm_polish" mode (see
            // configureAutoUpdateModelVisibility()), so an empty submission means the
            // placeholder was left selected. Reject it whenever the form actually
            // offered models to pick (same gates as getAutoUpdateModelOptions());
            // otherwise keep the legacy empty value, which the runtime resolves to
            // gpt-4o-mini (see VectorStoreAutoUpdateService).
            if ($this->couldSelectAutoUpdateModel($dc)) {
                throw new \InvalidArgumentException($this->getConfigLangString('auto_update_model_required', 'Please select a generation model for the AI processing mode.'));
            }

            return '';
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

    public function guardAutoUpdateFieldWithoutLicense($value, DataContainer $dc)
    {
        if (!$dc->id || !$dc->field) {
            return $value;
        }

        // A new valid-format key is being submitted in this same save request. The
        // key hasn't been committed to the DB yet when save_callbacks run, so
        // isLicenseActive() would incorrectly return false even for a valid key.
        // Let the value through; validatePremiumLicenseOnSave (onsubmit) will
        // enforce validity after the DB write.
        if ($this->isNewValidKeyPosted()) {
            return $value;
        }

        // The user is actively removing the license key. Let the 0/'' value through
        // so the disabled checkbox is not preserved at 1. The onsubmit callback
        // (validatePremiumLicenseOnSave) will also zero auto_update_enabled in DB.
        if ($this->isKeyBeingRemoved()) {
            return $value;
        }

        if ($this->licenseValidation->isLicenseActive((int) $dc->id)) {
            return $value;
        }

        return $dc->activeRecord->{$dc->field} ?? $value;
    }

    /**
     * save_callback for auto_update_site_root: block selections whose crawl scope
     * (selected pages + all subpages) exceeds the subscription's page limit.
     * Chained after guardAutoUpdateFieldWithoutLicense. Throwing keeps the previous
     * value and surfaces an inline field error (Contao standard behaviour).
     */
    public function enforceCrawlPageLimit($value, DataContainer $dc)
    {
        if (!$dc->id || !$this->licenseValidation->isLicenseActive((int) $dc->id)) {
            return $value;
        }

        $plan = (string) ($dc->activeRecord->premium_license_plan ?? '');
        $maxPages = (int) ($dc->activeRecord->premium_license_max_pages ?? 0);
        $checkedAt = (int) ($dc->activeRecord->premium_license_checked_at ?? 0);

        // Refresh plan data when: (a) never fetched, or (b) older than 1 hour — so a
        // plan upgrade is usable immediately without waiting 7 days for the cache to expire.
        $planIsStale = 0 === $checkedAt || time() - $checkedAt > 3600;
        if ('' === $plan || $planIsStale) {
            [$plan, $maxPages] = $this->refreshLicensePlan((int) $dc->id);
        }

        $limit = $this->resolvePageLimit($plan, $maxPages);

        if (null === $limit) {
            return $value; // unlimited (enterprise) or plan still unknown
        }

        $selectedIds = VectorStoreAutoUpdateService::parseConfiguredPageIds($value);
        $count = $this->autoUpdateService->countScopePages($value);

        if ($count > $limit) {
            if ([] === $selectedIds) {
                // Empty selection with a single root: countScopePages counted the full subtree,
                // which IS what would be synced. Explain the implicit all-pages behaviour.
                throw new \InvalidArgumentException(\sprintf($this->getConfigLangString('auto_update_pages_none_selected_limit', 'No pages selected — all %1$s subpages of the single site root would be automatically synced. Your current plan allows at most %2$s pages. Please select specific pages or upgrade your plan.'), $count, $limit));
            }

            throw new \InvalidArgumentException(\sprintf($this->getConfigLangString('auto_update_pages_over_limit', 'Your selection covers %1$s pages, but your current plan allows at most %2$s. Reduce the selection or upgrade your plan; the previous selection was kept.'), $count, $limit));
        }

        return $value;
    }

    public function loadAutoUpdateEnabled($value, DataContainer|null $dc = null): string
    {
        if ($dc?->id && !$this->licenseValidation->isLicenseActiveCached((int) $dc->id)) {
            return '';
        }

        return $value ? '1' : '';
    }

    public function saveAutoUpdateEnabled($value, DataContainer $dc): string
    {
        if (!$value || !$dc->id) {
            return (string) $value;
        }

        // A new valid-format key is being submitted in this same save request.
        // save_callbacks run before the DB write, so the new key is not yet
        // persisted and isLicenseActive() would wrongly return false. Allow the
        // checkbox through; if the key turns out to be inactive, validatePremiumLicenseOnSave
        // (onsubmit) will report the error and sync will be blocked at runtime.
        if ($this->isNewValidKeyPosted()) {
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

        // In manual-only mode the schedule fields are hidden (not submitted). Don't rebuild
        // the cron expression from missing POST data - that would clobber a previously saved
        // schedule with "* * * * *" (every minute) and make it run constantly if the user
        // later switches back to scheduled. Leave the stored schedule untouched.
        $trigger = (string) ($_POST['auto_update_trigger'] ?? '');
        if ('' === $trigger) {
            $trigger = (string) $this->connection->fetchOne('SELECT auto_update_trigger FROM tl_openai_config WHERE id = ?', [(int) $dc->id]);
        }
        if ('manual' === $trigger) {
            return;
        }

        $minute = $this->normalizeScheduleMinute($_POST['auto_update_schedule_minute'] ?? '*');
        $hour = $this->normalizeScheduleHour($_POST['auto_update_schedule_hour'] ?? '*');
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
     * Builds the inline state markup for the auto-update license gate. Rendered
     * INSIDE the edit form (via the premium_license_intro field callback), not via
     * $GLOBALS['TL_HEAD']/['TL_BODY'] — those globals are only processed by the
     * FRONTEND page renderer (Controller::replaceDynamicScriptTags); the backend
     * templates never output them, so anything pushed there is silently dropped.
     *
     * The state is a plain element with data attributes (not an inline <script>)
     * so Turbo morph re-renders always reflect the latest server state. The
     * premium_legend section precedes auto_update_legend in the palette, so the
     * <style> hides #pal_auto_update_legend before it is painted (no flash). The
     * license-check JS reveals the fieldset with an inline style after a successful
     * key validation.
     */
    public function renderAutoUpdateBackendState(int $configId, bool $licenseActive): string
    {
        $markup = \sprintf(
            '<div class="oaa-auto-update-state" data-config-id="%d" data-license-active="%s" hidden></div>',
            $configId,
            $licenseActive ? '1' : '0',
        );

        if (!$licenseActive) {
            $markup .= '<style>#pal_auto_update_legend{display:none}</style>';
        }

        return $markup;
    }

    /**
     * input_field_callback for auto_update_first_sync_hint: an inline info box inside
     * the auto-update legend telling the user to start the FIRST sync manually.
     * Rendered only while sync is enabled and no run has happened yet
     * (auto_update_last_run = 0); disappears after the first run.
     *
     * Cron-aware: scheduled mode still needs a manual first sync; the hint mentions
     * whether later syncs require a CLI cron job.
     */
    public function firstSyncHintField(DataContainer $dc, string $xlabel = ''): string
    {
        if (!$dc->id) {
            return '';
        }

        $row = $this->connection->fetchAssociative(
            'SELECT auto_update_enabled, auto_update_trigger, auto_update_last_run FROM tl_openai_config WHERE id = ?',
            [(int) $dc->id],
        );

        if (!$row || !$row['auto_update_enabled'] || (int) $row['auto_update_last_run'] > 0) {
            return '';
        }

        if ('manual' === (string) ($row['auto_update_trigger'] ?? 'scheduled')) {
            $text = $this->getConfigLangString(
                'first_sync_hint_manual',
                'Manual mode: start the first sync via the “Run sync now” button in the Auto-Sync dashboard — it also shows whether all prerequisites (e.g. search index, page selection) are met.',
            );
        } elseif (CronHealthService::STATUS_HEALTHY === $this->cronHealth->status($this->cronHealth->heartbeatLastRun())) {
            $text = $this->getConfigLangString(
                'first_sync_hint_cron',
                'Start the first sync via the “Run sync now” button in the Auto-Sync dashboard — it also shows whether all prerequisites (e.g. search index, page selection) are met. Later syncs run automatically on your schedule.',
            );
        } else {
            $text = $this->getConfigLangString(
                'first_sync_hint_nocron',
                'Start the first sync via the “Run sync now” button in the Auto-Sync dashboard — it also shows whether all prerequisites (e.g. search index, page selection) are met. Later syncs require a CLI cron job (contao:cron) on your server.',
            );
        }

        return \sprintf(
            '<div class="widget clr">'
            .'<style>.oaa-first-sync-link:hover,.oaa-first-sync-link:focus-visible{background:#1976d2 !important;border-color:#1565c0 !important;color:#fff !important}</style>'
            .'<div style="background: var(--info-bg); border-left: 4px solid #2196f3; padding: 10px; margin: 8px 0 0 0;">'
            .'<p style="margin: 0 0 4px 0;"><strong>ℹ️ %s:</strong></p>'
            .'<p style="margin: 0 0 8px 0;">%s</p>'
            .'<a href="%s" class="oaa-first-sync-link" style="display: inline-flex; align-items: center; justify-content: center; min-height: 26px; padding: 2px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; line-height: 1.25; background: #2196f3; border: 1px solid #1976d2; color: #fff; text-decoration: none; white-space: nowrap;">%s</a>'
            .'</div></div>',
            htmlspecialchars($this->getConfigLangString('first_sync_hint_heading', 'First sync'), ENT_QUOTES),
            htmlspecialchars($text, ENT_QUOTES),
            htmlspecialchars($this->router->generate('vector_store_auto_update'), ENT_QUOTES),
            htmlspecialchars($this->getConfigLangString('first_sync_hint_dashboard', 'Open the Auto-Sync dashboard'), ENT_QUOTES),
        );
    }

    /**
     * Inserts a line break after each sentence-ending punctuation mark so hint text
     * doesn't run on as a single dense paragraph.
     */
    private function breakSentences(string $text): string
    {
        return (string) preg_replace('/(?<=[.!?])\s+(?=\S)/u', '<br>', $text);
    }

    /**
     * Mirrors the gates of getAutoUpdateModelOptions(): only when all of them pass
     * did the rendered select contain real models, so only then is an empty value
     * a deliberate non-selection worth rejecting. auto_update_enabled and
     * auto_update_mode both use submitOnChange, so the persisted activeRecord
     * matches the form the admin actually saw.
     */
    private function couldSelectAutoUpdateModel(DataContainer $dc): bool
    {
        if (!$dc->id || !$dc->activeRecord || !(bool) ($dc->activeRecord->auto_update_enabled ?? false)) {
            return false;
        }

        if (!$this->licenseValidation->isLicenseActiveCached((int) $dc->id)) {
            return false;
        }

        return (bool) $this->encryption->getApiKeyForConfig((int) $dc->id);
    }

    /**
     * Remove Contao's automatic "password has been changed" confirmation
     * (added by DataContainer when a Password widget is saved). The api_key
     * field uses that widget, so the message would otherwise show on every
     * config save even though no password exists.
     */
    private function removePasswordChangedMessage(): void
    {
        $session = $this->requestStack->getSession();

        // getSession() is typed as SessionInterface, which does not expose getFlashBag();
        // the flash bag only exists on the concrete (flash-aware) session implementation.
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $flashBag = $session->getFlashBag();
        $key = 'contao.BE.confirm';

        if (!$flashBag->has($key)) {
            return;
        }

        System::loadLanguageFile('default');
        $pwChanged = $GLOBALS['TL_LANG']['MSC']['pw_changed'] ?? null;

        if (null === $pwChanged) {
            return;
        }

        $remaining = array_values(array_filter(
            $flashBag->get($key),
            static fn ($message): bool => $message !== $pwChanged,
        ));

        if ([] !== $remaining) {
            $flashBag->set($key, $remaining);
        }
    }

    /**
     * Resolve the effective page limit. Returns null when enforcement should be
     * skipped: empty plan (not yet validated) or "enterprise" (unlimited). Shared
     * with the runtime cap so save-time and sync-time limits never diverge.
     */
    private function resolvePageLimit(string $plan, int $maxPages): int|null
    {
        return LicenseValidationService::resolvePageLimit($plan, $maxPages);
    }

    /**
     * Force a remote re-validation to persist premium_license_plan / _max_pages, then
     * read them back. Used when the limit must be enforced but the plan was not stored
     * yet. Runs in the web request (config save), where the license key decrypts.
     *
     * @return array{0: string, 1: int} [plan, maxPages]
     */
    private function refreshLicensePlan(int $configId): array
    {
        $encrypted = (string) $this->connection->fetchOne(
            'SELECT premium_license_key FROM tl_openai_config WHERE id = ?',
            [$configId],
        );

        $plainKey = '' !== $encrypted ? $this->encryption->decryptLicenseKey($encrypted) : null;

        if (null !== $plainKey) {
            $this->licenseValidation->revalidate($configId, $plainKey);
        }

        $row = $this->connection->fetchAssociative(
            'SELECT premium_license_plan, premium_license_max_pages FROM tl_openai_config WHERE id = ?',
            [$configId],
        );

        return [
            (string) ($row['premium_license_plan'] ?? ''),
            (int) ($row['premium_license_max_pages'] ?? 0),
        ];
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

        $storedMinute = (string) ($dc->activeRecord->auto_update_schedule_minute ?? '*');
        $storedHour = (string) ($dc->activeRecord->auto_update_schedule_hour ?? '*');
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

    private function configureAutoUpdateFieldAccess(bool $licenseActive): void
    {
        if (!$licenseActive) {
            // Keep the palette section so JS can reveal it after a successful
            // key check without a page reload. Fields stay disabled (server-side
            // save guard); the block is hidden via a <style> injected in <head>.
            return;
        }

        foreach (self::AUTO_UPDATE_LICENSE_FIELDS as $field) {
            if (!isset($GLOBALS['TL_DCA']['tl_openai_config']['fields'][$field])) {
                continue;
            }

            unset($GLOBALS['TL_DCA']['tl_openai_config']['fields'][$field]['eval']['disabled']);
        }
    }

    /**
     * "Faithful" indexing uploads pages verbatim with no LLM rewrite step, so the
     * "Generation model" field has nothing to act on regardless of the trigger type.
     * Drop it from the palette whenever the mode is not explicitly "llm_polish".
     * The prompt template is equally inert in faithful mode, but stays visible as
     * a disabled textarea so the stored prompt is not hidden from the user.
     * Empty/legacy rows default to faithful behaviour, so they are treated the same.
     */
    private function configureAutoUpdateModelVisibility(int $configId): void
    {
        $mode = (string) ($_POST['auto_update_mode'] ?? '');
        if ('' === $mode) {
            $mode = (string) $this->connection->fetchOne('SELECT auto_update_mode FROM tl_openai_config WHERE id = ?', [$configId]);
        }

        if ('llm_polish' !== $mode) {
            // Use a regex so the field is removed regardless of its position in
            // the palette string (first item after legend, last item, or middle).
            $GLOBALS['TL_DCA']['tl_openai_config']['palettes']['default'] = (string) preg_replace(
                '/,auto_update_model(?=[,;]|$)/',
                '',
                $GLOBALS['TL_DCA']['tl_openai_config']['palettes']['default'],
            );

            // Disabled widgets skip submitInput(), so the stored prompt survives
            // saves made while faithful mode is active. The oaa-mode-locked class
            // tells the license JS to keep the field disabled when it re-enables
            // the premium fields after a successful license check.
            $eval = &$GLOBALS['TL_DCA']['tl_openai_config']['fields']['auto_update_prompt_template']['eval'];
            $eval['disabled'] = true;

            if (!str_contains($eval['tl_class'] ?? '', 'oaa-mode-locked')) {
                $eval['tl_class'] = trim(($eval['tl_class'] ?? '').' oaa-mode-locked');
            }
        }
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

    private function addNoFilesNoticeIfNeeded(int $configId): void
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_openai_files WHERE pid = ?',
            [$configId],
        );

        if (0 === $count && !$this->connection->fetchOne('SELECT vector_store_id FROM tl_openai_config WHERE id = ? AND vector_store_id IS NOT NULL AND vector_store_id != \'\'', [$configId])) {
            $text = htmlspecialchars($this->getTranslatedString(
                'no_files_notice',
                'No files have been uploaded to the OpenAI vector store yet. The chatbot cannot answer questions without knowledge documents. Important: at least one file upload is also required for the OpenAI vector store to be created on the platform — without it the Premium Add-on (automatic sync) will not work either. Go to «File upload» to add your first file.',
            ), ENT_QUOTES);

            Message::addRaw(
                '<div class="oaa-info-card oaa-info-card--notice">'
                .'<p style="margin:0;line-height:1.6;font-size:13px;">'
                .'<span style="color:#f59e0b;font-weight:700;margin-right:6px;">!</span>'
                .$text
                .'</p>'
                .'</div>',
            );
        }
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

    private function configureSingleRecordCreation(): void
    {
        $hasExistingConfig = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_openai_config') > 0;

        if ($hasExistingConfig) {
            $GLOBALS['TL_DCA']['tl_openai_config']['config']['notCreatable'] = true;

            return;
        }

        unset($GLOBALS['TL_DCA']['tl_openai_config']['config']['notCreatable']);
    }

    /**
     * Returns true when a new, valid-format license key is present in the current
     * POST request — i.e. neither the display mask nor empty.
     *
     * save_callbacks fire before the DB write, so any DB-backed license check would
     * miss a key that is being saved for the first time in this same request. Callers
     * use this to skip the isLicenseActive() guard and let the value through; the
     * actual remote validation happens in validatePremiumLicenseOnSave (onsubmit),
     * which runs after the DB write.
     */
    private function isNewValidKeyPosted(): bool
    {
        $posted = trim((string) ($_POST['premium_license_key'] ?? ''));

        return '' !== $posted
            && self::LICENSE_KEY_MASK !== $posted
            && $this->encryption->isValidLicenseKeyFormat($posted);
    }

    /**
     * Returns true when the license key field was explicitly cleared in the submitted
     * form (posted as empty string, distinct from the unchanged-mask case).
     */
    private function isKeyBeingRemoved(): bool
    {
        // Default to the mask so a missing field (not submitted) is treated as unchanged.
        $posted = trim((string) ($_POST['premium_license_key'] ?? self::LICENSE_KEY_MASK));

        return '' === $posted;
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
