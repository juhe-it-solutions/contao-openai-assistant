<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) JUHE IT-solutions
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Service;

/**
 * Deprecated wrapper kept solely for binary-compatibility with 1.x
 * customisations that type-hint against OpenAiAssistant.
 *
 * @deprecated since 2.0, will be removed in 2.1. Use {@see OpenAiResponder} instead.
 *   The OpenAI Assistants API (/v1/assistants, /v1/threads) is being sunset by
 *   OpenAI on 2026-08-26. This extension now uses the Responses + Conversations
 *   APIs via {@see OpenAiResponder}.
 */
class OpenAiAssistant extends OpenAiResponder
{
}
