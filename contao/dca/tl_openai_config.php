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
use Contao\Message;

$autoUpdateScheduleHours = [
    '*' => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_schedule_hour_options']['every'],
];
for ($hour = 0; $hour <= 23; ++$hour) {
    $autoUpdateScheduleHours[(string) $hour] = sprintf('%02d:00', $hour);
}

$autoUpdateScheduleMinutes = [
    '*' => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_schedule_minute_options']['every'],
];
for ($minute = 0; $minute <= 59; ++$minute) {
    $autoUpdateScheduleMinutes[(string) $minute] = sprintf('%02d', $minute);
}

$autoUpdateScheduleWeekdays = [
    '*' => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_schedule_weekday_options']['every'],
    '1' => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_schedule_weekday_options']['monday'],
    '2' => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_schedule_weekday_options']['tuesday'],
    '3' => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_schedule_weekday_options']['wednesday'],
    '4' => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_schedule_weekday_options']['thursday'],
    '5' => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_schedule_weekday_options']['friday'],
    '6' => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_schedule_weekday_options']['saturday'],
    '0' => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_schedule_weekday_options']['sunday'],
];

$autoUpdateScheduleDays = [
    '*' => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_schedule_day_options']['every'],
];
for ($day = 1; $day <= 31; ++$day) {
    $autoUpdateScheduleDays[(string) $day] = (string) $day;
}

$GLOBALS['TL_DCA']['tl_openai_config'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        'ctable'           => ['tl_openai_files', 'tl_openai_prompts'],
        'enableVersioning' => true,
        'onload_callback'  => [
            function ($dc) {
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
            ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiConfigListener', 'onLoadCallback'],
        ],
        'ondelete_callback' => [
            ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiConfigListener', 'deleteVectorStore'],
        ],
        'sql' => [
            'keys' => [
                'id' => 'primary',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode'        => 1,
            'fields'      => ['tstamp'],
            'flag'        => 1,
            'panelLayout' => 'filter;search,limit',
        ],
        'label' => [
            'fields'         => ['title', 'api_key'],
            'format'         => '%s <span style="color:#999;">[%s]</span>',
            'label_callback' => ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiConfigListener', 'addIcon'],
        ],
        'header_callback'   => ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiConfigListener', 'addHeader'],
        'global_operations' => [
            'all' => [
                'href'       => 'act=select',
                'class'      => 'header_edit_all',
                'attributes' => 'onclick="Backend.getScrollOffset()"',
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
                'attributes' => 'onclick="if(!confirm(\'' . ($GLOBALS['TL_LANG']['MSC']['deleteConfirm'] ?? '') . ' \'))return false;Backend.getScrollOffset()"',
            ],
            'files' => [
                'href'  => 'table=tl_openai_files',
                'icon'  => 'modules.svg',
                'label' => &$GLOBALS['TL_LANG']['tl_openai_config']['files'],
            ],
            'prompts' => [
                'href'  => 'table=tl_openai_prompts',
                'icon'  => 'member.svg',
                'label' => &$GLOBALS['TL_LANG']['tl_openai_config']['prompts'],
            ],
        ],
    ],
    'palettes' => [
        'default' => '{title_legend},title,api_key;{config_legend},vector_store_id'
            . ';{premium_legend},premium_license_intro,premium_license_key'
            . ';{auto_update_legend},auto_update_enabled,auto_update_schedule_hour,auto_update_schedule_minute,auto_update_schedule_weekday,auto_update_schedule_day,auto_update_raw_mode,auto_update_model,auto_update_max_content,auto_update_site_root,auto_update_prompt_template',
    ],
    'fields' => [
        'id' => [
            'sql' => [
                'type'          => 'integer',
                'unsigned'      => true,
                'autoincrement' => true,
            ],
        ],
        'tstamp' => [
            'sql' => [
                'type'     => 'integer',
                'unsigned' => true,
                'default'  => 0,
            ],
        ],
        'title' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_config']['title'],
            'exclude'   => true,
            'search'    => true,
            'inputType' => 'text',
            'eval'      => [
                'mandatory' => true,
                'maxlength' => 255,
                'tl_class'  => 'w50',
            ],
            'sql' => [
                'type'    => 'string',
                'length'  => 255,
                'default' => '',
            ],
        ],
        'api_key' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_config']['api_key'],
            'exclude'   => true,
            'inputType' => 'password',
            'xlabel'    => [
                ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiConfigListener', 'apiKeyWizard'],
            ],
            'eval'      => [
                'mandatory' => true,
                'maxlength' => 255,
                'tl_class'  => 'w50',
            ],
            'save_callback' => [
                ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiConfigListener', 'processApiKeyForStorage'],
            ],
            'sql' => [
                'type'    => 'string',
                'length'  => 1024,
                'default' => '',
            ],
        ],
        'vector_store_id' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_config']['vector_store_id'],
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

        // --- Premium license ---
        'premium_license_intro' => [
            'label'     => [''],
            'exclude'   => true,
            'input_field_callback' => [
                'JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiConfigListener',
                'premiumLicenseIntroField',
            ],
        ],
        'premium_license_key' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_config']['premium_license_key'],
            'exclude'   => true,
            'inputType' => 'text',
            'xlabel'    => [
                ['JuheItSolutions\ContaoOpenaiAssistant\EventListener\OpenAiConfigListener', 'licenseKeyWizard'],
            ],
            'eval'      => [
                'maxlength'   => 255,
                'tl_class'    => 'w50',
                'placeholder' => 'JUHE-AI-...',
                'allowHtml'   => true,
            ],
            'sql' => [
                'type'    => 'string',
                'length'  => 255,
                'default' => '',
            ],
        ],

        // --- Auto-update settings ---
        'auto_update_enabled' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_enabled'],
            'exclude'   => true,
            'inputType' => 'checkbox',
            // submitOnChange: ticking the box auto-saves and reloads, so the model
            // options_callback (which requires a persisted auto_update_enabled) runs
            // immediately and populates "Generierungsmodell" without a second manual save.
            'eval'      => ['submitOnChange' => true, 'tl_class' => 'clr m12 auto-update-field auto-update-toggle auto-update-license-field'],
            'sql'       => ['type' => 'boolean', 'default' => false],
        ],
        'auto_update_schedule_hour' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_schedule_hour'],
            'exclude'   => true,
            'inputType' => 'select',
            'options'   => $autoUpdateScheduleHours,
            'default'   => '2',
            'eval'      => ['tl_class' => 'w50 auto-update-field auto-update-license-field', 'chosen' => true, 'isAssociative' => true],
            'sql'       => ['type' => 'string', 'length' => 3, 'default' => '2'],
        ],
        'auto_update_schedule_minute' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_schedule_minute'],
            'exclude'   => true,
            'inputType' => 'select',
            'options'   => $autoUpdateScheduleMinutes,
            'default'   => '0',
            'eval'      => ['tl_class' => 'w50 auto-update-field auto-update-license-field', 'chosen' => true, 'isAssociative' => true],
            'sql'       => ['type' => 'string', 'length' => 3, 'default' => '0'],
        ],
        'auto_update_schedule_weekday' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_schedule_weekday'],
            'exclude'   => true,
            'inputType' => 'select',
            'options'   => $autoUpdateScheduleWeekdays,
            'eval'      => ['tl_class' => 'w50 auto-update-field auto-update-license-field', 'chosen' => true],
            'sql'       => ['type' => 'string', 'length' => 2, 'default' => '*'],
        ],
        'auto_update_schedule_day' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_schedule_day'],
            'exclude'   => true,
            'inputType' => 'select',
            'options'   => $autoUpdateScheduleDays,
            'eval'      => ['tl_class' => 'w50 auto-update-field auto-update-license-field', 'chosen' => true],
            'sql'       => ['type' => 'string', 'length' => 2, 'default' => '*'],
        ],
        'auto_update_schedule' => [
            'sql' => ['type' => 'string', 'length' => 20, 'default' => '0 2 * * *'],
        ],
        'auto_update_raw_mode' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_raw_mode'],
            'exclude'   => true,
            'inputType' => 'checkbox',
            'eval'      => ['tl_class' => 'clr m12 auto-update-field auto-update-license-field'],
            'sql'       => ['type' => 'boolean', 'default' => false],
        ],
        'auto_update_model' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_model'],
            'exclude'   => true,
            'inputType' => 'select',
            'eval'      => ['tl_class' => 'w50 auto-update-field auto-update-license-field', 'chosen' => true],
            'sql'       => ['type' => 'string', 'length' => 100, 'default' => ''],
        ],
        'auto_update_max_content' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_max_content'],
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['rgxp' => 'digit', 'tl_class' => 'w50 auto-update-field auto-update-license-field'],
            'sql'       => ['type' => 'integer', 'unsigned' => true, 'default' => 100000],
        ],
        'auto_update_site_root' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_site_root'],
            'exclude'   => true,
            'inputType' => 'pageTree',
            'foreignKey' => 'tl_page.title',
            'eval'      => [
                'multiple'  => true,
                'fieldType' => 'checkbox',
                'tl_class'  => 'clr auto-update-field auto-update-license-field oaa-picker-field',
            ],
            'sql'       => ['type' => 'blob', 'notnull' => false],
            'relation'  => ['type' => 'hasMany', 'table' => 'tl_page', 'load' => 'lazy'],
        ],
        'auto_update_prompt_template' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_openai_config']['auto_update_prompt_template'],
            'exclude'   => true,
            'inputType' => 'textarea',
            'eval'      => ['rte' => false, 'rows' => 8, 'tl_class' => 'clr auto-update-field auto-update-license-field oaa-prompt-field'],
            'sql'       => ['type' => 'text', 'notnull' => false],
        ],

        // Read-only status / internal fields — NOT in any palette; written by the
        // cron/license services and displayed read-only in the backend module.
        'auto_update_file_id' => [
            'sql' => ['type' => 'string', 'length' => 255, 'default' => ''],
        ],
        'auto_update_last_run' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'auto_update_last_status' => [
            'sql' => ['type' => 'string', 'length' => 20, 'default' => ''],
        ],
        'auto_update_last_message' => [
            'sql' => ['type' => 'text', 'notnull' => false],
        ],
        'premium_license_status' => [
            'sql' => ['type' => 'string', 'length' => 20, 'default' => ''],
        ],
        'premium_license_valid_until' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        'premium_license_checked_at' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
        // Subscription plan + page limit from the last validation; drive crawl-page
        // selection enforcement. max_pages 0 = unlimited (enterprise) or not yet known.
        'premium_license_plan' => [
            'sql' => ['type' => 'string', 'length' => 20, 'default' => ''],
        ],
        'premium_license_max_pages' => [
            'sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
        ],
    ],
];
