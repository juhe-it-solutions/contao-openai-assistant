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
 * Links found on indexed pages.
 *
 * One row per (indexed document URL, link target). Written by
 * SearchIndexLinkListener while Contao indexes a page - during contao:crawl and on
 * live front-end traffic alike - and read by the vector-store sync, which turns
 * the surviving links into the "Weiterführende Links" section of a page document.
 *
 * The table is internal machine state - created/maintained by Contao's Doctrine
 * schema sync (no migration), like tl_openai_vector_file. The DCA exists so the
 * schema is generated and so the table is never editable: it is registered as a
 * closed, read-only DC_Table, but it is deliberately NOT exposed as a backend
 * module. Rows whose source document disappears from tl_search are pruned by
 * every sync run.
 */
$GLOBALS['TL_DCA']['tl_openai_page_link'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        'closed'           => true,
        'notCopyable'      => true,
        'notEditable'      => true,
        'enableVersioning' => false,
        'sql' => [
            'keys' => [
                'id'          => 'primary',
                'page_id'     => 'index',
                'source_hash' => 'index',
                'url_hash'    => 'index',
                'type'        => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode'        => 2,
            'fields'      => ['page_id', 'position'],
            'panelLayout' => 'sort,search,limit',
        ],
        'label' => [
            'fields'      => ['page_id', 'type', 'label', 'url'],
            'showColumns' => true,
        ],
    ],
    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true],
        ],
        'tstamp' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // Source tl_page id, taken from the indexer's page data.
        'page_id' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // The indexed document URL the link was found on. Normalised exactly like
        // Contao stores tl_search.url - and declared with the SAME collation as
        // that column (see contao/dca/tl_search.php). Without the matching
        // collation the orphan-pruning JOIN would fail with "Illegal mix of
        // collations" on every sync.
        'source_url' => [
            'sql' => "varchar(2048) COLLATE ascii_bin NOT NULL default ''",
        ],
        // sha1(source_url) - the delete key; a 2048 char column cannot be indexed.
        'source_hash' => [
            'sql' => ['type' => 'string', 'length' => 40, 'fixed' => true, 'default' => ''],
        ],
        // The link target, normalised the same way. ascii_bin as well: the stored
        // value is always percent-encoded ASCII, and it keeps the row narrow.
        'url' => [
            'sql' => "varchar(2048) COLLATE ascii_bin NOT NULL default ''",
        ],
        // sha1(url) - de-duplication and cross-page frequency key.
        'url_hash' => [
            'sql' => ['type' => 'string', 'length' => 40, 'fixed' => true, 'default' => ''],
        ],
        'label' => [
            'sql' => ['type' => 'string', 'length' => 512, 'default' => ''],
        ],
        // The link's title attribute, when it adds information beyond the label.
        'link_title' => [
            'sql' => ['type' => 'string', 'length' => 512, 'default' => ''],
        ],
        // page | file | external | mailto | tel
        'type' => [
            'sql' => ['type' => 'string', 'length' => 16, 'default' => ''],
        ],
        'mime' => [
            'sql' => ['type' => 'string', 'length' => 100, 'default' => ''],
        ],
        // Project-relative path for type=file inside the upload directory.
        'file_path' => [
            'sql' => ['type' => 'string', 'length' => 1024, 'default' => ''],
        ],
        'file_size' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'language' => [
            'sql' => ['type' => 'string', 'length' => 5, 'default' => ''],
        ],
        // Document order, so the rendered list keeps the page's own sequence.
        'position' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'occurrences' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 1],
        ],
    ],
];
