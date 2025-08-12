<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) Leo Feyer
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\EventListener;

use Contao\Controller;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\DataContainer;
use Contao\Environment;
use Contao\Image;
use Contao\Message;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAiAssistantsListener
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly Connection $connection,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly string $csrfTokenName,
        private readonly RequestStack $requestStack
    ) {
    }

    /**
     * Gets available OpenAI models for assistants
     */
    public function getAvailableModels(?DataContainer $dc = null): array
    {
        $models = [];

        // Try to get models from API if we have a DataContainer context
        if ($dc && $dc->activeRecord && $dc->activeRecord->pid) {
            try {
                $apiKey = $this->getApiKeyFromEnvironment($dc->activeRecord->pid);

                if ($apiKey) {
                    $models = $this->fetchModelsFromApi($apiKey);
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to fetch models from API', [
                    'error'  => $e->getMessage(),
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                ]);
            }
        }

        // Create a new array with the custom model option in second position
        $orderedModels = [];

        // Add the blank option first (if any models exist)
        if (! empty($models)) {
            $orderedModels[''] = $this->getTranslatedString('model_select_placeholder', '-- Select Model --');
        }

        // Add the custom model option in second position
        $orderedModels['manual'] = $this->getTranslatedString('model_manual_option', '-- Enter Custom Model --');

        // Add all the API models after the custom option
        foreach ($models as $key => $value) {
            $orderedModels[$key] = $value;
        }

        return $orderedModels;
    }

    /**
     * Validates the model selection and handles manual override
     */
    public function validateModel($value, DataContainer $dc): string
    {
        $this->logger->info(
            'validateModel called',
            [
                'contao'       => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                'value'        => $value,
                'model_manual' => $dc->activeRecord->model_manual ?? 'not set',
            ]
        );

        // If manual override is selected, just return 'manual' - validation will happen in validateManualModel
        if ($value === 'manual') {
            return 'manual';
        }

        // If no model is selected and no manual model is provided, throw an error on the model field
        if (empty($value)) {
            $manualModel = $dc->activeRecord->model_manual ?? '';
            if (empty($manualModel)) {
                throw new \InvalidArgumentException(
                    $this->getTranslatedString('model_required', 'Please select a model or enter a custom model name.')
                );
            }
        }

        // For selected models, validate via API before saving
        if (! empty($value)) {
            $this->validateModelCompatibility($value, $dc);
        }

        return $value;
    }

    /**
     * Validates manual model input when the field is saved
     */
    public function validateManualModel($value, DataContainer $dc): string
    {
        $this->logger->info(
            'validateManualModel called',
            [
                'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                'value'  => $value,
                'model'  => $dc->activeRecord->model ?? 'not set',
            ]
        );

        // Only validate if the main model field is set to 'manual'
        if ($dc->activeRecord && $dc->activeRecord->model === 'manual') {
            if (empty($value)) {
                throw new \InvalidArgumentException(
                    $this->getTranslatedString('model_validation_error', 'Please enter a custom model name when selecting manual override.')
                );
            }

            // Validate the custom model via API
            $this->validateModelCompatibility($value, $dc);
        }

        return $value;
    }

    /**
     * Validates that a model is selected when the record is saved
     */
    public function validateModelSelection($value, DataContainer $dc): string
    {
        $this->logger->info(
            'validateModelSelection called',
            [
                'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                'value'  => $value,
            ]
        );

        // This is a fallback validation to ensure a model is always selected
        if (empty($value)) {
            throw new \InvalidArgumentException(
                $this->getTranslatedString('model_required', 'Please select a model or enter a custom model name.')
            );
        }

        return $value;
    }

    /**
     * Creates or updates an assistant on the OpenAI platform
     * Called as onsubmit_callback after the record is saved
     */
    public function createOrUpdateAssistant(DataContainer $dc): void
    {
        if (! $dc->activeRecord) {
            $this->logger->warning('No active record available for assistant creation/update');

            return;
        }

        // Ensure we have a valid record ID
        if (empty($dc->activeRecord->id)) {
            $this->logger->error('No record ID available for assistant creation/update');

            return;
        }

        try {
            $this->logger->info(
                'Starting assistant creation/update process',
                [
                    'contao'         => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                    'assistant_name' => $dc->activeRecord->name,
                    'config_id'      => $dc->activeRecord->pid,
                    'model'          => $dc->activeRecord->model,
                    'model_manual'   => $dc->activeRecord->model_manual ?? 'not set',
                    'record_id'      => $dc->activeRecord->id,
                ]
            );

            // Get API key from config
            $apiKey = $this->getApiKeyFromEnvironment($dc->activeRecord->pid);

            if (! $apiKey) {
                $this->logger->error('No API key found for config ID: ' . $dc->activeRecord->pid);
                Message::addError('No API key found in configuration');
                $this->updateStatus($dc->activeRecord->id, 'failed');

                return;
            }

            // Get the model to validate
            $modelToUse = $dc->activeRecord->model;
            if ($modelToUse === 'manual') {
                $modelToUse = $dc->activeRecord->model_manual ?? '';
            }

            if (empty($modelToUse)) {
                $this->logger->error('No model specified for assistant creation');
                Message::addError('No model specified. Please select a model or enter a custom model name.');
                $this->updateStatus($dc->activeRecord->id, 'failed');

                return;
            }

            // Validate model compatibility - throw exception if validation fails
            $this->logger->info(
                'About to validate model compatibility',
                [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                    'model'  => $modelToUse,
                ]
            );

            $this->validateModelCompatibility($modelToUse, $dc);

            $this->logger->info(
                'Model validation passed, proceeding with assistant creation',
                [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                    'model'  => $modelToUse,
                ]
            );

            // Get vector store ID from config
            $vectorStoreConfig = $this->connection->fetchAssociative(
                'SELECT vector_store_id FROM tl_openai_config WHERE id = ?',
                [$dc->activeRecord->pid]
            );

            if (! $vectorStoreConfig || ! $vectorStoreConfig['vector_store_id']) {
                $this->logger->error('No vector store ID found for config ID: ' . $dc->activeRecord->pid);
                Message::addError('No vector store ID found in configuration');
                $this->updateStatus($dc->activeRecord->id, 'failed');

                return;
            }

            // Set status to creating
            $this->updateStatus($dc->activeRecord->id, 'creating');

            // Prepare assistant data
            $rawInstructions = (string) ($dc->activeRecord->system_instructions ?? '');
            // Decode HTML entities so quotes/brackets are literal and normalize newlines/spaces
            $decodedInstructions = html_entity_decode($rawInstructions, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $assistantData = [
                'name'         => $dc->activeRecord->name,
                'instructions' => $decodedInstructions,
                'model'        => $modelToUse,
                'temperature'  => (float) ($dc->activeRecord->temperature ?? 0.25),
                'top_p'        => (float) ($dc->activeRecord->top_p ?? 1),
                'tools'        => [
                    [
                        'type' => 'file_search',
                    ],
                ],
                'tool_resources' => [
                    'file_search' => [
                        'vector_store_ids' => [$vectorStoreConfig['vector_store_id']],
                    ],
                ],
            ];

            $headers = [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
                'OpenAI-Beta'   => 'assistants=v2',
            ];

            // Check if we already have an OpenAI assistant ID
            if (! empty($dc->activeRecord->openai_assistant_id)) {
                // Update existing assistant
                $this->logger->info(
                    'Updating existing assistant',
                    [
                        'contao'         => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                        'assistant_id'   => $dc->activeRecord->openai_assistant_id,
                        'assistant_name' => $dc->activeRecord->name,
                    ]
                );

                $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/assistants/' . $dc->activeRecord->openai_assistant_id, [
                    'headers' => $headers,
                    'json'    => $assistantData,
                    'timeout' => 60,
                ]);
            } else {
                // Create new assistant
                $this->logger->info(
                    'Creating new assistant',
                    [
                        'contao'         => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                        'assistant_name' => $dc->activeRecord->name,
                        'model'          => $modelToUse,
                    ]
                );

                $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/assistants', [
                    'headers' => $headers,
                    'json'    => $assistantData,
                    'timeout' => 60,
                ]);
            }

            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Failed to create/update assistant', [
                    'status'   => $response->getStatusCode(),
                    'response' => $response->getContent(false),
                ]);
                $this->updateStatus($dc->activeRecord->id, 'failed');
                Message::addError('Failed to create/update assistant. Please check your configuration and try again.');

                return;
            }

            $responseData = json_decode($response->getContent(), true);
            if (! isset($responseData['id'])) {
                $this->logger->error('Invalid response from OpenAI API', [
                    'response' => $response->getContent(false),
                ]);
                $this->updateStatus($dc->activeRecord->id, 'failed');
                Message::addError('Invalid response from OpenAI API');

                return;
            }

            $this->logger->info(
                'Assistant successfully created/updated',
                [
                    'contao'         => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                    'assistant_id'   => $responseData['id'],
                    'assistant_name' => $dc->activeRecord->name,
                ]
            );

            // Update the record with the assistant ID and set status to active
            $this->connection->executeQuery(
                'UPDATE tl_openai_assistants SET openai_assistant_id = ?, status = ? WHERE id = ?',
                [$responseData['id'], 'active', $dc->activeRecord->id]
            );

            Message::addConfirmation('Assistant "' . $dc->activeRecord->name . '" was successfully created/updated.');

        } catch (\Exception $e) {
            $this->logger->error('Error creating/updating assistant: ' . $e->getMessage());
            Message::addError('Failed to create/update assistant: ' . $e->getMessage());

            // If we have a record ID, try to update its status
            if ($dc->activeRecord && $dc->activeRecord->id) {
                $this->updateStatus($dc->activeRecord->id, 'failed');
            }

            // Re-throw the exception to cause transaction rollback
            throw $e;
        }
    }

    /**
     * Normalize and decode system instructions on save to preserve literal characters (quotes, brackets)
     */
    public function normalizeSystemInstructions($value, DataContainer $dc): string
    {
        if ($value === null) {
            return '';
        }

        $raw = (string) $value;
        // Decode all HTML entities (including quotes) to literal characters
        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Keep newlines; normalize CRLF/CR to LF
        $decoded = str_replace(["\r\n", "\r"], "\n", $decoded);
        // Ensure there is no accidental HTML leftover
        $decoded = strip_tags($decoded);
        // Trim leading/trailing whitespace
        return trim($decoded);
    }

    /**
     * Lists assistants in the backend
     */
    public function listAssistants($row): string
    {
        $statusColors = [
            'active'   => 'green',
            'creating' => 'orange',
            'failed'   => 'red',
            'pending'  => 'gray',
        ];

        $statusIcons = [
            'active'   => '✓',
            'creating' => '⟳',
            'failed'   => '✗',
            'pending'  => '⏳',
        ];

        $status = $row['status'] ?? 'pending';
        $color  = $statusColors[$status] ?? 'gray';
        $icon   = $statusIcons[$status] ?? '⏳';

        $label = sprintf(
            '<div class="tl_file_list"><span class="name">%s</span> <span class="model">[%s]</span> <span class="settings">(temp: %s, top_p: %s)</span> <span class="status" style="color: %s">%s %s</span>',
            $row['name'],
            $row['model'],
            $row['temperature'],
            $row['top_p'],
            $color,
            $icon,
            $GLOBALS['TL_LANG']['tl_openai_assistants']['status_options'][$status] ?? $status
        );

        return $label;
    }

    /**
     * Generates the sync button for the backend
     */
    public function syncButton($row, $href, $label, $title, $icon, $attributes): string
    {
        if (! $row['openai_assistant_id']) {
            return '';
        }

        return sprintf(
            '<a href="%s" title="%s" %s>%s</a> ',
            Controller::addToUrl($href . '&amp;id=' . $row['id']),
            StringUtil::specialchars($title),
            $attributes,
            Image::getHtml($icon, $label)
        );
    }

    /**
     * Validates top_p value
     */
    public function validateTopP($value, DataContainer $dc): string
    {
        $value = (float) $value;

        if ($value < 0 || $value > 1) {
            throw new \Exception('Top P must be between 0 and 1');
        }

        return (string) $value;
    }

    /**
     * Validates temperature value
     */
    public function validateTemperature($value, DataContainer $dc): string
    {
        $value = (float) $value;

        if ($value < 0 || $value > 2) {
            throw new \Exception('Temperature must be between 0 and 2');
        }

        return (string) $value;
    }

    /**
     * Adds the header to the list view
     */
    public function addHeader(): string
    {
        return '<div class="tl_header">' . $GLOBALS['TL_LANG']['tl_openai_assistants']['header'] . '</div>';
    }

    /**
     * Deletes an assistant from the OpenAI platform
     * Called as ondelete_callback when the record is deleted
     */
    public function deleteAssistant(DataContainer $dc): void
    {
        if (! $dc->activeRecord || empty($dc->activeRecord->openai_assistant_id)) {
            return;
        }

        try {
            $this->logger->info(
                'Starting assistant deletion process',
                [
                    'contao'         => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                    'assistant_id'   => $dc->activeRecord->openai_assistant_id,
                    'assistant_name' => $dc->activeRecord->name,
                ]
            );

            // Get API key from config
            $apiKey = $this->getApiKeyFromEnvironment($dc->activeRecord->pid);

            if (! $apiKey) {
                $this->logger->error('No API key found for config ID: ' . $dc->activeRecord->pid);

                return;
            }

            try {
                $response = $this->httpClient->request('DELETE', 'https://api.openai.com/v1/assistants/' . $dc->activeRecord->openai_assistant_id, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'OpenAI-Beta'   => 'assistants=v2',
                    ],
                ]);

                // If we get a 404, the assistant doesn't exist anymore, which is fine
                if ($response->getStatusCode() === 404) {
                    $this->logger->info(
                        'Assistant already deleted from OpenAI platform',
                        [
                            'contao'         => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                            'assistant_id'   => $dc->activeRecord->openai_assistant_id,
                            'assistant_name' => $dc->activeRecord->name,
                        ]
                    );

                    return;
                }

                if ($response->getStatusCode() !== 200) {
                    $this->logger->error('Failed to delete assistant', [
                        'status'   => $response->getStatusCode(),
                        'response' => $response->getContent(false),
                    ]);

                    // Don't show error to user, just log it
                    return;
                }

                $this->logger->info(
                    'Assistant successfully deleted',
                    [
                        'contao'         => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                        'assistant_id'   => $dc->activeRecord->openai_assistant_id,
                        'assistant_name' => $dc->activeRecord->name,
                    ]
                );
            } catch (\Exception $e) {
                $this->logger->error('Error deleting assistant: ' . $e->getMessage());
                // Don't show error to user, just log it
            }
        } catch (\Exception $e) {
            $this->logger->error('Error in deleteAssistant: ' . $e->getMessage());
            // Don't show error to user, just log it
        }
    }

    public function onLoadCallback($dc): void
    {
        // Check for single record limitation only on create action
        $request = $this->requestStack->getCurrentRequest();
        if ($request && ($request->get('act') === 'create' || $request->get('act') === '')) {
            $this->checkSingleRecordLimitation($dc);
        }

        // Get the current backend language
        $language = $GLOBALS['TL_LANGUAGE'] ?? 'en';

        // Load language file if not already loaded
        System::loadLanguageFile('tl_openai_assistants', $language);

        // Get translated strings
        $lang = $GLOBALS['TL_LANG']['tl_openai_assistants'];

        // Create combined message with welcome and model selection tips
        $combinedMessage = '<strong style="display: block; font-size: 22px; position: relative; top: -5px;">' .
                          ($lang['welcome_heading'] ?? 'OpenAI Assistant') .
                          '</strong>' .
                          ($lang['welcome_message1'] ?? 'Welcome to the OpenAI Assistant screen.') .
                          '<br>' .
                          ($lang['welcome_message2'] ?? 'Here you can create and manage your OpenAI assistant.');

        // Add model selection tips
        $combinedMessage .= '<div style="margin: 15px 0 0 0; padding: 15px 13px; background: var(--info-bg); border-left: 4px solid #007cba; line-height: 1.3;">';
        $combinedMessage .= '<strong>💡 ' . ($lang['model_info_heading'] ?? 'Model Selection Tips') . ':</strong><br>';
        $combinedMessage .= '<span style="padding-left: 5px;">• <strong>' . ($lang['model_info_dynamic'] ?? 'All Models: All available models from your OpenAI account are shown') . '</strong><br></span>';
        $combinedMessage .= '<span style="padding-left: 5px;">• <strong>' . ($lang['model_info_custom'] ?? 'Custom Models: Select "Enter Custom Model" to use any OpenAI model') . '</strong><br></span>';
        $combinedMessage .= '<span style="padding-left: 5px;">• <strong>' . ($lang['model_info_compatibility'] ?? 'Model Validation: Model compatibility is checked when you save the assistant') . '</strong><br></span>';
        $combinedMessage .= '<span style="padding-left: 5px;">• <strong>' . ($lang['model_info_help'] ?? 'Need Help?') . '</strong> <a href="https://platform.openai.com/docs/models" target="_blank" style="color: #007cba;">' . ($lang['model_info_link'] ?? 'View all available models on OpenAI Platform') . '</a></span>';
        $combinedMessage .= '</div>';

        Message::addInfo($combinedMessage);

        // Add JavaScript for model field enhancement
        $script = '<script>
        function toggleManualModelField(select) {
            const manualField = document.querySelector("input[name=\'model_manual\']");
            const manualRow = manualField ? manualField.closest(".tl_field") : null;
            
            if (select.value === "manual") {
                if (manualRow) manualRow.style.display = "block";
                if (manualField) {
                    manualField.focus();
                    manualField.style.borderColor = "#007cba";
                    manualField.style.backgroundColor = "#f8f9fa";
                }
            } else {
                if (manualRow) manualRow.style.display = "none";
                if (manualField) {
                    manualField.value = "";
                    manualField.style.borderColor = "";
                    manualField.style.backgroundColor = "";
                }
            }
        }
        
        // Initialize on page load
        document.addEventListener("DOMContentLoaded", function() {
            const modelSelect = document.querySelector("select[name=\'model\']");
            if (modelSelect) {
                toggleManualModelField(modelSelect);
            }
        });
        </script>';

        // Add the script to the page
        $GLOBALS['TL_BODY'][] = $script;
    }

    /**
     * Fetch models from OpenAI API
     */
    private function fetchModelsFromApi(string $apiKey): array
    {
        try {
            $response = $this->httpClient->request('GET', 'https://api.openai.com/v1/models', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'timeout' => 30,
            ]);

            $data   = $response->toArray();
            $models = [];

            if (isset($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as $model) {
                    if (isset($model['id'])) {
                        // Include all models - validation will happen when saving
                        $models[$model['id']] = $model['id'];
                    }
                }
            }

            ksort($models);

            return $models;

        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch OpenAI models: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Validates if a model is compatible with the Assistants API via API call
     */
    private function validateModelViaApi(string $modelId, string $apiKey): bool
    {
        $this->logger->info(
            'validateModelViaApi called',
            [
                'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                'model'  => $modelId,
            ]
        );

        try {
            // Try to create a minimal assistant with the model to test compatibility
            $testData = [
                'name'         => 'Test Assistant',
                'instructions' => 'Test assistant for model validation',
                'model'        => $modelId,
                'tools'        => [],
            ];

            $headers = [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
                'OpenAI-Beta'   => 'assistants=v2',
            ];

            $this->logger->info(
                'Making API request to validate model',
                [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                    'model'  => $modelId,
                    'url'    => 'https://api.openai.com/v1/assistants',
                ]
            );

            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/assistants', [
                'headers' => $headers,
                'json'    => $testData,
                'timeout' => 30,
            ]);

            $this->logger->info(
                'API response received',
                [
                    'contao'      => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                    'model'       => $modelId,
                    'status_code' => $response->getStatusCode(),
                ]
            );

            if ($response->getStatusCode() === 200) {
                $responseData = json_decode($response->getContent(), true);
                if (isset($responseData['id'])) {
                    // Clean up the test assistant
                    $this->httpClient->request('DELETE', 'https://api.openai.com/v1/assistants/' . $responseData['id'], [
                        'headers' => $headers,
                        'timeout' => 10,
                    ]);

                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            $this->logger->error('Model validation failed for ' . $modelId . ': ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Get translated string with fallback
     */
    private function getTranslatedString(string $key, string $fallback): string
    {
        $lang = $GLOBALS['TL_LANG']['tl_openai_assistants'] ?? [];

        return $lang[$key] ?? $fallback;
    }

    /**
     * Strip HTML from text
     */
    private function stripHtml(string $text): string
    {
        // Normalize line endings and trim trailing spaces while preserving user-intended newlines
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Remove any remaining HTML tags for safety
        $text = strip_tags($text);
        // Collapse accidental non-breaking spaces introduced by editors
        $text = str_replace(["\u{00A0}", '&nbsp;'], ' ', $text);
        // Trim but keep internal formatting
        return trim($text);
    }

    /**
     * Validates model compatibility with Assistants API before saving
     */
    private function validateModelCompatibility(string $modelId, DataContainer $dc): void
    {
        $this->logger->info(
            'validateModelCompatibility called',
            [
                'contao'    => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                'model'     => $modelId,
                'config_id' => $dc->activeRecord->pid ?? 0,
            ]
        );

        // Get API key from config
        $apiKey = $this->getApiKeyFromEnvironment($dc->activeRecord->pid ?? 0);

        if (! $apiKey) {
            throw new \InvalidArgumentException(
                $this->getTranslatedString('no_api_key_error', 'No API key found in configuration. Please check your OpenAI configuration.')
            );
        }

        // Validate model via API
        if (! $this->validateModelViaApi($modelId, $apiKey)) {
            throw new \InvalidArgumentException(
                sprintf(
                    $this->getTranslatedString('model_incompatible_error', 'The model "%s" is not compatible with the Assistants API. Please select a different model.'),
                    $modelId
                )
            );
        }

        $this->logger->info(
            'Model validation successful',
            [
                'contao'    => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                'model'     => $modelId,
                'config_id' => $dc->activeRecord->pid ?? 0,
            ]
        );
    }

    /**
     * Update assistant status
     */
    private function updateStatus(int $assistantId, string $status): void
    {
        try {
            $this->connection->executeQuery(
                'UPDATE tl_openai_assistants SET status = ? WHERE id = ?',
                [$status, $assistantId]
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to update assistant status: ' . $e->getMessage());
        }
    }

    /**
     * Validates API key format
     */
    private function isValidApiKeyFormat(string $apiKey): bool
    {
        // OpenAI API keys typically start with 'sk-' and are 51 characters long
        return preg_match('/^sk-[A-Za-z0-9]{48}$/', $apiKey) === 1;
    }

    /**
     * Get API key from environment variable or database
     * Environment variable takes precedence for security
     */
    private function getApiKeyFromEnvironment(int $configId): ?string
    {
        // Try environment variable first (most secure)
        $envKey = sprintf('OPENAI_API_KEY_%d', $configId);
        if (isset($_ENV[$envKey])) {
            return $_ENV[$envKey];
        }

        // Fallback to database (encrypted)
        $config = $this->connection->fetchAssociative(
            'SELECT api_key FROM tl_openai_config WHERE id = ?',
            [$configId]
        );

        if ($config && $config['api_key']) {
            // Check if it's encrypted (base64 encoded and longer than typical API key)
            if (strlen($config['api_key']) > 100) {
                // Try to decrypt it using the same method as OpenAiConfigListener
                return $this->decryptApiKey($config['api_key']);
            }
            // It's still in old base64 format, decode it
            return base64_decode($config['api_key'], true);

        }

        return null;
    }

    /**
     * Generate encryption key (same as other services)
     */
    private function getEncryptionKey(): string
    {
        // Generate the same encryption key as in other services
        $serverName   = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '/';

        return hash('sha256', $serverName . $documentRoot, true);
    }

    /**
     * Decrypt API key from storage (same as OpenAiConfigListener)
     */
    private function decryptApiKey(string $encryptedData): ?string
    {
        try {
            $key    = $this->getEncryptionKey();
            $method = 'aes-256-cbc';

            $data      = base64_decode($encryptedData, true);
            $ivLength  = openssl_cipher_iv_length($method);
            $iv        = substr($data, 0, $ivLength);
            $encrypted = substr($data, $ivLength);

            $decrypted = openssl_decrypt($encrypted, $method, $key, 0, $iv);

            return $decrypted !== false ? $decrypted : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Checks if there's already an assistant record for this config and prevents creation of additional ones
     */
    private function checkSingleRecordLimitation($dc): void
    {
        // Get the parent config ID from the request or DataContainer
        $configId = null;

        if ($dc && $dc->activeRecord && $dc->activeRecord->pid) {
            $configId = $dc->activeRecord->pid;
        } else {
            // Try to get from request parameters
            $request = $this->requestStack->getCurrentRequest();
            if ($request) {
                $configId = $request->get('pid') ?: $request->get('id');
            }
        }

        if (! $configId) {
            return; // No config context, allow creation
        }

        // Check if there's already an assistant record for this config
        $existingAssistant = $this->connection->fetchAssociative(
            'SELECT id, name FROM tl_openai_assistants WHERE pid = ? LIMIT 1',
            [$configId]
        );

        if ($existingAssistant) {
            // Show message and redirect to the existing assistant
            Message::addInfo($this->getTranslatedString('single_assistant_redirect', 'Only one assistant is allowed per configuration. You are being redirected to the existing assistant.'));
            $url = Controller::addToUrl('act=edit&id=' . $existingAssistant['id']);
            Controller::redirect($url);
        }
    }
}
