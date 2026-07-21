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
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreSyncMessageTranslator;
use Symfony\Component\Routing\RouterInterface;

/**
 * Presentation tweaks for the read-only "OpenAI Sync-Protokoll" listing
 * (tl_openai_sync_log). Renders the status column as a coloured badge, the
 * duration as hh:mm:ss and the message through the sync-message translator
 * (messages are stored as MSC.vsau_* keys) so the page matches the "Letzte
 * Synchronisierungen" block on the vector-store-auto-update dashboard. The
 * matching card/table styling is loaded from a dedicated stylesheet that is
 * only published on this page.
 */
class OpenAiSyncLogListener
{
    private const STATUS_BADGES = [
        'success' => 'green',
        'partial' => 'amber',
        'error' => 'red',
    ];

    private const DOWNLOAD_ICON = '<svg width="16" height="16" viewBox="0 0 24 24" style="vertical-align:middle;fill:currentColor" aria-hidden="true"><path d="M5 20h14v-2H5v2zM19 9h-4V3H9v6H5l7 7 7-7z"/></svg>';

    /**
     * IDs of the sync-log rows that carry a downloadable document, loaded once
     * (ids only, never the blobs) and reused for every operation-button render.
     *
     * @var array<int, bool>|null
     */
    private array|null $rowsWithDocument = null;

    public function __construct(
        private readonly VectorStoreSyncMessageTranslator $syncMessages,
        private readonly Connection $connection,
        private readonly RouterInterface $router,
    ) {
    }

    /**
     * button_callback for the "download" row operation: link each run that has a
     * stored document to the auto-sync controller's download route (the same one
     * the dashboard's "Letzte Synchronisierungen" table uses). Runs with no
     * document render no button.
     *
     * Only the record ($row) is typed: Contao passes further positional arguments
     * (href, label, title, icon, attributes, …) which PHP ignores. This keeps the
     * callback working unchanged on Contao 5.3/5.7 and 6.0, where those extra
     * arguments differ in nullability and count.
     *
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_openai_sync_log', target: 'list.operations.download.button_callback')]
    public function downloadButton(array $row): string
    {
        $id = (int) ($row['id'] ?? 0);

        if ($id <= 0 || !$this->rowHasDocument($id)) {
            return '';
        }

        $url = $this->router->generate('vector_store_auto_update', ['download' => $id]);
        $title = (string) ($GLOBALS['TL_LANG']['tl_openai_sync_log']['download'] ?? 'Download document');

        return \sprintf(
            '<a href="%s" title="%s">%s</a> ',
            htmlspecialchars($url, ENT_QUOTES),
            htmlspecialchars($title, ENT_QUOTES),
            self::DOWNLOAD_ICON,
        );
    }

    /**
     * Load the listing stylesheet only when this DCA is in play (i.e. on the sync-log
     * backend page), so the card styling never leaks into other lists.
     */
    #[AsCallback(table: 'tl_openai_sync_log', target: 'config.onload')]
    public function loadStylesheet(): void
    {
        $GLOBALS['TL_CSS']['openai_sync_log'] = 'bundles/contaoopenaiassistant/css/sync-log.css|static';
    }

    /**
     * Format the columns of a single list row. In "showColumns" mode the callback
     * receives the positional $args array and must return it (see DC_Table).
     *
     * @param array<string, mixed> $row
     * @param array<int, string>   $args
     *
     * @return array<int, string>
     */
    #[AsCallback(table: 'tl_openai_sync_log', target: 'list.label.label_callback')]
    public function formatRow(array $row, string $label, DataContainer $dc, array $args): array
    {
        $fields = $GLOBALS['TL_DCA']['tl_openai_sync_log']['list']['label']['fields'] ?? [];
        $index = array_flip($fields);

        if (isset($index['status'])) {
            $color = self::STATUS_BADGES[$row['status'] ?? ''] ?? 'grey';
            $text = '' !== $args[$index['status']] ? $args[$index['status']] : ($row['status'] ?? '');
            $args[$index['status']] = '<span class="vsau-badge '.$color.'">'.$text.'</span>';
        }

        if (isset($index['duration'])) {
            $args[$index['duration']] = $this->formatDuration((int) ($row['duration'] ?? 0));
        }

        if (isset($index['message']) && '' !== (string) ($row['message'] ?? '')) {
            $translated = $this->syncMessages->translate((string) $row['message']) ?? (string) $row['message'];
            // The callback output is rendered raw (see the status badge above), so the
            // translated text must be escaped here.
            $args[$index['message']] = htmlspecialchars($translated, ENT_QUOTES);
        }

        return $args;
    }

    private function rowHasDocument(int $id): bool
    {
        if (null === $this->rowsWithDocument) {
            $ids = $this->connection->fetchFirstColumn(
                "SELECT id FROM tl_openai_sync_log WHERE document IS NOT NULL AND document <> ''",
            );
            $this->rowsWithDocument = array_fill_keys(array_map('intval', $ids), true);
        }

        return isset($this->rowsWithDocument[$id]);
    }

    private function formatDuration(int $seconds): string
    {
        return \sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60);
    }
}
