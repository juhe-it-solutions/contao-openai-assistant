<?php

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

$GLOBALS['BE_MOD']['ai_tools'] = [
    'openai_dashboard' => [
        'tables' => ['tl_openai_config', 'tl_openai_files', 'tl_openai_prompts'],
        'icon'   => 'bundles/contaocore/icons/modules.svg',
    ],
    // Status dashboard for the automatic vector store sync. The navigation link is
    // injected by BackendMenuListener (a custom route); this entry makes the module
    // appear in the per-group permission UI. disablePermissionChecks = false ⇒
    // access is restricted to admins and explicitly granted user groups.
    'vector_store_auto_update' => [
        'disablePermissionChecks' => false,
    ],
    // Read-only run history for the automatic vector store sync (standard DC_Table list:
    // pagination, search/sort, select mode + delete).
    'openai_sync_log' => [
        'tables' => ['tl_openai_sync_log'],
        'icon'   => 'bundles/contaocore/icons/modules.svg',
    ],
];

// Load backend CSS for AI Tools menu icon (Contao 5 official way)
$GLOBALS['TL_CSS'][] = 'bundles/contaoopenaiassistant/css/backend.css|static';
$GLOBALS['TL_JAVASCRIPT'][] = 'bundles/contaoopenaiassistant/js/backend-api-key-check.js|static';
