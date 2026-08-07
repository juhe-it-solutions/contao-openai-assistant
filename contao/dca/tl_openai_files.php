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
use Contao\Message;

/*
 * Where the callbacks below are registered, and why it differs per kind:
 *
 * - Every callback is registered ONCE: either in the array here, or as a contao.callback
 *   in config/services.yaml - never in both. Contao APPENDS a tagged callback to the DCA
 *   array (DataContainerCallbackListener::addCallbacks), so naming one in both places runs
 *   it TWICE per save. Which of the two places is used is a per-callback decision; inline
 *   closures obviously have no service to tag.
 * - The list callbacks stay HERE. A "list.label" / "list.child_record" tag compiles to
 *   ['list']['label_callback'] / ['list']['child_record_callback'], but Contao reads them
 *   from ['list']['label']['label_callback'] (DataContainer::generateRecordLabel) and
 *   ['list']['sorting']['child_record_callback'] (DC_Table::parentView) - such a tag is
 *   silently inert. The correct targets would be "list.label.label" and
 *   "list.sorting.child_record".
 */
$GLOBALS['TL_DCA']['tl_openai_files'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        'ptable'           => 'tl_openai_config',
        'enableVersioning' => true,
        'onload_callback'  => [
            function ($dc) {
                $message = '<div class="oaa-info-card">' .
                          '<p class="tl_info" style="background: transparent url(system/themes/flexible/icons/show.svg) no-repeat 11px 12px;">' .
                          '<strong class="oaa-info-card-heading" style="display: block; font-size: 22px; position: relative; top: -5px;">' .
                          $GLOBALS['TL_LANG']['tl_openai_files']['welcome_heading'] .
                          '</strong>' .
                          $GLOBALS['TL_LANG']['tl_openai_files']['welcome_message1'] .
                          '<br>' .
                          $GLOBALS['TL_LANG']['tl_openai_files']['welcome_message2'] .
                          '</p>' .
                          '</div>';
                Message::addRaw($message);
            },
            ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiFilesListener', 'onLoadCallback'],
        ],
        'sql' => [
            'keys' => [
                'id'  => 'primary',
                'pid' => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode'                  => 4,
            'fields'                => ['filename'],
            'headerFields'          => ['title'],
            'panelLayout'           => 'filter;search,limit',
            // Registered here, NOT as a contao.callback service: the tag target
            // "list.child_record" compiles to $GLOBALS['TL_DCA'][...]['list']['child_record_callback'],
            // while Contao reads it from ['list']['sorting']['child_record_callback']. A tag would
            // therefore be silently inert - this array is the only registration that runs.
            'child_record_callback' => ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiFilesListener', 'listFiles'],
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
    'palettes' => [
        'default' => '{file_legend},file_upload;{openai_legend},openai_file_id,status,file_size',
    ],
    'fields' => [
        'id' => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        'pid' => [
            'foreignKey' => 'tl_openai_config.title',
            'sql'        => 'int(10) unsigned NOT NULL default 0',
            'relation'   => [
                'type'=> 'belongsTo',
                'load'=> 'lazy',
            ],
        ],
        'tstamp' => [
            'sql' => 'int(10) unsigned NOT NULL default 0',
        ],
        'filename' => [
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => [
                'maxlength' => 255,
                'tl_class'  => 'w50',
                'doNotShow' => true,
                'doNotCopy' => true,
                'hideInput' => true,
            ],
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        'file_upload' => [
            'exclude'   => true,
            'inputType' => 'fileTree',
            'eval'      => [
                'multiple'   => true,
                'fieldType'  => 'checkbox',
                'filesOnly'  => true,
                'extensions' => 'pdf,txt,md,docx,pptx,json',
                'mandatory'  => true,
                'tl_class'   => 'clr',
                'orderField' => 'orderSRC',
            ],
            'sql'           => 'blob NULL',
        ],
        'openai_file_id' => [
            'inputType' => 'text',
            'eval'      => [
                'readonly' => true,
                'tl_class' => 'w50',
            ],
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        'status' => [
            'inputType' => 'select',
            'options'   => ['pending', 'processing', 'uploaded', 'completed', 'failed', 'error'],
            'reference' => &$GLOBALS['TL_LANG']['tl_openai_files']['status_options'],
            'eval'      => [
                'readonly' => true,
                'tl_class' => 'w50',
            ],
            'sql' => "varchar(32) NOT NULL default 'pending'",
        ],
        'file_size' => [
            'inputType' => 'text',
            'eval'      => [
                'readonly' => true,
                'tl_class' => 'w50',
            ],
            'sql' => "varchar(32) NOT NULL default ''",
        ],
    ],
];
