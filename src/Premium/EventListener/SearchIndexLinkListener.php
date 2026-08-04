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

namespace JuheItSolutions\ContaoOpenaiAssistant\Premium\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\LinkedFileMetadataResolver;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageLinkExtractor;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageLinkRepository;
use Nyholm\Psr7\Uri;
use Psr\Log\LoggerInterface;

/**
 * Collects the content links of every page Contao indexes.
 *
 * Why this hook: Contao\Search::indexPage() hands us the page HTML AFTER it has
 * removed <script>/<style> blocks and every "<!-- indexer::stop -->" region, and
 * BEFORE it destroys the links with strip_tags(). Navigation, breadcrumb,
 * pagination, search/login modules and - crucially - protected articles and
 * modules are therefore already gone. We only read the content; the search index
 * itself is never modified (the documented hook signature passes $content by
 * value, and $indexData is left untouched).
 *
 * The hook fires from two places, and this listener is written for both:
 *   - contao:crawl, i.e. the CLI subprocess the vector-store sync spawns, and
 *   - live front-end traffic via SearchIndexListener (kernel.terminate, usually
 *     handled asynchronously by a messenger worker).
 *
 * Consequences for this class: it must be cheap (one cached feature query per PHP
 * process, an early exit for link-free pages), it must never perform network I/O,
 * and it must never throw - a failure here would otherwise break Contao's search
 * indexing for the whole site.
 */
#[AsHook('indexPage')]
class SearchIndexLinkListener
{
    public function __construct(
        private readonly PageLinkRepository $repository,
        private readonly PageLinkExtractor $extractor,
        private readonly LinkedFileMetadataResolver $fileMetadata,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $pageData  the indexer's data array
     * @param array<string, mixed> $indexData the row Contao is about to store - not touched here
     */
    public function __invoke(string $content, array $pageData, array &$indexData): void
    {
        try {
            if ('' === trim($content) || !$this->repository->isFeatureEnabled()) {
                return;
            }

            // Protected pages are only indexed at all when the operator enabled
            // contao.search.index_protected. Their links are still member-only
            // information, so this feature never collects them.
            if (!empty($pageData['protected'])) {
                return;
            }

            $pageId = (int) ($pageData['pid'] ?? 0);
            $rawUrl = trim((string) ($pageData['url'] ?? ''));

            if ($pageId <= 0 || '' === $rawUrl) {
                return;
            }

            // Normalise exactly like Contao does for tl_search.url (Search.php:52),
            // so stored rows can be joined against the search index for pruning and
            // for the protected-target check.
            $sourceUrl = (string) new Uri($rawUrl);

            $links = $this->extractor->extract($content, $sourceUrl, $this->repository->siteHosts());
            $links = $this->fileMetadata->enrich($links);

            $this->repository->replaceForSource(
                $pageId,
                $sourceUrl,
                (string) ($pageData['language'] ?? ''),
                $links,
            );
        } catch (\Throwable $e) {
            // Never let link collection break Contao's search indexing.
            $this->logger->warning(
                'OpenAI assistant: link extraction failed for "'.(string) ($pageData['url'] ?? '?').'": '.$e->getMessage(),
            );
        }
    }
}
