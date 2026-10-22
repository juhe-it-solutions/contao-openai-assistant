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
 * The website a chat request belongs to, as far as it can be determined, without any wording.
 *
 * Shared by the English system log entry and the German or English email, which each
 * render it in their own way. All strings are already sanitised.
 *
 * Cases:
 *   A - a website root matched and has a domain set, so the domain is verified;
 *   B - a website root matched but has no domain set (it matches every host), so the
 *       request host is shown as unverified;
 *   C - no website root matched (or the host was malformed); the names of all published
 *       roots identify the installation instead.
 */
final readonly class ResolvedSite
{
    public const CASE_VERIFIED_DOMAIN = 'A';

    public const CASE_ROOT_WITHOUT_DOMAIN = 'B';

    public const CASE_NO_ROOT = 'C';

    /**
     * @param list<string> $installationRootTitles only in case C
     */
    public function __construct(
        public string $case,
        public string|null $host,
        public int|null $rootId = null,
        public string|null $rootTitle = null,
        public string|null $rootDns = null,
        public bool $useSsl = false,
        public array $installationRootTitles = [],
        public bool $moreInstallationRoots = false,
    ) {
    }
}
