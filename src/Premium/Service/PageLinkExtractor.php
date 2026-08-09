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

use Nyholm\Psr7\Uri;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;

/**
 * Extracts the content links of one rendered page.
 *
 * Input is the HTML Contao hands to the "indexPage" hook: <script>/<style> blocks
 * and every "<!-- indexer::stop -->" region have already been removed by
 * Contao\Search::indexPage(), so navigation, breadcrumb, pagination, search and
 * login modules - and, importantly, every PROTECTED article or module - are gone
 * before this class ever sees the markup. What is left here is a second, markup
 * based safety net for themes that do not carry those markers, plus the
 * classification and hardening of the links themselves.
 *
 * Framework-free on purpose: no container, no database, no file system. That keeps
 * it fully unit-testable against static fixtures and guarantees it cannot become a
 * performance or security problem inside Contao's indexing path. File metadata is
 * added afterwards by ContaoFileMetadataResolver.
 *
 * Security model (every one of these is covered by a test):
 *   - scheme allow-list: only http, https, mailto and tel survive. javascript:,
 *     data:, vbscript:, file: and friends are dropped, never stored, never rendered.
 *   - credentials ("https://user:pass@host/") are stripped from the stored URL.
 *   - control characters are removed from URLs and labels.
 *   - labels are made Markdown-safe (no "[" / "]") and length-capped, because they
 *     end up inside "[label](url)" in an uploaded document.
 *   - file paths are resolved against the configured upload path with "..'"
 *     segments collapsed first, so a crafted href can never point outside it.
 *   - a hard per-page cap bounds how much a single page can contribute.
 */
class PageLinkExtractor
{
    /**
     * Hard cap per indexed document. Bounds database growth and keeps the link
     * section of a page document short enough to stay useful for retrieval.
     */
    public const MAX_LINKS_PER_PAGE = 40;

    /**
     * How many distinct link targets are collected before the type-preference cap
     * is applied. Bounds the work a generated or hostile page can cause while
     * still giving the cap enough candidates to prefer documents from.
     */
    public const MAX_LINKS_SCANNED = 400;

    /**
     * Column-safe caps (tl_openai_page_link: url 2048, label/link_title 512).
     * Measured in characters, applied with mb_substr, so multi-byte content
     * cannot overflow the column either.
     */
    private const MAX_URL_LENGTH = 2000;

    private const MAX_LABEL_LENGTH = 160;

    private const MAX_TITLE_LENGTH = 250;

    /**
     * The only schemes that may ever reach the vector store. Anything else - most
     * importantly javascript:, data: and vbscript: - is dropped silently.
     */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Path extensions that make a link a "document". Deliberately WITHOUT image
     * formats: a lightbox link to a JPG is a viewer, not a resource a chatbot
     * should recommend.
     */
    private const DOCUMENT_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'dot', 'dotx', 'odt', 'rtf', 'txt', 'md',
        'xls', 'xlsx', 'xlsm', 'ods', 'csv',
        'ppt', 'pptx', 'pps', 'ppsx', 'odp',
        'zip', '7z', 'rar', 'tar', 'gz', 'tgz',
        'epub', 'ics', 'vcf', 'dwg', 'eps', 'ai', 'psd', 'xml', 'json',
        'mp3', 'm4a', 'wav', 'ogg', 'mp4', 'm4v', 'mov', 'avi', 'webm',
    ];

    /**
     * Image formats. A link to one of these is a lightbox or an illustration, not
     * a resource - even inside the upload directory. Only an explicit download
     * intent (a "download" attribute or Contao's download element) overrides this.
     */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'avif', 'bmp', 'ico', 'tif', 'tiff', 'heic'];

    /**
     * Elements whose subtree never contains content links.
     */
    private const EXCLUDED_TAGS = ['nav', 'header', 'footer', 'aside', 'form', 'dialog', 'noscript', 'template'];

    /**
     * ARIA landmark roles that mark site chrome.
     */
    private const EXCLUDED_ROLES = ['navigation', 'banner', 'contentinfo', 'search', 'menu', 'menubar', 'dialog', 'complementary'];

    /**
     * Normalised class tokens (see classTokens()) that mark site chrome. Covers the
     * legacy Contao 5.3 "mod_navigation" spelling and the Twig "module-navigation"
     * spelling of 5.7/6.0 alike, plus the usual theme and consent-tool classes.
     *
     * Deliberately NOT in this list: the bare tokens "header" and "footer". They
     * are just as often used for the header of a teaser, card or article - whose
     * title link is exactly the kind of content link we want - as for the page
     * chrome. Page chrome is caught by the <header>/<footer> TAGS, by the Contao
     * section ids, by the qualified tokens below, and finally by the cross-page
     * frequency filter, which needs no markup knowledge at all.
     */
    private const EXCLUDED_CLASSES = [
        'navigation', 'customnav', 'quicknav', 'quicklink', 'booknav', 'breadcrumb',
        'articlenav', 'sitemap', 'search', 'changelanguage', 'login', 'logout',
        'pagination', 'pager', 'invisible', 'skip_navigation', 'skip_link', 'sr_only',
        'visually_hidden', 'screen_reader_text', 'cookiebar', 'cc_banner', 'cookie_consent',
        'toplink', 'social_links', 'calendar', 'newsmenu', 'eventmenu', 'comment_default',
        'site_header', 'page_header', 'main_header', 'site_footer', 'page_footer',
        'main_footer', 'topbar', 'meta_nav', 'metanav', 'metanavigation', 'langnav',
        'language_switcher', 'lang_switcher', 'mainmenu', 'main_menu', 'submenu',
    ];

    /**
     * Opt-out attribute for integrators: any element carrying it excludes its whole
     * subtree, mirroring Contao's own data-skip-search-index idea.
     */
    private const IGNORE_ATTRIBUTE = 'data-oaa-ignore-links';

    /**
     * Contao's own per-link opt-out. Its crawler refuses to follow a link carrying
     * it (SearchIndexSubscriber), and core sets it on the mini calendar's month
     * arrows (cal_mini) and on the article print button - neither of which is
     * wrapped in an indexer::stop region. Identical in 5.3, 5.7 and 6.0.
     */
    private const CONTAO_SKIP_ATTRIBUTE = 'data-skip-search-index';

    /**
     * A "nofollow" link is one the page itself refuses to endorse: comment author
     * websites (com_default sets it), user-submitted content, ads. Recommending
     * those in a chat answer is exactly what the attribute asks us not to do.
     */
    private const EXCLUDED_REL = 'nofollow';

    /**
     * @param string $uploadPath Contao's upload path (container parameter
     *                           contao.upload_path, default "files")
     */
    public function __construct(private readonly string $uploadPath = 'files')
    {
    }

    /**
     * @param string       $html      the already chrome-stripped page HTML
     * @param string       $baseUrl   absolute URL of the indexed document
     * @param list<string> $siteHosts additional hosts that count as "this website"
     *                                (root page domains); the base URL's host is
     *                                always included
     *
     * @return list<PageLink> in document order, de-duplicated, capped
     */
    public function extract(string $html, string $baseUrl, array $siteHosts = []): array
    {
        if ('' === trim($html) || false === stripos($html, '<a ')) {
            return [];
        }

        $baseUrl = $this->sanitiseControlChars($baseUrl);

        if (!preg_match('#^https?://#i', $baseUrl)) {
            // Without an absolute base, relative hrefs cannot be resolved safely.
            return [];
        }

        try {
            $crawler = new Crawler(null, $baseUrl);
            $crawler->addHtmlContent($html, 'UTF-8');
        } catch (\Throwable) {
            return [];
        }

        // A <base href> changes how every relative href resolves. Contao does not
        // emit one by default, but a custom template may.
        $base = $this->resolveBaseHref($crawler, $baseUrl);

        // The page's own identity is the URL Contao indexed it under - NEVER the
        // <base href>. Contao's fe_page.html5 emits the SITE ROOT there
        // ("https://example.com/"), so deriving $ownUrl from it would compare every
        // link against the root instead of the page: self-links would all survive,
        // and on a site whose root page resolves to "/" every genuine link to the
        // home page would be dropped as a self-link. $base stays the resolution
        // base for relative hrefs, which is the one job it is right for.
        $ownUrl = $this->normaliseHttpUrl($baseUrl);
        $hosts = $this->buildHostSet($base, $siteHosts);

        $root = $crawler->filterXPath('//main | //*[@role="main"] | //*[@id="main"]');

        if (!$root->count()) {
            $root = $crawler->filterXPath('//body');
        }

        if (!$root->count()) {
            return [];
        }

        /** @var array<string, PageLink> $byUrl */
        $byUrl = [];
        $position = 0;

        foreach ($root->filterXPath('descendant-or-self::a[@href]') as $node) {
            if (!$node instanceof \DOMElement || $this->isExcluded($node)) {
                continue;
            }

            $link = $this->buildLink($node, $base, $ownUrl, $hosts, $position);

            if (!$link instanceof PageLink) {
                continue;
            }

            ++$position;
            $key = $link->urlHash();

            if (isset($byUrl[$key])) {
                $existing = $byUrl[$key];
                // Keep the most descriptive label of all occurrences.
                $better = mb_strlen($link->label) > mb_strlen($existing->label) ? $link->label : $existing->label;
                $byUrl[$key] = $existing->withOccurrences($existing->occurrences + 1)->withLabel($better);

                continue;
            }

            $byUrl[$key] = $link;

            // Bound the work a single hostile or generated page can cause. The
            // per-page LIMIT is applied afterwards, by type preference.
            if (\count($byUrl) >= self::MAX_LINKS_SCANNED) {
                break;
            }
        }

        // Documents survive the cap before pages, pages before external links -
        // capping in document order would lose the PDFs of a page that lists many
        // teaser links first.
        return PageLink::capByTypePreference(array_values($byUrl), self::MAX_LINKS_PER_PAGE);
    }

    /**
     * Normalise an absolute http(s) URL for comparison and storage.
     *
     * Uses the SAME implementation Contao uses when it writes tl_search.url
     * (Search.php: `(string) (new Uri($url))`), so a stored link can be compared
     * against the search index byte for byte - which is what the protected-target
     * check and the orphan pruning rely on. A hand-rolled normaliser would differ
     * exactly where it matters: non-ASCII paths, which Uri percent-encodes.
     *
     * On top of that: credentials are dropped (they must never be persisted, sent
     * to OpenAI or shown to a visitor) and the fragment is removed (it never
     * changes which document a link points to).
     */
    public function normaliseHttpUrl(string $url): string
    {
        $url = $this->sanitiseControlChars($url);

        try {
            $uri = new Uri($url);
        } catch (\Throwable) {
            return '';
        }

        // Uri lowercases the scheme and host and drops a standard port for us.
        if (!\in_array($uri->getScheme(), ['http', 'https'], true) || '' === $uri->getHost()) {
            return '';
        }

        return (string) $uri->withUserInfo('')->withFragment('');
    }

    /**
     * Turn one <a> element into a sanitised PageLink, or null when it must be skipped.
     *
     * @param array<string, true> $hosts
     */
    private function buildLink(\DOMElement $node, string $base, string $ownUrl, array $hosts, int $position): PageLink|null
    {
        $href = trim($this->sanitiseControlChars($node->getAttribute('href')));

        if ('' === $href || str_starts_with($href, '#')) {
            return null;
        }

        try {
            $absolute = UriResolver::resolve($href, $base);
        } catch (\Throwable) {
            return null;
        }

        $scheme = strtolower((string) parse_url($absolute, PHP_URL_SCHEME));

        if (!\in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return null;
        }

        if ('mailto' === $scheme || 'tel' === $scheme) {
            return $this->buildContactLink($node, $absolute, $scheme, $position);
        }

        $url = $this->normaliseHttpUrl($absolute);

        if ('' === $url || mb_strlen($url) > self::MAX_URL_LENGTH) {
            return null;
        }

        // A link to the page itself carries no information.
        if ($url === $ownUrl) {
            return null;
        }

        // Lightbox links open an image viewer, they are not a resource.
        if ($node->hasAttribute('data-lightbox')) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $isOwnHost = isset($hosts[$this->bareHost($host)]);
        $filePath = $isOwnHost ? $this->resolveUploadPath($url) : '';

        // Contao serves some downloads through a query parameter rather than a
        // file path; resolving it gives those links a size and a type hint too.
        if ('' === $filePath && $isOwnHost) {
            $filePath = $this->normaliseDownloadQueryPath($this->downloadQueryPath($url)['path']);
        }
        $type = $this->classify($node, $url, $isOwnHost, '' !== $filePath);

        $label = $this->resolveLabel($node, $this->fallbackLabel($url, $type));

        if ('' === $label) {
            return null;
        }

        return new PageLink(
            $url,
            $label,
            $type,
            $this->sanitiseText($node->getAttribute('title'), self::MAX_TITLE_LENGTH),
            $this->sanitiseText($node->getAttribute('type'), 100),
            $filePath,
            0,
            $position,
        );
    }

    private function buildContactLink(\DOMElement $node, string $absolute, string $scheme, int $position): PageLink|null
    {
        // Keep only the address/number itself: parameters such as
        // "?subject=...&body=..." add nothing and can carry injected text.
        $target = explode('?', substr($absolute, \strlen($scheme) + 1), 2)[0];
        $target = trim(rawurldecode($target));
        $target = $this->sanitiseControlChars($target);

        if ('' === $target || mb_strlen($target) > 254) {
            return null;
        }

        if ('mailto' === $scheme && !filter_var($target, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        if ('tel' === $scheme && !preg_match('/^\+?[0-9 ()\/.-]{4,}$/', $target)) {
            return null;
        }

        $url = $scheme.':'.$target;
        // The address/number itself is the fallback label - never a URL-derived one,
        // which would render a phone number as "/+43 1 234".
        $label = $this->resolveLabel($node, $target);

        return new PageLink(
            $url,
            '' !== $label ? $label : $target,
            'mailto' === $scheme ? PageLink::TYPE_MAILTO : PageLink::TYPE_TEL,
            $this->sanitiseText($node->getAttribute('title'), self::MAX_TITLE_LENGTH),
            '',
            '',
            0,
            $position,
        );
    }

    /**
     * page | file | external. "file" wins over the host check: a document is a
     * document no matter where it is hosted.
     */
    private function classify(\DOMElement $node, string $url, bool $isOwnHost, bool $isInUploadPath): string
    {
        if ($this->isDocument($node, $url, $isInUploadPath)) {
            return PageLink::TYPE_FILE;
        }

        return $isOwnHost ? PageLink::TYPE_PAGE : PageLink::TYPE_EXTERNAL;
    }

    private function isDocument(\DOMElement $node, string $url, bool $isInUploadPath): bool
    {
        // An explicit download intent always wins, whatever the file is.
        if ($node->hasAttribute('download') || $this->hasDownloadWrapper($node)) {
            return true;
        }

        // An image is an illustration, not a resource a chatbot should recommend -
        // even when it lives in the upload directory. Checked before every other
        // rule so neither the upload-path shortcut nor the download-query rule
        // below can override it.
        $extension = $this->effectiveExtension($url);

        if ('' !== $extension && \in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return false;
        }

        $typeAttr = strtolower(trim($node->getAttribute('type')));

        if ('' !== $typeAttr && !str_contains($typeAttr, 'text/html')) {
            return true;
        }

        if ($isInUploadPath) {
            return true;
        }

        if ('' !== $this->downloadQueryFileName($url)) {
            return true;
        }

        return '' !== $extension && \in_array($extension, self::DOCUMENT_EXTENSIONS, true);
    }

    /**
     * Contao's download element wraps its anchor in .download-element.ext-<x>.
     */
    private function hasDownloadWrapper(\DOMElement $node): bool
    {
        for ($el = $node; $el instanceof \DOMElement; $el = $el->parentNode) {
            foreach ($this->classTokens($el) as $token) {
                if ('download_element' === $token || str_starts_with($token, 'ext_')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * File name of a Contao download URL that carries the file in the QUERY rather
     * than in the path, or '' when this is not such a URL.
     *
     * Two shapes exist:
     *   - the legacy download element: "...?file=files/preisliste.pdf"
     *   - FileDownloadHelper (5.3+): "...?p=<path>&f=<name>&d=…&_hash=…"
     *     (parameter names verified in 5.3 and 6.0)
     *
     * Such a URL is served with Content-Disposition: attachment, so it is a
     * document even though its path ends in ".html".
     */
    private function downloadQueryFileName(string $url): string
    {
        return $this->downloadQueryPath($url)['name'];
    }

    /**
     * Extension that describes what a link actually hands the visitor: the one
     * from a download query parameter when present, otherwise the one from the
     * URL path. A legacy download URL such as "/download-center.html?file=x.pdf"
     * must be judged by "pdf", not by "html".
     */
    private function effectiveExtension(string $url): string
    {
        $name = $this->downloadQueryFileName($url);

        if ('' !== $name) {
            return strtolower(pathinfo($name, PATHINFO_EXTENSION));
        }

        return strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    }

    /**
     * @return array{name: string, path: string} both '' when this is not a
     *                                           download URL of either shape
     */
    private function downloadQueryPath(string $url): array
    {
        $empty = ['name' => '', 'path' => ''];
        $query = (string) parse_url($url, PHP_URL_QUERY);

        if ('' === $query) {
            return $empty;
        }

        parse_str($query, $params);

        // Legacy download element: "?file=files/preisliste.pdf" - distinctive on
        // its own.
        $file = $params['file'] ?? null;

        if (\is_string($file) && '' !== $file && '' !== pathinfo($file, PATHINFO_EXTENSION)) {
            $file = $this->sanitiseControlChars(rawurldecode($file));

            return ['name' => basename($file), 'path' => $file];
        }

        // FileDownloadHelper (5.3+): "?p=<path>&f=<name>&d=…&_hash=…". The path
        // parameter alone is far too generic, so at least one companion parameter
        // must be present (names verified in Contao 5.3 and 6.0).
        $path = $params['p'] ?? null;

        if (!\is_string($path) || '' === $path) {
            return $empty;
        }

        $hasCompanion = isset($params['_hash']) || isset($params['d']) || isset($params['f']) || isset($params['t']);

        if (!$hasCompanion) {
            return $empty;
        }

        $path = $this->sanitiseControlChars(rawurldecode($path));
        $name = $params['f'] ?? null;
        $name = \is_string($name) && '' !== $name
            ? basename($this->sanitiseControlChars(rawurldecode($name)))
            : basename($path);

        return ['name' => $name, 'path' => $path];
    }

    /**
     * Project-relative path of an own-host URL inside the upload directory, or ''
     * when it does not point there.
     *
     * Path traversal guard: the decoded path is normalised ("." / ".." segments
     * collapsed) BEFORE the prefix check, so "/files/../../.env" can never be
     * reported as living under the upload path.
     */
    private function resolveUploadPath(string $url): string
    {
        $path = $this->stripFileStreamRoute((string) parse_url($url, PHP_URL_PATH));

        return $this->normaliseUploadPath(rawurldecode($path));
    }

    /**
     * Contao 6 serves fragment downloads through the "contao_file_stream" route,
     * which carries the storage path as a ROUTE parameter instead of a query one:
     * "/_file_stream/files/downloads/preisliste.pdf?d=attachment&ctx=…&_hash=…"
     * (contao-core-6.0 core-bundle/src/Controller/FileStreamController.php:20 and
     * Filesystem/FileDownloadHelper.php:96-115). downloadQueryPath() only knows the
     * query spellings, so without this the most common way to publish a PDF on 6.0
     * resolved to no file path, and therefore to no size and no MIME type.
     *
     * Unlike "?p=…", this path needs no prefixing: it is a MountManager path, and
     * the upload directory is mounted under its own name (ContaoCoreExtension.php:242),
     * so it already starts with "files/". Keeping it strict also stops the other
     * mounts ("backups", "user_templates") from being mistaken for uploads.
     *
     * The route segment is searched for rather than anchored at position 0, so an
     * install served from a subdirectory ("/cms/_file_stream/…") works too. Stripping
     * happens BEFORE decoding, so a percent-encoded "%2F_file_stream%2F" inside a
     * path segment cannot forge the marker - and even if it did, normaliseUploadPath()
     * still confines the result to the upload directory.
     */
    private function stripFileStreamRoute(string $path): string
    {
        $marker = '/_file_stream/';
        $position = strpos($path, $marker);

        if (false === $position) {
            return $path;
        }

        return substr($path, $position + \strlen($marker));
    }

    /**
     * Upload path of a download served through a query parameter, in either of the
     * two spellings Contao uses.
     *
     * The legacy element writes the full project-relative path
     * ("?file=files/downloads/preisliste.pdf"), while FileDownloadHelper (5.3+)
     * writes a path relative to the UPLOAD DIRECTORY
     * ("?p=downloads/preisliste.pdf&f=…&_hash=…") - without the "files/" prefix
     * normaliseUploadPath() insists on. Taking only the first spelling left every
     * Download element without a file path, and therefore without a size or MIME
     * type in the link block.
     *
     * Prefixing is traversal-safe because normaliseUploadPath() collapses "."/".."
     * BEFORE it checks the prefix: "p=../../.env" becomes "files/../../.env",
     * normalises to ".env", fails the prefix test and is rejected.
     */
    private function normaliseDownloadQueryPath(string $path): string
    {
        if ('' === $path) {
            return '';
        }

        $direct = $this->normaliseUploadPath($path);

        if ('' !== $direct) {
            return $direct;
        }

        return $this->normaliseUploadPath(trim($this->uploadPath, '/').'/'.ltrim($path, '/'));
    }

    /**
     * Traversal-safe normalisation of an already decoded path, returning it only
     * when it really lives inside the upload directory.
     */
    private function normaliseUploadPath(string $decoded): string
    {
        if ('' === $decoded) {
            return '';
        }

        if (str_contains($decoded, "\0")) {
            return '';
        }

        $segments = [];

        foreach (explode('/', $decoded) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $normalised = implode('/', $segments);
        $prefix = trim($this->uploadPath, '/').'/';

        if (!str_starts_with($normalised, $prefix) || $normalised === $prefix) {
            return '';
        }

        return mb_substr($normalised, 0, 1000);
    }

    /**
     * Anchor text, then title, then aria-label, then a nested image's alt text,
     * then the caller-provided fallback.
     */
    private function resolveLabel(\DOMElement $node, string $fallback): string
    {
        $candidates = [
            $node->textContent,
            $node->getAttribute('title'),
            $node->getAttribute('aria-label'),
        ];

        foreach ($node->getElementsByTagName('img') as $img) {
            $candidates[] = $img->getAttribute('alt');
        }

        $candidates[] = $fallback;

        foreach ($candidates as $candidate) {
            $label = $this->sanitiseText($candidate, self::MAX_LABEL_LENGTH);

            if ('' !== $label) {
                return $label;
            }
        }

        return '';
    }

    private function fallbackLabel(string $url, string $type): string
    {
        if (PageLink::TYPE_FILE === $type) {
            // For a download served through a query parameter the path basename is
            // the PAGE name ("download-center.html"), so the query wins.
            $name = $this->downloadQueryFileName($url);

            if ('' === $name) {
                $name = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
            }

            return $this->sanitiseText($name, self::MAX_LABEL_LENGTH);
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $this->sanitiseText('' !== $path ? $host.'/'.$path : $host, self::MAX_LABEL_LENGTH);
    }

    /**
     * Make a string safe to embed as a Markdown link label and to store.
     *
     * "[" and "]" are removed because the chat renderer's Markdown pattern is
     * "\[([^\]]+)\]\(url\)" - a bracket inside the label would break the link and
     * leak raw Markdown into the answer. Contao's unresolved basic entities are
     * decoded first so a label never shows "[&]" to a visitor.
     */
    private function sanitiseText(string $value, int $maxLength): string
    {
        $value = $this->decodeBasicEntities($this->sanitiseControlChars($value));
        $value = str_replace(['[', ']'], ['(', ')'], $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        if ('' === $value) {
            return '';
        }

        if (mb_strlen($value) > $maxLength) {
            $value = rtrim(mb_substr($value, 0, $maxLength - 1)).'…';
        }

        return $value;
    }

    /**
     * Since Contao 5.0 these are no longer resolved automatically when a page is
     * rendered, so they can reach the indexer literally.
     */
    private function decodeBasicEntities(string $text): string
    {
        return str_replace(
            ['[&]', '[&amp;]', '[lt]', '[gt]', '[nbsp]', '[-]', '[zwsp]', '[lsqb]', '[rsqb]'],
            ['&', '&', '<', '>', ' ', '', '', '(', ')'],
            $text,
        );
    }

    /**
     * Removes C0/C7F control characters and the Unicode replacement character.
     * U+FFFD matters in practice: the HTML parser turns a NUL byte in the source
     * into it, so stripping only the control range would let a smuggled NUL
     * survive as visible garbage in a label.
     */
    private function sanitiseControlChars(string $value): string
    {
        return preg_replace('/[\x00-\x1F\x7F]+|\x{FFFD}+/u', '', $value) ?? '';
    }

    /**
     * True when the element or any ancestor marks site chrome.
     */
    private function isExcluded(\DOMElement $node): bool
    {
        if (\in_array(self::EXCLUDED_REL, preg_split('/\s+/', strtolower(trim($node->getAttribute('rel')))) ?: [], true)) {
            return true;
        }

        for ($el = $node; $el instanceof \DOMElement; $el = $el->parentNode) {
            if ($el->hasAttribute(self::IGNORE_ATTRIBUTE) || $el->hasAttribute(self::CONTAO_SKIP_ATTRIBUTE)) {
                return true;
            }

            if (\in_array(strtolower($el->nodeName), self::EXCLUDED_TAGS, true)) {
                return true;
            }

            if ($el->hasAttribute('hidden') || 'true' === strtolower($el->getAttribute('aria-hidden'))) {
                return true;
            }

            if (\in_array(strtolower(trim($el->getAttribute('role'))), self::EXCLUDED_ROLES, true)) {
                return true;
            }

            if (\in_array(strtolower(trim($el->getAttribute('id'))), ['header', 'footer', 'left', 'right', 'nav', 'cookiebar'], true)) {
                return true;
            }

            foreach ($this->classTokens($el) as $token) {
                if (\in_array($token, self::EXCLUDED_CLASSES, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Normalised class tokens of an element: lowercased, a leading Contao module
     * prefix removed ("mod_navigation" and "module-navigation" both become
     * "navigation") and "-" folded to "_", so one list matches every Contao
     * template generation.
     *
     * @return list<string>
     */
    private function classTokens(\DOMElement $element): array
    {
        $class = trim($element->getAttribute('class'));

        if ('' === $class) {
            return [];
        }

        $tokens = [];

        foreach (preg_split('/\s+/', strtolower($class)) ?: [] as $token) {
            if ('' === $token) {
                continue;
            }

            $token = str_replace('-', '_', $token);

            if (str_starts_with($token, 'mod_')) {
                $token = substr($token, 4);
            } elseif (str_starts_with($token, 'module_')) {
                $token = substr($token, 7);
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    private function resolveBaseHref(Crawler $crawler, string $fallback): string
    {
        try {
            $baseNodes = $crawler->filterXPath('//base[@href]');

            if ($baseNodes->count()) {
                $href = trim($this->sanitiseControlChars($baseNodes->first()->attr('href') ?? ''));

                if (preg_match('#^https?://#i', $href)) {
                    return $href;
                }
            }
        } catch (\Throwable) {
            // Malformed markup - fall through to the document URL.
        }

        return $fallback;
    }

    /**
     * @param list<string> $siteHosts
     *
     * @return array<string, true>
     */
    private function buildHostSet(string $baseUrl, array $siteHosts): array
    {
        $hosts = [];
        $baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        if ('' !== $baseHost) {
            $hosts[$this->bareHost($baseHost)] = true;
        }

        foreach ($siteHosts as $host) {
            $host = strtolower(trim($host));

            if ('' !== $host) {
                $hosts[$this->bareHost($host)] = true;
            }
        }

        return $hosts;
    }

    /**
     * A site served at example.com and one served at www.example.com are the same
     * site to every visitor, and the crawler may have indexed either variant.
     */
    private function bareHost(string $host): string
    {
        return preg_replace('/^www\./', '', $host) ?? $host;
    }
}
