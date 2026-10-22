<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Exception;

use JuheItSolutions\ContaoOpenaiAssistant\Notification\ChatFailureDetails;

/**
 * A chat turn failed in a way that may have been billed (a 5xx after delivery, a read
 * timeout, an incomplete answer).
 *
 * It replaces the plain \RuntimeException the responder threw before, so every caller that
 * catches \RuntimeException keeps working, and adds the failure details for the reporting.
 * It deliberately does NOT extend UnbilledRequestException: the billed/unbilled split decides
 * whether the daily-budget slot is handed back, and reporting must never change that.
 */
class OpenAiRequestException extends \RuntimeException implements ChatFailureDetailsAwareInterface
{
    public function __construct(
        string $message,
        private readonly ChatFailureDetails|null $details = null,
        int $code = 0,
        \Throwable|null $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getFailureDetails(): ChatFailureDetails|null
    {
        return $this->details;
    }
}
