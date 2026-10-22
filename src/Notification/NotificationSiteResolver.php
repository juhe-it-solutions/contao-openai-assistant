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

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\PageFinder;
use Contao\PageModel;
use Symfony\Component\HttpFoundation\Request;

/**
 * Finds the website (root page) a chat request belongs to, for the system log and the email.
 *
 * The chat endpoint is a plain AJAX route without page context, so the root is derived
 * from the request host. The page name is the primary identifier; the domain is shown in
 * addition and only counts as verified when the root has it configured. A forged Host
 * header sent straight to the server can therefore never produce a "verified" domain.
 *
 * This runs database queries, so callers only reach it after their cheap cache checks.
 */
class NotificationSiteResolver
{
    public const MAX_TEXT_LENGTH = 100;

    public const MAX_HOST_LENGTH = 253;

    private const MAX_ROOT_TITLES = 10;

    public function __construct(
        private readonly PageFinder $pageFinder,
        private readonly ContaoFramework $framework,
    ) {
    }

    /**
     * Never throws: anything that goes wrong degrades to case C.
     */
    public function resolve(Request $request): ResolvedSite
    {
        $host = $this->validHost($request);

        if (null !== $host) {
            try {
                $root = $this->pageFinder->findRootPageForHostAndLanguage($host);
            } catch (\Throwable) {
                $root = null;
            }

            if ($root instanceof PageModel) {
                $row = $root->row();
                $dns = self::sanitise((string) ($row['dns'] ?? ''), self::MAX_HOST_LENGTH);

                return new ResolvedSite(
                    '' !== $dns ? ResolvedSite::CASE_VERIFIED_DOMAIN : ResolvedSite::CASE_ROOT_WITHOUT_DOMAIN,
                    $host,
                    (int) ($row['id'] ?? 0),
                    self::sanitise((string) ($row['title'] ?? '')),
                    '' !== $dns ? $dns : null,
                    (bool) ($row['useSSL'] ?? false),
                );
            }
        }

        [$titles, $more] = $this->loadInstallationRootTitles();

        return new ResolvedSite(ResolvedSite::CASE_NO_ROOT, $host, installationRootTitles: $titles, moreInstallationRoots: $more);
    }

    /**
     * Strip CR, LF and every other control character, then cut to length.
     *
     * Applied to every value that comes from the database or the request before it reaches
     * a mail header, a mail body or the system log.
     */
    public static function sanitise(string $value, int $maxLength = self::MAX_TEXT_LENGTH): string
    {
        // Invalid UTF-8 would make the /u pattern below fail and return null.
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        $value = (string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value);

        return mb_substr(trim($value), 0, $maxLength);
    }

    /**
     * The request host as received, or null when it is not a plain (punycode) hostname.
     */
    private function validHost(Request $request): string|null
    {
        try {
            // Symfony lower-cases the host, strips the port and throws on malformed hosts.
            $host = $request->getHost();
        } catch (\Throwable) {
            return null;
        }

        return 1 === preg_match('/^[a-z0-9.-]{1,253}$/', $host) ? $host : null;
    }

    /**
     * Page names of the published website roots: at most ten, plus whether there are more.
     *
     * @return array{list<string>, bool}
     */
    private function loadInstallationRootTitles(): array
    {
        try {
            $this->framework->initialize();

            // One more than shown, only to know whether "..." is needed.
            $pages = $this->framework->getAdapter(PageModel::class)->findPublishedRootPages(['limit' => self::MAX_ROOT_TITLES + 1]);

            $titles = [];

            foreach ($pages ?? [] as $page) {
                $titles[] = self::sanitise((string) ($page->row()['title'] ?? ''));
            }
        } catch (\Throwable) {
            return [[], false];
        }

        return [\array_slice($titles, 0, self::MAX_ROOT_TITLES), \count($titles) > self::MAX_ROOT_TITLES];
    }
}
