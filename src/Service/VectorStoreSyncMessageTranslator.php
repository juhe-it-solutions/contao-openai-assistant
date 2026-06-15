<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) JUHE IT-solutions
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Service;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Resolves persisted vector-store sync status/log messages for the backend UI.
 *
 * Messages are stored as translation keys (MSC.vsau_*) or legacy English text from
 * earlier runs; both are translated here using the active backend locale.
 */
class VectorStoreSyncMessageTranslator
{
    private const DOMAIN = 'contao_default';

    private const LEGACY_KEYS = [
        'Manual sync dispatched to CLI. Refresh this page in a few minutes.' => 'MSC.vsau_dispatched_manual',
        'No indexed pages found for this site root (tl_search is empty). Run System → Maintenance → Rebuild search index. If pages are still missing, check whether they carry robots=noindex or Suchindexer=Never index — set Suchindexer=Always index on those page records to force-include them in both Contao search and the vector store.' => 'MSC.vsau_err_no_indexed_pages',
        'No page content found to upload; aborting before replacing the existing file.' => 'MSC.vsau_err_empty_document_raw',
        'The model returned an empty document; aborting before replacing the existing file.' => 'MSC.vsau_err_empty_document_llm',
        'No vector store ID configured. Complete the file upload workflow or set a vector store ID first.' => 'MSC.vsau_err_no_vector_store_sync',
        'Multiple site roots detected. Select the pages to keep updated in OpenAI Configuration → Automatic vector store sync.' => 'MSC.vsau_err_multiple_roots',
        'Could not create a temporary file for the upload.' => 'MSC.vsau_err_temp_file',
        'OpenAI Files upload did not return a file ID.' => 'MSC.vsau_err_upload_no_id',
        'Automatic sync is not enabled for this configuration.' => 'MSC.vsau_err_sync_not_enabled',
        'No active premium license.' => 'MSC.vsau_err_no_license',
        'A sync is already queued or running for this configuration.' => 'MSC.vsau_err_sync_already_running',
    ];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function translate(string|null $message): string|null
    {
        if (null === $message || '' === $message) {
            return $message;
        }

        if (str_starts_with($message, 'MSC.')) {
            if (str_contains($message, '|')) {
                return $this->translateKeyedMessage($message);
            }

            $key = self::LEGACY_KEYS[$message] ?? $message;

            return $this->translator->trans($key, [], self::DOMAIN);
        }

        $key = self::LEGACY_KEYS[$message] ?? null;
        if (null !== $key) {
            return $this->translator->trans($key, [], self::DOMAIN);
        }

        if (preg_match('/^OpenAI configuration (\d+) not found\.$/', $message, $matches)) {
            return $this->translator->trans('MSC.vsau_err_config_not_found', ['%id%' => $matches[1]], self::DOMAIN);
        }

        if (preg_match('/^No usable OpenAI API key for configuration (\d+)\.$/', $message, $matches)) {
            return $this->translator->trans('MSC.vsau_err_no_api_key', ['%id%' => $matches[1]], self::DOMAIN);
        }

        if (preg_match('/^Invalid page selected for auto-update \(ID (\d+)\)\.$/', $message, $matches)) {
            return $this->translator->trans('MSC.vsau_err_invalid_page', ['%id%' => $matches[1]], self::DOMAIN);
        }

        if (preg_match('/^contao:crawl failed: (.*)$/s', $message, $matches)) {
            return $this->translator->trans('MSC.vsau_err_crawl_failed', ['%details%' => $matches[1]], self::DOMAIN);
        }

        if (preg_match('/^OpenAI chat completion failed \(HTTP (\d+)\): (.*)$/s', $message, $matches)) {
            return $this->translator->trans(
                'MSC.vsau_err_openai_chat',
                ['%status%' => $matches[1], '%details%' => $matches[2]],
                self::DOMAIN,
            );
        }

        return $message;
    }

    private function translateKeyedMessage(string $message): string
    {
        if (str_starts_with($message, 'MSC.vsau_err_openai_chat|')) {
            $rest = substr($message, \strlen('MSC.vsau_err_openai_chat|'));
            if (preg_match('/^(\d+)\|(.*)$/s', $rest, $matches)) {
                return $this->translator->trans(
                    'MSC.vsau_err_openai_chat',
                    ['%status%' => $matches[1], '%details%' => $matches[2]],
                    self::DOMAIN,
                );
            }
        }

        if (str_starts_with($message, 'MSC.vsau_err_crawl_failed|')) {
            return $this->translator->trans(
                'MSC.vsau_err_crawl_failed',
                ['%details%' => substr($message, \strlen('MSC.vsau_err_crawl_failed|'))],
                self::DOMAIN,
            );
        }

        $parts = explode('|', $message, 3);
        $key = $parts[0];

        return match ($key) {
            'MSC.vsau_err_config_not_found' => $this->translator->trans($key, ['%id%' => $parts[1] ?? ''], self::DOMAIN),
            'MSC.vsau_err_no_api_key' => $this->translator->trans($key, ['%id%' => $parts[1] ?? ''], self::DOMAIN),
            'MSC.vsau_err_invalid_page' => $this->translator->trans($key, ['%id%' => $parts[1] ?? ''], self::DOMAIN),
            default => $this->translator->trans($key, [], self::DOMAIN),
        };
    }
}
