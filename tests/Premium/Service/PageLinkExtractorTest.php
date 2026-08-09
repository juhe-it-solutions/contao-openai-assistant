<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant premium add-on.
 *
 * (c) JUHE IT-solutions
 *
 * @license Proprietary - see LICENSE-PREMIUM. Usage of the premium add-on
 *          requires a valid premium subscription from JUHE IT-solutions.
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Premium\Service;

use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageLink;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageLinkExtractor;
use PHPUnit\Framework\TestCase;

class PageLinkExtractorTest extends TestCase
{
    private const BASE = 'https://example.com/leistungen.html';

    /**
     * Wraps content the way Contao delivers it to the indexPage hook: a full
     * document whose <!-- indexer::stop --> regions are ALREADY removed.
     */
    private static function page(string $bodyInner, string $extraHead = ''): string
    {
        return '<!DOCTYPE html><html lang="de"><head><title>T</title>'.$extraHead.'</head><body>'
            .'<div id="wrapper"><main id="main"><div class="inside">'.$bodyInner.'</div></main></div>'
            .'</body></html>';
    }

    /**
     * @param list<PageLink> $links
     *
     * @return list<string>
     */
    private static function urls(array $links): array
    {
        return array_map(static fn (PageLink $l): string => $l->url, $links);
    }

    private function extract(string $html, string $base = self::BASE): array
    {
        return (new PageLinkExtractor('files'))->extract($html, $base, ['example.com']);
    }

    public function testExtractsContentLinksWithLabels(): void
    {
        $links = $this->extract(self::page(
            '<p>Siehe <a href="/kontakt.html">Kontakt</a> und '
            .'<a href="https://www.austrian-standards.at/norm">ÖNORM B 1300</a>.</p>',
        ));

        $this->assertCount(2, $links);
        $this->assertSame('https://example.com/kontakt.html', $links[0]->url);
        $this->assertSame('Kontakt', $links[0]->label);
        $this->assertSame(PageLink::TYPE_PAGE, $links[0]->type);
        $this->assertSame(PageLink::TYPE_EXTERNAL, $links[1]->type);
        $this->assertSame('ÖNORM B 1300', $links[1]->label);
    }

    public function testReturnsEmptyForMarkupWithoutAnchors(): void
    {
        $this->assertSame([], $this->extract(self::page('<p>Kein Link hier.</p>')));
        $this->assertSame([], $this->extract(''));
    }

    // ------------------------------------------------------------------ security

    /**
     * The single most important guarantee: only http, https, mailto and tel may
     * ever reach the vector store.
     */
    public function testDropsDangerousSchemes(): void
    {
        $links = $this->extract(self::page(
            '<a href="javascript:alert(1)">x</a>'
            .'<a href="JavaScript:alert(2)">x</a>'
            .'<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>'
            .'<a href="vbscript:msgbox">x</a>'
            .'<a href="file:///etc/passwd">x</a>'
            .'<a href="ftp://example.com/x">x</a>'
            .'<a href="blob:https://example.com/uuid">x</a>'
            .'<a href="/ok.html">ok</a>',
        ));

        $this->assertSame(['https://example.com/ok.html'], self::urls($links));
    }

    public function testStripsCredentialsFromUrls(): void
    {
        $links = $this->extract(self::page('<a href="https://user:secret@intranet.example.org/report">Bericht</a>'));

        $this->assertCount(1, $links);
        $this->assertSame('https://intranet.example.org/report', $links[0]->url);
        $this->assertStringNotContainsString('secret', $links[0]->url);
    }

    /**
     * Labels end up inside "[label](url)" in an uploaded document; the chat
     * renderer's pattern is \[([^\]]+)\], so a bracket would break the link.
     */
    public function testLabelsAreMarkdownSafe(): void
    {
        $links = $this->extract(self::page('<a href="/a.html">Preis [Netto] (2026)</a>'));

        $this->assertSame('Preis (Netto) (2026)', $links[0]->label);
        $this->assertStringNotContainsString('[', $links[0]->label);
        $this->assertStringNotContainsString(']', $links[0]->label);
    }

    public function testStripsControlCharactersAndHtmlFromLabels(): void
    {
        $links = $this->extract(self::page("<a href=\"/a.html\">Kon\x00takt\x07 \n  Formular</a>"));

        $this->assertSame('Kontakt Formular', $links[0]->label);
    }

    public function testDecodesContaoBasicEntitiesInLabels(): void
    {
        $links = $this->extract(self::page('<a href="/a.html">Fischer [&] Söhne</a>'));

        $this->assertSame('Fischer & Söhne', $links[0]->label);
    }

    public function testCapsOverlongLabels(): void
    {
        $links = $this->extract(self::page('<a href="/a.html">'.str_repeat('sehr langer text ', 40).'</a>'));

        $this->assertLessThanOrEqual(160, mb_strlen($links[0]->label));
        $this->assertStringEndsWith('…', $links[0]->label);
    }

    /**
     * A crafted href must never be reported as living inside the upload path.
     */
    public function testPathTraversalCannotEscapeTheUploadPath(): void
    {
        $links = $this->extract(self::page(
            '<a href="/files/../../.env">a</a>'
            .'<a href="/files/%2e%2e/%2e%2e/config/parameters.yml">b</a>'
            .'<a href="/files/ok/preisliste.pdf">c</a>',
        ));

        $paths = array_map(static fn (PageLink $l): string => $l->filePath, $links);

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('..', $path);

            if ('' !== $path) {
                $this->assertStringStartsWith('files/', $path);
            }
        }

        $withPath = array_values(array_filter($links, static fn (PageLink $l): bool => '' !== $l->filePath));
        $this->assertCount(1, $withPath);
        $this->assertSame('files/ok/preisliste.pdf', $withPath[0]->filePath);
    }

    public function testDropsOverlongUrls(): void
    {
        $links = $this->extract(self::page('<a href="/x?q='.str_repeat('a', 2100).'">lang</a><a href="/ok.html">ok</a>'));

        $this->assertSame(['https://example.com/ok.html'], self::urls($links));
    }

    public function testCapsLinksPerPage(): void
    {
        $body = '';

        for ($i = 0; $i < 80; ++$i) {
            $body .= \sprintf('<a href="/seite-%d.html">Seite %d</a>', $i, $i);
        }

        $this->assertCount(PageLinkExtractor::MAX_LINKS_PER_PAGE, $this->extract(self::page($body)));
    }

    /**
     * The cap must prefer documents. A resource page that lists many teaser links
     * before its PDFs would otherwise lose exactly the links this feature exists
     * to surface.
     */
    public function testCapKeepsDocumentsOverEarlierPageLinks(): void
    {
        $body = '';

        for ($i = 0; $i < 60; ++$i) {
            $body .= \sprintf('<a href="/teaser-%d.html">Teaser %d</a>', $i, $i);
        }

        // The documents come LAST in the document order.
        for ($i = 0; $i < 5; ++$i) {
            $body .= \sprintf('<a href="/files/bericht-%d.pdf">Bericht %d</a>', $i, $i);
        }

        $links = $this->extract(self::page($body));
        $files = array_values(array_filter($links, static fn (PageLink $l): bool => PageLink::TYPE_FILE === $l->type));

        $this->assertCount(PageLinkExtractor::MAX_LINKS_PER_PAGE, $links);
        $this->assertCount(5, $files, 'every PDF survives the cap');

        // Survivors keep the page's own order, so the PDFs are still last.
        $positions = array_map(static fn (PageLink $l): int => $l->position, $links);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }

    /**
     * Contao serves some downloads through a query parameter; the path then ends
     * in ".html" although the response is an attachment.
     */
    public function testDetectsDownloadQueryUrls(): void
    {
        $links = $this->extract(self::page(
            '<a href="/download-center.html?file=files/preisliste.pdf">Preisliste</a>'
            .'<a href="/seite.html?p=files/handbuch.docx&amp;f=handbuch.docx&amp;d=1&amp;_hash=abc">Handbuch</a>'
            .'<a href="/suche.html?f=bericht.pdf">Suchergebnis</a>',
        ));

        $byUrl = [];

        foreach ($links as $link) {
            $byUrl[$link->url] = $link;
        }

        $legacy = $byUrl['https://example.com/download-center.html?file=files/preisliste.pdf'];
        $this->assertSame(PageLink::TYPE_FILE, $legacy->type);
        $this->assertSame('files/preisliste.pdf', $legacy->filePath);

        $helper = $byUrl['https://example.com/seite.html?p=files/handbuch.docx&f=handbuch.docx&d=1&_hash=abc'];
        $this->assertSame(PageLink::TYPE_FILE, $helper->type);
        $this->assertSame('files/handbuch.docx', $helper->filePath);

        // A lone "f" parameter is a normal query string, not a download.
        $this->assertSame(PageLink::TYPE_PAGE, $byUrl['https://example.com/suche.html?f=bericht.pdf']->type);
    }

    /**
     * Regression, found live on Contao 5.3 (08.08.2026): FileDownloadHelper writes
     * the "p" parameter relative to the UPLOAD DIRECTORY ("p=downloads/preisliste.pdf"),
     * not to the project root. The fixture above uses "p=files/handbuch.docx", which
     * no Contao version actually emits - so the missing-prefix case went untested and
     * every real Download element ended up without a file path, and therefore without
     * a size or MIME type in the link block.
     */
    public function testResolvesDownloadQueryPathsRelativeToTheUploadDirectory(): void
    {
        $links = $this->extract(self::page(
            '<a href="/linktest?_hash=abc&amp;d=attachment&amp;f=preisliste.pdf&amp;p=downloads%2Fpreisliste.pdf">Preisliste</a>',
        ));

        $this->assertCount(1, $links);
        $this->assertSame(PageLink::TYPE_FILE, $links[0]->type);
        $this->assertSame('files/downloads/preisliste.pdf', $links[0]->filePath);
    }

    /**
     * Prepending the upload path must not become a way out of it: "." and ".." are
     * collapsed BEFORE the prefix is checked, so "p=../../.env" normalises to ".env",
     * fails the prefix test and yields no file path at all.
     *
     * Only filePath is asserted. The link is still TYPED as a file, because
     * classify() takes that hint from the "f" parameter regardless of whether the
     * path resolves (PageLinkExtractor.php:439) - pre-existing behaviour, and
     * harmless: with no file path nothing outside the upload directory is ever
     * stat-ed or read, and no size or MIME is reported.
     */
    public function testDownloadQueryPathsCannotEscapeTheUploadDirectory(): void
    {
        $links = $this->extract(self::page(
            '<a href="/linktest?_hash=abc&amp;d=attachment&amp;f=env&amp;p=..%2F..%2F.env">Geheim</a>',
        ));

        $this->assertCount(1, $links);
        $this->assertSame('', $links[0]->filePath);
    }

    /**
     * Contao 6 does not use "?p=…" for fragment downloads at all: FileDownloadHelper
     * generates the "contao_file_stream" route, which carries the storage path as a
     * ROUTE parameter ("/_file_stream/files/downloads/preisliste.pdf?d=…&ctx=…").
     * Left untaught, the extractor read that path as "_file_stream/files/…", failed
     * the upload-path prefix check and gave every 6.0 Download element no size and
     * no MIME type.
     */
    public function testResolvesContao6FileStreamDownloadUrls(): void
    {
        $links = $this->extract(self::page(
            '<a href="/_file_stream/files/downloads/preisliste.pdf?d=attachment&amp;ctx=abc&amp;_hash=xyz">Preisliste</a>',
        ));

        $this->assertCount(1, $links);
        $this->assertSame(PageLink::TYPE_FILE, $links[0]->type);
        $this->assertSame('files/downloads/preisliste.pdf', $links[0]->filePath);
        $this->assertSame('Preisliste', $links[0]->label);
    }

    /**
     * The route is not anchored at the site root: an install served from a
     * subdirectory prefixes it with the base path.
     */
    public function testResolvesFileStreamUrlsOfASubdirectoryInstall(): void
    {
        $links = $this->extract(self::page(
            '<a href="/cms/_file_stream/files/handbuch.docx?d=attachment&amp;ctx=abc">Handbuch</a>',
        ));

        $this->assertSame('files/handbuch.docx', $links[0]->filePath);
    }

    /**
     * The file-stream path is a MountManager path, so it always carries its mount
     * name. It must NOT be prefixed with the upload path the way "?p=…" is, or the
     * other mounts would be reported as uploads - and traversal must still be
     * confined to the upload directory.
     *
     * The traversal case is percent-encoded on purpose: UriResolver already collapses
     * literal ".." while resolving the href, so only "%2E%2E" still reaches the
     * extractor as a dot segment - which is exactly why the route is stripped BEFORE
     * the path is decoded.
     */
    public function testFileStreamUrlsCannotEscapeTheUploadDirectory(): void
    {
        $links = $this->extract(self::page(
            '<a href="/_file_stream/user_templates/mail.html.twig?ctx=abc">Vorlage</a>'
            .'<a href="/_file_stream/files/%2E%2E/%2E%2E/.env?d=attachment&amp;ctx=abc">Geheim</a>',
        ));

        $this->assertCount(2, $links);
        $this->assertSame('', $links[0]->filePath);
        $this->assertSame('', $links[1]->filePath);
    }

    public function testDownloadQueryUrlsUseTheFileNameAsLabelFallback(): void
    {
        $links = $this->extract(self::page('<a href="/download-center.html?file=files/quartalsbericht.pdf"></a>'));

        $this->assertSame('quartalsbericht.pdf', $links[0]->label);
    }

    /**
     * Images are illustrations, not resources - the upload-path shortcut must not
     * turn them into "documents" the chatbot recommends.
     */
    public function testImagesAreNeverDocuments(): void
    {
        $links = $this->extract(self::page(
            '<a href="/files/foto.png">Foto</a>'
            .'<a href="/files/plan.jpg">Plan</a>'
            .'<a href="/files/logo.svg">Logo</a>'
            .'<a href="/files/preisliste.pdf">Preisliste</a>'
            .'<a href="/files/bauplan.jpg" download>Bauplan herunterladen</a>',
        ));

        $byUrl = [];

        foreach ($links as $link) {
            $byUrl[$link->url] = $link;
        }

        $this->assertSame(PageLink::TYPE_PAGE, $byUrl['https://example.com/files/foto.png']->type);
        $this->assertSame(PageLink::TYPE_PAGE, $byUrl['https://example.com/files/plan.jpg']->type);
        $this->assertSame(PageLink::TYPE_PAGE, $byUrl['https://example.com/files/logo.svg']->type);
        $this->assertSame(PageLink::TYPE_FILE, $byUrl['https://example.com/files/preisliste.pdf']->type);
        // An explicit download intent still wins.
        $this->assertSame(PageLink::TYPE_FILE, $byUrl['https://example.com/files/bauplan.jpg']->type);
    }

    // ------------------------------------------------------------ chrome filtering

    public function testIgnoresChromeOutsideMain(): void
    {
        $html = '<!DOCTYPE html><html lang="de"><head><title>T</title></head><body>'
            .'<header id="header"><a href="/start.html">Startseite</a></header>'
            .'<nav class="mod_navigation"><a href="/nav1.html">Nav 1</a></nav>'
            .'<main id="main"><p><a href="/content.html">Inhalt</a></p></main>'
            .'<footer id="footer"><a href="/impressum.html">Impressum</a></footer>'
            .'</body></html>';

        $this->assertSame(['https://example.com/content.html'], self::urls($this->extract($html)));
    }

    /**
     * A theme without <main>: the structural rules must still remove the chrome.
     */
    public function testFiltersChromeWithoutMainElement(): void
    {
        $html = '<!DOCTYPE html><html lang="de"><head><title>T</title></head><body>'
            .'<div class="site-header"><a href="/a.html">A</a></div>'
            .'<nav><a href="/b.html">B</a></nav>'
            .'<div class="mod_breadcrumb"><a href="/c.html">C</a></div>'
            .'<div class="module-navigation"><a href="/d.html">D</a></div>'
            .'<ul class="pagination"><li><a href="/e.html">2</a></li></ul>'
            .'<div class="content"><a href="/keep.html">Behalten</a></div>'
            .'<div class="cookiebar"><a href="/f.html">F</a></div>'
            .'<footer><a href="/g.html">G</a></footer>'
            .'</body></html>';

        $this->assertSame(['https://example.com/keep.html'], self::urls($this->extract($html)));
    }

    /**
     * Contao 5.3 renders "mod_navigation", Contao 5.7/6.0 renders
     * "module-navigation" - one rule must match both spellings.
     */
    public function testMatchesLegacyAndTwigModuleClassSpellings(): void
    {
        foreach (['mod_navigation', 'module-navigation', 'mod_changelanguage', 'module-changelanguage'] as $class) {
            $links = $this->extract(self::page(
                '<div class="'.$class.'"><a href="/nav.html">Nav</a></div><a href="/keep.html">Keep</a>',
            ));

            $this->assertSame(['https://example.com/keep.html'], self::urls($links), $class);
        }
    }

    /**
     * The bare tokens "header"/"footer" must NOT be treated as chrome: they are
     * just as often the header of a teaser or card, whose title link is exactly
     * the kind of content link this feature exists for.
     */
    public function testKeepsLinksInsideContentTeaserHeaders(): void
    {
        $links = $this->extract(self::page(
            '<div class="teaser"><div class="header"><a href="/news/neue-halle.html">Neue Halle eröffnet</a></div></div>',
        ));

        $this->assertSame(['https://example.com/news/neue-halle.html'], self::urls($links));
    }

    /**
     * Contao's own per-link opt-out, honoured by its crawler. Core sets it on the
     * mini calendar's month arrows (cal_mini.html5 / cal_mini.html.twig) and on
     * the article print button - the markup below is theirs.
     */
    public function testHonoursContaosSkipSearchIndexAttribute(): void
    {
        $links = $this->extract(self::page(
            '<table class="mini"><tr><th class="head previous">'
            .'<a href="/events.html?month=202607" title="Vorheriger Monat" data-skip-search-index>&lt;</a>'
            .'</th></tr></table>'
            .'<div class="syndication" data-skip-search-index><a href="/seite.html?pdf=1">Drucken</a></div>'
            .'<a href="/keep.html">Keep</a>',
        ));

        $this->assertSame(['https://example.com/keep.html'], self::urls($links));
    }

    /**
     * A page that refuses to endorse a link should not have the chatbot recommend
     * it. Comment author websites are the case in Contao (com_default.html5).
     */
    public function testDropsNofollowLinks(): void
    {
        $links = $this->extract(self::page(
            '<div class="comment_default"><p class="info">Von '
            .'<a href="https://spam.example.net/" target="_blank" rel="nofollow noreferrer noopener">Max</a>'
            .'</p></div>'
            .'<a href="https://partner.example.org/" rel="NoFollow">Partner</a>'
            .'<a href="https://echt.example.org/" rel="noopener">Echte Quelle</a>',
        ));

        $this->assertSame(['https://echt.example.org/'], self::urls($links));
    }

    public function testSkipsCalendarAndArchiveMenuModules(): void
    {
        $links = $this->extract(self::page(
            '<div class="mod_calendar block"><a href="/events.html?day=20260804">4</a></div>'
            .'<div class="mod_newsmenu block"><a href="/news.html?year=2026">2026</a></div>'
            .'<div class="module-eventmenu"><a href="/events.html?month=202608">August</a></div>'
            .'<div class="mod_newslist block"><a href="/news/neue-halle.html">Neue Halle</a></div>',
        ));

        $this->assertSame(['https://example.com/news/neue-halle.html'], self::urls($links));
    }

    /**
     * Insert tags are resolved before Contao indexes the page, so the extractor
     * only ever sees their output. {{email::…}} is the interesting one: core
     * writes the whole href as numeric character references
     * (StringUtil::encodeEmail()), which the DOM parser decodes for us.
     */
    public function testResolvesContaoEmailInsertTagOutput(): void
    {
        $links = $this->extract(self::page(
            '<a href="&#109;&#97;&#105;&#108;&#116;&#111;&#58;&#111;&#x66;&#102;&#x69;&#99;&#x65;&#64;&#x65;&#120;&#x61;&#109;&#x70;&#108;&#x65;&#46;&#x63;&#111;&#x6D;" class="email">office@example.com</a>',
        ));

        $this->assertSame(['mailto:office@example.com'], self::urls($links));
    }

    public function testHonoursIgnoreAttributeAndHiddenElements(): void
    {
        $links = $this->extract(self::page(
            '<div data-oaa-ignore-links><a href="/a.html">A</a></div>'
            .'<div aria-hidden="true"><a href="/b.html">B</a></div>'
            .'<div hidden><a href="/c.html">C</a></div>'
            .'<div role="navigation"><a href="/d.html">D</a></div>'
            .'<a href="/keep.html">Keep</a>',
        ));

        $this->assertSame(['https://example.com/keep.html'], self::urls($links));
    }

    public function testSkipsSelfLinksAndFragments(): void
    {
        $links = $this->extract(self::page(
            '<a href="#anker">Zum Anker</a>'
            .'<a href="/leistungen.html">Diese Seite</a>'
            .'<a href="/leistungen.html#abschnitt">Diese Seite, Abschnitt</a>'
            .'<a href="/andere.html">Andere</a>',
        ));

        $this->assertSame(['https://example.com/andere.html'], self::urls($links));
    }

    /**
     * Regression, found live on Contao 5.3 (08.08.2026): self-link detection used
     * to compare against the <base href>, and Contao's fe_page.html5 emits the SITE
     * ROOT there - so on every stock install the yardstick was "https://example.com/"
     * instead of the page's own URL, and no self-link was ever dropped.
     *
     * Every fixture in this class renders without a <base>, which is precisely why
     * 193 tests went green while the live site collected self-links.
     */
    public function testSkipsSelfLinksEvenWhenThePageEmitsABaseHref(): void
    {
        $links = $this->extract(self::page(
            '<a href="/leistungen.html">Diese Seite</a>'
            .'<a href="/andere.html">Andere</a>',
            '<base href="https://example.com/">',
        ));

        $this->assertSame(['https://example.com/andere.html'], self::urls($links));
    }

    /**
     * The other half of the same bug: with the site root as the yardstick, a genuine
     * link TO the home page looked like a self-link and vanished from every page.
     */
    public function testKeepsLinksToTheHomePageWhenThePageEmitsABaseHref(): void
    {
        $links = $this->extract(self::page(
            '<a href="/">Startseite</a>',
            '<base href="https://example.com/">',
        ));

        $this->assertSame(['https://example.com/'], self::urls($links));
    }

    // ------------------------------------------------------------- classification

    public function testClassifiesDocuments(): void
    {
        $links = $this->extract(self::page(
            '<figure class="download-element ext-pdf"><a href="/files/preisliste.pdf" type="application/pdf">Preisliste 2026</a></figure>'
            .'<a href="/download/handbuch.docx">Handbuch</a>'
            .'<a href="https://cdn.other.tld/whitepaper.pdf">Whitepaper</a>'
            .'<a href="/service.html" download>Service</a>'
            .'<a href="/normal.html">Normale Seite</a>',
        ));

        $byUrl = [];

        foreach ($links as $link) {
            $byUrl[$link->url] = $link;
        }

        $this->assertSame(PageLink::TYPE_FILE, $byUrl['https://example.com/files/preisliste.pdf']->type);
        $this->assertSame('files/preisliste.pdf', $byUrl['https://example.com/files/preisliste.pdf']->filePath);
        $this->assertSame('application/pdf', $byUrl['https://example.com/files/preisliste.pdf']->mime);
        $this->assertSame(PageLink::TYPE_FILE, $byUrl['https://example.com/download/handbuch.docx']->type);
        $this->assertSame(PageLink::TYPE_FILE, $byUrl['https://cdn.other.tld/whitepaper.pdf']->type);
        $this->assertSame(PageLink::TYPE_FILE, $byUrl['https://example.com/service.html']->type);
        $this->assertSame(PageLink::TYPE_PAGE, $byUrl['https://example.com/normal.html']->type);
    }

    public function testTreatsAdditionalSiteHostsAsInternal(): void
    {
        $extractor = new PageLinkExtractor('files');
        $links = $extractor->extract(
            self::page('<a href="https://www.example.com/a.html">A</a><a href="https://shop.example.net/b">B</a>'),
            self::BASE,
            ['example.com', 'shop.example.net'],
        );

        $this->assertSame(PageLink::TYPE_PAGE, $links[0]->type);
        $this->assertSame(PageLink::TYPE_PAGE, $links[1]->type);
    }

    public function testIgnoresImageLightboxLinks(): void
    {
        $links = $this->extract(self::page(
            '<a href="/files/bild.jpg" data-lightbox="123"><img src="/files/bild-klein.jpg" alt="Bild"></a>'
            .'<a href="/files/foto.png">Foto</a>'
            .'<a href="/keep.html">Keep</a>',
        ));

        $urls = self::urls($links);
        $this->assertNotContains('https://example.com/files/bild.jpg', $urls);
        $this->assertContains('https://example.com/keep.html', $urls);
    }

    public function testHandlesMailtoAndTel(): void
    {
        $links = $this->extract(self::page(
            '<a href="mailto:office@example.com?subject=Anfrage&amp;body=Hallo">Schreiben Sie uns</a>'
            .'<a href="tel:+43 1 234 5678">Anrufen</a>'
            .'<a href="mailto:kaputt">Ungültig</a>',
        ));

        $this->assertCount(2, $links);
        $this->assertSame('mailto:office@example.com', $links[0]->url);
        $this->assertSame(PageLink::TYPE_MAILTO, $links[0]->type);
        $this->assertSame(PageLink::TYPE_TEL, $links[1]->type);
        $this->assertStringNotContainsString('subject', $links[0]->url);
    }

    /**
     * A contact link without anchor text must fall back to the address/number, not
     * to a URL-derived label ("/+43 1 234 5678").
     */
    public function testContactLinksFallBackToTheAddressItself(): void
    {
        $links = $this->extract(self::page(
            '<a href="tel:+43 1 234 5678"></a><a href="mailto:buchhaltung@example.com"></a>',
        ));

        $this->assertSame('+43 1 234 5678', $links[0]->label);
        $this->assertSame('buchhaltung@example.com', $links[1]->label);
    }

    // -------------------------------------------------------------- normalisation

    public function testNormalisesUrls(): void
    {
        $links = $this->extract(self::page(
            '<a href="HTTPS://EXAMPLE.COM:443/Gross.html">A</a>'
            .'<a href="http://example.com:80/klein.html">B</a>'
            .'<a href="/mit-query.html?a=1&amp;b=2">C</a>',
        ));

        $this->assertSame([
            'https://example.com/Gross.html',
            'http://example.com/klein.html',
            'https://example.com/mit-query.html?a=1&b=2',
        ], self::urls($links));
    }

    /**
     * Stored URLs must be byte-identical to what Contao writes into tl_search.url
     * ("(string) new Uri($url)", Search.php:52). The protected-target check and the
     * orphan pruning compare against that column, so a differently normalised
     * non-ASCII path would silently break both.
     */
    public function testNormalisationMatchesContaoSearchIndex(): void
    {
        $links = $this->extract(self::page(
            '<a href="/über-uns.html">Umlaut</a>'
            .'<a href="/%C3%BCber-uns/team.html">Bereits kodiert</a>',
        ));

        $urls = self::urls($links);

        foreach ($urls as $url) {
            $this->assertSame((string) new \Nyholm\Psr7\Uri($url), $url);
            $this->assertSame($url, preg_replace('/[^\x20-\x7E]/', '', $url), 'stored URLs must be ASCII');
        }

        $this->assertSame('https://example.com/%C3%BCber-uns.html', $urls[0]);
        $this->assertSame('https://example.com/%C3%BCber-uns/team.html', $urls[1]);
    }

    public function testDeduplicatesAndCountsOccurrences(): void
    {
        $links = $this->extract(self::page(
            '<a href="/a.html">A</a><a href="/a.html">Ausführlicher Linktext</a><a href="/a.html">A</a>',
        ));

        $this->assertCount(1, $links);
        $this->assertSame(3, $links[0]->occurrences);
        $this->assertSame('Ausführlicher Linktext', $links[0]->label);
    }

    /**
     * The fragment is dropped, so a link to a section of a page merges with a plain
     * link to that page - and the anchor text must not win the merge. "#kontakt" is
     * one character longer than "Kontakt" and says less: it names a place inside the
     * target rather than the target, which is what the chatbot reads out.
     */
    public function testAnchorTextNeverBeatsARealLabelForTheSameTarget(): void
    {
        $links = $this->extract(self::page(
            '<p>Siehe <a href="/kontakt.html">Kontakt</a>, '
            .'oder springe zu <a href="/kontakt.html#formular">#formular</a>.</p>',
        ));

        $this->assertCount(1, $links);
        $this->assertSame('https://example.com/kontakt.html', $links[0]->url);
        $this->assertSame('Kontakt', $links[0]->label);
        $this->assertSame(2, $links[0]->occurrences);
    }

    /**
     * The case from the live site, with its real shape: a Contao page URL is a slug, so
     * the label of a well-written link is the last path segment give or take its case.
     *
     * The first attempt at this ranking demoted any label matching the path basename, on
     * the theory that it restated the target - which made "00-Home" score as low as
     * "#kontakt" on ".../00-home", handed the decision back to length, and left the
     * output byte-for-byte as reported. Anything that treats a page's own name as a
     * worthless label has misunderstood the problem.
     */
    public function testAPageNameThatMatchesItsSlugStillBeatsAnchorText(): void
    {
        $links = $this->extract(
            self::page(
                '<p>Link auf die Seite <a href="/00-home">00-Home</a></p>'
                .'<p>Ankerlink auf <a href="/00-home#kontakt">#kontakt</a> der Seite 00-Home</p>',
            ),
            'https://example.com/linktest',
        );

        $this->assertCount(1, $links);
        $this->assertSame('00-Home', $links[0]->label);
    }

    /**
     * A file name is a label a human writes, so it must not be demoted for appearing in
     * the URL either - the same mistake, one path segment along.
     */
    public function testAFileNameLabelIsNotTreatedAsRestatingTheTarget(): void
    {
        $links = $this->extract(self::page(
            '<a href="/files/Preisliste.pdf">Preisliste.pdf</a>'
            .'<a href="/files/Preisliste.pdf">https://example.com/files/Preisliste.pdf</a>',
        ));

        $this->assertSame('Preisliste.pdf', $links[0]->label);
    }

    /**
     * Order must not decide it either: the anchor link coming first would previously
     * make its text the incumbent that nothing shorter could displace.
     */
    public function testAnchorTextLosesEvenWhenItComesFirst(): void
    {
        $links = $this->extract(self::page(
            '<a href="/kontakt.html#formular">#formular</a><a href="/kontakt.html">Kontakt</a>',
        ));

        $this->assertSame('Kontakt', $links[0]->label);
    }

    /**
     * A pasted address is long by nature and only repeats the destination, which the
     * rendered line already carries.
     */
    public function testAPastedUrlLosesToAWrittenLabel(): void
    {
        $links = $this->extract(self::page(
            '<a href="/preise.html">Preise</a>'
            .'<a href="/preise.html">https://example.com/preise.html</a>',
        ));

        $this->assertSame('Preise', $links[0]->label);
    }

    /**
     * The host/path label the extractor synthesises for an anchor with no text at all
     * must not outrank one a human wrote, however much longer it is.
     */
    public function testASynthesisedLabelLosesToAWrittenOne(): void
    {
        $links = $this->extract(self::page(
            '<a href="/unternehmen/geschichte.html"><img src="/x.png" alt=""></a>'
            .'<a href="/unternehmen/geschichte.html">Historie</a>',
        ));

        $this->assertCount(1, $links);
        $this->assertSame('Historie', $links[0]->label);
    }

    /**
     * The rule the length comparison was written for still holds between two labels
     * that both say something.
     */
    public function testTheLongerLabelStillWinsBetweenTwoRealOnes(): void
    {
        $links = $this->extract(self::page(
            '<a href="/preise.html">mehr</a><a href="/preise.html">Preise und Konditionen</a>',
        ));

        $this->assertSame('Preise und Konditionen', $links[0]->label);
    }

    public function testUsesLabelFallbackChain(): void
    {
        $links = $this->extract(self::page(
            '<a href="/a.html" title="Titel-Attribut"></a>'
            .'<a href="/b.html" aria-label="Aria-Label"></a>'
            .'<a href="/c.html"><img src="/x.png" alt="Alt-Text"></a>'
            .'<a href="/files/dritter-quartalsbericht.pdf"></a>',
        ));

        $this->assertSame('Titel-Attribut', $links[0]->label);
        $this->assertSame('Aria-Label', $links[1]->label);
        $this->assertSame('Alt-Text', $links[2]->label);
        $this->assertSame('dritter-quartalsbericht.pdf', $links[3]->label);
    }

    public function testRespectsBaseHref(): void
    {
        $html = self::page('<a href="unterseite.html">Relativ</a>', '<base href="https://example.com/bereich/">');

        $this->assertSame(['https://example.com/bereich/unterseite.html'], self::urls($this->extract($html)));
    }

    public function testSurvivesMalformedMarkup(): void
    {
        // Unbalanced tags are normal: Contao removes indexer::stop regions with
        // plain string surgery before the hook runs.
        $html = '<html><body><main><div><p>Text <a href="/a.html">A</a></div></main></body>';

        $this->assertSame(['https://example.com/a.html'], self::urls($this->extract($html)));
    }

    public function testReturnsEmptyWithoutAbsoluteBaseUrl(): void
    {
        $this->assertSame([], $this->extract(self::page('<a href="/a.html">A</a>'), '/relative/path'));
    }
}
