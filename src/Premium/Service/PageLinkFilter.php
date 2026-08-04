<?php

/*
 * This file is part of the JUHE Contao OpenAI Assistant premium add-on.
 *
 * (c) JUHE IT-solutions
 *
 * @license Proprietary - see LICENSE-PREMIUM. Usage of the premium add-on
 *          requires a valid premium subscription from JUHE IT-solutions.
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Premium\Service;

/**
 * Decides which of the collected links actually belong into a page document.
 *
 * Two independent stages, both operating on the whole corpus of one sync run:
 *
 *   applyPolicy() - the operator's rules: allowed link types, site-specific
 *                        exclude patterns, and the hard rule that a link to a
 *                        protected page is never advertised.
 *   removeBoilerplate() - the markup-agnostic safety net: a link that appears on a
 *                        large share of all pages is site chrome (footer, teaser
 *                        rail, language switcher) no matter what the theme's markup
 *                        looks like. Same statistical guarantee as BoilerplateFilter
 *                        uses for text: unique content can never reach that
 *                        frequency, so it can never be removed.
 */
class PageLinkFilter
{
    /**
     * Below this number of pages there is not enough signal to tell chrome from
     * content, so the frequency stage is a no-op.
     */
    public const MIN_PAGES = 4;

    /**
     * A link must appear on at least this many pages before frequency can drop it,
     * whatever the fraction says. Guards small corpora.
     */
    public const MIN_OCCURRENCES = 3;

    /**
     * Fraction of pages a link must reach to count as chrome, per link type.
     *
     * Documents get a much higher threshold on purpose: a PDF linked from many
     * pages is usually genuinely important (price list, terms, catalogue), whereas
     * a page or social-media link on every page is almost always chrome.
     */
    private const THRESHOLDS = [
        PageLink::TYPE_PAGE => 0.5,
        PageLink::TYPE_EXTERNAL => 0.5,
        PageLink::TYPE_MAILTO => 0.6,
        PageLink::TYPE_TEL => 0.6,
        PageLink::TYPE_FILE => 0.9,
    ];

    /**
     * Bounds how much work a hostile or misconfigured exclude list can cause.
     */
    private const MAX_PATTERNS = 100;

    private const MAX_PATTERN_LENGTH = 500;

    /**
     * Wildcards allowed per exclude pattern; see compilePatterns().
     */
    private const MAX_WILDCARDS = 10;

    /**
     * @param array<int, list<PageLink>> $linksByPage
     * @param list<string>|null          $allowedTypes    NULL = not configured, every type is allowed
     *                                                    (this is what an installation that has never
     *                                                    saved the field sees). An EMPTY list is an
     *                                                    explicit "no link types", i.e. no links at all -
     *                                                    anything else would contradict the checkboxes
     * @param list<string>               $excludePatterns glob patterns matched against the URL
     * @param array<string, true>        $protectedUrls   protected page targets, keyed by
     *                                                    PageLink::comparisonKey()
     *
     * @return array{links: array<int, list<PageLink>>, dropped: int}
     */
    public function applyPolicy(array $linksByPage, array|null $allowedTypes, array $excludePatterns, array $protectedUrls = []): array
    {
        $patterns = $this->compilePatterns($excludePatterns);
        $types = null;

        if (null !== $allowedTypes) {
            $types = [];

            foreach ($allowedTypes as $type) {
                if (\in_array($type, PageLink::TYPES, true)) {
                    $types[$type] = true;
                }
            }
        }

        $result = [];
        $dropped = 0;

        foreach ($linksByPage as $pageId => $links) {
            $kept = [];

            foreach ($links as $link) {
                if (null !== $types && !isset($types[$link->type])) {
                    ++$dropped;

                    continue;
                }

                // Compared on a loose key: a members-only page linked as
                // "https://www.example.com/intern/" but indexed as
                // "http://example.com/intern" is the same page, and missing that
                // would advertise a members-only URL.
                if (isset($protectedUrls[PageLink::comparisonKey($link->url)])) {
                    ++$dropped;

                    continue;
                }

                if ($this->matchesAny($link->url, $patterns)) {
                    ++$dropped;

                    continue;
                }

                $kept[] = $link;
            }

            if ([] !== $kept) {
                $result[$pageId] = $kept;
            }
        }

        return ['links' => $result, 'dropped' => $dropped];
    }

    /**
     * @param array<int, list<PageLink>> $linksByPage
     * @param int                        $totalPages  number of pages in the sync scope; 0 = derive from
     *                                                $linksByPage. Passing the real scope size matters:
     *                                                using only the pages that HAVE links would shrink
     *                                                the denominator and let the filter delete content
     *                                                links on sites where few pages link anywhere at all
     *
     * @return array{links: array<int, list<PageLink>>, dropped: int, samples: list<string>}
     */
    public function removeBoilerplate(array $linksByPage, int $totalPages = 0): array
    {
        $pageCount = max($totalPages, \count($linksByPage));

        if ($pageCount < self::MIN_PAGES) {
            return ['links' => $linksByPage, 'dropped' => 0, 'samples' => []];
        }

        /** @var array<string, int> $frequency */
        $frequency = [];
        /** @var array<string, string> $typeByHash */
        $typeByHash = [];

        foreach ($linksByPage as $links) {
            $seen = [];

            foreach ($links as $link) {
                $hash = $link->urlHash();

                if (isset($seen[$hash])) {
                    continue;
                }

                $seen[$hash] = true;
                $frequency[$hash] = ($frequency[$hash] ?? 0) + 1;
                $typeByHash[$hash] ??= $link->type;
            }
        }

        $boilerplate = [];

        foreach ($frequency as $hash => $count) {
            $threshold = self::THRESHOLDS[$typeByHash[$hash] ?? PageLink::TYPE_EXTERNAL] ?? 0.5;
            $limit = max(self::MIN_OCCURRENCES, (int) ceil($threshold * $pageCount));

            if ($count >= $limit) {
                $boilerplate[$hash] = true;
            }
        }

        if ([] === $boilerplate) {
            return ['links' => $linksByPage, 'dropped' => 0, 'samples' => []];
        }

        $result = [];
        $dropped = 0;
        $samples = [];

        foreach ($linksByPage as $pageId => $links) {
            $kept = [];

            foreach ($links as $link) {
                if (isset($boilerplate[$link->urlHash()])) {
                    ++$dropped;

                    if (\count($samples) < 10 && !\in_array($link->url, $samples, true)) {
                        $samples[] = $link->url;
                    }

                    continue;
                }

                $kept[] = $link;
            }

            if ([] !== $kept) {
                $result[$pageId] = $kept;
            }
        }

        return ['links' => $result, 'dropped' => $dropped, 'samples' => $samples];
    }

    /**
     * Turn the operator's glob list into anchored regular expressions.
     *
     * A hand-written converter rather than fnmatch(): fnmatch is not available on
     * every platform, and preg_quote() guarantees that nothing in an admin-provided
     * pattern can be interpreted as a regular expression.
     *
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    private function compilePatterns(array $patterns): array
    {
        $compiled = [];

        foreach ($patterns as $pattern) {
            // Contao 5.3/5.7 encode every posted value on save: "#", "=", "(", ")",
            // "<", ">", "\", "'" and '"' become numeric entities (Input::encodeInput,
            // InputEncodingMode::encodeAll - the ampersand is left alone). Contao 6
            // dropped input encoding and stores the raw value instead. Without this
            // decode, "*?file=*" would be stored as "*?file&#61;*" and never match a
            // real URL on the 5.x line, and a "# note" line would not be recognised
            // as a comment - while both work on 6.0.
            $pattern = trim(html_entity_decode($pattern, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ('' === $pattern || str_starts_with($pattern, '#') || mb_strlen($pattern) > self::MAX_PATTERN_LENGTH) {
                continue;
            }

            // Collapse "**" and bound the number of wildcards: many ".*" groups in
            // one expression are the classic catastrophic-backtracking shape. PCRE's
            // own backtrack limit already prevents a hang (preg_match then returns
            // false, i.e. "no match"), but a pattern that burns the full limit for
            // every one of tens of thousands of links would still slow a sync down.
            $pattern = preg_replace('/\*{2,}/', '*', $pattern) ?? $pattern;

            if (substr_count($pattern, '*') > self::MAX_WILDCARDS) {
                continue;
            }

            $quoted = preg_quote($pattern, '#');
            $regex = '#^'.str_replace(['\*', '\?'], ['.*', '.'], $quoted).'$#iu';
            $compiled[] = $regex;

            if (\count($compiled) >= self::MAX_PATTERNS) {
                break;
            }
        }

        return $compiled;
    }

    /**
     * @param list<string> $patterns
     */
    private function matchesAny(string $url, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (1 === preg_match($pattern, $url)) {
                return true;
            }
        }

        return false;
    }
}
