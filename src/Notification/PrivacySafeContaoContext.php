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

use Contao\CoreBundle\Monolog\ContaoContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * The only place where the failure reporting creates a ContaoContext.
 *
 * Contao's ContaoTableProcessor fills every context field that is still empty: the
 * visitor's user agent as "browser", a logged-in member's username, and the full request
 * URL including the query string. None of that may reach the system log for a failed chat
 * message, so all three are set here explicitly - and because every call site goes through
 * this factory, no call site can forget one.
 */
final class PrivacySafeContaoContext
{
    private const NOT_AVAILABLE = 'N/A';

    public static function create(string $func, string $action, Request|null $request): ContaoContext
    {
        return self::createForPath($func, $action, null !== $request ? self::path($request) : null);
    }

    /**
     * For log entries written after the request is gone (mail sending on kernel.terminate):
     * the caller keeps the path from self::path() and passes it here.
     */
    public static function createForPath(string $func, string $action, string|null $path): ContaoContext
    {
        $context = new ContaoContext($func, $action);
        $context->setBrowser(self::NOT_AVAILABLE);
        $context->setUsername(self::NOT_AVAILABLE);
        $context->setUri(null !== $path && '' !== $path ? $path : self::NOT_AVAILABLE);

        return $context;
    }

    /**
     * The request path without host and query string; the query string can carry personal data.
     */
    public static function path(Request $request): string
    {
        return $request->getBaseUrl().$request->getPathInfo();
    }
}
