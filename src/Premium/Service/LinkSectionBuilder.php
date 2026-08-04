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
 * Renders the "Weiterführende Links" section that is appended to a page document.
 *
 * The section is built deterministically and appended AFTER any LLM rewrite, so no
 * model can ever truncate, reword or invent a URL, the output is byte-stable (which
 * keeps the incremental content hash meaningful) and it costs no tokens.
 *
 * Everything emitted here has to survive the chat frontend's Markdown link pattern
 *   \[([^\]]+)\]\((https?://…)\)
 * so labels must not contain brackets (guaranteed by PageLinkExtractor and
 * re-checked here) and URLs are percent-encoded where a raw character would
 * terminate the pattern early.
 */
class LinkSectionBuilder
{
    /**
     * Rendering order of the groups. "file" first: a document is the most concrete
     * thing a chatbot can hand a visitor.
     */
    private const GROUP_ORDER = [
        PageLink::TYPE_FILE,
        PageLink::TYPE_PAGE,
        PageLink::TYPE_EXTERNAL,
        PageLink::TYPE_MAILTO,
        PageLink::TYPE_TEL,
    ];

    /**
     * Section headings per language, English as the fallback for every other one.
     */
    private const HEADINGS = [
        'de' => [
            'section' => 'Weiterführende Links auf „%s"',
            'section_plain' => 'Weiterführende Links',
            PageLink::TYPE_FILE => 'Dokumente und Downloads',
            PageLink::TYPE_PAGE => 'Seiten auf dieser Website',
            PageLink::TYPE_EXTERNAL => 'Externe Links',
            PageLink::TYPE_MAILTO => 'E-Mail-Adressen',
            PageLink::TYPE_TEL => 'Telefonnummern',
        ],
        'en' => [
            'section' => 'Related links on "%s"',
            'section_plain' => 'Related links',
            PageLink::TYPE_FILE => 'Documents and downloads',
            PageLink::TYPE_PAGE => 'Pages on this website',
            PageLink::TYPE_EXTERNAL => 'External links',
            PageLink::TYPE_MAILTO => 'E-mail addresses',
            PageLink::TYPE_TEL => 'Phone numbers',
        ],
    ];

    /**
     * Build the Markdown block for one page. Returns '' when nothing is left to
     * render, so the caller can append unconditionally.
     *
     * @param list<PageLink> $links
     */
    public function build(array $links, string $pageTitle, string $language): string
    {
        if ([] === $links) {
            return '';
        }

        $strings = $this->strings($language);
        $grouped = [];

        foreach ($links as $link) {
            $grouped[$link->type][] = $link;
        }

        $lines = [];
        $title = $this->plainText($pageTitle);

        // The page title is repeated in the heading because OpenAI chunks each
        // uploaded file independently: a chunk containing only the link list would
        // otherwise carry no context about which page it belongs to.
        $lines[] = '## '.('' !== $title
            ? \sprintf($strings['section'], $title)
            : $strings['section_plain']);

        foreach (self::GROUP_ORDER as $type) {
            if (empty($grouped[$type])) {
                continue;
            }

            $lines[] = '';
            $lines[] = '### '.$strings[$type];

            foreach ($grouped[$type] as $link) {
                $lines[] = $this->renderLink($link, $language);
            }
        }

        return implode("\n", $lines);
    }

    private function renderLink(PageLink $link, string $language): string
    {
        $label = $this->plainText($link->label);

        if ('' === $label) {
            $label = $link->url;
        }

        $line = '- ['.$label.']('.$this->markdownUrl($link->url).')';
        $hints = [];

        $extension = $this->extensionOf($link);

        if ('' !== $extension) {
            $hints[] = $extension;
        }

        if ($link->fileSize > 0) {
            $hints[] = $this->formatBytes($link->fileSize, $language);
        }

        // A title attribute that just repeats the label adds nothing.
        $title = $this->plainText($link->linkTitle);

        if ('' !== $title && 0 !== strcasecmp($title, $label)) {
            $hints[] = $title;
        }

        if ([] !== $hints) {
            $line .= ' — '.implode(', ', $hints);
        }

        return $line;
    }

    /**
     * Percent-encode the characters that would terminate the frontend's Markdown
     * destination pattern early. Parentheses are only encoded when unbalanced -
     * balanced ones are explicitly supported by the renderer and are common in
     * real URLs ("…/Function_(mathematics)").
     */
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

    /**
     * Uppercase file type hint ("PDF", "DOCX") derived from the stored path or the
     * URL, so a visitor knows what a link hands them before clicking.
     */
    private function extensionOf(PageLink $link): string
    {
        if (PageLink::TYPE_FILE !== $link->type) {
            return '';
        }

        $source = '' !== $link->filePath ? $link->filePath : (string) parse_url($link->url, PHP_URL_PATH);
        $extension = pathinfo($source, PATHINFO_EXTENSION);

        if ('' === $extension || mb_strlen($extension) > 5 || !preg_match('/^[a-z0-9]+$/i', $extension)) {
            return '';
        }

        return strtoupper($extension);
    }

    private function formatBytes(int $bytes, string $language): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < \count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        $decimals = $unit > 0 && $value < 100 ? 1 : 0;
        $formatted = number_format($value, $decimals, $this->isGerman($language) ? ',' : '.', '');

        return $formatted.' '.$units[$unit];
    }

    /**
     * Defence in depth: the extractor already removed brackets and control
     * characters, but this is the last step before the text is uploaded.
     */
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
        return $this->isGerman($language) ? self::HEADINGS['de'] : self::HEADINGS['en'];
    }

    private function isGerman(string $language): bool
    {
        return str_starts_with(strtolower(trim($language)), 'de');
    }
}
