<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Notification;

use JuheItSolutions\ContaoOpenaiAssistant\Exception\OpenAiRequestException;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\ChatFailureDetails;
use Symfony\Component\HttpFoundation\Request;

/**
 * Visitor data planted in every place it could leak from: the exception message (where
 * OpenAI may quote the visitor), the previous exception, and the request. The data
 * protection tests assert that none of it reaches a mail, a log message or a log context.
 */
trait HostileInputTrait
{
    private static function hostileException(): OpenAiRequestException
    {
        return new OpenAiRequestException(
            'Responses API returned HTTP 400: Invalid input "Hallo, ich bin Jane Doe" from 203.0.113.7 in conv_abc123 with sk-test',
            ChatFailureDetails::fromHttpStatus(400, ['error' => ['message' => 'Hallo, ich bin Jane Doe', 'code' => 'invalid_value']]),
            0,
            new \RuntimeException('conv_abc123 resp_def456 sk-test'),
        );
    }

    private static function createHostileRequest(): Request
    {
        $server = [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 VisitorBrowser',
            'HTTP_REFERER' => 'https://example.com/kontakt?name=jane',
            'HTTP_ACCEPT_LANGUAGE' => 'de-AT',
            'HTTP_COOKIE' => 'PHPSESSID=sess_secret42',
            'REMOTE_ADDR' => '203.0.113.7',
        ];

        $request = Request::create('https://example.com/ai-chat/send?x=1&email=jane@example.org', 'POST', ['message' => 'Hallo, ich bin Jane Doe', 'REQUEST_TOKEN' => 'csrf_token_99'], ['PHPSESSID' => 'sess_secret42'], [], $server);

        return $request;
    }

    private function assertNoVisitorData(string $haystack): void
    {
        foreach (['Jane', '203.0.113.7', 'conv_abc123', 'resp_def456', 'sk-test', 'VisitorBrowser', 'kontakt', 'x=1', 'jane@example.org', 'de-AT', 'sess_secret42', 'csrf_token_99'] as $needle) {
            $this->assertStringNotContainsString($needle, $haystack);
        }
    }
}
