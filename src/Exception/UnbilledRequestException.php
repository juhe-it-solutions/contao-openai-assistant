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
 * The request failed without OpenAI ever processing it, so nothing was charged.
 *
 * Thrown only where that is PROVABLE, never where it is merely likely:
 *
 *   - the failure happened before any HTTP request was made (no configuration, no prompt,
 *     no usable API key);
 *   - the connection never completed, so the message was not delivered (a connect-phase
 *     transport error, after the responder's one retry);
 *   - conversation creation failed (that endpoint never invokes a model);
 *   - OpenAI rejected the Responses request with a 4xx, or with HTTP 503.
 *
 * A read timeout or a 5xx after delivery is deliberately NOT in that list: the completion
 * may well have been produced and billed, and we cannot tell from here.
 *
 * The chat controller uses this to hand back the daily-budget slot it reserved before the
 * call. Getting the classification wrong in the generous direction would reopen the hole the
 * reservation exists to close - anyone could take the chatbot offline for the day with
 * requests that cost nothing - so when in doubt, the slot stays spent.
 *
 * The optional failure details only feed the system log and the administrator email; they
 * have no say in the billing classification.
 */
class UnbilledRequestException extends \RuntimeException implements ChatFailureDetailsAwareInterface
{
    /**
     * The parameter order of \RuntimeException is kept, with the details appended, so every
     * existing "new UnbilledRequestException($message, 0, $previous)" stays valid.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        \Throwable|null $previous = null,
        private readonly ChatFailureDetails|null $details = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getFailureDetails(): ChatFailureDetails|null
    {
        return $this->details;
    }
}
