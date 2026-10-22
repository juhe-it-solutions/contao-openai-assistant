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

/**
 * Why a visitor's chat message could not be answered, in words an administrator can act on.
 *
 * The values are stable and appear verbatim in the Contao system log, so administrators can
 * search for them; they also select the reason and action texts of the notification email.
 * The daily limit is deliberately not a case: it is not a failure and has its own email.
 */
enum ChatFailureReason: string
{
    case QuotaExceeded = 'quota_exceeded';
    case RateLimited = 'rate_limited';
    case AuthenticationFailed = 'authentication_failed';
    case RequestRejected = 'request_rejected';
    case OpenAiUnavailable = 'openai_unavailable';
    case ConnectionFailed = 'connection_failed';
    case IncompleteResponse = 'incomplete_response';
    case NotConfigured = 'not_configured';
    case Unknown = 'unknown';
}
