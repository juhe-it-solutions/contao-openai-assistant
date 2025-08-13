<?php

declare(strict_types=1);

use Contao\DC_Table;
use Contao\Message;

$GLOBALS['TL_DCA']['tl_openai_files'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ptable' => 'tl_openai_config',
        'enableVersioning' => true,
        'onload_callback' => [
            function($dc) {
                $message = '<strong style="display: block; font-size: 22px; position: relative; top: -5px;">' . 
                          $GLOBALS['TL_LANG']['tl_openai_files']['welcome_heading'] . 
                          '</strong>' . 
                          $GLOBALS['TL_LANG']['tl_openai_files']['welcome_message1'] . 
                          '<br>' .
                          $GLOBALS['TL_LANG']['tl_openai_files']['welcome_message2'];
                Message::addInfo($message);
            },
            ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiFilesListener', 'onLoadCallback']
        ],
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'pid' => 'index'
            ]
        ]
    ],
    'list' => [
        'sorting' => [
            'mode' => 4,
            'fields' => ['filename'],
            'headerFields' => ['title'],
            'panelLayout' => 'filter;search,limit',
            'child_record_callback' => ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiFilesListener', 'listFiles']
        ],
        'header_callback' => ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiFilesListener', 'addHeader'],
        'global_operations' => [
            'all' => [
                'href' => 'act=select',
                'class' => 'header_edit_all',
                'attributes' => 'onclick="Backend.getScrollOffset()" accesskey="e"'
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
                'attributes' => 'onclick="if(!confirm(\'' . ($GLOBALS['TL_LANG']['MSC']['deleteConfirm'] ?? '') . '\'))return false;Backend.getScrollOffset()"'
            ],
            'show' => [
                'href' => 'act=show',
                'icon' => 'show.svg'
            ]
        ]
    ],
    'palettes' => [
        'default' => '{file_legend},file_upload;{openai_legend},openai_file_id,status,file_size'
    ],
    'fields' => [
        'id' => [
            'sql' => "int(10) unsigned NOT NULL auto_increment"
        ],
        'pid' => [
            'foreignKey' => 'tl_openai_config.title',
            'sql' => "int(10) unsigned NOT NULL default 0",
            'relation' => ['type'=>'belongsTo', 'load'=>'lazy']
        ],
        'tstamp' => [
            'sql' => "int(10) unsigned NOT NULL default 0"
        ],
        'filename' => [
            'exclude' => true,
            'inputType' => 'text',
            'eval' => [
                'maxlength' => 255,
                'tl_class' => 'w50',
                'doNotShow' => true,
                'doNotCopy' => true,
                'hideInput' => true
            ],
            'sql' => "varchar(255) NOT NULL default ''"
        ],
        'file_upload' => [
            'exclude' => true,
            'inputType' => 'fileTree',
            'eval' => [
                'multiple' => true,
                'fieldType' => 'checkbox',
                'filesOnly' => true,
                'extensions' => 'pdf,txt,md,docx,pptx,json',
                'mandatory' => true,
                'tl_class' => 'clr',
                'orderField' => 'orderSRC'
            ],
            'save_callback' => [['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiFilesListener', 'uploadToOpenAI']],
            'sql' => "blob NULL"
        ],
        'openai_file_id' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => "varchar(255) NOT NULL default ''"
        ],
        'status' => [
            'inputType' => 'select',
            'options' => ['pending', 'processing', 'uploaded', 'completed', 'failed', 'error'],
            'reference' => &$GLOBALS['TL_LANG']['tl_openai_files']['status_options'],
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => "varchar(32) NOT NULL default 'pending'"
        ],
        'file_size' => [
            'inputType' => 'text',
            'eval' => ['readonly' => true, 'tl_class' => 'w50'],
            'sql' => "varchar(32) NOT NULL default ''"
        ]
    ]
];
