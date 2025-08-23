<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) JUHE IT-solutions
 *  *
 *  * @license LGPL-3.0-or-later
 */

$GLOBALS['BE_MOD']['ai_tools'] = [
    'openai_dashboard' => [
        'tables' => ['tl_openai_config', 'tl_openai_files', 'tl_openai_assistants'],
        'icon'   => 'bundles/contaocore/icons/modules.svg',
    ],
];

// Load backend CSS for AI Tools menu icon (Contao 5 official way)
$GLOBALS['TL_CSS'][] = 'bundles/contaoopenaiassistant/css/backend.css|static';
