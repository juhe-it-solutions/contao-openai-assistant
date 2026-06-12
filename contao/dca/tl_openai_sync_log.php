<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) JUHE IT-solutions
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

/*
 * Run-history table for the automatic vector store sync.
 *
 * This DCA exists ONLY to declare the schema. The table is managed entirely by
 * Contao's Doctrine schema sync (single source of truth) — there is no migration.
 * Declaring it here ensures the table is both created on install/update AND
 * protected from being flagged as an orphan "DROP TABLE" candidate.
 *
 * It is intentionally not registered in any BE_MOD, so it never appears as an
 * editable backend module; rows are written/read directly via DBAL by the
 * VectorStoreAutoUpdateService and the backend status controller.
 */
$GLOBALS['TL_DCA']['tl_openai_sync_log'] = [
    'config' => [
        'sql' => [
            'keys' => [
                'id'     => 'primary',
                'pid'    => 'index',
                'run_at' => 'index',
            ],
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
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'status' => [
            'sql' => ['type' => 'string', 'length' => 20, 'default' => ''],
        ],
        'pages' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'tokens_in' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'tokens_out' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'file_id' => [
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'duration' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'message' => [
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
    ],
];
