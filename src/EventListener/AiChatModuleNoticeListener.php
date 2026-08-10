<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\EventListener;

use Contao\DataContainer;
use Contao\System;

/**
 * Renders the notice next to Contao's "Protected / member groups" option on the
 * chat module.
 *
 * Contao's module protection controls rendering: it decides who sees the widget.
 * The chat endpoints are deliberately anonymous - that is what a public chatbot
 * is - so protecting the module does not stop anyone from querying the vector
 * store directly. An operator has no way of knowing that from the field label,
 * which is exactly how confidential content ends up in a public knowledge base.
 */
class AiChatModuleNoticeListener
{
    public function publicEndpointNoticeField(DataContainer|null $dc = null): string
    {
        return '<div class="widget clr"><div class="oaa-info-card oaa-info-card--notice">'
            .'<p class="tl_info">'
            .htmlspecialchars($this->notice(), ENT_QUOTES)
            .'</p></div></div>';
    }

    private function notice(): string
    {
        $language = strtolower(substr((string) (System::getContainer()->get('request_stack')->getCurrentRequest()?->getLocale() ?? 'en'), 0, 2));

        // The option is named after Contao's own label for tl_module.protected -
        // "Modul schützen" / "Protect module" (core tl_module.xlf, trans-unit
        // tl_module.protected.0). Naming it anything else sends the reader looking for
        // a checkbox that is not on the screen: the notice sits directly above that
        // very checkbox, so the two have to match word for word.
        // Written for an editor, not a developer: no "endpoint", no "widget". The order
        // is deliberate - what the option does, what it does NOT do, and only then the
        // instruction, which is the one line that has to survive skim-reading.
        // "Vector Store" stays: it is the term the backend itself uses (Automatische
        // Vector-Store-Aktualisierung, OpenAI Vector-Store-Dateien), and it names a
        // concrete place to act on rather than an abstraction.
        if ('de' === $language) {
            return 'Achtung: „Modul schützen" versteckt nur das Chat-Fenster - es hält die '
                .'Inhalte nicht geheim. Der Chatbot kann auch ohne sichtbares Fenster '
                .'befragt werden. Legen Sie daher keine vertraulichen Inhalte in den '
                .'Vector Store.';
        }

        return 'Careful: "Protect module" only hides the chat window - it does not keep the '
            .'content secret. The chatbot can still be asked questions without a visible '
            .'window. So do not put confidential content into the vector store.';
    }
}
