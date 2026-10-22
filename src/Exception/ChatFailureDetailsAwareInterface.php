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
 * An exception that knows the privacy-safe classification of the chat failure it reports.
 *
 * The details are read by the system log entry and the administrator email; the exception
 * message itself never is, because it can contain OpenAI's raw error text.
 */
interface ChatFailureDetailsAwareInterface
{
    public function getFailureDetails(): ChatFailureDetails|null;
}
