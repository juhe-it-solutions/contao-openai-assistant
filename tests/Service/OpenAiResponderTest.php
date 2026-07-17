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

use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\OpenAiResponder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class OpenAiResponderTest extends TestCase
{
    #[DataProvider('fileSearchCapProvider')]
    public function testResponsePayloadContainsTruncationAndFileSearchCap(array $promptExtra, int $expectedCap): void
    {
        $requests = [];
        $http = new MockHttpClient($this->createResponseFactory($requests, [
            new MockResponse('{"id": "conv_1"}'),
            new MockResponse($this->completedResponseJson('Hello!')),
        ]));

        $session = $this->createSession();
        $responder = $this->createResponder($http, $promptExtra);
        $reply = $responder->processMessage('Was kostet eine neue Webseite?', $session);

        $this->assertSame('Hello!', $reply);
        $this->assertCount(2, $requests);

        $payload = json_decode($requests[1]['body'], true);
        $this->assertSame('auto', $payload['truncation']);
        $this->assertSame('conv_1', $payload['conversation']);
        $this->assertSame(hash('sha256', $session->getId()), $payload['safety_identifier']);
        $this->assertSame('file_search', $payload['tools'][0]['type']);
        $this->assertSame(['vs_123'], $payload['tools'][0]['vector_store_ids']);
        $this->assertSame($expectedCap, $payload['tools'][0]['max_num_results']);
    }

    public static function fileSearchCapProvider(): iterable
    {
        yield 'configured value is passed through' => [['max_num_results' => 12], 12];
        yield 'pre-migration row without the column falls back to 8' => [[], 8];
        yield 'zero falls back to 8' => [['max_num_results' => 0], 8];
        yield 'values above 20 are clamped' => [['max_num_results' => 50], 20];
    }

    public function testContextWindowErrorRetriesOnceOnFreshConversation(): void
    {
        $requests = [];
        $http = new MockHttpClient($this->createResponseFactory($requests, [
            new MockResponse('{"id": "conv_1"}'),
            new MockResponse(
                '{"error": {"message": "Your input exceeds the context window of this model. Please adjust your input and try again.", "code": null}}',
                ['http_code' => 400],
            ),
            new MockResponse('{"id": "conv_2"}'),
            new MockResponse($this->completedResponseJson('Fresh answer')),
        ]));

        $session = $this->createSession();
        $responder = $this->createResponder($http, []);
        $reply = $responder->processMessage('Frage', $session);

        $this->assertSame('Fresh answer', $reply);
        $this->assertCount(4, $requests);
        $this->assertStringEndsWith('/conversations', $requests[2]['url']);
        $this->assertSame('conv_2', $session->get('openai_conversation_id'));

        $retryPayload = json_decode($requests[3]['body'], true);
        $this->assertSame('conv_2', $retryPayload['conversation']);
        $this->assertSame('Frage', $retryPayload['input']);
    }

    public function testConversationNotFoundRetriesOnceOnFreshConversation(): void
    {
        $requests = [];
        $http = new MockHttpClient($this->createResponseFactory($requests, [
            new MockResponse('{"id": "conv_1"}'),
            new MockResponse(
                '{"error": {"message": "Conversation with id \'conv_1\' not found.", "type": "invalid_request_error"}}',
                ['http_code' => 404],
            ),
            new MockResponse('{"id": "conv_2"}'),
            new MockResponse($this->completedResponseJson('Recovered')),
        ]));

        $session = $this->createSession();
        $responder = $this->createResponder($http, []);
        $reply = $responder->processMessage('Frage', $session);

        $this->assertSame('Recovered', $reply);
        $this->assertCount(4, $requests);
        $this->assertSame('conv_2', $session->get('openai_conversation_id'));
    }

    public function testConversationBoundToAnotherConfigIsDiscarded(): void
    {
        $requests = [];
        $http = new MockHttpClient($this->createResponseFactory($requests, [
            new MockResponse('{"id": "conv_new"}'),
            new MockResponse($this->completedResponseJson('Fresh config answer')),
        ]));

        $session = $this->createSession();
        $session->set('openai_conversation_id', 'conv_old');
        $session->set('openai_conversation_config_id', 99);

        $responder = $this->createResponder($http, []);
        $reply = $responder->processMessage('Frage', $session);

        $this->assertSame('Fresh config answer', $reply);
        // The stale conversation is never used: a new one is created up front.
        $this->assertStringEndsWith('/conversations', $requests[0]['url']);
        $this->assertSame('conv_new', json_decode($requests[1]['body'], true)['conversation']);
        $this->assertSame(1, $session->get('openai_conversation_config_id'));
    }

    public function testPreUpgradeConversationWithoutBindingIsAdopted(): void
    {
        $requests = [];
        $http = new MockHttpClient($this->createResponseFactory($requests, [
            new MockResponse($this->completedResponseJson('Continued answer')),
        ]));

        // A session from a version before the config binding existed: it has a
        // conversation id but no bound config id. It must be kept, not reset.
        $session = $this->createSession();
        $session->set('openai_conversation_id', 'conv_pre_upgrade');

        $responder = $this->createResponder($http, []);
        $reply = $responder->processMessage('Frage', $session);

        $this->assertSame('Continued answer', $reply);
        $this->assertCount(1, $requests);
        $this->assertSame('conv_pre_upgrade', json_decode($requests[0]['body'], true)['conversation']);
        $this->assertSame(1, $session->get('openai_conversation_config_id'));
    }

    public function testRejectedSamplingParamIsStrippedAndRemembered(): void
    {
        $requests = [];
        $http = new MockHttpClient($this->createResponseFactory($requests, [
            new MockResponse('{"id": "conv_1"}'),
            new MockResponse(
                '{"error": {"message": "Unsupported parameter: \'temperature\' is not supported with this model.", "type": "invalid_request_error", "param": "temperature", "code": null}}',
                ['http_code' => 400],
            ),
            new MockResponse($this->completedResponseJson('Answer without temperature')),
            new MockResponse($this->completedResponseJson('Second answer')),
        ]));

        $session = $this->createSession();
        $responder = $this->createResponder($http, [], new ArrayAdapter());

        $this->assertSame('Answer without temperature', $responder->processMessage('Frage 1', $session));
        $this->assertCount(3, $requests);

        $firstPayload = json_decode($requests[1]['body'], true);
        $this->assertArrayHasKey('temperature', $firstPayload);

        $retryPayload = json_decode($requests[2]['body'], true);
        $this->assertArrayNotHasKey('temperature', $retryPayload);
        $this->assertArrayHasKey('top_p', $retryPayload);

        // The rejection is cached: the next turn skips the parameter up front
        // without paying an extra round-trip.
        $this->assertSame('Second answer', $responder->processMessage('Frage 2', $session));
        $this->assertCount(4, $requests);
        $this->assertArrayNotHasKey('temperature', json_decode($requests[3]['body'], true));
    }

    #[DataProvider('transientStatusProvider')]
    public function testTransientApiErrorsAreRetriedOnce(int $statusCode): void
    {
        $requests = [];
        $http = new MockHttpClient($this->createResponseFactory($requests, [
            new MockResponse('{"id": "conv_1"}'),
            new MockResponse('{"error": {"message": "Please slow down"}}', ['http_code' => $statusCode]),
            new MockResponse($this->completedResponseJson('Recovered')),
        ]));

        $responder = $this->createResponder($http, []);
        $reply = $responder->processMessage('Frage', $this->createSession());

        $this->assertSame('Recovered', $reply);
        $this->assertCount(3, $requests);
    }

    public static function transientStatusProvider(): iterable
    {
        yield 'rate limited (429)' => [429];
        yield 'service unavailable (503)' => [503];
    }

    public function testHistoryIsFetchedNewestFirstAndReturnedOldestFirst(): void
    {
        $requests = [];
        $itemsJson = json_encode([
            'data' => [
                ['type' => 'message', 'role' => 'assistant', 'created_at' => 200, 'content' => [['type' => 'output_text', 'text' => 'Second answer']]],
                ['type' => 'message', 'role' => 'user', 'created_at' => 150, 'content' => [['type' => 'input_text', 'text' => 'Second question']]],
                ['type' => 'file_search_call', 'created_at' => 120],
                ['type' => 'message', 'role' => 'assistant', 'created_at' => 100, 'content' => [['type' => 'output_text', 'text' => 'First answer']]],
                ['type' => 'message', 'role' => 'user', 'created_at' => 50, 'content' => [['type' => 'input_text', 'text' => 'First question']]],
            ],
        ], \JSON_THROW_ON_ERROR);

        $http = new MockHttpClient($this->createResponseFactory($requests, [
            new MockResponse($itemsJson),
        ]));

        $responder = $this->createResponder($http, []);
        $history = $responder->getConversationHistory('conv_1', 'sk-test');

        $this->assertStringContainsString('order=desc', $requests[0]['url']);
        $this->assertSame(
            ['First question', 'First answer', 'Second question', 'Second answer'],
            array_column($history, 'content'),
        );
    }

    public function testOtherApiErrorsAreNotRetried(): void
    {
        $requests = [];
        $http = new MockHttpClient($this->createResponseFactory($requests, [
            new MockResponse('{"id": "conv_1"}'),
            new MockResponse('{"error": {"message": "Invalid model", "code": "model_not_found"}}', ['http_code' => 400]),
        ]));

        $session = $this->createSession();
        $responder = $this->createResponder($http, []);

        try {
            $responder->processMessage('Frage', $session);
            $this->fail('Expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Invalid model', $e->getMessage());
        }

        // No retry: only the conversation create and the single failed call.
        $this->assertCount(2, $requests);
        $this->assertSame('conv_1', $session->get('openai_conversation_id'));
    }

    private function completedResponseJson(string $text): string
    {
        return json_encode(
            [
                'id' => 'resp_1',
                'status' => 'completed',
                'output' => [
                    [
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [
                            ['type' => 'output_text', 'text' => $text],
                        ],
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function createResponseFactory(array &$requests, array $responses): \Closure
    {
        return static function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'body' => $options['body'] ?? '',
            ];

            if ([] === $responses) {
                throw new \LogicException('No mock response queued for '.$method.' '.$url);
            }

            return array_shift($responses);
        };
    }

    private function createResponder(MockHttpClient $http, array $promptExtra, CacheItemPoolInterface|null $cache = null): OpenAiResponder
    {
        $configRow = [
            'id' => 1,
            'api_key' => 'encrypted',
            'vector_store_id' => 'vs_123',
        ];

        $promptRow = array_merge(
            [
                'name' => 'Default',
                'model' => 'gpt-4o-mini',
                'system_instructions' => 'Sei hilfreich.',
                'max_tokens' => 500,
                'temperature' => 0.7,
                'top_p' => 1.0,
                'status' => 'active',
            ],
            $promptExtra,
        );

        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAssociative')
            ->willReturnCallback(
                static fn (string $sql) => str_contains($sql, 'tl_openai_config') ? $configRow : $promptRow,
            )
        ;

        $encryption = $this->createMock(EncryptionService::class);
        $encryption
            ->method('getApiKeyForConfig')
            ->willReturn('sk-test')
        ;

        return new OpenAiResponder($http, new NullLogger(), $connection, $encryption, $cache);
    }

    private function createSession(): Session
    {
        return new Session(new MockArraySessionStorage());
    }
}
