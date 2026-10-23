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
use JuheItSolutions\ContaoOpenaiAssistant\Exception\UnbilledRequestException;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\ChatFailureDetails;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\ChatFailureReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;

class ChatFailureDetailsTest extends TestCase
{
    #[DataProvider('provideStatusMappings')]
    public function testMapsTheHttpStatusToAReason(int $status, array $body, ChatFailureReason $expected): void
    {
        $details = ChatFailureDetails::fromHttpStatus($status, $body);

        $this->assertSame($expected, $details->reason);
        $this->assertSame($status, $details->httpStatus);
    }

    public static function provideStatusMappings(): iterable
    {
        yield '429 with insufficient_quota as code' => [429, ['error' => ['code' => 'insufficient_quota']], ChatFailureReason::QuotaExceeded];
        yield '429 with insufficient_quota as type' => [429, ['error' => ['type' => 'insufficient_quota', 'code' => null]], ChatFailureReason::QuotaExceeded];
        yield '429 otherwise' => [429, ['error' => ['code' => 'rate_limit_exceeded']], ChatFailureReason::RateLimited];
        yield '429 without a body' => [429, [], ChatFailureReason::RateLimited];
        yield '401' => [401, [], ChatFailureReason::AuthenticationFailed];
        yield '403' => [403, [], ChatFailureReason::AuthenticationFailed];
        yield '400' => [400, [], ChatFailureReason::RequestRejected];
        yield '404' => [404, [], ChatFailureReason::RequestRejected];
        yield '422' => [422, [], ChatFailureReason::RequestRejected];
        yield '500' => [500, [], ChatFailureReason::OpenAiUnavailable];
        yield '502' => [502, [], ChatFailureReason::OpenAiUnavailable];
        yield '503' => [503, [], ChatFailureReason::OpenAiUnavailable];
        yield '520' => [520, [], ChatFailureReason::OpenAiUnavailable];
        yield '524' => [524, [], ChatFailureReason::OpenAiUnavailable];
    }

    #[DataProvider('provideErrorCodes')]
    public function testOnlyIdentifierLikeErrorCodesAreKept(mixed $code, string|null $expected): void
    {
        $details = ChatFailureDetails::fromHttpStatus(400, ['error' => ['code' => $code, 'message' => 'Hello, my name is Jane']]);

        $this->assertSame($expected, $details->openAiErrorCode);
    }

    public static function provideErrorCodes(): iterable
    {
        yield 'identifier' => ['insufficient_quota', 'insufficient_quota'];
        yield 'with dots and dashes' => ['model.not-found', 'model.not-found'];
        yield 'spaces' => ['invalid request', null];
        yield 'html' => ['<b>x</b>', null];
        yield 'over 64 characters' => [str_repeat('a', 65), null];
        yield 'exactly 64 characters' => [str_repeat('a', 64), str_repeat('a', 64)];
        yield 'not a string' => [42, null];
        yield 'missing' => [null, null];
    }

    public function testQuotaTypeWinsEvenWhenAnotherCodeIsPresent(): void
    {
        $details = ChatFailureDetails::fromHttpStatus(429, ['error' => ['code' => 'billing_limit', 'type' => 'insufficient_quota']]);

        $this->assertSame(ChatFailureReason::QuotaExceeded, $details->reason);
        $this->assertSame('billing_limit', $details->openAiErrorCode);
    }

    public function testTheErrorMessageIsNeverUsedAsTheCode(): void
    {
        $details = ChatFailureDetails::fromHttpStatus(400, ['error' => ['message' => 'invalid_value']]);

        $this->assertNull($details->openAiErrorCode);
    }

    public function testFindsDetailsOnAWrappedPreviousException(): void
    {
        $inner = new OpenAiRequestException('inner', ChatFailureDetails::fromHttpStatus(429, ['error' => ['code' => 'insufficient_quota']]));
        $outer = new \RuntimeException('outer', 0, $inner);

        $details = ChatFailureDetails::fromThrowable($outer);

        $this->assertSame(ChatFailureReason::QuotaExceeded, $details->reason);
        $this->assertSame(429, $details->httpStatus);
        $this->assertSame('insufficient_quota', $details->openAiErrorCode);
    }

    public function testTheOutermostDetailsWin(): void
    {
        $inner = new OpenAiRequestException('inner', new ChatFailureDetails(ChatFailureReason::IncompleteResponse));
        $outer = new UnbilledRequestException('outer', 0, $inner, new ChatFailureDetails(ChatFailureReason::NotConfigured));

        $this->assertSame(ChatFailureReason::NotConfigured, ChatFailureDetails::fromThrowable($outer)->reason);
    }

    public function testAnExceptionWithoutDetailsDefersToItsPrevious(): void
    {
        $inner = new OpenAiRequestException('inner', new ChatFailureDetails(ChatFailureReason::IncompleteResponse));
        $outer = new UnbilledRequestException('outer', 0, $inner);

        $this->assertSame(ChatFailureReason::IncompleteResponse, ChatFailureDetails::fromThrowable($outer)->reason);
    }

    public function testATransportExceptionInTheChainMeansConnectionFailed(): void
    {
        $outer = new UnbilledRequestException('Failed to create conversation', 0, new TransportException('Could not resolve host'));

        $details = ChatFailureDetails::fromThrowable($outer);

        $this->assertSame(ChatFailureReason::ConnectionFailed, $details->reason);
        $this->assertNull($details->httpStatus);
    }

    public function testAnUnrelatedExceptionIsUnknown(): void
    {
        $this->assertSame(ChatFailureReason::Unknown, ChatFailureDetails::fromThrowable(new \LogicException('boom'))->reason);
    }
}
