<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches OpenAI model lists and validates model compatibility with the Responses API.
 */
class OpenAiModelCatalogService
{
    /**
     * Model ids that can never power a text chat (audio, image, video,
     * embedding, moderation, ...). /v1/models returns the whole account
     * catalog; offering these in the prompt settings only produces broken
     * configurations. Chat-capable families (gpt-*, o*, chatgpt-*) pass.
     */
    private const NON_CHAT_MODEL_PATTERN = '/^(whisper|tts|dall-e|text-embedding|(omni-)?moderation|davinci|babbage|gpt-image|sora|computer-use)|(-audio|-realtime|-transcribe|-tts)/i';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, string> model id => label
     */
    public function fetchModelOptions(string $apiKey): array
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
                    if (!isset($model['id'])) {
                        continue;
                    }

                    $id = (string) $model['id'];
                    if (1 === preg_match(self::NON_CHAT_MODEL_PATTERN, $id)) {
                        continue;
                    }

                    $models[$id] = $id;
                }
            }

            ksort($models);

            return $models;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch OpenAI models: '.$e->getMessage());

            return [];
        }
    }

    public function isModelCompatibleWithResponsesApi(string $modelId, string $apiKey): bool
    {
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

            return $status >= 200 && $status < 300;
        } catch (\Throwable $e) {
            $this->logger->warning('Model validation failed for '.$modelId.': '.$e->getMessage());

            return false;
        }
    }
}
