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
 * Adds the on-disk size of linked documents, so the chatbot can say
 * "Preisliste 2026 (PDF, 1,2 MB)" instead of just naming a file.
 *
 * Deliberately the only place in this feature that touches the file system, and it
 * only ever stats - it never reads, writes or opens anything. Two independent
 * guards keep it inside the upload directory:
 *
 *   1. PageLinkExtractor already collapsed "." / ".." segments before deciding a
 *      URL points into the upload path, so the incoming relative path is clean.
 *   2. realpath() is re-checked against the resolved upload directory here, which
 *      also defeats a symlink inside the upload directory pointing outside of it.
 */
class LinkedFileMetadataResolver
{
    private string|false|null $uploadRoot = null;

    public function __construct(
        private readonly string $projectDir,
        private readonly string $uploadPath = 'files',
    ) {
    }

    /**
     * @param list<PageLink> $links
     *
     * @return list<PageLink>
     */
    public function enrich(array $links): array
    {
        $root = $this->uploadRoot();

        if (false === $root) {
            return $links;
        }

        $enriched = [];

        foreach ($links as $link) {
            $size = PageLink::TYPE_FILE === $link->type && '' !== $link->filePath
                ? $this->sizeOf($link->filePath, $root)
                : 0;

            $enriched[] = $size > 0 ? $link->withFileMetadata('', $size) : $link;
        }

        return $enriched;
    }

    private function sizeOf(string $relativePath, string $root): int
    {
        // Defence in depth: the extractor already rejected traversal, but a path
        // that still contains ".." must never be stat'ed.
        if (str_contains($relativePath, '..') || str_contains($relativePath, "\0")) {
            return 0;
        }

        $candidate = realpath($this->projectDir.'/'.$relativePath);

        if (false === $candidate || !is_file($candidate)) {
            return 0;
        }

        if (!str_starts_with($candidate, $root.\DIRECTORY_SEPARATOR)) {
            return 0;
        }

        $size = @filesize($candidate);

        return \is_int($size) && $size > 0 ? $size : 0;
    }

    private function uploadRoot(): string|false
    {
        if (null === $this->uploadRoot) {
            $this->uploadRoot = realpath($this->projectDir.'/'.trim($this->uploadPath, '/'));
        }

        return $this->uploadRoot;
    }
}
