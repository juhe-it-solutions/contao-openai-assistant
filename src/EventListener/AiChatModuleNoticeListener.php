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

        if ('de' === $language) {
            return 'Hinweis: Der Chat-Endpunkt ist öffentlich. Die Option „Geschützt" '
                .'blendet nur das Widget aus - Fragen an den Chatbot kann jeder stellen, '
                .'der die Seite erreicht. Legen Sie daher keine vertraulichen Inhalte in '
                .'den Vector Store.';
        }

        return 'Note: the chat endpoint is public. The "Protected" option only hides the '
            .'widget - anyone who can reach the site can ask the chatbot questions. Do not '
            .'put confidential content into the vector store.';
    }
}
