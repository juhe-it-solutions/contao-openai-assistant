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
 * The Responses API rejected the request because the referenced conversation
 * no longer exists (deleted, expired, or created under a different OpenAI
 * account after an API key change). Callers may retry once on a fresh
 * conversation.
 */
class ConversationNotFoundException extends \RuntimeException
{
}
