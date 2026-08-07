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

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\DataContainer;
use Contao\Input;
use Contao\Message;
use Contao\System;
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
        'filtered_hint' => 'Showing the files of one OpenAI configuration only.',
        'filtered_reset' => 'Show all configurations',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly RouterInterface $router,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Load the shared read-only-listing stylesheet and, when a "pid" is given, scope the
     * list to that OpenAI configuration.
     */
    #[AsCallback(table: 'tl_openai_vector_file', target: 'config.onload')]
    public function prepareListing(): void
    {
        $GLOBALS['TL_CSS']['openai_sync_log'] = 'bundles/contaoopenaiassistant/css/sync-log.css|static';

        // Input::get() hands back an array for "?pid[]=1"; only a scalar can be an id.
        $param = Input::get('pid');
        $pid = \is_scalar($param) ? (int) $param : 0;

        if ($pid < 1) {
            return;
        }

        // Bound as a prepared-statement value by DC_Table (never interpolated). Added only
        // once: a second DataContainer for the same table in one request would otherwise
        // stack the condition (harmless in SQL, but pointless).
        $filters = &$GLOBALS['TL_DCA']['tl_openai_vector_file']['list']['sorting']['filter'];

        if (!\is_array($filters)) {
            $filters = [];
        }

        if (!\in_array(['pid=?', $pid], $filters, true)) {
            $filters[] = ['pid=?', $pid];
        }

        unset($filters);

        $request = $this->requestStack->getCurrentRequest();

        // A silently filtered list looks like data loss, so say so - with a way back. Only
        // on a plain page load: panel submits (POST) redirect without rendering, and their
        // message would just pile a duplicate onto the following request.
        if (null === $request || !$request->isMethod('GET') || $request->isXmlHttpRequest()) {
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
     * the positional $args array and must return it (see DC_Table).
     *
     * @param array<string, mixed> $row
     * @param array<int, string>   $args
     *
     * @return array<int, string>
     */
    #[AsCallback(table: 'tl_openai_vector_file', target: 'list.label.label_callback')]
    public function formatRow(array $row, string $label, DataContainer $dc, array $args): array
    {
        return $this->formatColumns($row, $args);
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
            // The DCA "reference" already turned the raw value into a label; fall back to the
            // raw status for a value no reference covers (e.g. the legacy "orphan").
            $text = (string) ($args[$index['status']] ?? '');
            $text = '' !== $text ? $text : $status;
            $args[$index['status']] = '<span class="vsau-badge '.$color.'">'.htmlspecialchars($text, ENT_QUOTES).'</span>';
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

        return $args;
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
