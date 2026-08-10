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
 * Builds one extra vector-store document: a directory of every document and every
 * page of the website.
 *
 * Why it earns its own file: the per-page link sections only help once the right
 * page has been retrieved. This directory answers "do you have a PDF about X?" and
 * "is there a page about Y?" directly, because every title lives in one densely
 * titled document. It is uploaded with page_id = 0 and therefore never consumes a
 * page of the customer's plan quota - it is built after the plan cap has been
 * applied.
 */
class LinkIndexDocumentBuilder
{
    /**
     * Entries per section. A directory that grows without bound would stop being
     * useful for retrieval long before it hits any API limit.
     */
    private const MAX_ENTRIES = 2000;

    /**
     * How many linking pages are named per document.
     */
    private const MAX_SOURCES = 3;

    private const STRINGS = [
        'de' => [
            'title' => 'Link- und Dokumentenverzeichnis',
            'intro' => 'Dieses Verzeichnis listet alle Dokumente und Seiten dieser Website mit ihrer Adresse auf.',
            'documents' => 'Dokumente und Downloads',
            'pages' => 'Seitenverzeichnis',
            'linked_on' => 'verlinkt auf',
            'truncated' => '_(Verzeichnis gekürzt - es enthält nur die ersten %d Einträge.)_',
        ],
        'en' => [
            'title' => 'Link and document directory',
            'intro' => 'This directory lists every document and every page of this website together with its address.',
            'documents' => 'Documents and downloads',
            'pages' => 'Page directory',
            'linked_on' => 'linked on',
            'truncated' => '_(Directory truncated - it contains the first %d entries only.)_',
        ],
    ];

    public function __construct(private readonly LinkSectionBuilder $links = new LinkSectionBuilder())
    {
    }

    public function title(string $language): string
    {
        return $this->strings($language)['title'];
    }

    /**
     * @param list<array{page_id: int, url: string, title: string, language: string}> $pages
     * @param array<int, list<PageLink>>                                              $linksByPage
     */
    public function build(array $pages, array $linksByPage, string $language, string $siteLabel = ''): string
    {
        $strings = $this->strings($language);
        $documents = $this->collectDocuments($pages, $linksByPage);

        if ([] === $documents && [] === $pages) {
            return '';
        }

        $heading = $strings['title'];

        if ('' !== $siteLabel) {
            $heading .= ' - '.$siteLabel;
        }

        $lines = [
            '# '.$heading,
            '',
            $strings['intro'],
        ];

        if ([] !== $documents) {
            $lines[] = '';
            $lines[] = \sprintf('## %s (%d)', $strings['documents'], \count($documents));

            foreach (\array_slice($documents, 0, self::MAX_ENTRIES) as $document) {
                $lines[] = $this->renderDocument($document, $language, $strings['linked_on']);
            }

            if (\count($documents) > self::MAX_ENTRIES) {
                $lines[] = \sprintf($strings['truncated'], self::MAX_ENTRIES);
            }
        }

        if ([] !== $pages) {
            $lines[] = '';
            $lines[] = \sprintf('## %s (%d)', $strings['pages'], \count($pages));

            foreach (\array_slice($pages, 0, self::MAX_ENTRIES) as $page) {
                $title = $this->plainText((string) $page['title']);
                $url = (string) $page['url'];

                if ('' === $url) {
                    continue;
                }

                $lines[] = '- ['.('' !== $title ? $title : $url).']('.$this->markdownUrl($url).')';
            }

            if (\count($pages) > self::MAX_ENTRIES) {
                $lines[] = \sprintf($strings['truncated'], self::MAX_ENTRIES);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{link: PageLink, sources: list<string>} $document
     */
    private function renderDocument(array $document, string $language, string $linkedOn): string
    {
        $link = $document['link'];
        $line = $this->links->build([$link], '', $language);

        // Reuse the per-link rendering of LinkSectionBuilder (label escaping, file
        // type and size hints) and keep only its list line.
        $rendered = '';

        foreach (explode("\n", $line) as $candidate) {
            if (str_starts_with($candidate, '- [')) {
                $rendered = $candidate;
                break;
            }
        }

        if ('' === $rendered) {
            return '';
        }

        if ([] !== $document['sources']) {
            $sources = \array_slice($document['sources'], 0, self::MAX_SOURCES);
            $suffix = implode(', ', $sources);

            if (\count($document['sources']) > self::MAX_SOURCES) {
                $suffix .= ', …';
            }

            $rendered .= ' · '.$linkedOn.': '.$suffix;
        }

        return $rendered;
    }

    /**
     * De-duplicate documents across the whole site and remember which pages link
     * to them.
     *
     * @param list<array{page_id: int, url: string, title: string, language: string}> $pages
     * @param array<int, list<PageLink>>                                              $linksByPage
     *
     * @return list<array{link: PageLink, sources: list<string>}>
     */
    private function collectDocuments(array $pages, array $linksByPage): array
    {
        $titles = [];

        foreach ($pages as $page) {
            $title = $this->plainText((string) $page['title']);
            $titles[(int) $page['page_id']] = '' !== $title ? $title : (string) $page['url'];
        }

        /** @var array<string, array{link: PageLink, sources: list<string>}> $documents */
        $documents = [];

        foreach ($linksByPage as $pageId => $links) {
            foreach ($links as $link) {
                if (PageLink::TYPE_FILE !== $link->type) {
                    continue;
                }

                $key = $link->urlHash();
                $source = $titles[(int) $pageId] ?? '';

                if (!isset($documents[$key])) {
                    $documents[$key] = ['link' => $link, 'sources' => []];
                } elseif (mb_strlen($link->label) > mb_strlen($documents[$key]['link']->label)) {
                    // Keep the most descriptive label found anywhere on the site.
                    $documents[$key]['link'] = $link;
                }

                if ('' !== $source && !\in_array($source, $documents[$key]['sources'], true)) {
                    $documents[$key]['sources'][] = $source;
                }
            }
        }

        return array_values($documents);
    }

    private function markdownUrl(string $url): string
    {
        $url = str_replace(
            [' ', '<', '>', '"', "'", '[', ']'],
            ['%20', '%3C', '%3E', '%22', '%27', '%5B', '%5D'],
            $url,
        );

        if (substr_count($url, '(') !== substr_count($url, ')')) {
            $url = str_replace(['(', ')'], ['%28', '%29'], $url);
        }

        return $url;
    }

    private function plainText(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value) ?? '';
        $value = str_replace(['[', ']'], ['(', ')'], $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return array<string, string>
     */
    private function strings(string $language): array
    {
        return str_starts_with(strtolower(trim($language)), 'de') ? self::STRINGS['de'] : self::STRINGS['en'];
    }
}
