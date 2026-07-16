<?php

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

/**
 * Frontend AI Chat module - English
 */
$GLOBALS['TL_LANG']['mod_ai_chat'] = [
    // Default content (when not overridden in module)
    'chat_title'                => 'Assistant',
    'welcome_message'           => 'How can I help you?',
    'initial_bot_message'       => 'Hello! How can I help you?',

    // Region and buttons - aria-labels and titles
    'aria_label_region'         => 'AI Chat',
    'aria_label_disclaimer_show'=> 'Show disclaimer',
    'title_disclaimer'          => 'Disclaimer',
    'aria_label_theme'          => 'Switch theme',
    'title_theme'               => 'Switch theme',
    'aria_label_minimize'       => 'Minimize chat',
    'placeholder_message'      => 'Type your message here...',
    'aria_label_message'       => 'Enter message',
    'title_send'                => 'Send message',
    'aria_label_send'           => 'Send message',
    'disclaimer_title'         => 'Disclaimer',
    'aria_label_close_dialog'   => 'Close dialog',

    // Strings used by JavaScript (injected as JSON)
    'js_ai_chat_open'           => 'Open AI Chat',
    'js_initial_message_fallback' => 'Hello! How can I help you?',
    'js_error_generic'          => 'An error occurred. Please try again.',
    'js_error_reload_page'      => 'Please reload the page and try again.',
    'js_link_label_download'    => 'Download',
    'js_link_label_page'        => 'Visit page',

    // Long default disclaimer (when module has no custom disclaimer)
    'disclaimer_default'        => 'Our chatbot is a service offered by our company to facilitate communication and access to information. Responses are generated automatically and are for general information and support purposes only. Despite careful development, content may be incomplete, misleading or incorrect. We therefore do not guarantee the accuracy or completeness of the answers. Binding information, individual advice or legal recommendations are not provided by the chatbot. Please use the information provided as a guide and contact our team or a qualified professional directly for important matters.',
];
