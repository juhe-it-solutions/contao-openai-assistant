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
use JuheItSolutions\ContaoOpenaiAssistant\Service\ChatRateLimiter;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\OpenAiResponder;
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

    private function createChatRequest(string $message): Request
    {
        $request = new Request(
            [],
            ['message' => $message, 'REQUEST_TOKEN' => 'valid-token'],
            [],
            [],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
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
