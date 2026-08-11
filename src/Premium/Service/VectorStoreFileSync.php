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
     * A row whose remote file could not be confirmed deleted. It is not tracking a live
     * document any more - it exists solely so the next run can retry the deletion, and it
     * is therefore excluded from loadState() and never counted as an upload.
     */
    private const STATUS_PENDING_DELETE = 'pending_delete';

    /**
     * Attached, but OpenAI has not confirmed the file is ingested. Deliberately not
     * "uploaded": a file that later fails server-side would otherwise be invisible forever,
     * because unchanged pages with an "uploaded" row are never looked at again.
     */
    private const STATUS_PROCESSING = 'processing';

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

    /**
     * Message of the last "failed" ingestion status, carried from the status read to the
     * exception that reports it.
     */
    private string $lastIngestionError = 'unknown';

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
     * @return array{added: int, updated: int, removed: int, unchanged: int, files_uploaded: int, files_failed: int, deletes_pending: int, legacy_file_removed: bool, bytes: int, page_states: array<int, array{state: string, files: list<string>}>} page_states maps every page in scope to what happened to it and which vector-store file(s) now hold it - the orchestrator writes it into the downloadable manifest so operators can match a page to a file id in the OpenAI platform; legacy_file_removed is false when $legacyFileId must be kept for a later retry
     */
    public function sync(string $apiKey, string $vectorStoreId, int $configId, array $pages, string $legacyFileId = '', callable|null $progress = null): array
    {
        $stats = [
            'added' => 0,
            'updated' => 0,
            'removed' => 0,
            'unchanged' => 0,
            'files_uploaded' => 0,
            'files_failed' => 0,
            'deletes_pending' => 0,
            'legacy_file_removed' => true,
            'bytes' => 0,
            'page_states' => [],
        ];

        // Debt from earlier runs first: a file whose deletion was never confirmed is still
        // attached to the store and still answering, so it outranks anything new.
        $stats['deletes_pending'] = $this->retryPendingDeletes($apiKey, $vectorStoreId, $configId);

        // Settle anything left mid-ingestion before deciding what counts as unchanged.
        $this->refreshProcessingRows($apiKey, $vectorStoreId, $configId);

        $existing = $this->loadState($configId);

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
            // Title and URL are compared too: they head the uploaded document and travel as
            // file attributes, so a page renamed without a text change must still be
            // re-uploaded - the hash alone would keep the stale heading in the store forever.
            if (
                null !== $current
                && $current['content_hash'] === $contentHash
                && 'uploaded' === $current['status']
                && $current['title'] === $this->storedTitle($page['title'])
                && $current['url'] === $this->storedUrl($page['url'])
            ) {
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
                    // "uploaded" only when OpenAI confirmed ingestion; "processing" otherwise,
                    // so the next run rechecks it instead of trusting a guess forever.
                    $ingestStatus = $this->waitForIngestion($apiKey, $vectorStoreId, $fileId);

                    $replacementRows[] = [$configId, $page, $contentHash, $fileId, $bytes, $i, $chunkCount, $ingestStatus, null];
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
                        if (!$this->detachAndDelete($apiKey, $vectorStoreId, $replacementFileId)) {
                            $this->markPendingDelete($configId, $pageId, $replacementFileId, 'Rollback of a failed state swap could not be confirmed.');
                            ++$stats['deletes_pending'];
                        }
                    }

                    throw $e;
                }

                if (null !== $current) {
                    foreach ($current['files'] as $oldFileId) {
                        if ($this->detachAndDelete($apiKey, $vectorStoreId, $oldFileId)) {
                            continue;
                        }

                        // The new revision is committed, so this is the OLD one still sitting
                        // in the store. Two revisions of the same page now answer queries;
                        // tracking it as pending keeps the next run trying until one wins.
                        $this->markPendingDelete($configId, $pageId, $oldFileId, 'Superseded revision could not be removed from the vector store.');
                        ++$stats['deletes_pending'];
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
                        if (!$this->detachAndDelete($apiKey, $vectorStoreId, $replacementFileId)) {
                            $this->markPendingDelete($configId, $pageId, $replacementFileId, 'Partial upload could not be rolled back from the vector store.');
                            ++$stats['deletes_pending'];
                        }
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

            $outcome = $this->removePageFiles($apiKey, $vectorStoreId, $configId, $pageId, $row['files']);
            $stats['removed'] += $outcome['removed'];
            $stats['deletes_pending'] += $outcome['deletes_pending'];
        }

        // The legacy bulk file goes LAST. It used to go first, which meant a run that then
        // failed at upload, attach or ingestion had already destroyed the only working
        // knowledge base the site had - every existing premium installation walks this path
        // exactly once, on its first per-page sync, so it got one chance to get it right.
        $stats['legacy_file_removed'] = $this->removeLegacyFile($apiKey, $vectorStoreId, $legacyFileId, $stats['files_failed']);

        if (!$stats['legacy_file_removed'] && '' !== $legacyFileId && 0 === $stats['files_failed']) {
            // Deferred because a page failed is a different thing from "OpenAI would not
            // delete it": only the latter leaves a superset document answering alongside
            // the new per-page ones, so only the latter is reported as a pending deletion.
            ++$stats['deletes_pending'];
        }

        return $stats;
    }

    /**
     * Remove the tracked documents of specific pages, without an upload set.
     *
     * A separate entry point from sync() because the DELETION half of reconciliation has to
     * be able to run on its own: the orchestrator uses it for pages the page tree proves are
     * gone (deleted, unpublished, protected, deselected), which is authoritative whether or
     * not the crawl produced anything to upload. sync() aborts on an empty index - correctly,
     * since an empty index is usually a failed crawl rather than an empty site - and without
     * this the privacy-critical removals would abort with it.
     *
     * Same confirmed-outcome contract as sync(): a deletion OpenAI does not confirm keeps its
     * row as pending_delete and is not counted as removed.
     *
     * @param list<int> $pageIds
     *
     * @return array{removed: int, deletes_pending: int}
     */
    public function removePages(string $apiKey, string $vectorStoreId, int $configId, array $pageIds): array
    {
        $stats = ['removed' => 0, 'deletes_pending' => 0];

        if ([] === $pageIds) {
            return $stats;
        }

        $existing = $this->loadState($configId);

        foreach ($pageIds as $pageId) {
            if (!isset($existing[$pageId])) {
                continue;
            }

            $outcome = $this->removePageFiles($apiKey, $vectorStoreId, $configId, $pageId, $existing[$pageId]['files']);
            $stats['removed'] += $outcome['removed'];
            $stats['deletes_pending'] += $outcome['deletes_pending'];
        }

        return $stats;
    }

    /**
     * Retry deletions an earlier run could not confirm.
     *
     * Public so the orchestrator can still make progress on a run that aborts before sync()
     * - on a site whose crawl keeps failing, that abort is every run, and the debt would
     * otherwise never be worked off.
     *
     * @return int files still pending afterwards
     */
    public function retryPendingDeletions(string $apiKey, string $vectorStoreId, int $configId): int
    {
        return $this->retryPendingDeletes($apiKey, $vectorStoreId, $configId);
    }

    /**
     * Remove every file this config tracks (used when the feature is reset / config deleted).
     *
     * Teardown, so unlike sync() this cannot keep anything for retry: the configuration is
     * going away and no later run will ever look at these rows again. Files that cannot be
     * confirmed deleted are therefore logged with their ids and an explicit instruction, the
     * same handle the orphan-Assistant cleanup gives operators, and then the rows go.
     */
    public function purge(string $apiKey, string $vectorStoreId, int $configId): void
    {
        $orphans = [];

        foreach ($this->loadPendingDeletes($configId) as $row) {
            if (!$this->detachAndDelete($apiKey, $vectorStoreId, $row['file'])) {
                $orphans[] = $row['file'];
            }
        }

        foreach ($this->loadState($configId) as $row) {
            foreach ($row['files'] as $fileId) {
                if (!$this->detachAndDelete($apiKey, $vectorStoreId, $fileId)) {
                    $orphans[] = $fileId;
                }
            }
        }

        if ([] !== $orphans) {
            $this->logger->error(\sprintf(
                'Vector store cleanup for config %d could not remove %d file(s): %s. They are no longer tracked and must be deleted manually in the OpenAI platform dashboard.',
                $configId,
                \count($orphans),
                implode(', ', $orphans),
            ));
        }

        $this->connection->delete('tl_openai_vector_file', ['pid' => $configId]);
    }

    /**
     * Seam for the retry backoff.
     *
     * Overridden in tests so exercising "429 after every retry" costs no real time. Without
     * it each failure-path test sleeps the full 1+2+4+8+16 second ladder, which is how
     * failure paths quietly end up with no tests at all.
     */
    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * Seam for the ingestion poll interval, so tests do not spend real time waiting.
     */
    protected function pause(int $microseconds): void
    {
        usleep($microseconds);
    }

    /**
     * Seam for the ingestion wait budget. A test for "still ingesting when the budget ran
     * out" would otherwise have to burn the full 30 seconds to reach the branch it is about.
     */
    protected function ingestWaitSeconds(): int
    {
        return self::INGEST_WAIT_SECONDS;
    }

    /**
     * Detach and delete every file tracked for one page, then drop its rows.
     *
     * @param list<string> $files
     *
     * @return array{removed: int, deletes_pending: int}
     */
    private function removePageFiles(string $apiKey, string $vectorStoreId, int $configId, int $pageId, array $files): array
    {
        $stats = ['removed' => 0, 'deletes_pending' => 0];
        $confirmed = true;

        foreach ($files as $fileId) {
            if ($this->detachAndDelete($apiKey, $vectorStoreId, $fileId)) {
                continue;
            }

            // This is the privacy path: a page leaves scope because it was protected,
            // unpublished or deleted. Reporting it "removed" while its document is still
            // attached would tell the operator the content is gone when it still answers.
            $confirmed = false;
            $this->markPendingDelete($configId, $pageId, $fileId, 'Page left the sync scope but its file could not be removed from the vector store.');
            ++$stats['deletes_pending'];
        }

        // Only rows whose file is confirmed gone may go. A pending_delete row IS the retry
        // handle, so it has to survive the cleanup that follows it.
        $this->connection->executeStatement(
            'DELETE FROM tl_openai_vector_file WHERE pid = ? AND page_id = ? AND status != ?',
            [$configId, $pageId, self::STATUS_PENDING_DELETE],
        );

        if ($confirmed) {
            ++$stats['removed'];
        }

        return $stats;
    }

    /**
     * Retire the single bulk file the pre-2.2 pipeline produced.
     *
     * Returns whether the caller may now forget the id. TRUE also covers "there was nothing
     * to remove". FALSE means the id must be kept in auto_update_file_id, because that field
     * is the only handle on this file - it has no tl_openai_vector_file row to fall back on,
     * so a cleared id and an undeleted file means a stale superset document answers questions
     * next to every per-page document, forever, with nothing left to find it by.
     */
    private function removeLegacyFile(string $apiKey, string $vectorStoreId, string $legacyFileId, int $filesFailed): bool
    {
        if ('' === $legacyFileId) {
            return true;
        }

        if ($filesFailed > 0) {
            // Some pages have no usable replacement yet, and the bulk file is the only thing
            // still covering them. Keep it until a clean run makes it genuinely redundant.
            $this->logger->notice('Legacy bulk file '.$legacyFileId.' kept: '.$filesFailed.' file(s) failed this run, so the per-page replacement is incomplete.');

            return false;
        }

        if ($this->detachAndDelete($apiKey, $vectorStoreId, $legacyFileId)) {
            return true;
        }

        $this->logger->warning('Legacy bulk file '.$legacyFileId.' could not be removed; its id is kept so the next run retries.');

        return false;
    }

    /**
     * @return array<int, array{content_hash: string, title: string, url: string, status: string, files: list<string>}>
     */
    private function loadState(int $configId): array
    {
        // pending_delete rows are excluded: they track a file we are trying to get RID of,
        // not a document representing the page. Left in, they would make a page look
        // "already uploaded" with a file id that is on its way out.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT page_id, content_hash, title, url, status, openai_file_id FROM tl_openai_vector_file WHERE pid = ? AND status != ?',
            [$configId, self::STATUS_PENDING_DELETE],
        );

        $state = [];

        foreach ($rows as $row) {
            $pageId = (int) $row['page_id'];
            if (!isset($state[$pageId])) {
                $state[$pageId] = [
                    'content_hash' => (string) $row['content_hash'],
                    'title' => (string) $row['title'],
                    'url' => (string) $row['url'],
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
                // status != pending_delete: a file this page is still trying to have deleted
                // remotely must not be swept away by its own replacement, or the retry handle
                // is lost and the old file stays in the store for good.
                $this->connection->executeStatement(
                    'DELETE FROM tl_openai_vector_file WHERE pid = ? AND page_id = ? AND status != ?',
                    [$configId, $pageId, self::STATUS_PENDING_DELETE],
                );

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
            'url' => $this->storedUrl($page['url']),
            'title' => $this->storedTitle($page['title']),
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
     * The column widths of tl_openai_vector_file. Comparing a fresh value against a stored
     * one has to apply the same cut, or an over-long title would look "changed" on every
     * single run and re-upload the page forever.
     */
    private function storedTitle(string $title): string
    {
        return mb_substr($title, 0, 512);
    }

    private function storedUrl(string $url): string
    {
        return mb_substr($url, 0, 2048);
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
     * Poll the vector-store file until ingestion leaves "in_progress".
     *
     * Returns the status to persist: "uploaded" once OpenAI confirms the file is ingested,
     * "processing" when it is not yet - or when we could not find out. A "failed" status
     * throws so the page is recorded as failed and retried.
     *
     * "Or when we could not find out" is the point of this method. It used to read
     * $data['status'] ?? 'completed' from a body fetched with throw: false, and an OpenAI
     * error body carries {"error": ...} and no "status" at all - so every failed status
     * check was stored as a permanent success, on a row later runs would never look at again.
     */
    private function waitForIngestion(string $apiKey, string $vectorStoreId, string $fileId): string
    {
        $deadline = time() + $this->ingestWaitSeconds();

        do {
            $state = $this->readIngestionState($apiKey, $vectorStoreId, $fileId);

            if ('failed' === $state) {
                throw new \RuntimeException('Vector store ingestion failed: '.$this->lastIngestionError);
            }

            if ('completed' === $state) {
                return 'uploaded';
            }

            if ('in_progress' !== $state) {
                // Unknown state, including every HTTP error: not proof of success, and no
                // reason to keep polling for it either.
                return self::STATUS_PROCESSING;
            }

            $this->pause(750_000);
        } while (time() < $deadline);

        // Still ingesting when the budget ran out. The file IS attached and will very
        // probably finish server-side, but "probably" is not a state to record as done -
        // the next run rechecks it instead.
        return self::STATUS_PROCESSING;
    }

    /**
     * One ingestion status check.
     *
     * @return string one of completed, in_progress, failed, unknown
     */
    private function readIngestionState(string $apiKey, string $vectorStoreId, string $fileId): string
    {
        $this->lastIngestionError = 'unknown';

        try {
            $response = $this->request(
                'GET',
                self::OPENAI_BASE."/vector_stores/{$vectorStoreId}/files/{$fileId}",
                [
                    'headers' => ['Authorization' => 'Bearer '.$apiKey],
                    'timeout' => 30,
                ],
            );

            $status = $response->getStatusCode();
        } catch (\Throwable $e) {
            $this->logger->warning('Could not read ingestion status of file '.$fileId.': '.$e->getMessage());

            return 'unknown';
        }

        if ($status < 200 || $status >= 300) {
            $this->logger->warning('Ingestion status of file '.$fileId.' unavailable: OpenAI returned HTTP '.$status.'.');

            return 'unknown';
        }

        $data = $response->toArray(throw: false);
        $state = (string) ($data['status'] ?? '');

        if ('failed' === $state) {
            $this->lastIngestionError = (string) ($data['last_error']['message'] ?? 'unknown');
        }

        return \in_array($state, ['completed', 'in_progress', 'failed'], true) ? $state : 'unknown';
    }

    /**
     * Re-check files an earlier run left mid-ingestion.
     *
     * Without this a "processing" row would make the page look changed and be re-uploaded in
     * full on the next run - paying twice for a file that finished ingesting seconds after we
     * stopped waiting. One cheap GET settles it.
     */
    private function refreshProcessingRows(string $apiKey, string $vectorStoreId, int $configId): void
    {
        foreach ($this->loadRowsWithStatus($configId, self::STATUS_PROCESSING) as $row) {
            if ('' === $row['file']) {
                continue;
            }

            $state = $this->readIngestionState($apiKey, $vectorStoreId, $row['file']);

            if ('completed' === $state) {
                $this->connection->update(
                    'tl_openai_vector_file',
                    ['tstamp' => time(), 'status' => 'uploaded', 'last_error' => null],
                    ['id' => $row['id']],
                );

                continue;
            }

            if ('failed' === $state) {
                // Left as a non-uploaded status, so the page is re-uploaded below and its
                // old file is detached in the normal swap.
                $this->connection->update(
                    'tl_openai_vector_file',
                    ['tstamp' => time(), 'status' => 'failed', 'last_error' => 'Vector store ingestion failed: '.$this->lastIngestionError],
                    ['id' => $row['id']],
                );
            }
        }
    }

    /**
     * Detach a file from the vector store and delete it from the Files API.
     *
     * Returns TRUE only when the remote file is CONFIRMED gone. This matters more than it
     * looks: request() deliberately returns the final response instead of throwing, so a
     * 401, a persistent 429 and a 500 after the last retry all arrive here looking exactly
     * like a 200. The previous version read getStatusCode() without checking it, and every
     * caller then discarded the tl_openai_vector_file row - the only handle on the remote
     * file. A page withdrawn from scope could stay attached to the store, keep answering
     * visitor questions, and never be retried by any later run.
     *
     * 404 counts as success: the object is absent, which is the state we are trying to
     * reach. Anything else is unconfirmed, and the caller must keep the row for retry.
     */
    private function detachAndDelete(string $apiKey, string $vectorStoreId, string $fileId): bool
    {
        if ('' === $fileId) {
            return true;
        }

        // Order matters: detach first, so a file that is gone from Files but still attached
        // cannot linger in the store. Both calls are idempotent (404 = success), so a retry
        // that repeats a step which already worked is harmless.
        if (!$this->confirmDelete($apiKey, self::OPENAI_BASE."/vector_stores/{$vectorStoreId}/files/{$fileId}", 'detach file '.$fileId.' from the vector store')) {
            return false;
        }

        return $this->confirmDelete($apiKey, self::OPENAI_BASE."/files/{$fileId}", 'delete file '.$fileId.' from OpenAI Files');
    }

    /**
     * Issue one DELETE and report whether the object is provably gone.
     */
    private function confirmDelete(string $apiKey, string $url, string $what): bool
    {
        try {
            $status = $this->request(
                'DELETE',
                $url,
                [
                    'headers' => ['Authorization' => 'Bearer '.$apiKey],
                    'timeout' => 30,
                ],
            )->getStatusCode();
        } catch (\Throwable $e) {
            $this->logger->warning('Could not '.$what.': '.$e->getMessage().'. Kept for retry on the next sync.');

            return false;
        }

        if (($status >= 200 && $status < 300) || 404 === $status) {
            return true;
        }

        $this->logger->warning('Could not '.$what.': OpenAI returned HTTP '.$status.'. Kept for retry on the next sync.');

        return false;
    }

    /**
     * Keep a file that could not be confirmed deleted, so a later run can try again.
     *
     * The row is normally still there (a page that dropped out of scope), in which case it
     * only changes status. After a successful swap it is not: replacePageState() has already
     * replaced the page's rows with the new chunk files, so the old file id has no tracking
     * left and has to be re-recorded, or the orphan becomes invisible and permanent.
     */
    private function markPendingDelete(int $configId, int $pageId, string $fileId, string $reason): void
    {
        if ('' === $fileId) {
            return;
        }

        $updated = $this->connection->update(
            'tl_openai_vector_file',
            [
                'tstamp' => time(),
                'status' => self::STATUS_PENDING_DELETE,
                'last_error' => $reason,
            ],
            [
                'pid' => $configId,
                'page_id' => $pageId,
                'openai_file_id' => $fileId,
            ],
        );

        if ($updated > 0) {
            return;
        }

        $this->connection->insert('tl_openai_vector_file', [
            'pid' => $configId,
            'tstamp' => time(),
            'page_id' => $pageId,
            'openai_file_id' => $fileId,
            'status' => self::STATUS_PENDING_DELETE,
            'last_error' => $reason,
        ]);
    }

    /**
     * Retry every deletion an earlier run could not confirm.
     *
     * Runs before anything else in a sync: these are the oldest debt in the store and the
     * reason a withdrawn page can still answer a visitor. A confirmed deletion drops the
     * row; anything else leaves it exactly where it is for the run after this one.
     *
     * @return int files still pending after this attempt
     */
    private function retryPendingDeletes(string $apiKey, string $vectorStoreId, int $configId): int
    {
        $pending = 0;

        foreach ($this->loadPendingDeletes($configId) as $row) {
            if ($this->detachAndDelete($apiKey, $vectorStoreId, $row['file'])) {
                $this->connection->delete('tl_openai_vector_file', ['id' => $row['id']]);

                continue;
            }

            ++$pending;
            $this->connection->update('tl_openai_vector_file', ['tstamp' => time()], ['id' => $row['id']]);
        }

        return $pending;
    }

    /**
     * @return list<array{id: int, page_id: int, file: string}>
     */
    private function loadPendingDeletes(int $configId): array
    {
        return $this->loadRowsWithStatus($configId, self::STATUS_PENDING_DELETE);
    }

    /**
     * @return list<array{id: int, page_id: int, file: string}>
     */
    private function loadRowsWithStatus(int $configId, string $status): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, page_id, openai_file_id FROM tl_openai_vector_file WHERE pid = ? AND status = ?',
            [$configId, $status],
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'page_id' => (int) $row['page_id'],
                'file' => (string) $row['openai_file_id'],
            ],
            $rows,
        );
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

            $this->sleep($delay);
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
