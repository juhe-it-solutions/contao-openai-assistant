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
 * (BE_MOD ai_tools.openai_vector_file) so operators can look up which OpenAI file holds
 * which page - the uploaded files carry no such index - but it is never edited by hand.
 *
 * Rows are not deletable either: deleting one would drop the sync's memory of an existing
 * remote file, so the next run would upload the page a second time and leave the first file
 * orphaned in the store. Removal happens through the sync itself (page out of scope) or by
 * resetting the feature on the config.
 */
$GLOBALS['TL_DCA']['tl_openai_vector_file'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        'closed'           => true,
        'notCopyable'      => true,
        'notEditable'      => true,
        'notDeletable'     => true,
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
            'panelLayout' => 'filter;sort,search,limit',
        ],
        'label' => [
            'fields'      => ['page_id', 'title', 'url', 'indexed_urls', 'status', 'chunk_index', 'bytes', 'openai_file_id', 'tstamp'],
            'showColumns' => true,
        ],
        // "content" shows the indexed text of one page, served by the auto-sync controller
        // from the stored run manifest (button_callback in OpenAiVectorFileListener). The
        // default show operation is auto-appended by Contao's DefaultOperationsListener;
        // edit/copy/delete are all disabled by the config flags above.
        'operations' => [
            'content' => [
                'label' => &$GLOBALS['TL_LANG']['tl_openai_vector_file']['content'],
                'icon'  => 'show.svg',
            ],
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
            'label'   => &$GLOBALS['TL_LANG']['tl_openai_vector_file']['tstamp'],
            'sorting' => true,
            'flag'    => 6,
            'eval'    => ['rgxp' => 'datim'],
            'sql'     => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // Source tl_page id (0 if the content is not bound to a single page).
        'page_id' => [
            'label'   => &$GLOBALS['TL_LANG']['tl_openai_vector_file']['page_id'],
            'sorting' => true,
            'search'  => true,
            'sql'     => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'url' => [
            'label'  => &$GLOBALS['TL_LANG']['tl_openai_vector_file']['url'],
            'search' => true,
            'sql'    => ['type' => 'string', 'length' => 2048, 'default' => ''],
        ],
        'title' => [
            'label'   => &$GLOBALS['TL_LANG']['tl_openai_vector_file']['title'],
            'sorting' => true,
            'search'  => true,
            'sql'     => ['type' => 'string', 'length' => 512, 'default' => ''],
        ],
        'language' => [
            'label'  => &$GLOBALS['TL_LANG']['tl_openai_vector_file']['language'],
            'filter' => true,
            'sql'    => ['type' => 'string', 'length' => 5, 'default' => ''],
        ],
        // Virtual column - deliberately without an "sql" key, so Contao's schema sync never
        // creates a database field for it. The label callback fills it by counting the page's
        // rows in tl_search: 1 for an ordinary page, one per indexed news/FAQ/event entry on a
        // reader page. Never made sortable/searchable/filterable - there is no column to query.
        'indexed_urls' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_vector_file']['indexed_urls'],
        ],
        // Copy of tl_search.checksum, kept for reference/debugging only.
        'search_checksum' => [
            'sql' => ['type' => 'string', 'length' => 32, 'default' => ''],
        ],
        // sha256 of the final cleaned content actually uploaded - the incremental key.
        'content_hash' => [
            'sql' => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        // Chunk position for the rare page that exceeds the OpenAI per-file limit. Rendered
        // as "1/2" by the label callback, hence only chunk_index appears in the column list.
        'chunk_index' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_vector_file']['chunk'],
            'sql'   => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'chunk_count' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 1],
        ],
        'openai_file_id' => [
            'label'  => &$GLOBALS['TL_LANG']['tl_openai_vector_file']['openai_file_id'],
            'search' => true,
            'sql'    => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'bytes' => [
            'label'   => &$GLOBALS['TL_LANG']['tl_openai_vector_file']['bytes'],
            'sorting' => true,
            'sql'     => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // uploaded | failed | orphan
        'status' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_vector_file']['status'],
            'filter'    => true,
            'reference' => &$GLOBALS['TL_LANG']['tl_openai_vector_file']['status_ref'],
            'sql'       => ['type' => 'string', 'length' => 20, 'default' => ''],
        ],
        'last_error' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_vector_file']['last_error'],
            'sql'   => ['type' => 'text', 'notnull' => false],
        ],
    ],
];
