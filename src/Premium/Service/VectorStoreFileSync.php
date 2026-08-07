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

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Reconciles the OpenAI vector store with the current set of pages, one file per page.
 *
 * State lives in tl_openai_vector_file (page_id -> openai_file_id + content_hash). Each run:
 *   - uploads NEW or CHANGED pages (content_hash differs),
 *   - leaves UNCHANGED pages untouched (incremental - cheap at scale),
 *   - deletes files for pages REMOVED from scope,
 *   - (once) detaches the legacy single bulk file produced by the old pipeline.
 *
 * No content limit is ever applied: a page that would exceed the OpenAI per-file ceiling is
 * split into multiple chunk-files - never truncated. In practice a single page is orders of
 * magnitude below the 512 MB / 5,000,000-token per-file limit, so splitting is a safety net.
 */
class VectorStoreFileSync
{
    private const OPENAI_BASE = 'https://api.openai.com/v1';

    /**
     * Hard safety ceiling per file, in characters. Far below OpenAI's 5,000,000-token /
     * 512 MB limit; a page above this is split, guaranteeing we never truncate content.
     */
    private const MAX_FILE_CHARS = 2_000_000;

    /**
     * Max seconds to wait for a single file's server-side ingestion before moving on.
     */
    private const INGEST_WAIT_SECONDS = 30;

    /**
     * How many times a rate-limited (429) or transiently failing call is retried.
     */
    private const MAX_RETRIES = 5;

    /**
     * Upper bound on a single backoff sleep, in seconds.
     */
    private const MAX_BACKOFF_SECONDS = 60;

    /**
     * Latin letters with diacritics mapped to ASCII for the upload file name. German
     * umlauts follow the DIN convention (ä -> ae) because that is what a German page title
     * is expected to look like; the rest is a plain accent strip.
     */
    private const TRANSLITERATION = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A', 'Æ' => 'Ae',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'oe',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ø' => 'Oe',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U',
        'ç' => 'c', 'Ç' => 'C', 'ñ' => 'n', 'Ñ' => 'N',
        'ý' => 'y', 'ÿ' => 'y', 'Ý' => 'Y',
        'š' => 's', 'Š' => 'S', 'ž' => 'z', 'Ž' => 'Z', 'č' => 'c', 'Č' => 'C',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<array{page_id: int, url: string, title: string, language: string, content: string, search_checksum: string}> $pages
     * @param (callable(int, int):void)|null                                                                                    $progress called with (pages done, pages total) once before the loop and after every processed page, so the orchestrator can publish live progress and refresh the run lease during a long sync
     *
     * @return array{added: int, updated: int, removed: int, unchanged: int, files_uploaded: int, files_failed: int, bytes: int, page_states: array<int, array{state: string, files: list<string>}>} page_states maps every page in scope to what happened to it and which vector-store file(s) now hold it - the orchestrator writes it into the downloadable manifest so operators can match a page to a file id in the OpenAI platform
     */
    public function sync(string $apiKey, string $vectorStoreId, int $configId, array $pages, string $legacyFileId = '', callable|null $progress = null): array
    {
        // One-time cleanup: the old pipeline left a single bulk file. Remove it the first
        // time the per-page sync runs so the store does not keep a stale superset document.
        if ('' !== $legacyFileId) {
            $this->detachAndDelete($apiKey, $vectorStoreId, $legacyFileId);
        }

        $existing = $this->loadState($configId);

        $stats = [
            'added' => 0,
            'updated' => 0,
            'removed' => 0,
            'unchanged' => 0,
            'files_uploaded' => 0,
            'files_failed' => 0,
            'bytes' => 0,
            'page_states' => [],
        ];

        $seenPageIds = [];
        $pagesTotal = \count($pages);
        $pagesDone = 0;

        if (null !== $progress) {
            $progress(0, $pagesTotal);
        }

        foreach ($pages as $page) {
            $pageId = $page['page_id'];
            $seenPageIds[$pageId] = true;
            $contentHash = hash('sha256', $page['content']);

            $current = $existing[$pageId] ?? null;

            // Unchanged: same content already uploaded successfully -> skip (incremental).
            if (null !== $current && $current['content_hash'] === $contentHash && 'uploaded' === $current['status']) {
                ++$stats['unchanged'];
                $stats['page_states'][$pageId] = ['state' => 'unchanged', 'files' => $current['files']];
                ++$pagesDone;
                if (null !== $progress) {
                    $progress($pagesDone, $pagesTotal);
                }

                continue;
            }

            $chunks = $this->splitContent($page['content']);
            $chunkCount = \count($chunks);
            $pageOk = true;
            $replacementRows = [];
            $replacementFileIds = [];

            foreach ($chunks as $i => $chunk) {
                $document = $this->buildDocument($page, $chunk, $i, $chunkCount);
                $bytes = \strlen($document);

                try {
                    $fileId = $this->uploadFile($apiKey, $document, $this->buildFilename($page, $i, $chunkCount));
                    $replacementFileIds[] = $fileId;
                    $this->attachToStore($apiKey, $vectorStoreId, $fileId, $page, $contentHash, $i, $chunkCount);
                    $this->waitForIngestion($apiKey, $vectorStoreId, $fileId);

                    $replacementRows[] = [$configId, $page, $contentHash, $fileId, $bytes, $i, $chunkCount, 'uploaded', null];
                    ++$stats['files_uploaded'];
                    $stats['bytes'] += $bytes;
                } catch (\Throwable $e) {
                    $pageOk = false;
                    ++$stats['files_failed'];
                    $this->logger->error('Vector file upload failed for page '.$pageId.' chunk '.$i.': '.$e->getMessage());

                    if (null === $current) {
                        $this->insertState($configId, $page, $contentHash, '', $bytes, $i, $chunkCount, 'failed', $e->getMessage());
                    }
                }
            }

            if ($pageOk) {
                // A changed page is swapped only after every replacement chunk was uploaded,
                // attached and ingested. Until this point the previous files remain queryable.
                try {
                    $this->replacePageState($configId, $pageId, $replacementRows);
                } catch (\Throwable $e) {
                    foreach ($replacementFileIds as $replacementFileId) {
                        $this->detachAndDelete($apiKey, $vectorStoreId, $replacementFileId);
                    }

                    throw $e;
                }

                if (null !== $current) {
                    foreach ($current['files'] as $oldFileId) {
                        $this->detachAndDelete($apiKey, $vectorStoreId, $oldFileId);
                    }
                }

                $stats['page_states'][$pageId] = [
                    'state' => null === $current ? 'added' : 'updated',
                    'files' => $replacementFileIds,
                ];

                null === $current ? ++$stats['added'] : ++$stats['updated'];
            } else {
                if ([] !== $replacementFileIds) {
                    // Partial replacement files would duplicate old knowledge for changed pages
                    // (or leave orphan chunks for new pages), so remove them best-effort.
                    foreach ($replacementFileIds as $replacementFileId) {
                        $this->detachAndDelete($apiKey, $vectorStoreId, $replacementFileId);
                    }
                }

                // The swap never happened, so whatever the store held before this run still
                // answers queries for this page - nothing at all for a brand-new page.
                $stats['page_states'][$pageId] = ['state' => 'failed', 'files' => $current['files'] ?? []];
            }

            ++$pagesDone;
            if (null !== $progress) {
                $progress($pagesDone, $pagesTotal);
            }
        }

        // Delete pages that dropped out of scope.
        foreach ($existing as $pageId => $row) {
            if (isset($seenPageIds[$pageId])) {
                continue;
            }

            // Counter stays at total during cleanup; the call still refreshes the run lease.
            if (null !== $progress) {
                $progress($pagesTotal, $pagesTotal);
            }

            foreach ($row['files'] as $fileId) {
                $this->detachAndDelete($apiKey, $vectorStoreId, $fileId);
            }
            $this->connection->delete('tl_openai_vector_file', ['pid' => $configId, 'page_id' => $pageId]);
            ++$stats['removed'];
        }

        return $stats;
    }

    /**
     * Remove every file this config tracks (used when the feature is reset / config deleted).
     */
    public function purge(string $apiKey, string $vectorStoreId, int $configId): void
    {
        foreach ($this->loadState($configId) as $row) {
            foreach ($row['files'] as $fileId) {
                $this->detachAndDelete($apiKey, $vectorStoreId, $fileId);
            }
        }

        $this->connection->delete('tl_openai_vector_file', ['pid' => $configId]);
    }

    /**
     * @return array<int, array{content_hash: string, status: string, files: list<string>}>
     */
    private function loadState(int $configId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT page_id, content_hash, status, openai_file_id FROM tl_openai_vector_file WHERE pid = ?',
            [$configId],
        );

        $state = [];

        foreach ($rows as $row) {
            $pageId = (int) $row['page_id'];
            if (!isset($state[$pageId])) {
                $state[$pageId] = [
                    'content_hash' => (string) $row['content_hash'],
                    'status' => (string) $row['status'],
                    'files' => [],
                ];
            }

            // A page is only "uploaded" when every one of its chunks is.
            if ('uploaded' !== $row['status']) {
                $state[$pageId]['status'] = (string) $row['status'];
            }

            $fileId = (string) $row['openai_file_id'];
            if ('' !== $fileId) {
                $state[$pageId]['files'][] = $fileId;
            }
        }

        return $state;
    }

    /**
     * Atomically swap the tracked files for one page. Remote old files are deleted only after
     * this transaction succeeds; if the DB write fails, the previous tracking remains intact.
     *
     * @param list<array{0: int, 1: array{page_id: int, url: string, title: string, language: string, search_checksum: string}, 2: string, 3: string, 4: int, 5: int, 6: int, 7: string, 8: string|null}> $rows
     */
    private function replacePageState(int $configId, int $pageId, array $rows): void
    {
        $this->connection->transactional(
            function () use ($configId, $pageId, $rows): void {
                $this->connection->delete('tl_openai_vector_file', ['pid' => $configId, 'page_id' => $pageId]);

                foreach ($rows as $row) {
                    $this->insertState(...$row);
                }
            },
        );
    }

    /**
     * @param array{page_id: int, url: string, title: string, language: string, search_checksum: string} $page
     */
    private function insertState(int $configId, array $page, string $contentHash, string $fileId, int $bytes, int $chunkIndex, int $chunkCount, string $status, string|null $error): void
    {
        $this->connection->insert('tl_openai_vector_file', [
            'pid' => $configId,
            'tstamp' => time(),
            'page_id' => $page['page_id'],
            'url' => mb_substr($page['url'], 0, 2048),
            'title' => mb_substr($page['title'], 0, 512),
            'language' => mb_substr($page['language'], 0, 5),
            'search_checksum' => mb_substr($page['search_checksum'], 0, 32),
            'content_hash' => $contentHash,
            'chunk_index' => $chunkIndex,
            'chunk_count' => $chunkCount,
            'openai_file_id' => $fileId,
            'bytes' => $bytes,
            'status' => $status,
            'last_error' => $error,
        ]);
    }

    /**
     * @param array{url: string, title: string} $page
     */
    private function buildDocument(array $page, string $chunk, int $chunkIndex, int $chunkCount): string
    {
        $title = trim($page['title']);
        $heading = '' !== $title ? '# '.$title : '# '.$page['url'];

        if ($chunkCount > 1) {
            $heading .= \sprintf(' (Teil %d/%d)', $chunkIndex + 1, $chunkCount);
        }

        // The source URL is kept inline so retrieved chunks can be cited, and also stored as
        // a file attribute for attribute-filtered search.
        return $heading."\n\nQuelle: ".$page['url']."\n\n".$chunk;
    }

    /**
     * Split only if a page exceeds the safety ceiling - at paragraph boundaries, never
     * mid-content. Returns at least one chunk.
     *
     * @return list<string>
     */
    private function splitContent(string $content): array
    {
        if (mb_strlen($content) <= self::MAX_FILE_CHARS) {
            return [$content];
        }

        $paragraphs = preg_split('/\n{2,}/', $content) ?: [$content];
        $chunks = [];
        $buffer = '';

        foreach ($paragraphs as $paragraph) {
            if ('' !== $buffer && mb_strlen($buffer) + mb_strlen($paragraph) + 2 > self::MAX_FILE_CHARS) {
                $chunks[] = $buffer;
                $buffer = '';
            }

            // A single paragraph larger than the ceiling is hard-split as a last resort -
            // still no content loss, just a mechanical cut.
            while (mb_strlen($paragraph) > self::MAX_FILE_CHARS) {
                $chunks[] = mb_substr($paragraph, 0, self::MAX_FILE_CHARS);
                $paragraph = mb_substr($paragraph, self::MAX_FILE_CHARS);
            }

            $buffer = '' === $buffer ? $paragraph : $buffer."\n\n".$paragraph;
        }

        if ('' !== $buffer) {
            $chunks[] = $buffer;
        }

        return $chunks;
    }

    /**
     * Build the name the file is uploaded under. OpenAI shows it verbatim in the platform's
     * file list, and it is the only human-readable handle there - so it carries the page id
     * (the key into tl_openai_vector_file and the manifest) plus a slug of the title.
     *
     * @param array{page_id: int, url: string, title: string} $page
     */
    private function buildFilename(array $page, int $chunkIndex, int $chunkCount): string
    {
        $source = trim($page['title']);

        if ('' === $source) {
            // Fall back to the URL path; a page with neither title nor path keeps the id alone.
            $source = trim((string) parse_url($page['url'], PHP_URL_PATH), '/');
        }

        // ASCII only: the multipart filename travels in a header, and a transliterated slug
        // stays readable in the OpenAI UI where raw UTF-8 may not. Everything the map does
        // not cover (Greek, Cyrillic, CJK, …) collapses into separators below - the page id
        // in front of the slug remains the reliable identifier either way.
        $slug = strtolower(strtr($source, self::TRANSLITERATION));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        if (mb_strlen($slug) > 60) {
            $slug = rtrim(mb_substr($slug, 0, 60), '-');
        }

        // page_id 0 is the synthetic site-wide link directory, not a real page.
        $name = 0 === $page['page_id'] ? 'seite-index' : 'seite-'.$page['page_id'];

        if ('' !== $slug) {
            $name .= '-'.$slug;
        }

        if ($chunkCount > 1) {
            $name .= \sprintf('-teil-%d-von-%d', $chunkIndex + 1, $chunkCount);
        }

        return $name.'.md';
    }

    private function uploadFile(string $apiKey, string $content, string $filename): string
    {
        // Symfony's multipart encoder takes the upload filename from the stream's path, and
        // OpenAI stores that name. The uniqueness suffix therefore goes on a throwaway
        // DIRECTORY rather than the file, so it never leaks into what operators see.
        $dir = sys_get_temp_dir().'/contao_vs_'.bin2hex(random_bytes(16));

        if (!@mkdir($dir, 0700) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create temp directory for upload.');
        }

        $tmpPath = $dir.'/'.$filename;

        $handle = @fopen($tmpPath, 'x+');
        if (false === $handle) {
            @rmdir($dir);

            throw new \RuntimeException('Could not create temp file for upload.');
        }

        try {
            $written = fwrite($handle, $content);
            if (\strlen($content) !== $written) {
                // Disk full or quota hit: abort instead of uploading a silently
                // truncated document.
                throw new \RuntimeException('Could not write temp file for upload (disk full?).');
            }

            // The stream is consumed on each send, so rewind it before every (re)try.
            $response = $this->request(
                'POST',
                self::OPENAI_BASE.'/files',
                static function () use ($apiKey, $handle): array {
                    rewind($handle);

                    return [
                        'headers' => ['Authorization' => 'Bearer '.$apiKey],
                        'body' => ['purpose' => 'assistants', 'file' => $handle],
                        'timeout' => 120,
                    ];
                },
            );

            $id = (string) ($response->toArray()['id'] ?? '');
            if ('' === $id) {
                throw new \RuntimeException('OpenAI Files API returned no file id.');
            }

            return $id;
        } finally {
            if (\is_resource($handle)) {
                fclose($handle);
            }
            @unlink($tmpPath);
            @rmdir($dir);
        }
    }

    /**
     * @param array{page_id: int, url: string, title: string, language: string} $page
     */
    private function attachToStore(string $apiKey, string $vectorStoreId, string $fileId, array $page, string $contentHash, int $chunkIndex, int $chunkCount): void
    {
        $attributes = [
            'page_id' => (string) $page['page_id'],
            'url' => mb_substr($page['url'], 0, 256),
            'title' => mb_substr($page['title'], 0, 256),
            'language' => mb_substr($page['language'], 0, 5),
            'content_hash' => $contentHash,
            'chunk' => ($chunkIndex + 1).'/'.$chunkCount,
        ];

        [$status, $body] = $this->postAttach($apiKey, $vectorStoreId, $fileId, $attributes);

        // Attributes are a newer vector-store feature. If the API rejects them (4xx), retry
        // once without attributes so attachment still succeeds on accounts/endpoints that do
        // not support them - retrieval works either way; attributes only aid filtering.
        if ($status >= 400 && $status < 500) {
            $this->logger->warning('Attach with attributes failed ('.$status.') for file '.$fileId.'; retrying without attributes.');
            [$status, $body] = $this->postAttach($apiKey, $vectorStoreId, $fileId, null);
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Attach failed ('.$status.'): '.(string) ($body['error']['message'] ?? 'unknown'));
        }
    }

    /**
     * @param array<string, string>|null $attributes
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function postAttach(string $apiKey, string $vectorStoreId, string $fileId, array|null $attributes): array
    {
        $json = ['file_id' => $fileId];
        if (null !== $attributes) {
            $json['attributes'] = $attributes;
        }

        $response = $this->request(
            'POST',
            self::OPENAI_BASE."/vector_stores/{$vectorStoreId}/files",
            [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $json,
                'timeout' => 60,
            ],
        );

        return [$response->getStatusCode(), $response->toArray(throw: false)];
    }

    /**
     * Poll the vector-store file until ingestion leaves "in_progress". Non-fatal: if it is
     * still processing after the budget, we return - the file is attached and will finish
     * server-side. A "failed" status throws so the page is recorded as failed.
     */
    private function waitForIngestion(string $apiKey, string $vectorStoreId, string $fileId): void
    {
        $deadline = time() + self::INGEST_WAIT_SECONDS;

        do {
            $data = $this->request(
                'GET',
                self::OPENAI_BASE."/vector_stores/{$vectorStoreId}/files/{$fileId}",
                [
                    'headers' => ['Authorization' => 'Bearer '.$apiKey],
                    'timeout' => 30,
                ],
            )->toArray(throw: false);

            $status = (string) ($data['status'] ?? 'completed');

            if ('failed' === $status) {
                throw new \RuntimeException('Vector store ingestion failed: '.(string) ($data['last_error']['message'] ?? 'unknown'));
            }

            if ('in_progress' !== $status) {
                return;
            }

            usleep(750_000);
        } while (time() < $deadline);
    }

    private function detachAndDelete(string $apiKey, string $vectorStoreId, string $fileId): void
    {
        if ('' === $fileId) {
            return;
        }

        try {
            $this->request(
                'DELETE',
                self::OPENAI_BASE."/vector_stores/{$vectorStoreId}/files/{$fileId}",
                [
                    'headers' => ['Authorization' => 'Bearer '.$apiKey],
                    'timeout' => 30,
                ],
            )->getStatusCode();
        } catch (\Throwable $e) {
            $this->logger->warning('Could not detach file '.$fileId.' from vector store: '.$e->getMessage());
        }

        try {
            $this->request(
                'DELETE',
                self::OPENAI_BASE."/files/{$fileId}",
                [
                    'headers' => ['Authorization' => 'Bearer '.$apiKey],
                    'timeout' => 30,
                ],
            )->getStatusCode();
        } catch (\Throwable $e) {
            $this->logger->warning('Could not delete file '.$fileId.' from OpenAI Files: '.$e->getMessage());
        }
    }

    /**
     * Perform an HTTP request, retrying on 429 / 503 / transport errors with exponential
     * backoff that honours the Retry-After header. $options may be a closure so callers with
     * a consumable body (an upload stream) can rebuild fresh options on each attempt.
     *
     * @param array<string, mixed>|\Closure(): array<string, mixed> $options
     */
    private function request(string $method, string $url, \Closure|array $options): ResponseInterface
    {
        $attempt = 0;

        while (true) {
            $opts = $options instanceof \Closure ? ($options)() : $options;

            try {
                $response = $this->http->request($method, $url, $opts);
                // getStatusCode() triggers the request but does not throw on 4xx/5xx.
                $status = $response->getStatusCode();

                if (!$this->isRetryable($status) || $attempt >= self::MAX_RETRIES) {
                    return $response;
                }

                $delay = $this->backoffDelay($attempt, $response);
                $this->logger->notice(\sprintf('OpenAI %s returned %d; backing off %ds (retry %d/%d).', $url, $status, $delay, $attempt + 1, self::MAX_RETRIES));
                $response->cancel(); // free the discarded response before retrying
            } catch (TransportExceptionInterface $e) {
                if ($attempt >= self::MAX_RETRIES) {
                    throw $e;
                }

                $delay = $this->backoffDelay($attempt, null);
                $this->logger->notice(\sprintf('OpenAI %s transport error "%s"; backing off %ds (retry %d/%d).', $url, $e->getMessage(), $delay, $attempt + 1, self::MAX_RETRIES));
            }

            sleep($delay);
            ++$attempt;
        }
    }

    private function isRetryable(int $status): bool
    {
        // 429 rate limit plus the transient 5xx family (500/502/503/504) OpenAI is
        // known to return intermittently. Other 4xx/5xx are permanent and not retried.
        return \in_array($status, [429, 500, 502, 503, 504], true);
    }

    private function backoffDelay(int $attempt, ResponseInterface|null $response): int
    {
        // Honour Retry-After (delta seconds) when the server provides it.
        if (null !== $response) {
            $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
            if (null !== $retryAfter && ctype_digit(trim((string) $retryAfter))) {
                return max(1, min(self::MAX_BACKOFF_SECONDS, (int) $retryAfter));
            }
        }

        // Exponential backoff: 1, 2, 4, 8, 16 ... capped.
        return min(self::MAX_BACKOFF_SECONDS, 2 ** $attempt);
    }
}
