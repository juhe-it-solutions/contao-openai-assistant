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
 * The translated website lines of one email, built from a ResolvedSite.
 *
 * The page name always comes first; an unverified domain is printed without a scheme so it
 * does not read as a link to click.
 */
final readonly class NotificationSite
{
    public function __construct(
        public string $siteLabel,
        public string $siteIntro,
        public string $pageNameLine,
        public string $domainLine,
        public string|null $installationRoots = null,
    ) {
    }
}
