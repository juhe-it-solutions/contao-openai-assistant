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
 * The Responses API rejected the request because the conversation no longer
 * fits into the model's context window. Callers may retry once on a fresh
 * conversation.
 *
 * Carries the failure details for the system log and the administrator email in case the
 * retry on a fresh conversation fails as well.
 */
class ContextWindowExceededException extends \RuntimeException implements ChatFailureDetailsAwareInterface
{
    /**
     * The parameter order of \RuntimeException is kept, with the details appended, so every
     * existing "new ContextWindowExceededException($message, 0, $previous)" stays valid.
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
