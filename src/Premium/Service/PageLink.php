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
 * One outgoing link found on an indexed page.
 *
 * Immutable value object. Everything in here is sanitised at construction time by
 * PageLinkExtractor - the URL scheme is allow-listed, credentials are stripped and
 * the label is safe to embed in Markdown - because these values end up in a
 * document that is uploaded to OpenAI and quoted back to visitors.
 */
final class PageLink
{
    public const TYPE_PAGE = 'page';

    public const TYPE_FILE = 'file';

    public const TYPE_EXTERNAL = 'external';

    public const TYPE_MAILTO = 'mailto';

    public const TYPE_TEL = 'tel';

    public const TYPES = [self::TYPE_PAGE, self::TYPE_FILE, self::TYPE_EXTERNAL, self::TYPE_MAILTO, self::TYPE_TEL];

    /**
     * Preference used when a page has more links than may be stored or rendered:
     * a document is the most concrete thing a chatbot can hand a visitor, so
     * documents survive a cap before pages, and pages before external links.
     */
    private const TYPE_RANK = [
        self::TYPE_FILE => 0,
        self::TYPE_PAGE => 1,
        self::TYPE_EXTERNAL => 2,
        self::TYPE_MAILTO => 3,
        self::TYPE_TEL => 4,
    ];

    public function __construct(
        public readonly string $url,
        public readonly string $label,
        public readonly string $type,
        public readonly string $linkTitle = '',
        public readonly string $mime = '',
        public readonly string $filePath = '',
        public readonly int $fileSize = 0,
        public readonly int $position = 0,
        public readonly int $occurrences = 1,
    ) {
    }

    /**
     * Stable identity of the link target. Used for per-page de-duplication and as
     * the cross-page frequency key of PageLinkFilter.
     */
    public function urlHash(): string
    {
        return sha1($this->url);
    }

    /**
     * Loose identity of a link target, used only for "is this the same resource?"
     * comparisons against Contao's search index.
     *
     * Deliberately more forgiving than the URL itself: the scheme is dropped, a
     * leading "www." is removed and a trailing slash is normalised away, because a
     * page linked as "https://www.example.com/intern/" and indexed as
     * "http://example.com/intern" is the same page to every visitor. Used for the
     * protected-target check, where a miss would advertise a members-only URL.
     */
    public static function comparisonKey(string $url): string
    {
        $parts = parse_url($url);

        if (false === $parts || !isset($parts['host'])) {
            return rtrim(strtolower($url), '/');
        }

        $host = preg_replace('/^www\./', '', strtolower($parts['host'])) ?? $parts['host'];
        $path = rtrim($parts['path'] ?? '', '/');

        return $host.$path.(isset($parts['query']) && '' !== $parts['query'] ? '?'.$parts['query'] : '');
    }

    /**
     * Pick the better of two labels found for the SAME target, used wherever
     * duplicates of one link are merged.
     *
     * Length alone used to decide it, on the reasoning that a longer label is the
     * more descriptive one - which is true against "mehr" or "weiterlesen", the case
     * the rule was written for, and wrong in three shapes that are longer without
     * saying more: anchor text ("#kontakt" beats a page called "Kontakt" by one
     * character), a pasted address (long by nature, and it only repeats the
     * destination the line already carries), and the host/path or file name the
     * extractor synthesises for an anchor that has no text at all - an invented
     * label outranking a human-written one.
     *
     * So: rank first, length only within a rank. Ties keep the incumbent, which
     * makes the first occurrence in document order win, as before.
     */
    public static function betterLabel(string $current, string $candidate, string $url): string
    {
        $currentRank = self::labelRank($current, $url);
        $candidateRank = self::labelRank($candidate, $url);

        if ($currentRank !== $candidateRank) {
            return $candidateRank > $currentRank ? $candidate : $current;
        }

        return mb_strlen($candidate) > mb_strlen($current) ? $candidate : $current;
    }

    /**
     * Reduce a page's links to at most $max, keeping the most useful ones.
     *
     * Selection is by type preference (documents first), NOT by document order -
     * a resource page that lists thirty teaser links before its PDFs would
     * otherwise lose exactly the links this feature exists to surface. The
     * survivors are returned in their original document order so the rendered
     * list still follows the page.
     *
     * @param list<self> $links
     *
     * @return list<self>
     */
    public static function capByTypePreference(array $links, int $max): array
    {
        if ($max <= 0 || \count($links) <= $max) {
            return $links;
        }

        usort(
            $links,
            static fn (self $a, self $b): int => [self::TYPE_RANK[$a->type] ?? 9, $a->position, $a->url]
                <=> [self::TYPE_RANK[$b->type] ?? 9, $b->position, $b->url],
        );

        $kept = \array_slice($links, 0, $max);

        usort($kept, static fn (self $a, self $b): int => [$a->position, $a->url] <=> [$b->position, $b->url]);

        return $kept;
    }

    public function withOccurrences(int $occurrences): self
    {
        return new self(
            $this->url,
            $this->label,
            $this->type,
            $this->linkTitle,
            $this->mime,
            $this->filePath,
            $this->fileSize,
            $this->position,
            $occurrences,
        );
    }

    public function withLabel(string $label): self
    {
        return new self(
            $this->url,
            $label,
            $this->type,
            $this->linkTitle,
            $this->mime,
            $this->filePath,
            $this->fileSize,
            $this->position,
            $this->occurrences,
        );
    }

    /**
     * Returns a copy carrying resolved file metadata. Used by the Contao-coupled
     * resolver, so the extractor itself stays framework-free.
     */
    public function withFileMetadata(string $mime, int $fileSize): self
    {
        return new self(
            $this->url,
            $this->label,
            $this->type,
            $this->linkTitle,
            '' !== $mime ? $mime : $this->mime,
            $this->filePath,
            $fileSize > 0 ? $fileSize : $this->fileSize,
            $this->position,
            $this->occurrences,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'label' => $this->label,
            'type' => $this->type,
            'link_title' => $this->linkTitle,
            'mime' => $this->mime,
            'file_path' => $this->filePath,
            'file_size' => $this->fileSize,
            'position' => $this->position,
            'occurrences' => $this->occurrences,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['url'] ?? ''),
            (string) ($row['label'] ?? ''),
            (string) ($row['type'] ?? self::TYPE_EXTERNAL),
            (string) ($row['link_title'] ?? ''),
            (string) ($row['mime'] ?? ''),
            (string) ($row['file_path'] ?? ''),
            (int) ($row['file_size'] ?? 0),
            (int) ($row['position'] ?? 0),
            max(1, (int) ($row['occurrences'] ?? 1)),
        );
    }

    /**
     * 1 for a label a human wrote, 0 for one that only restates the target.
     *
     * The "restates the target" test recomputes the shapes PageLinkExtractor's
     * fallbackLabel() produces, rather than asking it: this has to work in the
     * repository too, where the labels come back out of the database and the
     * extractor is not in play. It misses one shape - the file name taken from a
     * download query - and that is deliberate, because missing a demotion only
     * leaves the previous length comparison in charge.
     */
    private static function labelRank(string $label, string $url): int
    {
        $label = trim($label);

        if ('' === $label) {
            return 0;
        }

        // Names a section of the target, not the target.
        if (str_starts_with($label, '#')) {
            return 0;
        }

        if (1 === preg_match('#^(https?://|www\.)#i', $label)) {
            return 0;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $trimmedPath = trim($path, '/');

        $synthesised = [
            '' !== $trimmedPath ? $host.'/'.$trimmedPath : $host,
            rawurldecode(basename($path)),
        ];

        foreach ($synthesised as $shape) {
            if ('' !== $shape && 0 === strcasecmp($label, $shape)) {
                return 0;
            }
        }

        return 1;
    }
}
