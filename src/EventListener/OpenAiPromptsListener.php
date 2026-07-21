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
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\DataContainer;
use Contao\Message;
use Contao\System;
use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Backend DCA listener for tl_openai_prompts.
 *
 * Prompts are purely local configuration records: this listener does not create,
 * update, or delete OpenAI Assistant resources (that API is sunset on
 * 2026-08-26). Model compatibility is now validated against the Responses API
 * (/v1/responses) with a minimal ping call.
 */
class OpenAiPromptsListener
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly Connection $connection,
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly string $csrfTokenName,
        private readonly RequestStack $requestStack,
        private readonly EncryptionService $encryption,
    ) {
    }

    /**
     * Gets available OpenAI models for prompts.
     */
    public function getAvailableModels(DataContainer|null $dc = null): array
    {
        $models = [];

        if ($dc && $dc->activeRecord && $dc->activeRecord->pid) {
            try {
                $apiKey = $this->encryption->getApiKeyForConfig((int) $dc->activeRecord->pid);

                if ($apiKey) {
                    $models = $this->fetchModelsFromApi($apiKey);
                }
            } catch (\Exception $e) {
                $this->logger->warning(
                    'Failed to fetch models from API',
                    [
                        'error' => $e->getMessage(),
                        'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                    ],
                );
            }
        }

        $orderedModels = [];

        if (!empty($models)) {
            $orderedModels[''] = $this->getTranslatedString('model_select_placeholder', '-- Select Model --');
        }

        $orderedModels['manual'] = $this->getTranslatedString('model_manual_option', '-- Enter Custom Model --');

        foreach ($models as $key => $value) {
            $orderedModels[$key] = $value;
        }

        return $orderedModels;
    }

    /**
     * Validates the model selection and handles manual override.
     */
    public function validateModel($value, DataContainer $dc): string
    {
        $this->logger->info(
            'validateModel called',
            [
                'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                'value' => $value,
                'model_manual' => $dc->activeRecord->model_manual ?? 'not set',
            ],
        );

        if ('manual' === $value) {
            return 'manual';
        }

        if (empty($value)) {
            $manualModel = $dc->activeRecord->model_manual ?? '';
            if (empty($manualModel)) {
                throw new \InvalidArgumentException($this->getTranslatedString('model_required', 'Please select a model or enter a custom model name.'));
            }
        }

        if (!empty($value)) {
            $this->validateModelCompatibility((string) $value, $dc);
        }

        return (string) $value;
    }

    /**
     * Validates manual model input when the field is saved.
     */
    public function validateManualModel($value, DataContainer $dc): string
    {
        $this->logger->info(
            'validateManualModel called',
            [
                'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                'value' => $value,
                'model' => $dc->activeRecord->model ?? 'not set',
            ],
        );

        if ($dc->activeRecord && 'manual' === $dc->activeRecord->model) {
            if (empty($value)) {
                throw new \InvalidArgumentException($this->getTranslatedString('model_validation_error', 'Please enter a custom model name when selecting manual override.'));
            }

            $this->validateModelCompatibility((string) $value, $dc);
        }

        return (string) $value;
    }

    /**
     * Normalize and decode system instructions on save to preserve literal characters.
     */
    public function normalizeSystemInstructions($value, DataContainer $dc): string
    {
        if (null === $value) {
            return '';
        }

        $raw = (string) $value;
        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = str_replace(["\r\n", "\r"], "\n", $decoded);
        $decoded = strip_tags($decoded);

        return trim($decoded);
    }

    /**
     * Renders a single prompt row in the backend list.
     */
    public function listPrompts($row): string
    {
        $statusColors = [
            'active' => 'green',
            'creating' => 'orange',
            'failed' => 'red',
            'pending' => 'gray',
        ];

        $statusIcons = [
            'active' => '✓',
            'creating' => '⟳',
            'failed' => '✗',
            'pending' => '⏳',
        ];

        $status = $row['status'] ?? 'pending';
        $color = $statusColors[$status] ?? 'gray';
        $icon = $statusIcons[$status] ?? '⏳';

        $cause = '';
        if (($row['status'] ?? '') === 'failed' && !empty($row['status_cause'] ?? '')) {
            $cause = ' - '.htmlspecialchars((string) $row['status_cause']);
        }

        $promptRef = '';
        if (!empty($row['prompt_id'])) {
            $promptRef = \sprintf(
                ' <span class="prompt-ref" style="color:#888">[prompt: %s%s]</span>',
                htmlspecialchars((string) $row['prompt_id']),
                !empty($row['prompt_version']) ? '@'.htmlspecialchars((string) $row['prompt_version']) : '',
            );
        }

        return \sprintf(
            '<div class="tl_file_list"><span class="name">%s</span> <span class="model">[%s]</span> <span class="settings">(temp: %s, top_p: %s)</span>%s <span class="status" style="color: %s">%s %s%s</span>',
            htmlspecialchars((string) ($row['name'] ?? '')),
            htmlspecialchars((string) ($row['model'] ?? '')),
            htmlspecialchars((string) ($row['temperature'] ?? '')),
            htmlspecialchars((string) ($row['top_p'] ?? '')),
            $promptRef,
            $color,
            $icon,
            $GLOBALS['TL_LANG']['tl_openai_prompts']['status_options'][$status] ?? $status,
            $cause,
        );
    }

    /**
     * Validates top_p value.
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
     * Validates temperature value.
     */
    public function validateTemperature($value, DataContainer $dc): string
    {
        $value = (float) $value;

        if ($value < 0 || $value > 2) {
            throw new \Exception('Temperature must be between 0 and 2');
        }

        return (string) $value;
    }

    public function onLoadCallback($dc): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request && ('create' === $request->get('act') || '' === $request->get('act'))) {
            $this->checkSingleRecordLimitation($dc);
        }

        $language = $GLOBALS['TL_LANGUAGE'] ?? 'en';
        System::loadLanguageFile('tl_openai_prompts', $language);

        $lang = $GLOBALS['TL_LANG']['tl_openai_prompts'] ?? [];

        $combinedMessage = '<div class="oaa-info-card">';
        $combinedMessage .= '<p class="tl_info" style="background: transparent url(system/themes/flexible/icons/show.svg) no-repeat 11px 12px;">';
        $combinedMessage .= '<strong class="oaa-info-card-heading" style="display: block; font-size: 22px; position: relative; top: -5px;">'.
                          ($lang['welcome_heading'] ?? 'OpenAI Prompt').
                          '</strong>'.
                          ($lang['welcome_message1'] ?? 'Welcome to the OpenAI Prompt screen.').
                          '<br>'.
                          ($lang['welcome_message2'] ?? 'Here you can configure the prompt that drives your chat.');
        $combinedMessage .= '</p>';

        // Merged into the same card (not a separately bordered sub-box): a thin top
        // divider, aligned with the heading text, is enough to separate the tips list.
        $combinedMessage .= '<div style="margin: 12px 0 0 33px; padding-top: 12px; border-top: 1px solid var(--active-bg); line-height: 1.3;">';
        $combinedMessage .= '<strong>💡 '.($lang['model_info_heading'] ?? 'Model Selection Tips').':</strong><br>';
        $combinedMessage .= '<span style="padding-left: 5px;">• <strong>'.($lang['model_info_dynamic'] ?? 'All Models: All available models from your OpenAI account are shown').'</strong><br></span>';
        $combinedMessage .= '<span style="padding-left: 5px;">• <strong>'.($lang['model_info_custom'] ?? 'Custom Models: Select "Enter Custom Model" to use any OpenAI model').'</strong><br></span>';
        $combinedMessage .= '<span style="padding-left: 5px;">• <strong>'.($lang['model_info_compatibility'] ?? 'Model Validation: Model compatibility is checked when you save the prompt').'</strong><br></span>';
        $combinedMessage .= '<span style="padding-left: 5px;">• <strong>'.($lang['model_info_help'] ?? 'Need Help?').'</strong> <a href="https://platform.openai.com/docs/models" target="_blank" style="color: #007cba;">'.($lang['model_info_link'] ?? 'View all available models on OpenAI Platform').'</a></span>';
        $combinedMessage .= '</div>';
        $combinedMessage .= '</div>';

        Message::addRaw($combinedMessage);
    }

    /**
     * Fetch models from OpenAI API.
     */
    private function fetchModelsFromApi(string $apiKey): array
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                'https://api.openai.com/v1/models',
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'timeout' => 30,
                ],
            );

            $data = $response->toArray();
            $models = [];

            if (isset($data['data']) && \is_array($data['data'])) {
                foreach ($data['data'] as $model) {
                    if (isset($model['id'])) {
                        $models[$model['id']] = $model['id'];
                    }
                }
            }

            ksort($models);

            return $models;
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch OpenAI models: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Validates the given model against the Responses API via a cheap ping call.
     *
     * POST /v1/responses with {"input":"ping","max_output_tokens":16,"store":false}.
     * We consider a 2xx status as "model usable". This replaces the legacy behaviour
     * that created a throwaway Assistant on /v1/assistants.
     */
    private function validateModelViaApi(string $modelId, string $apiKey): bool
    {
        $this->logger->info(
            'validateModelViaApi called',
            [
                'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                'model' => $modelId,
            ],
        );

        try {
            $response = $this->httpClient->request(
                'POST',
                'https://api.openai.com/v1/responses',
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $modelId,
                        'input' => 'ping',
                        'max_output_tokens' => 16,
                        'store' => false,
                    ],
                    'timeout' => 30,
                ],
            );

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return true;
            }

            $this->logger->warning(
                'Model ping returned non-2xx',
                [
                    'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                    'model' => $modelId,
                    'status' => $status,
                    'response' => $this->shortenApiError($response->getContent(false)),
                ],
            );

            return false;
        } catch (\Exception $e) {
            $this->logger->error('Model validation failed for '.$modelId.': '.$e->getMessage());

            return false;
        }
    }

    private function shortenApiError(string $raw): string
    {
        $message = '';
        $data = json_decode($raw, true);
        if (\is_array($data)) {
            $message = (string) ($data['error']['message'] ?? $data['message'] ?? '');
        }
        if ('' === $message) {
            $message = trim($raw);
        }
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;
        if (mb_strlen($message) > 180) {
            $message = mb_substr($message, 0, 177).'...';
        }

        return $message;
    }

    /**
     * Get translated string with fallback.
     */
    private function getTranslatedString(string $key, string $fallback): string
    {
        $lang = $GLOBALS['TL_LANG']['tl_openai_prompts'] ?? [];

        return $lang[$key] ?? $fallback;
    }

    /**
     * Validates model compatibility with the Responses API before saving.
     */
    private function validateModelCompatibility(string $modelId, DataContainer $dc): void
    {
        $this->logger->info(
            'validateModelCompatibility called',
            [
                'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                'model' => $modelId,
                'config_id' => $dc->activeRecord->pid ?? 0,
            ],
        );

        $configId = (int) ($dc->activeRecord->pid ?? 0);
        $apiKey = $this->encryption->getApiKeyForConfig($configId);

        if (!$apiKey) {
            throw new \InvalidArgumentException($this->getTranslatedString('no_api_key_error', 'No API key found in configuration. Please check your OpenAI configuration.'));
        }

        if (!$this->validateModelViaApi($modelId, $apiKey)) {
            throw new \InvalidArgumentException(\sprintf($this->getTranslatedString('model_incompatible_error', 'The model "%s" is not compatible with the Responses API. Please select a different model.'), $modelId));
        }

        $this->logger->info(
            'Model validation successful',
            [
                'contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL),
                'model' => $modelId,
                'config_id' => $configId,
            ],
        );
    }

    /**
     * Checks if there's already a prompt record for this config and prevents creation
     * of additional ones.
     */
    private function checkSingleRecordLimitation($dc): void
    {
        $configId = null;

        if ($dc && $dc->activeRecord && $dc->activeRecord->pid) {
            $configId = (int) $dc->activeRecord->pid;
        } else {
            $request = $this->requestStack->getCurrentRequest();
            if ($request) {
                $configId = (int) ($request->get('pid') ?: $request->get('id'));
            }
        }

        if (!$configId) {
            return;
        }

        $existing = $this->connection->fetchAssociative(
            'SELECT id, name FROM tl_openai_prompts WHERE pid = ? LIMIT 1',
            [$configId],
        );

        if ($existing) {
            Message::addInfo($this->getTranslatedString('single_prompt_redirect', 'Only one prompt is allowed per configuration. You are being redirected to the existing prompt.'));
            $url = Controller::addToUrl('act=edit&id='.$existing['id']);
            Controller::redirect($url);
        }
    }
}
