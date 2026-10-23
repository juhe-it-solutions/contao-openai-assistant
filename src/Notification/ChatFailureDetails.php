<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Notification;

use JuheItSolutions\ContaoOpenaiAssistant\Exception\ChatFailureDetailsAwareInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * The privacy-safe part of a chat failure: a reason, the HTTP status and OpenAI's
 * machine-readable error code.
 *
 * OpenAI's error MESSAGE is deliberately never carried here. A validation error can quote
 * the visitor's input, so only values that cannot contain visitor data leave the responder
 * this way - and those are the only values the system log and the email ever see.
 */
final readonly class ChatFailureDetails
{
    public function __construct(
        public ChatFailureReason $reason,
        public int|null $httpStatus = null,
        public string|null $openAiErrorCode = null,
    ) {
    }

    /**
     * Classify a non-2xx answer from OpenAI.
     *
     * @param array<mixed> $responseData the decoded error body, or [] when it could not be read
     */
    public static function fromHttpStatus(int $status, array $responseData): self
    {
        $code = self::extractErrorCode($responseData);

        $reason = match (true) {
            429 === $status && ('insufficient_quota' === $code || 'insufficient_quota' === ($responseData['error']['type'] ?? null)) => ChatFailureReason::QuotaExceeded,
            429 === $status => ChatFailureReason::RateLimited,
            401 === $status, 403 === $status => ChatFailureReason::AuthenticationFailed,
            $status >= 400 && $status < 500 => ChatFailureReason::RequestRejected,
            $status >= 500 && $status < 600 => ChatFailureReason::OpenAiUnavailable,
            default => ChatFailureReason::Unknown,
        };

        return new self($reason, $status, $code);
    }

    /**
     * Find the details of a failure anywhere in an exception chain.
     *
     * The first exception that carries details wins, because wrapping (conversation creation,
     * for one) puts the specific cause into "previous". Without details, a transport error
     * anywhere in the chain still says "connection failed", which is more useful than "unknown".
     */
    public static function fromThrowable(\Throwable $e): self
    {
        $isTransport = false;

        for ($current = $e; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof ChatFailureDetailsAwareInterface && null !== ($details = $current->getFailureDetails())) {
                return $details;
            }

            if ($current instanceof TransportExceptionInterface) {
                $isTransport = true;
            }
        }

        return new self($isTransport ? ChatFailureReason::ConnectionFailed : ChatFailureReason::Unknown);
    }

    /**
     * error.code, or error.type when there is no code - only if it looks like an identifier.
     *
     * OpenAI reports "insufficient_quota" in either field depending on the endpoint. Anything
     * that is not a short identifier is dropped rather than shortened: a free-text value here
     * would be a back door for exactly the error text the email must not contain.
     *
     * @param array<mixed> $responseData
     */
    private static function extractErrorCode(array $responseData): string|null
    {
        $error = $responseData['error'] ?? null;

        if (!\is_array($error)) {
            return null;
        }

        $code = $error['code'] ?? null;

        if (!\is_string($code) || '' === $code) {
            $code = $error['type'] ?? null;
        }

        if (!\is_string($code) || 1 !== preg_match('/^[a-z0-9_.-]{1,64}$/i', $code)) {
            return null;
        }

        return $code;
    }
}
