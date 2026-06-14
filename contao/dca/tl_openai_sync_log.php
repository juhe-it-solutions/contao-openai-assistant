<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) JUHE IT-solutions
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

use Contao\DC_Table;

/*
 * Run-history table for the automatic vector store sync.
 *
 * Registered as a read-only DC_Table backend module (BE_MOD ai_tools.openai_sync_log)
 * so operators get the standard Contao list: pagination, search/sort, "edit multiple"
 * select mode and (multi-)delete. Records are written by VectorStoreAutoUpdateService;
 * the table is closed (no "new") and not editable/copyable — only deletable. The schema
 * is still created/maintained by Contao's Doctrine schema sync (no migration).
 */
$GLOBALS['TL_DCA']['tl_openai_sync_log'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        'closed'           => true,  // no "new" records via the UI
        'notCopyable'      => true,
        'notEditable'      => true,  // read-only; delete + multi-delete remain
        'enableVersioning' => false,
        'sql' => [
            'keys' => [
                'id'     => 'primary',
                'pid'    => 'index',
                'run_at' => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode'        => 2,
            'fields'      => ['run_at DESC'],
            'flag'        => 6,
            'panelLayout' => 'sort,search,limit',
        ],
        'label' => [
            'fields'      => ['run_at', 'status', 'pages', 'tokens_in', 'tokens_out', 'duration', 'file_id', 'message'],
            'showColumns' => true,
        ],
    ],
    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true],
        ],
        'pid' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'tstamp' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'run_at' => [
            'label'   => &$GLOBALS['TL_LANG']['tl_openai_sync_log']['run_at'],
            'sorting' => true,
            'flag'    => 6,
            'eval'    => ['rgxp' => 'datim'],
            'sql'     => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'status' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_sync_log']['status'],
            'filter'    => true,
            'reference' => &$GLOBALS['TL_LANG']['tl_openai_sync_log']['status_ref'],
            'sql'       => ['type' => 'string', 'length' => 20, 'default' => ''],
        ],
        'pages' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_sync_log']['pages'],
            'sql'   => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'tokens_in' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_sync_log']['tokens_in'],
            'sql'   => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'tokens_out' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_sync_log']['tokens_out'],
            'sql'   => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'file_id' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_sync_log']['file_id'],
            'sql'   => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'duration' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_sync_log']['duration'],
            'sql'   => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'message' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_sync_log']['message'],
            'sql'   => ['type' => 'text', 'notnull' => false],
        ],
    ],
];
