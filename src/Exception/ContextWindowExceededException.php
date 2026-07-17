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

/**
 * The Responses API rejected the request because the conversation no longer
 * fits into the model's context window. Callers may retry once on a fresh
 * conversation.
 */
class ContextWindowExceededException extends \RuntimeException
{
}
