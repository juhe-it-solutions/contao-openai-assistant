<?php

declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Contao\DC_Table;
use Contao\Message;

$GLOBALS['TL_DCA']['tl_openai_config'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ctable' => ['tl_openai_files', 'tl_openai_assistants'],
        'enableVersioning' => true,
        'onload_callback' => [
            function($dc) {
                $message = '<strong style="display: block; font-size: 22px; position: relative; top: -5px;">' . 
                          $GLOBALS['TL_LANG']['tl_openai_config']['welcome_heading'] . 
                          '</strong>' . 
                          $GLOBALS['TL_LANG']['tl_openai_config']['welcome_message1'] . 
                          '<br>' .
                          $GLOBALS['TL_LANG']['tl_openai_config']['welcome_message2'] . 
                          '<br>' .
                          '<span style="color: #f59e0b; line-height: 2">' . $GLOBALS['TL_LANG']['tl_openai_config']['navigation_message'] . '</span>' .
                          '<div style="background: var(--info-bg); border-left: 4px solid #2196f3; padding: 10px; margin-top: -2px;">' .
                          '<strong>ℹ️ ' . ($GLOBALS['TL_LANG']['tl_openai_config']['single_config_heading'] ?? 'Single Configuration') . ':</strong> ' .
                          ($GLOBALS['TL_LANG']['tl_openai_config']['single_config_message'] ?? 'Only one OpenAI configuration is allowed per system. If a configuration already exists, you will be redirected to edit it.') .
                          '</div>';
                Message::addInfo($message);
            },
            ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiConfigListener', 'onLoadCallback']
        ],
        'ondelete_callback' => [
            ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiConfigListener', 'deleteVectorStore']
        ],
        'sql' => [
            'keys' => [
                'id' => 'primary'
            ]
        ]
    ],    
    'list' => [
        'sorting' => [
            'mode' => 1,
            'fields' => ['tstamp'],
            'flag' => 1,
            'panelLayout' => 'filter;search,limit'
        ],
        'label' => [
            'fields' => ['title', 'api_key'],
            'format' => '%s <span style="color:#999;">[%s]</span>',
            'label_callback' => ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiConfigListener', 'addIcon']
        ],
        'header_callback' => ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiConfigListener', 'addHeader'],
        'global_operations' => [
            'all' => [
                'href' => 'act=select',
                'class' => 'header_edit_all',
                'attributes' => 'onclick="Backend.getScrollOffset()"'
            ]
        ],
        'operations' => [
            'edit' => [
                'href' => 'act=edit',
                'icon' => 'edit.svg'
            ],
            'delete' => [
                'href' => 'act=delete',
                'icon' => 'delete.svg',
                'attributes' => 'onclick="if(!confirm(\''.($GLOBALS['TL_LANG']['MSC']['deleteConfirm'] ?? '').' \'))return false;Backend.getScrollOffset()"'
            ],
            'files' => [
                'href' => 'table=tl_openai_files',
                'icon' => 'modules.svg',
                'label' => &$GLOBALS['TL_LANG']['tl_openai_config']['files']
            ],
            'assistants' => [
                'href' => 'table=tl_openai_assistants',
                'icon' => 'member.svg',
                'label' => &$GLOBALS['TL_LANG']['tl_openai_config']['assistants']
            ]
        ]
    ],
    'palettes' => [
        'default' => '{title_legend},title,api_key;{config_legend},vector_store_id'
    ],
    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]
        ],
        'tstamp' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]
        ],
        'title' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_config']['title'],
            'exclude' => true,
            'search' => true,
            'inputType' => 'text',
            'eval' => [
                'mandatory' => true,
                'maxlength' => 255,
                'tl_class' => 'w50'
            ],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => '']
        ],
        'api_key' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_config']['api_key'],
            'exclude' => true,
            'inputType' => 'password',
            'eval' => [
                'mandatory' => true,
                'maxlength' => 255,
                'tl_class' => 'w50'
            ],
            'save_callback' => [
                ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiConfigListener', 'processApiKeyForStorage']
            ],
            'sql' => ['type' => 'string', 'length' => 1024, 'default' => '']
        ],
        'vector_store_id' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_config']['vector_store_id'],
            'exclude' => true,
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => ['type' => 'string', 'length' => 255, 'default' => '']
        ]
    ]
];
