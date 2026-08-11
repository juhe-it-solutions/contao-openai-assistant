<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Controller;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use JuheItSolutions\ContaoOpenaiAssistant\Controller\AiChatController;
use JuheItSolutions\ContaoOpenaiAssistant\Exception\UnbilledRequestException;
use JuheItSolutions\ContaoOpenaiAssistant\Service\ChatRateLimiter;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\OpenAiResponder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

class AiChatControllerTest extends TestCase
{
    /**
     * The endpoint is anonymous and billed per input token, while the IP and daily
     * limits count messages - so the length check has to reject before anything
     * else is spent.
     */
    public function testRejectsAMessageAboveTheLengthLimit(): void
    {
        $responder = $this->createMock(OpenAiResponder::class);
        $responder->expects($this->never())->method('processMessage');
        $responder->expects($this->never())->method('getActiveConfig');

        $rateLimiter = $this->createMock(ChatRateLimiter::class);
        $rateLimiter->expects($this->never())->method('acceptClientIp');
        $rateLimiter->expects($this->never())->method('acceptConfigDaily');

        $response = $this->createController($responder, $rateLimiter)->send(
            $this->createChatRequest(str_repeat('a', AiChatController::MAX_MESSAGE_LENGTH + 1)),
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString(
            (string) AiChatController::MAX_MESSAGE_LENGTH,
            (string) $response->getContent(),
            'The error should tell the visitor what the limit is.',
        );
    }

    /**
     * Multi-byte characters must not shorten the limit - the check counts
     * characters, not bytes.
     */
    public function testAcceptsAMessageAtTheLimitIncludingUmlauts(): void
    {
        $responder = $this->createMock(OpenAiResponder::class);
        $responder->expects($this->once())->method('processMessage')->willReturn('ok');
        $responder->method('getActiveConfig')->willReturn(null);

        $rateLimiter = $this->createMock(ChatRateLimiter::class);
        $rateLimiter->method('acceptClientIp')->willReturn(true);

        $response = $this->createController($responder, $rateLimiter)->send(
            $this->createChatRequest(str_repeat('ü', AiChatController::MAX_MESSAGE_LENGTH)),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testStillRejectsAnEmptyMessage(): void
    {
        $responder = $this->createMock(OpenAiResponder::class);
        $responder->expects($this->never())->method('processMessage');

        $response = $this->createController($responder, $this->createMock(ChatRateLimiter::class))->send(
            $this->createChatRequest('   '),
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * The slot is reserved BEFORE the paid call, so a request that is over the cap never
     * reaches OpenAI at all.
     */
    public function testARequestOverTheDailyCapIsRejectedWithoutCallingOpenAi(): void
    {
        $responder = $this->createMock(OpenAiResponder::class);
        $responder->expects($this->never())->method('processMessage');
        $responder->method('getActiveConfig')->willReturn(['id' => 1, 'chat_daily_limit' => 5]);

        $rateLimiter = $this->createMock(ChatRateLimiter::class);
        $rateLimiter->method('acceptClientIp')->willReturn(true);
        $rateLimiter->method('reserveConfigDaily')->willReturn(false);
        $rateLimiter->expects($this->never())->method('releaseConfigDaily');

        $response = $this->createController($responder, $rateLimiter)->send($this->createChatRequest('Hallo'));

        $this->assertSame(429, $response->getStatusCode());
    }

    /**
     * A failure that provably never reached OpenAI hands the slot back - otherwise a wrong
     * API key or an outage would let anyone burn the day's budget with requests that cost
     * nothing, and take the chatbot offline until midnight.
     */
    public function testAnUnbilledFailureReturnsTheReservedSlot(): void
    {
        $responder = $this->createMock(OpenAiResponder::class);
        $responder->method('getActiveConfig')->willReturn(['id' => 1, 'chat_daily_limit' => 5]);
        $responder
            ->method('processMessage')
            ->willThrowException(new UnbilledRequestException('No valid API key available'))
        ;

        $rateLimiter = $this->createMock(ChatRateLimiter::class);
        $rateLimiter->method('acceptClientIp')->willReturn(true);
        $rateLimiter->method('reserveConfigDaily')->willReturn(true);
        $rateLimiter->expects($this->once())->method('releaseConfigDaily')->with(1, 5);

        $response = $this->createController($responder, $rateLimiter)->send($this->createChatRequest('Hallo'));

        $this->assertSame(500, $response->getStatusCode(), 'The visitor sees the same error either way.');
    }

    /**
     * The other direction, and the one that must not be generous: a failure that may have
     * produced a billed completion keeps its slot.
     */
    public function testAPossiblyBilledFailureKeepsTheReservedSlot(): void
    {
        $responder = $this->createMock(OpenAiResponder::class);
        $responder->method('getActiveConfig')->willReturn(['id' => 1, 'chat_daily_limit' => 5]);
        $responder
            ->method('processMessage')
            ->willThrowException(new \RuntimeException('Responses API returned HTTP 500'))
        ;

        $rateLimiter = $this->createMock(ChatRateLimiter::class);
        $rateLimiter->method('acceptClientIp')->willReturn(true);
        $rateLimiter->method('reserveConfigDaily')->willReturn(true);
        $rateLimiter->expects($this->never())->method('releaseConfigDaily');

        $response = $this->createController($responder, $rateLimiter)->send($this->createChatRequest('Hallo'));

        $this->assertSame(500, $response->getStatusCode());
    }

    /**
     * Accept-Language is an ordered preference list, so only the first tag may decide.
     * A German entry ranked below English is a fallback, not a request for German.
     */
    #[DataProvider('provideAcceptLanguageHeaders')]
    public function testAnswersInTheVisitorsFirstRankedLanguage(string $header, string $expected): void
    {
        $responder = $this->createMock(OpenAiResponder::class);
        $responder->expects($this->never())->method('processMessage');

        $response = $this->createController($responder, $this->createMock(ChatRateLimiter::class))->send(
            $this->createChatRequest(str_repeat('a', AiChatController::MAX_MESSAGE_LENGTH + 1), $header),
        );

        $this->assertStringContainsString($expected, (string) $response->getContent());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideAcceptLanguageHeaders(): iterable
    {
        // The regression: German ranked third used to win over English ranked first.
        yield 'German ranked below English stays English' => ['en-US,en;q=0.9,de-DE;q=0.8', 'Your message is too long'];
        yield 'German first' => ['de-DE,de;q=0.9,en;q=0.8', 'Ihre Nachricht ist zu lang'];
        yield 'bare German' => ['de', 'Ihre Nachricht ist zu lang'];
        yield 'German with a quality value' => ['de;q=0.9', 'Ihre Nachricht ist zu lang'];
        yield 'uppercase German' => ['DE-AT', 'Ihre Nachricht ist zu lang'];
        yield 'no header' => ['', 'Your message is too long'];
        // "de" must be a whole tag, not a prefix of an unrelated language code.
        yield 'Delaware-style prefix is not German' => ['den', 'Your message is too long'];
    }

    private function createChatRequest(string $message, string|null $acceptLanguage = null): Request
    {
        $server = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

        if (null !== $acceptLanguage) {
            $server['HTTP_ACCEPT_LANGUAGE'] = $acceptLanguage;
        }

        $request = new Request(
            [],
            ['message' => $message, 'REQUEST_TOKEN' => 'valid-token'],
            [],
            [],
            [],
            $server,
        );

        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage(),
        ));

        return $request;
    }

    private function createController(OpenAiResponder $responder, ChatRateLimiter $rateLimiter): AiChatController
    {
        $csrfTokenManager = $this->createMock(ContaoCsrfTokenManager::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        return new AiChatController(
            $responder,
            $this->createMock(EncryptionService::class),
            $this->createMock(ContaoFramework::class),
            $csrfTokenManager,
            'REQUEST_TOKEN',
            new NullLogger(),
            $rateLimiter,
        );
    }
}
