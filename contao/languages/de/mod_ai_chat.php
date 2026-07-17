<?php

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

/**
 * Frontend AI Chat module - German
 */
$GLOBALS['TL_LANG']['mod_ai_chat'] = [
    // Default content (when not overridden in module)
    'chat_title'                => 'Assistent - JUHE IT-solutions.',
    'welcome_message'           => 'Wie kann ich dir helfen?',
    'initial_bot_message'       => 'Hallo! Wie kann ich dir helfen?',

    // Region and buttons - aria-labels and titles
    'aria_label_region'         => 'AI Chat Module',
    'aria_label_disclaimer_show'=> 'Disclaimer anzeigen',
    'title_disclaimer'         => 'Disclaimer',
    'aria_label_theme'         => 'Theme wechseln',
    'title_theme'              => 'Theme wechseln',
    'aria_label_minimize'      => 'Chat minimieren',
    'placeholder_message'     => 'Frage hier eingeben...',
    'aria_label_message'      => 'Frage eingeben',
    'title_send'               => 'Frage abschicken',
    'aria_label_send'          => 'Frage abschicken',
    'disclaimer_title'        => 'Disclaimer',
    'aria_label_close_dialog' => 'Dialog schließen',

    // Strings used by JavaScript (injected as JSON)
    'js_ai_chat_open'          => 'AI Chat öffnen',
    'js_initial_message_fallback' => 'Hallo! Wie kann ich dir helfen?',
    'js_error_generic'         => 'Es ist ein Fehler aufgetreten. Bitte erneut versuchen.',
    'js_error_reload_page'     => 'Bitte lade die Seite neu und versuche es erneut.',
    'js_error_timeout'         => 'Die Antwort dauert gerade länger als erwartet. Bitte versuche es gleich noch einmal.',
    'js_link_label_download'   => 'Download',
    'js_link_label_page'       => 'Seite aufrufen',

    // Long default disclaimer (when module has no custom disclaimer)
    'disclaimer_default'       => 'Unser Chatbot ist ein Serviceangebot unseres Unternehmens und soll die Kommunikation sowie den Informationszugang erleichtern. Die Antworten werden automatisch generiert und dienen ausschließlich allgemeinen Informations- und Unterstützungszwecken. Trotz sorgfältiger Entwicklung können Inhalte unvollständig, missverständlich oder fehlerhaft sein. Wir übernehmen daher keine Gewähr für die inhaltliche Richtigkeit oder Vollständigkeit der Antworten. Verbindliche Auskünfte, individuelle Beratung oder rechtliche Empfehlungen werden durch den Chatbot nicht erteilt. Bitte nutze die bereitgestellten Informationen als Orientierung und wende dich für wichtige Anliegen direkt an unser Team oder an eine entsprechend qualifizierte Fachperson.',
];
