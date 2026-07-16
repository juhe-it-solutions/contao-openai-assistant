<?php

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

use Contao\DC_Table;

/*
 * State map for the per-page vector store sync.
 *
 * One row per page (or page chunk) currently uploaded to the OpenAI vector store for a
 * given tl_openai_config record. It is the source of truth for incremental sync: each run
 * compares content_hash against the freshly built page content to decide upload/skip/delete.
 *
 * The table is internal machine state - created/maintained by Contao's Doctrine schema sync
 * (no migration), like tl_openai_sync_log. It is registered as a closed, read-only DC_Table
 * so operators can inspect it, but it is never edited by hand.
 */
$GLOBALS['TL_DCA']['tl_openai_vector_file'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        'closed'           => true,
        'notCopyable'      => true,
        'notEditable'      => true,
        'enableVersioning' => false,
        'sql' => [
            'keys' => [
                'id'             => 'primary',
                'pid'            => 'index',
                'pid,page_id'    => 'index',
                'openai_file_id' => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode'        => 2,
            'fields'      => ['page_id'],
            'panelLayout' => 'sort,search,limit',
        ],
        'label' => [
            'fields'      => ['page_id', 'title', 'url', 'status', 'bytes', 'openai_file_id'],
            'showColumns' => true,
        ],
    ],
    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true],
        ],
        // Owning tl_openai_config record.
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'tstamp' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // Source tl_page id (0 if the content is not bound to a single page).
        'page_id' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'url' => [
            'sql' => ['type' => 'string', 'length' => 2048, 'default' => ''],
        ],
        'title' => [
            'sql' => ['type' => 'string', 'length' => 512, 'default' => ''],
        ],
        'language' => [
            'sql' => ['type' => 'string', 'length' => 5, 'default' => ''],
        ],
        // Copy of tl_search.checksum, kept for reference/debugging only.
        'search_checksum' => [
            'sql' => ['type' => 'string', 'length' => 32, 'default' => ''],
        ],
        // sha256 of the final cleaned content actually uploaded - the incremental key.
        'content_hash' => [
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        // Chunk position for the rare page that exceeds the OpenAI per-file limit.
        'chunk_index' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'chunk_count' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 1],
        ],
        'openai_file_id' => [
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'bytes' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // uploaded | failed | orphan
        'status' => [
            'sql' => ['type' => 'string', 'length' => 20, 'default' => ''],
        ],
        'last_error' => [
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
    ],
];
