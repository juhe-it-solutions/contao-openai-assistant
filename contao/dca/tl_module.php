<?php

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

// Add the module configuration
$GLOBALS['TL_DCA']['tl_module']['palettes']['ai_chat'] = '{title_legend},name,type;{chat_legend},chatPosition,initial_state,chat_title,welcome_message,initial_bot_message,disclaimer_text,custom_css,theme,base_font_size,shorten_urls,link_target;{colors_legend},dark_toggle_icon_color,dark_bg_primary,dark_bg_secondary,dark_text_primary,dark_text_secondary,color_separator,light_toggle_icon_color,light_bg_primary,light_bg_secondary,light_text_primary,light_text_secondary;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},guests,cssID,space';

// Add the position field
$GLOBALS['TL_DCA']['tl_module']['fields']['chatPosition'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['chatPosition'],
    'exclude'   => true,
    'inputType' => 'select',
    'options'   => ['right-bottom', 'right-center', 'left-bottom', 'left-center'],
    'default'   => 'right-bottom',
    'reference' => &$GLOBALS['TL_LANG']['tl_module']['chatPosition'],
    'eval'      => [
        'tl_class' => 'w50',
    ],
    'sql'       => "varchar(32) NOT NULL default 'right-bottom'",
];

// Add the initial state field
$GLOBALS['TL_DCA']['tl_module']['fields']['initial_state'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['initial_state'],
    'exclude'   => true,
    'inputType' => 'select',
    'options'   => ['collapsed', 'expanded'],
    'default'   => 'collapsed',
    'reference' => &$GLOBALS['TL_LANG']['tl_module']['initial_state'],
    'eval'      => [
        'tl_class' => 'w50',
    ],
    'sql'       => "varchar(32) NOT NULL default 'collapsed'",
];

// Add the custom CSS field
$GLOBALS['TL_DCA']['tl_module']['fields']['custom_css'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['custom_css'],
    'exclude'   => true,
    'inputType' => 'text',
    'eval'      => [
        'tl_class' => 'w50',
    ],
    'sql'       => "varchar(255) NOT NULL default ''",
];

// Add the theme (dark/light) field
$GLOBALS['TL_DCA']['tl_module']['fields']['theme'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['theme'],
    'exclude'   => true,
    'inputType' => 'select',
    'options'   => ['light', 'dark'],
    'default'   => 'dark',
    'reference' => &$GLOBALS['TL_LANG']['tl_module']['theme'],
    'eval'      => [
        'tl_class' => 'w50',
    ],
    'sql'       => "varchar(32) NOT NULL default 'dark'",
];

// Add the base font size field
$GLOBALS['TL_DCA']['tl_module']['fields']['base_font_size'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['base_font_size'],
    'exclude'   => true,
    'inputType' => 'select',
    'options'   => ['12px', '14px', '16px', '18px', '20px'],
    'default'   => '14px',
    'reference' => &$GLOBALS['TL_LANG']['tl_module']['base_font_size'],
    'eval'      => [
        'tl_class' => 'w50',
    ],
    'sql'       => "varchar(32) NOT NULL default '14px'",
];

// Add the "shorten plain URLs" field. Default ON: plain URLs emitted by the
// model are rendered as short localized labels ("Download" / "Visit page")
// instead of the full URL. Markdown links keep their model-provided text.
// The SQL default '1' also backfills existing module rows on contao:migrate,
// so no separate data migration is needed.
$GLOBALS['TL_DCA']['tl_module']['fields']['shorten_urls'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['shorten_urls'],
    'exclude'   => true,
    'inputType' => 'checkbox',
    'default'   => true,
    'eval'      => [
        'tl_class' => 'w50 m12',
    ],
    'sql'       => "char(1) NOT NULL default '1'",
];

// Add the "link target" field. Controls where links in chat answers open:
// blank = always a new tab (legacy behaviour), external_blank = only links to
// another host, self = never. The SQL default 'blank' also backfills existing
// module rows on contao:migrate, so no separate data migration is needed and
// existing installations keep their current rendering.
$GLOBALS['TL_DCA']['tl_module']['fields']['link_target'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['link_target'],
    'exclude'   => true,
    'inputType' => 'select',
    'options'   => ['blank', 'external_blank', 'self'],
    'reference' => &$GLOBALS['TL_LANG']['tl_module']['link_target_ref'],
    'default'   => 'blank',
    'eval'      => [
        'tl_class'           => 'w50',
        'includeBlankOption' => false,
    ],
    'sql'       => "varchar(16) NOT NULL default 'blank'",
];

// Add the chat title field
//
// allowHtml on this and the two fields below lets editors use inline formatting
// ("<br>", "<strong>") in the chat texts. On Contao 5.3/5.7 the flag is what keeps
// the markup out of the input encoder ("<" would be stored as "&#60;"); on Contao 6
// input is stored raw and the flag switches on Contao's input sanitizing instead.
// Either way the value is sanitized again on output (ChatHtmlSanitizer).
$GLOBALS['TL_DCA']['tl_module']['fields']['chat_title'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['chat_title'],
    'exclude'   => true,
    'inputType' => 'text',
    'default'   => 'Chat-Header-Titel',
    'eval'      => [
        'tl_class'  => 'w50',
        'maxlength' => 255,
        'mandatory' => true,
        'allowHtml' => true,
    ],
    'sql'       => "varchar(255) NOT NULL default 'Chat-Header-Titel'",
];

// Add the welcome message field
$GLOBALS['TL_DCA']['tl_module']['fields']['welcome_message'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['welcome_message'],
    'exclude'   => true,
    'inputType' => 'text',
    'default'   => 'Willkommenszeile1',
    'eval'      => [
        'tl_class'  => 'w50',
        'maxlength' => 255,
        'allowHtml' => true,
    ],
    'sql'       => "varchar(255) NOT NULL default 'Willkommenszeile1'",
];

// Add the initial bot message field
$GLOBALS['TL_DCA']['tl_module']['fields']['initial_bot_message'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['initial_bot_message'],
    'exclude'   => true,
    'inputType' => 'text',
    'default'   => 'Hallo! Wie kann ich dir helfen?',
    'eval'      => [
        'tl_class'  => 'w50',
        'maxlength' => 255,
        'allowHtml' => true,
    ],
    'sql'       => "varchar(255) NOT NULL default 'Hallo! Wie kann ich dir helfen?'",
];

// Dark theme color fields
$GLOBALS['TL_DCA']['tl_module']['fields']['dark_toggle_icon_color'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['dark_toggle_icon_color'],
    'exclude'   => true,
    'inputType' => 'text',
    'default'   => 'ff6600',
    'eval'      => [
        'tl_class'    => 'w50',
        'maxlength'   => 6,
        'rgxp'        => 'hex',
        'colorpicker' => true,
    ],
    'sql'       => "varchar(6) NOT NULL default 'ff6600'",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['dark_bg_primary'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['dark_bg_primary'],
    'exclude'   => true,
    'inputType' => 'text',
    'default'   => '121212',
    'eval'      => [
        'tl_class'    => 'w50',
        'maxlength'   => 6,
        'rgxp'        => 'hex',
        'colorpicker' => true,
    ],
    'sql'       => "varchar(6) NOT NULL default '121212'",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['dark_bg_secondary'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['dark_bg_secondary'],
    'exclude'   => true,
    'inputType' => 'text',
    'default'   => '1e1e1e',
    'eval'      => [
        'tl_class'    => 'w50',
        'maxlength'   => 6,
        'rgxp'        => 'hex',
        'colorpicker' => true,
    ],
    'sql'       => "varchar(6) NOT NULL default '1e1e1e'",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['dark_text_primary'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['dark_text_primary'],
    'exclude'   => true,
    'inputType' => 'text',
    'default'   => 'ff6600',
    'eval'      => [
        'tl_class'    => 'w50',
        'maxlength'   => 6,
        'rgxp'        => 'hex',
        'colorpicker' => true,
    ],
    'sql'       => "varchar(6) NOT NULL default 'ff6600'",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['dark_text_secondary'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['dark_text_secondary'],
    'exclude'   => true,
    'inputType' => 'text',
    'default'   => 'fdfdf1',
    'eval'      => [
        'tl_class'    => 'w50',
        'maxlength'   => 6,
        'rgxp'        => 'hex',
        'colorpicker' => true,
    ],
    'sql'       => "varchar(6) NOT NULL default 'fdfdf1'",
];

// Add visual separator between dark and light theme colors
$GLOBALS['TL_DCA']['tl_module']['fields']['color_separator'] = [
    'label'     => '',
    'exclude'   => true,
    'inputType' => 'text',
    'eval'      => [
        'tl_class' => 'clr',
        'style'    => 'height: 1px; background-color: var(--border); margin: 10px 0; padding: 0; position: relative; top: -10px; opacity: .44;',
    ],
    'sql'       => "varchar(1) NOT NULL default ''",
];

// Light theme color fields
$GLOBALS['TL_DCA']['tl_module']['fields']['light_toggle_icon_color'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['light_toggle_icon_color'],
    'exclude'   => true,
    'inputType' => 'text',
    'default'   => '007bff',
    'eval'      => [
        'tl_class'    => 'w50',
        'maxlength'   => 6,
        'rgxp'        => 'hex',
        'colorpicker' => true,
    ],
    'sql'       => "varchar(6) NOT NULL default '007bff'",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['light_bg_primary'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['light_bg_primary'],
    'exclude'   => true,
    'inputType' => 'text',
    'default'   => 'f8f9fa',
    'eval'      => [
        'tl_class'    => 'w50',
        'maxlength'   => 6,
        'rgxp'        => 'hex',
        'colorpicker' => true,
    ],
    'sql'       => "varchar(6) NOT NULL default 'f8f9fa'",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['light_bg_secondary'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['light_bg_secondary'],
    'exclude'   => true,
    'inputType' => 'text',
    'default'   => 'e1e5e9',
    'eval'      => [
        'tl_class'    => 'w50',
        'maxlength'   => 6,
        'rgxp'        => 'hex',
        'colorpicker' => true,
    ],
    'sql'       => "varchar(6) NOT NULL default 'e1e5e9'",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['light_text_primary'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['light_text_primary'],
    'exclude'   => true,
    'inputType' => 'text',
    'default'   => '333',
    'eval'      => [
        'tl_class'    => 'w50',
        'maxlength'   => 6,
        'rgxp'        => 'hex',
        'colorpicker' => true,
    ],
    'sql'       => "varchar(6) NOT NULL default '333'",
];

$GLOBALS['TL_DCA']['tl_module']['fields']['light_text_secondary'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['light_text_secondary'],
    'exclude'   => true,
    'inputType' => 'text',
    'default'   => '666',
    'eval'      => [
        'tl_class'    => 'w50',
        'maxlength'   => 6,
        'rgxp'        => 'hex',
        'colorpicker' => true,
    ],
    'sql'       => "varchar(6) NOT NULL default '666'",
];

// Add the disclaimer text field
$GLOBALS['TL_DCA']['tl_module']['fields']['disclaimer_text'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['disclaimer_text'],
    'exclude'   => true,
    'inputType' => 'textarea',
    'default'   => 'Unser Chatbot ist ein Serviceangebot unseres Unternehmens und soll die Kommunikation sowie den Informationszugang erleichtern. Die Antworten werden automatisch generiert und dienen ausschließlich allgemeinen Informations- und Unterstützungszwecken. Trotz sorgfältiger Entwicklung können Inhalte unvollständig, missverständlich oder fehlerhaft sein. Wir übernehmen daher keine Gewähr für die inhaltliche Richtigkeit oder Vollständigkeit der Antworten. Verbindliche Auskünfte, individuelle Beratung oder rechtliche Empfehlungen werden durch den Chatbot nicht erteilt. Bitte nutze die bereitgestellten Informationen als Orientierung und wende dich für wichtige Anliegen direkt an unser Team oder an eine entsprechend qualifizierte Fachperson.',
    'eval'      => [
        'tl_class'  => 'clr',
        'rte'       => 'tinyMCE',
        'mandatory' => true,
    ],
    'sql'       => ['type' => 'text', 'notnull' => false],
];

// Explanation shown next to Contao's "Protected / member groups" option: that
// setting hides the widget, it does not gate the chat API. Rendered as a notice,
// not stored - hence input_field_callback and no "sql" key. The field is only in
// the ai_chat palette, so no other module type ever sees it.
$GLOBALS['TL_DCA']['tl_module']['fields']['ai_chat_public_endpoint_note'] = [
    'label'                => [''],
    'exclude'              => true,
    'input_field_callback' => [
        'JuheItSolutions\ContaoOpenaiAssistant\EventListener\AiChatModuleNoticeListener',
        'publicEndpointNoticeField',
    ],
];

$GLOBALS['TL_DCA']['tl_module']['palettes']['ai_chat'] = str_replace(
    '{protected_legend:hide},protected',
    '{protected_legend:hide},ai_chat_public_endpoint_note,protected',
    $GLOBALS['TL_DCA']['tl_module']['palettes']['ai_chat'],
);
