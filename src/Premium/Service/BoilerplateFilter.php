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

namespace JuheItSolutions\ContaoOpenaiAssistant\Premium\Service;

/**
 * Removes cross-page boilerplate (navigation, breadcrumbs, footers, cookie notices ...)
 * from auto-extracted page text WITHOUT risking unique content.
 *
 * The safety guarantee is statistical: a text segment is only dropped when it appears on a
 * large fraction of the indexed pages. Unique page content cannot reach that frequency, so
 * it is never removed. Site chrome - repeated verbatim on (nearly) every page - is the only
 * thing that qualifies.
 *
 * The filter is format-agnostic: Contao's indexer stores plain text whose whitespace layout
 * is not guaranteed, so segmentation falls back from line breaks to sentence boundaries.
 */
class BoilerplateFilter
{
    /**
     * Below this page count there is not enough signal to tell chrome from content, so the
     * filter is a no-op (returns the input unchanged).
     */
    private const MIN_PAGES = 4;

    /**
     * A segment is boilerplate when it appears on at least this fraction of pages.
     */
    private const DEFAULT_THRESHOLD = 0.5;

    /**
     * A segment must also appear on at least this many pages. Guards small corpora where a
     * single coincidental repetition could otherwise cross the fraction threshold.
     */
    private const MIN_OCCURRENCES = 3;

    /**
     * Clean a set of pages. Input and output are keyed identically so callers can map the
     * result back to their page records.
     *
     * @param array<int|string, string> $texts     page key => raw extracted text
     * @param float                     $threshold fraction of pages a segment must hit (0-1)
     *
     * @return array{texts: array<int|string, string>, stats: array{removed_segments: int, samples: list<string>}}
     */
    public function clean(array $texts, float $threshold = self::DEFAULT_THRESHOLD): array
    {
        $pageCount = \count($texts);

        if ($pageCount < self::MIN_PAGES) {
            return ['texts' => $texts, 'stats' => ['removed_segments' => 0, 'samples' => []]];
        }

        $threshold = min(0.95, max(0.2, $threshold));

        // Two passes, re-segmenting in the second, so we never hold every page's segments in
        // memory at once - only the frequency map survives between passes. This keeps peak
        // memory close to the input size even for sites with thousands of pages.

        // Pass 1: document frequency of each normalised segment (counted once per page).
        $documentFrequency = [];

        foreach ($texts as $text) {
            $seen = [];

            foreach ($this->segment((string) $text) as $segment) {
                $norm = $this->normalise($segment);
                if ('' === $norm || isset($seen[$norm])) {
                    continue;
                }
                $seen[$norm] = true;
                $documentFrequency[$norm] = ($documentFrequency[$norm] ?? 0) + 1;
            }
        }

        $minPages = max(self::MIN_OCCURRENCES, (int) ceil($threshold * $pageCount));

        // Build the boilerplate set: segments appearing on >= minPages pages.
        $boilerplate = [];

        foreach ($documentFrequency as $norm => $freq) {
            if ($freq >= $minPages) {
                $boilerplate[$norm] = true;
            }
        }
        unset($documentFrequency);

        // Pass 2: rebuild each page without its boilerplate segments.
        $cleaned = [];
        $removedCount = 0;
        $samples = [];

        foreach ($texts as $key => $text) {
            $kept = [];

            foreach ($this->segment((string) $text) as $segment) {
                $norm = $this->normalise($segment);

                if ('' !== $norm && isset($boilerplate[$norm])) {
                    ++$removedCount;
                    if (\count($samples) < 10 && !\in_array($segment, $samples, true)) {
                        $samples[] = $segment;
                    }

                    continue;
                }

                $kept[] = $segment;
            }

            $cleaned[$key] = trim(implode("\n", $kept));
        }

        return [
            'texts' => $cleaned,
            'stats' => ['removed_segments' => $removedCount, 'samples' => $samples],
        ];
    }

    /**
     * Split text into comparable segments. Prefers line breaks; if the text is one long
     * blob (the indexer collapsed whitespace), it falls back to sentence-ish boundaries so
     * the frequency analysis still has units to compare.
     *
     * @return list<string>
     */
    private function segment(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = preg_split('/\n+/', $text) ?: [];

        $segments = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            // A "line" that is really a paragraph blob gets split further on sentence
            // boundaries so menu items glued onto a sentence can still be isolated.
            if (mb_strlen($line) > 200) {
                $parts = preg_split('/(?<=[.!?:])\s+/u', $line) ?: [$line];

                foreach ($parts as $part) {
                    $part = trim($part);
                    if ('' !== $part) {
                        $segments[] = $part;
                    }
                }

                continue;
            }

            $segments[] = $line;
        }

        return $segments;
    }

    /**
     * Normalise a segment for comparison: lowercase + collapsed whitespace. Used only as a
     * frequency key - the original segment text is what gets kept or removed.
     */
    private function normalise(string $segment): string
    {
        $segment = preg_replace('/\s+/u', ' ', $segment) ?? $segment;

        return mb_strtolower(trim($segment));
    }
}
