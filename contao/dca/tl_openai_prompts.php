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

$GLOBALS['TL_DCA']['tl_openai_prompts'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        'ptable'           => 'tl_openai_config',
        'enableVersioning' => true,
        'sql'              => [
            'keys' => [
                'id'  => 'primary',
                'pid' => 'index',
            ],
        ],
    ],

    'list' => [
        'sorting' => [
            'mode'                  => 4,
            'fields'                => ['name'],
            'headerFields'          => ['title'],
            'panelLayout'           => 'filter;search,limit',
            'child_record_callback' => ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiPromptsListener', 'listPrompts'],
        ],
        'label' => [
            'fields' => ['name', 'model', 'status'],
            'format' => '%s<br><span style="color:#999;padding-left:3px">[%s] - Status: %s</span>',
        ],
        'child_record' => [
            'fields' => ['name', 'model', 'temperature', 'top_p', 'status'],
        ],
        'global_operations' => [
            'all' => [
                'href'       => 'act=select',
                'class'      => 'header_edit_all',
                'attributes' => 'onclick="Backend.getScrollOffset()" accesskey="e"',
            ],
        ],
        'operations' => [
            'edit' => [
                'href' => 'act=edit',
                'icon' => 'edit.svg',
            ],
            'delete' => [
                'href'       => 'act=delete',
                'icon'       => 'delete.svg',
                'attributes' => 'onclick="if(!confirm(\'' . ($GLOBALS['TL_LANG']['MSC']['deleteConfirm'] ?? '') . '\'))return false;Backend.getScrollOffset()"',
            ],
            'show' => [
                'href' => 'act=show',
                'icon' => 'show.svg',
            ],
        ],
    ],

    'header' => [
        'fields' => ['name'],
        'format' => '%s',
    ],

    'palettes' => [
        'default' => '{title_legend},name;{instructions_legend},system_instructions;{model_legend},model,model_manual;{settings_legend},max_tokens,temperature,top_p;{prompt_legend},prompt_id,prompt_version',
    ],

    'fields' => [
        'id' => [
            'sql' => [
                'type'          => 'integer',
                'unsigned'      => true,
                'autoincrement' => true,
            ],
        ],
        'pid' => [
            'foreignKey' => 'tl_openai_config.title',
            'sql'        => [
                'type'     => 'integer',
                'unsigned' => true,
                'default'  => 0,
            ],
            'relation' => [
                'type' => 'belongsTo',
                'load' => 'lazy',
            ],
        ],
        'tstamp' => [
            'sql' => [
                'type'     => 'integer',
                'unsigned' => true,
                'default'  => 0,
            ],
        ],
        'name' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_prompts']['name'],
            'exclude'   => true,
            'search'    => true,
            'inputType' => 'text',
            'eval'      => [
                'mandatory' => true,
                'maxlength' => 255,
                'tl_class'  => 'w50',
            ],
            'sorting' => true,
            'sql'     => "varchar(255) NOT NULL default ''",
        ],
        'model' => [
            'label'            => &$GLOBALS['TL_LANG']['tl_openai_prompts']['model'],
            'exclude'          => true,
            'inputType'        => 'select',
            'options_callback' => ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiPromptsListener', 'getAvailableModels'],
            'save_callback'    => [
                ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiPromptsListener', 'validateModel'],
            ],
            'eval' => [
                'chosen'             => true,
                'tl_class'           => 'w50',
                'includeBlankOption' => true,
                'blankOptionLabel'   => &$GLOBALS['TL_LANG']['tl_openai_prompts']['model_select_placeholder'],
            ],
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        'model_manual' => [
            'label'         => &$GLOBALS['TL_LANG']['tl_openai_prompts']['model_manual'],
            'exclude'       => true,
            'inputType'     => 'text',
            'save_callback' => [
                ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiPromptsListener', 'validateManualModel'],
            ],
            'eval' => [
                'maxlength'   => 255,
                'tl_class'    => 'w50',
                'placeholder' => &$GLOBALS['TL_LANG']['tl_openai_prompts']['model_manual_placeholder'],
            ],
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        'max_tokens' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_prompts']['max_tokens'],
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => [
                'mandatory' => true,
                'rgxp'      => 'natural',
                'tl_class'  => 'w33',
            ],
            'sql' => 'int(10) unsigned NOT NULL default 2000',
        ],
        'temperature' => [
            'label'         => &$GLOBALS['TL_LANG']['tl_openai_prompts']['temperature'],
            'exclude'       => true,
            'inputType'     => 'text',
            'save_callback' => [
                ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiPromptsListener', 'validateTemperature'],
            ],
            'eval' => [
                'mandatory' => true,
                'rgxp'      => 'prcnt',
                'tl_class'  => 'w33',
                'minval'    => 0,
                'maxval'    => 2,
            ],
            'sql' => 'float NOT NULL default 0',
        ],
        'top_p' => [
            'label'         => &$GLOBALS['TL_LANG']['tl_openai_prompts']['top_p'],
            'exclude'       => true,
            'inputType'     => 'text',
            'save_callback' => [
                ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiPromptsListener', 'validateTopP'],
            ],
            'eval' => [
                'mandatory' => true,
                'rgxp'      => 'prcnt',
                'tl_class'  => 'w33',
                'minval'    => 0,
                'maxval'    => 1,
            ],
            'sql' => 'float NOT NULL default 1',
        ],
        'system_instructions' => [
            'label'         => &$GLOBALS['TL_LANG']['tl_openai_prompts']['system_instructions'],
            'exclude'       => true,
            'inputType'     => 'textarea',
            'save_callback' => [
                ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiPromptsListener', 'normalizeSystemInstructions'],
            ],
            'eval' => [
                'rte'      => '',
                'tl_class' => 'clr',
                'rows'     => 6,
                // Preserve literal characters and decode HTML entities on save so instructions remain 1:1
                'preserveTags'   => true,
                'decodeEntities' => true,
            ],
            'search' => true,
            'sql'    => 'text NULL',
        ],
        'prompt_id' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_prompts']['prompt_id'],
            'exclude'   => true,
            'search'    => true,
            'inputType' => 'text',
            'eval'      => [
                'maxlength'   => 128,
                'tl_class'    => 'w50',
                'placeholder' => &$GLOBALS['TL_LANG']['tl_openai_prompts']['prompt_id_placeholder'],
            ],
            'sql' => "varchar(128) NOT NULL default ''",
        ],
        'prompt_version' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_prompts']['prompt_version'],
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => [
                'maxlength'   => 32,
                'tl_class'    => 'w50',
                'placeholder' => &$GLOBALS['TL_LANG']['tl_openai_prompts']['prompt_version_placeholder'],
            ],
            'sql' => "varchar(32) NOT NULL default ''",
        ],
        'openai_assistant_id' => [
            // Deprecated. Kept in the schema so the cleanup migration can reach legacy rows.
            // Hidden from the palette; will be dropped after the cleanup migration has run once.
            'sql' => [
                'type'    => 'string',
                'length'  => 255,
                'default' => '',
            ],
        ],
        'status' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_prompts']['status'],
            'exclude'   => true,
            'inputType' => 'select',
            'options'   => ['active', 'creating', 'failed', 'pending'],
            'reference' => &$GLOBALS['TL_LANG']['tl_openai_prompts']['status_options'],
            'eval'      => [
                'tl_class' => 'w50',
            ],
            'default' => 'active',
            'sql'     => [
                'type'    => 'string',
                'length'  => 32,
                'default' => 'active',
            ],
        ],
        'status_cause' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_prompts']['status_cause'],
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => [
                'readonly' => true,
                'tl_class' => 'w50',
            ],
            'sql' => [
                'type'    => 'string',
                'length'  => 255,
                'default' => '',
            ],
        ],
    ],
];
