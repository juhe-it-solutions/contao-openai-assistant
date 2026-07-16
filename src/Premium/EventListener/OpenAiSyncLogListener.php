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
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreSyncMessageTranslator;

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

    public function __construct(private readonly VectorStoreSyncMessageTranslator $syncMessages)
    {
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

    private function formatDuration(int $seconds): string
    {
        return \sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60);
    }
}
