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

namespace JuheItSolutions\ContaoOpenaiAssistant\Premium\EventListener;

use Contao\CoreBundle\DataContainer\RecordLabel;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\DataContainer;
use Contao\Input;
use Contao\Message;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

/**
 * Presentation for the read-only "OpenAI Vector-Store-Dateien" listing
 * (tl_openai_vector_file): the map from a page to the OpenAI file that holds it.
 *
 * The listing is the only place where a file id seen in the OpenAI platform can be
 * traced back to a page, so the two id columns are made actionable: the page id links
 * into the site structure, the URL opens the live page. Status is rendered as the same
 * coloured badge the sync dashboard uses, sizes and chunk positions are humanised.
 *
 * An optional "pid" query parameter scopes the list to one OpenAI configuration - that
 * is what the dashboard's "Show indexed files" button uses on multi-config installs.
 */
class OpenAiVectorFileListener
{
    private const STATUS_BADGES = [
        'uploaded' => 'green',
        'failed' => 'red',
    ];

    private const CONTENT_ICON = '<svg width="16" height="16" viewBox="0 0 24 24" style="vertical-align:middle;fill:currentColor" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>';

    /**
     * Cut-off for the displayed URL text; the full URL stays in the link target and the
     * title attribute. Long query strings would otherwise stretch the column.
     */
    private const URL_DISPLAY_CHARS = 70;

    /**
     * English fallbacks for the few labels this class writes into markup itself; used when
     * the language file is not loaded (e.g. a listing rendered before loadLanguageFile()).
     */
    private const FALLBACK_LABELS = [
        'link_index' => 'Link directory (not a page)',
        'edit_page' => 'Edit page in the site structure',
        'open_url' => 'Open page in a new tab',
        'show_content' => 'Show the indexed content of this page',
        'intro_hint' => 'This list shows only the files the automatic synchronisation manages itself - '
            .'one file per indexed page. Files you uploaded by hand (OpenAI Dashboard → File upload) are '
            .'not listed here and are never touched by the sync. Entries disappear on their own as soon '
            .'as a page leaves the synchronisation, which is why nothing can be deleted here by hand.',
        'filtered_hint' => 'Showing the files of one OpenAI configuration only.',
        'filtered_reset' => 'Show all configurations',
    ];

    /**
     * Indexed URL count per page id, loaded once per request. A normal page has one; a
     * reader page has one per news/FAQ/event entry that Contao indexed.
     *
     * @var array<int, int>|null
     */
    private array|null $indexedUrls = null;

    public function __construct(
        private readonly Security $security,
        private readonly RouterInterface $router,
        private readonly RequestStack $requestStack,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Load the shared read-only-listing stylesheet, explain what the list is, and - when a
     * "pid" is given - scope it to that OpenAI configuration.
     */
    #[AsCallback(table: 'tl_openai_vector_file', target: 'config.onload')]
    public function prepareListing(): void
    {
        $GLOBALS['TL_CSS']['openai_sync_log'] = 'bundles/contaoopenaiassistant/css/sync-log.css|static';

        // Input::get() hands back an array for "?pid[]=1"; only a scalar can be an id.
        $param = Input::get('pid');
        $pid = \is_scalar($param) ? (int) $param : 0;

        if ($pid > 0) {
            // Bound as a prepared-statement value by DC_Table (never interpolated). Added
            // only once: a second DataContainer for the same table in one request would
            // otherwise stack the condition (harmless in SQL, but pointless).
            $filters = &$GLOBALS['TL_DCA']['tl_openai_vector_file']['list']['sorting']['filter'];

            if (!\is_array($filters)) {
                $filters = [];
            }

            if (!\in_array(['pid=?', $pid], $filters, true)) {
                $filters[] = ['pid=?', $pid];
            }

            unset($filters);
        }

        $request = $this->requestStack->getCurrentRequest();

        // Messages only on a plain page load: panel submits (POST) redirect without
        // rendering, and their message would just pile a duplicate onto the next request.
        if (null === $request || !$request->isMethod('GET') || $request->isXmlHttpRequest()) {
            return;
        }

        Message::addInfo(htmlspecialchars($this->trans('intro_hint'), ENT_QUOTES));

        // An install has exactly one OpenAI configuration by design, so the scoping is
        // invisible there and saying "one configuration only" would just confuse. Only a
        // legacy install carrying several configs gets the note - and a way back.
        if ($pid < 1 || $this->configCount() < 2) {
            return;
        }

        Message::addInfo(\sprintf(
            '%s <a href="%s">%s</a>',
            htmlspecialchars($this->trans('filtered_hint'), ENT_QUOTES),
            htmlspecialchars($this->router->generate('contao_backend', ['do' => 'openai_vector_file']), ENT_QUOTES),
            htmlspecialchars($this->trans('filtered_reset'), ENT_QUOTES),
        ));
    }

    /**
     * Format the columns of a single list row. In "showColumns" mode the callback receives
     * the positional $args array and returns the per-column values.
     *
     * Contao 6 auto-encodes plain string/array returns, so the columns (which carry the
     * page/URL links and the status badge as markup) are wrapped in a RecordLabel to
     * render as HTML.
     *
     * @param array<string, mixed> $row
     * @param array<int, string>   $args
     */
    #[AsCallback(table: 'tl_openai_vector_file', target: 'list.label.label_callback')]
    public function formatRow(array $row, string $label, DataContainer $dc, array $args): RecordLabel
    {
        return RecordLabel::fromHtml($this->formatColumns($row, $args));
    }

    /**
     * The column formatting itself, kept apart from the callback signature: the listing
     * markup is identical on every Contao version, only the way a callback hands HTML back
     * to the core differs.
     *
     * Callback output is rendered raw, so every value taken from the database is escaped
     * here before it is wrapped in markup.
     *
     * @param array<string, mixed> $row
     * @param array<int, string>   $args
     *
     * @return array<int, string>
     */
    public function formatColumns(array $row, array $args): array
    {
        $fields = $GLOBALS['TL_DCA']['tl_openai_vector_file']['list']['label']['fields'] ?? [];
        $index = array_flip($fields);

        if (isset($index['page_id'])) {
            $args[$index['page_id']] = $this->pageColumn((int) ($row['page_id'] ?? 0));
        }

        if (isset($index['title'])) {
            $title = trim((string) ($row['title'] ?? ''));
            $args[$index['title']] = '' !== $title ? htmlspecialchars($title, ENT_QUOTES) : '–';
        }

        if (isset($index['url'])) {
            $args[$index['url']] = $this->urlColumn((string) ($row['url'] ?? ''));
        }

        if (isset($index['status'])) {
            $status = (string) ($row['status'] ?? '');
            $color = self::STATUS_BADGES[$status] ?? 'grey';
            // The label is resolved from the DCA reference here rather than reused from
            // $args: Contao 6 hands the callback pre-escaped column values while 5.3 and 5.7
            // hand over raw ones, so reusing them would double-encode on one version or stay
            // unescaped on the other. A status no reference covers keeps its raw value.
            $args[$index['status']] = '<span class="vsau-badge '.$color.'">'
                .htmlspecialchars($this->statusLabel($status), ENT_QUOTES).'</span>';
        }

        if (isset($index['chunk_index'])) {
            $count = max(1, (int) ($row['chunk_count'] ?? 1));
            $args[$index['chunk_index']] = \sprintf('%d/%d', (int) ($row['chunk_index'] ?? 0) + 1, $count);
        }

        if (isset($index['bytes'])) {
            $args[$index['bytes']] = $this->formatBytes((int) ($row['bytes'] ?? 0));
        }

        if (isset($index['openai_file_id'])) {
            $fileId = (string) ($row['openai_file_id'] ?? '');
            $args[$index['openai_file_id']] = '' !== $fileId ? '<code>'.htmlspecialchars($fileId, ENT_QUOTES).'</code>' : '–';
        }

        // Virtual column (no database field): how many indexed URLs were merged into this
        // page's document. News, FAQ and event entries have no page of their own - they all
        // sit behind one reader page and become a single document - so this is the only
        // place where their volume becomes visible at all.
        if (isset($index['indexed_urls'])) {
            $pageId = (int) ($row['page_id'] ?? 0);
            // The link directory is built from the other pages' links, not from an indexed
            // URL of its own - a count would be meaningless there.
            $args[$index['indexed_urls']] = $pageId > 0 ? (string) $this->indexedUrlCount($pageId) : '–';
        }

        return $args;
    }

    /**
     * button_callback for the "content" row operation: link to the indexed text of this
     * page. The content is served by the auto-sync controller out of the stored run
     * manifest, so the button only shows for a user who may open that module.
     *
     * Only the record ($row) is typed: Contao passes further positional arguments (href,
     * label, title, icon, attributes, …) which PHP ignores. This keeps the callback working
     * unchanged on Contao 5.3/5.7 and 6.0, where those arguments differ in count.
     *
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_openai_vector_file', target: 'list.operations.content.button_callback')]
    public function contentButton(array $row): string
    {
        $id = (int) ($row['id'] ?? 0);

        if ($id < 1 || !$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'vector_store_auto_update')) {
            return '';
        }

        return \sprintf(
            '<a href="%s" title="%s" target="_blank" rel="noopener noreferrer">%s</a> ',
            htmlspecialchars($this->router->generate('vector_store_auto_update', ['page_content' => $id]), ENT_QUOTES),
            htmlspecialchars($this->trans('show_content'), ENT_QUOTES),
            self::CONTENT_ICON,
        );
    }

    /**
     * Number of indexed URLs behind one page, counted from Contao's search index - the same
     * source the synchronisation reads. Loaded in a single grouped query on first use.
     */
    private function indexedUrlCount(int $pageId): int
    {
        if (null === $this->indexedUrls) {
            try {
                // Same predicate the synchronisation applies: protected URLs never reach the
                // vector store, so counting them here would overstate what a document holds.
                $rows = $this->connection->fetchAllKeyValue(
                    'SELECT pid, COUNT(*) FROM tl_search WHERE COALESCE(protected, 0) = 0 GROUP BY pid',
                );
                $this->indexedUrls = array_map('intval', $rows);
            } catch (\Throwable) {
                // No search index (or no table yet): the column simply stays empty.
                $this->indexedUrls = [];
            }
        }

        return $this->indexedUrls[$pageId] ?? 0;
    }

    /**
     * Translate a stored status through the DCA reference ("uploaded" -> "Hochgeladen"),
     * falling back to the raw value for anything the reference does not cover.
     */
    private function statusLabel(string $status): string
    {
        $reference = $GLOBALS['TL_DCA']['tl_openai_vector_file']['fields']['status']['reference'][$status] ?? null;

        if (\is_array($reference)) {
            $reference = $reference[0] ?? null;
        }

        return \is_string($reference) && '' !== $reference ? $reference : $status;
    }

    private function configCount(): int
    {
        try {
            return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_openai_config');
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * The page id links into the site structure - but only for a real page, and only for a
     * user who may open the page module at all (a dead link into an access-denied screen
     * would be worse than plain text).
     */
    private function pageColumn(int $pageId): string
    {
        if ($pageId < 1) {
            // page_id 0 is the synthetic site-wide link directory, not a Contao page.
            return htmlspecialchars($this->trans('link_index'), ENT_QUOTES);
        }

        if (!$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'page')) {
            return (string) $pageId;
        }

        return \sprintf(
            '<a href="%s" title="%s">%d</a>',
            htmlspecialchars(
                $this->router->generate('contao_backend', ['do' => 'page', 'act' => 'edit', 'id' => $pageId]),
                ENT_QUOTES,
            ),
            htmlspecialchars($this->trans('edit_page'), ENT_QUOTES),
            $pageId,
        );
    }

    /**
     * Render the source URL as a link to the live page. Only http(s) becomes a link, so a
     * malformed or unexpected scheme can never turn into an executable href.
     */
    private function urlColumn(string $url): string
    {
        $url = trim($url);

        if ('' === $url) {
            return '–';
        }

        $display = mb_strlen($url) > self::URL_DISPLAY_CHARS
            ? mb_substr($url, 0, self::URL_DISPLAY_CHARS).'…'
            : $url;

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!\in_array($scheme, ['http', 'https'], true)) {
            return htmlspecialchars($display, ENT_QUOTES);
        }

        return \sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s</a>',
            htmlspecialchars($url, ENT_QUOTES),
            htmlspecialchars($this->trans('open_url'), ENT_QUOTES),
            htmlspecialchars($display, ENT_QUOTES),
        );
    }

    /**
     * Contao's own size formatter, so the separators and unit names follow the backend
     * language instead of being hardcoded to one locale. Falls back to plain bytes if the
     * unit labels are not loaded.
     */
    private function formatBytes(int $bytes): string
    {
        if (empty($GLOBALS['TL_LANG']['UNITS'])) {
            return $bytes.' B';
        }

        return System::getReadableSize($bytes);
    }

    /**
     * Labels come from the DCA language file that Contao loads with the DCA itself
     * (contao/languages/*\/tl_openai_vector_file.xlf), same as the sync-log listing.
     */
    private function trans(string $key): string
    {
        $label = $GLOBALS['TL_LANG']['tl_openai_vector_file'][$key] ?? null;

        return \is_string($label) && '' !== $label ? $label : self::FALLBACK_LABELS[$key];
    }
}
