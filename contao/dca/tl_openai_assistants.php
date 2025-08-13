<?php

declare(strict_types=1);

use Contao\DC_Table;
use Contao\Message;

$GLOBALS['TL_DCA']['tl_openai_assistants'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ptable' => 'tl_openai_config',
        'enableVersioning' => true,
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
            'fields' => ['name'],
            'headerFields' => ['title'],
            'panelLayout' => 'filter;search,limit',
            'child_record_callback' => ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiAssistantsListener', 'listAssistants']
        ],
        'label' => [
            'fields' => ['name', 'model', 'status'],
            'format' => '%s<br><span style="color:#999;padding-left:3px">[%s] - Status: %s</span>'
        ],
        'child_record' => [
            'fields' => ['name', 'model', 'temperature', 'top_p', 'status']
        ],
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
    
    'header' => [
        'fields' => ['name'],
        'format' => '%s'
    ],
    

    
		'palettes' => [
			'default' => '{title_legend},name;{instructions_legend},system_instructions;{model_legend},model,model_manual;{settings_legend},max_tokens,temperature,top_p;{openai_legend},openai_assistant_id,status,status_cause'
		],
    
    'fields' => [
        'id' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]
        ],
        'pid' => [
            'foreignKey' => 'tl_openai_config.title',
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
            'relation' => ['type' => 'belongsTo', 'load' => 'lazy']
        ],
        'tstamp' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]
        ],
        'name' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_assistants']['name'],
            'exclude' => true,
            'search' => true,
            'inputType' => 'text',
            'eval' => [
                'mandatory' => true,
                'maxlength' => 255,
                'tl_class' => 'w50'
            ],
            'sorting' => true,
            'sql' => "varchar(255) NOT NULL default ''"
        ],
        'model' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_assistants']['model'],
            'exclude' => true,
            'inputType' => 'select',
            'options_callback' => ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiAssistantsListener', 'getAvailableModels'],
            'save_callback' => [
                ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiAssistantsListener', 'validateModel']
            ],
            'eval' => [
                'chosen' => true,
                'tl_class' => 'w50',
                'includeBlankOption' => true,
                'blankOptionLabel' => &$GLOBALS['TL_LANG']['tl_openai_assistants']['model_select_placeholder']
            ],
            'sql' => "varchar(255) NOT NULL default ''"
        ],
        'model_manual' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_assistants']['model_manual'],
            'exclude' => true,
            'inputType' => 'text',
            'save_callback' => [
                ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiAssistantsListener', 'validateManualModel']
            ],
            'eval' => [
                'maxlength' => 255,
                'tl_class' => 'w50',
                'placeholder' => &$GLOBALS['TL_LANG']['tl_openai_assistants']['model_manual_placeholder']
            ],
            'sql' => "varchar(255) NOT NULL default ''"
        ],
        'max_tokens' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_assistants']['max_tokens'],
            'exclude' => true,
            'inputType' => 'text',
            'eval' => [
                'mandatory' => true,
                'rgxp' => 'natural',
                'tl_class' => 'w33'
            ],
            'sql' => "int(10) unsigned NOT NULL default 2000"
        ],
        'temperature' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_assistants']['temperature'],
            'exclude' => true,
            'inputType' => 'text',
            'save_callback' => [
                ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiAssistantsListener', 'validateTemperature']
            ],
            'eval' => [
                'mandatory' => true,
                'rgxp' => 'prcnt',
                'tl_class' => 'w33',
                'minval' => 0,
                'maxval' => 2
            ],
            'sql' => "float NOT NULL default 0"
        ],
        'top_p' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_assistants']['top_p'],
            'exclude' => true,
            'inputType' => 'text',
            'save_callback' => [
                ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiAssistantsListener', 'validateTopP']
            ],
            'eval' => [
                'mandatory' => true,
                'rgxp' => 'prcnt',
                'tl_class' => 'w33',
                'minval' => 0,
                'maxval' => 1
            ],
            'sql' => "float NOT NULL default 1"
        ],
        'system_instructions' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_assistants']['system_instructions'],
            'exclude' => true,
            'inputType' => 'textarea',
            'save_callback' => [
                ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiAssistantsListener', 'normalizeSystemInstructions']
            ],
            'eval' => [
                'mandatory' => true,
                'rte' => '',
                'tl_class' => 'clr',
                'rows' => 6,
                // Preserve literal characters and decode HTML entities on save so instructions remain 1:1
                'preserveTags' => true,
                'decodeEntities' => true
            ],
            'search' => true,
            'sql' => "text NULL"
        ],
        'openai_assistant_id' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_assistants']['openai_assistant_id'],
            'exclude' => true,
            'inputType' => 'text',
            'eval' => [
                'readonly' => true,
                'tl_class' => 'w50'
            ],
            'search' => true,
            'sql' => ['type' => 'string', 'length' => 255, 'default' => '']
        ],
        'status' => [
            'label' => &$GLOBALS['TL_LANG']['tl_openai_assistants']['status'],
            'exclude' => true,
            'inputType' => 'select',
            'options' => ['active', 'creating', 'failed', 'pending'],
            'reference' => &$GLOBALS['TL_LANG']['tl_openai_assistants']['status_options'],
            'eval' => [
                'readonly' => true,
                'tl_class' => 'w50'
            ],
            'default' => 'pending',
            'sql' => ['type' => 'string', 'length' => 32, 'default' => 'pending']
        ]
			,
			'status_cause' => [
				'label' => &$GLOBALS['TL_LANG']['tl_openai_assistants']['status_cause'],
				'exclude' => true,
				'inputType' => 'text',
				'eval' => [
					'readonly' => true,
					'tl_class' => 'w50'
				],
				'sql' => ['type' => 'string', 'length' => 255, 'default' => '']
			]
    ]
];
