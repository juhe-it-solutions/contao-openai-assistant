<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Premium\Service;

use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageLink;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageLinkFilter;
use PHPUnit\Framework\TestCase;

class PageLinkFilterTest extends TestCase
{
    private static function link(string $url, string $type = PageLink::TYPE_PAGE): PageLink
    {
        return new PageLink($url, 'Label', $type);
    }

    /**
     * @param list<PageLink> $extra
     *
     * @return array<int, list<PageLink>>
     */
    private static function corpus(int $pages, array $shared, array $extra = []): array
    {
        $result = [];

        for ($i = 1; $i <= $pages; ++$i) {
            $links = $shared;
            $links[] = self::link('https://example.com/unique-'.$i.'.html');
            $result[$i] = array_merge($links, $extra[$i] ?? []);
        }

        return $result;
    }

    public function testDropsLinksRepeatedOnMostPages(): void
    {
        $footer = self::link('https://example.com/impressum.html');
        $result = (new PageLinkFilter())->removeBoilerplate(self::corpus(10, [$footer]));

        foreach ($result['links'] as $links) {
            foreach ($links as $link) {
                $this->assertStringNotContainsString('impressum', $link->url);
            }
        }

        $this->assertSame(10, $result['dropped']);
        $this->assertSame(['https://example.com/impressum.html'], $result['samples']);
    }

    public function testKeepsUniqueContentLinks(): void
    {
        $result = (new PageLinkFilter())->removeBoilerplate(self::corpus(10, []));

        $this->assertCount(10, $result['links']);
        $this->assertSame(0, $result['dropped']);
    }

    /**
     * A document linked from many pages is usually genuinely important, so it needs
     * a much higher frequency before it counts as chrome.
     */
    public function testKeepsDocumentsLinkedFromManyPages(): void
    {
        $pdf = self::link('https://example.com/files/preisliste.pdf', PageLink::TYPE_FILE);
        $extra = [];

        // 6 of 10 pages link the PDF - above the 0.5 page threshold, below 0.9.
        for ($i = 1; $i <= 6; ++$i) {
            $extra[$i] = [$pdf];
        }

        $result = (new PageLinkFilter())->removeBoilerplate(self::corpus(10, [], $extra));

        $kept = 0;

        foreach ($result['links'] as $links) {
            foreach ($links as $link) {
                if (PageLink::TYPE_FILE === $link->type) {
                    ++$kept;
                }
            }
        }

        $this->assertSame(6, $kept);
        $this->assertSame(0, $result['dropped']);
    }

    public function testDropsDocumentLinkedFromEveryPage(): void
    {
        $pdf = self::link('https://example.com/files/agb.pdf', PageLink::TYPE_FILE);
        $result = (new PageLinkFilter())->removeBoilerplate(self::corpus(10, [$pdf]));

        foreach ($result['links'] as $links) {
            foreach ($links as $link) {
                $this->assertNotSame(PageLink::TYPE_FILE, $link->type);
            }
        }
    }

    /**
     * The denominator must be the whole sync scope. Otherwise a site where only a
     * handful of pages link anywhere would see those few content links deleted as
     * "chrome" simply because they are frequent among the linking pages.
     */
    public function testUsesTheFullScopeAsFrequencyDenominator(): void
    {
        $shared = self::link('https://example.com/wichtig.html');
        // 4 pages carry links; 3 of them share one link. Without the scope size that
        // is 3/4 = above threshold and would be dropped.
        $corpus = self::corpus(4, [$shared]);

        $withScope = (new PageLinkFilter())->removeBoilerplate($corpus, 200);
        $withoutScope = (new PageLinkFilter())->removeBoilerplate($corpus);

        $this->assertSame(0, $withScope['dropped'], 'must survive when the site has 200 pages');
        $this->assertSame(4, $withoutScope['dropped'], 'without the scope size it looks like chrome');
    }

    public function testIsNoOpBelowMinimumPageCount(): void
    {
        $footer = self::link('https://example.com/impressum.html');
        $corpus = self::corpus(3, [$footer]);
        $result = (new PageLinkFilter())->removeBoilerplate($corpus);

        $this->assertSame($corpus, $result['links']);
        $this->assertSame(0, $result['dropped']);
    }

    // ------------------------------------------------------------------- policy

    public function testFiltersByType(): void
    {
        $corpus = [1 => [
            self::link('https://example.com/a.html'),
            self::link('https://other.tld/b', PageLink::TYPE_EXTERNAL),
            self::link('https://example.com/files/c.pdf', PageLink::TYPE_FILE),
        ]];

        $result = (new PageLinkFilter())->applyPolicy($corpus, [PageLink::TYPE_PAGE, PageLink::TYPE_FILE], []);

        $this->assertCount(2, $result['links'][1]);
        $this->assertSame(1, $result['dropped']);
    }

    /**
     * NULL = the field was never saved (every installation upgrading into this
     * feature) and must keep every type.
     */
    public function testNullTypeListAllowsEverything(): void
    {
        $corpus = [1 => [self::link('https://other.tld/b', PageLink::TYPE_EXTERNAL)]];
        $result = (new PageLinkFilter())->applyPolicy($corpus, null, []);

        $this->assertCount(1, $result['links'][1]);
    }

    /**
     * An admin who unchecks every type wants no links - anything else contradicts
     * the checkboxes they are looking at.
     */
    public function testEmptyTypeListDropsEverything(): void
    {
        $corpus = [1 => [
            self::link('https://example.com/a.html'),
            self::link('https://example.com/files/b.pdf', PageLink::TYPE_FILE),
        ]];

        $result = (new PageLinkFilter())->applyPolicy($corpus, [], []);

        $this->assertSame([], $result['links']);
        $this->assertSame(2, $result['dropped']);
    }

    public function testAppliesExcludeGlobs(): void
    {
        $corpus = [1 => [
            self::link('https://example.com/impressum.html'),
            self::link('https://example.com/datenschutz/cookies.html'),
            self::link('https://www.facebook.com/juhe'),
            self::link('https://example.com/leistungen.html'),
        ]];

        $result = (new PageLinkFilter())->applyPolicy($corpus, null, [
            '*/impressum*',
            '*/datenschutz/*',
            'https://www.facebook.com/*',
            '',
            '# a comment',
        ]);

        $this->assertSame(['https://example.com/leistungen.html'], array_map(
            static fn (PageLink $l): string => $l->url,
            $result['links'][1],
        ));
        $this->assertSame(3, $result['dropped']);
    }

    /**
     * Admin-provided patterns must be treated as globs, never as regular
     * expressions - otherwise a stray "(" would break the whole sync.
     */
    public function testExcludePatternsAreNotRegularExpressions(): void
    {
        $corpus = [1 => [self::link('https://example.com/a+b(c).html')]];
        $filter = new PageLinkFilter();

        $untouched = $filter->applyPolicy($corpus, null, ['.*']);
        $this->assertCount(1, $untouched['links'][1]);

        $literal = $filter->applyPolicy($corpus, null, ['*/a+b(c).html']);
        $this->assertArrayNotHasKey(1, $literal['links']);
    }

    /**
     * Contao 5.3/5.7 store "=", "#", "(", ")" and the quotes of a posted value as
     * numeric entities; Contao 6 stores the raw text. The same configuration has
     * to behave identically on both.
     */
    public function testExcludePatternsWorkWithContaoInputEncoding(): void
    {
        $corpus = [1 => [
            self::link('https://example.com/download.html?file=preise.pdf'),
            self::link('https://example.com/aktuelles.html'),
        ]];
        $filter = new PageLinkFilter();

        $encoded = $filter->applyPolicy($corpus, null, ['*?file&#61;*']);
        $this->assertSame(
            ['https://example.com/aktuelles.html'],
            array_map(static fn ($link) => $link->url, $encoded['links'][1]),
            'A pattern saved on Contao 5.3/5.7 arrives entity-encoded and must still match.',
        );

        $raw = $filter->applyPolicy($corpus, null, ['*?file=*']);
        $this->assertSame(
            ['https://example.com/aktuelles.html'],
            array_map(static fn ($link) => $link->url, $raw['links'][1]),
            'The same pattern saved on Contao 6 arrives raw and must match the same way.',
        );

        $comment = $filter->applyPolicy($corpus, null, ['&#35; nur eine Notiz']);
        $this->assertCount(2, $comment['links'][1], 'An encoded "#" line is still a comment, not a pattern.');
    }

    public function testRemovesLinksToProtectedPages(): void
    {
        $corpus = [1 => [
            self::link('https://example.com/intern/mitglieder.html'),
            self::link('https://example.com/oeffentlich.html'),
        ]];

        $result = (new PageLinkFilter())->applyPolicy($corpus, null, [], [
            PageLink::comparisonKey('https://example.com/intern/mitglieder.html') => true,
        ]);

        $this->assertSame(['https://example.com/oeffentlich.html'], array_map(
            static fn (PageLink $l): string => $l->url,
            $result['links'][1],
        ));
    }

    /**
     * The protected-target check must survive host and scheme variants: the page
     * may link "https://www.example.com/intern/" while the search index knows
     * "http://example.com/intern".
     */
    public function testMatchesProtectedTargetsAcrossHostAndSchemeVariants(): void
    {
        $corpus = [1 => [
            self::link('https://www.example.com/intern/mitglieder.html'),
            self::link('http://example.com/intern/vorstand.html'),
            self::link('https://example.com/oeffentlich.html'),
        ]];

        $protected = [
            PageLink::comparisonKey('https://example.com/intern/mitglieder.html') => true,
            PageLink::comparisonKey('https://www.example.com/intern/vorstand.html/') => true,
        ];

        $result = (new PageLinkFilter())->applyPolicy($corpus, null, [], $protected);

        $this->assertSame(['https://example.com/oeffentlich.html'], array_map(
            static fn (PageLink $l): string => $l->url,
            $result['links'][1],
        ));
        $this->assertSame(2, $result['dropped']);
    }

    /**
     * Admin globs must not be able to make the sync crawl into catastrophic
     * backtracking.
     */
    public function testRejectsPatternsWithTooManyWildcards(): void
    {
        $corpus = [1 => [self::link('https://example.com/'.str_repeat('ab', 200).'.html')]];
        $evil = str_repeat('*a', 40);

        $start = microtime(true);
        $result = (new PageLinkFilter())->applyPolicy($corpus, null, [$evil]);
        $elapsed = microtime(true) - $start;

        $this->assertCount(1, $result['links'][1], 'the pattern is ignored, not applied');
        $this->assertLessThan(1.0, $elapsed);
    }

    public function testDropsPagesThatEndUpWithoutLinks(): void
    {
        $corpus = [1 => [self::link('https://example.com/impressum.html')]];
        $result = (new PageLinkFilter())->applyPolicy($corpus, null, ['*/impressum*']);

        $this->assertSame([], $result['links']);
    }
}
