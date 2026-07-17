<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Service;

use JuheItSolutions\ContaoOpenaiAssistant\Service\OpenAiModelCatalogService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OpenAiModelCatalogServiceTest extends TestCase
{
    public function testNonChatModelsAreFilteredFromTheDropdown(): void
    {
        $modelIds = [
            // chat-capable: must stay
            'gpt-4o-mini',
            'gpt-4o',
            'gpt-5',
            'o3-mini',
            'chatgpt-4o-latest',
            // not chat-capable: must be filtered
            'whisper-1',
            'tts-1-hd',
            'dall-e-3',
            'text-embedding-3-small',
            'omni-moderation-latest',
            'gpt-image-1',
            'gpt-4o-audio-preview',
            'gpt-4o-realtime-preview',
            'gpt-4o-mini-transcribe',
            'gpt-4o-mini-tts',
            'davinci-002',
            'babbage-002',
            'computer-use-preview',
            'sora-2',
        ];

        $data = json_encode([
            'data' => array_map(static fn (string $id) => ['id' => $id], $modelIds),
        ], \JSON_THROW_ON_ERROR);

        $service = new OpenAiModelCatalogService(
            new MockHttpClient([new MockResponse($data)]),
            new NullLogger(),
        );

        $options = $service->fetchModelOptions('sk-test');

        $this->assertSame(
            ['chatgpt-4o-latest', 'gpt-4o', 'gpt-4o-mini', 'gpt-5', 'o3-mini'],
            array_keys($options),
        );
    }
}
