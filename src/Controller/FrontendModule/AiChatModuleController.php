<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) JUHE IT-solutions
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Controller\FrontendModule;

use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\ModuleModel;
use JuheItSolutions\ContaoOpenaiAssistant\Service\OpenAiAssistant;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AsFrontendModule(
    category: 'ai_tools',
    type: 'ai_chat',
    template: 'frontend_module/ai_chat_module',
    name: 'AI-Chatbot'
)]
class AiChatModuleController extends AbstractFrontendModuleController
{
    public function __construct(
        private readonly OpenAiAssistant $assistant,
        private readonly ContaoCsrfTokenManager $csrfTokenManager
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ModuleModel $model, Request $request): Response
    {
        // Generate CSRF token for the template
        $csrfToken = $this->csrfTokenManager->getDefaultTokenValue();

        $template->set('chat_endpoint', '/ai-chat/send');
        $template->set('token_endpoint', '/ai-chat/token');
        $template->set('csrf_token', $csrfToken);
        $template->set('module_id', 'ai-chat-' . $model->id);
        $template->set('module_class', 'mod_ai_chat');
        $template->set('chat_position', $model->chatPosition ?? 'right-bottom');
        $template->set('custom_css', $model->custom_css ?? '');
        $template->set('theme', $model->theme ?? 'dark');
        $template->set('base_font_size', $model->base_font_size ?? '14px');

        // Chat text configuration
        $template->set('chat_title', $model->chat_title ?? 'Chat-Header-Titel');
        $template->set('welcome_message', $model->welcome_message ?? 'Wie kann ich dir helfen?');
        $template->set('initial_bot_message', $model->initial_bot_message ?? 'Hallo! Wie kann ich dir helfen?');
        $template->set('initial_state', $model->initial_state ?? 'collapsed');
        $template->set('disclaimer_text', $model->disclaimer_text);

        // Load language file and get default disclaimer text
        $language = $GLOBALS['TL_LANGUAGE'] ?? 'en';
        \Contao\System::loadLanguageFile('tl_module', $language);
        $defaultDisclaimerText = $GLOBALS['TL_LANG']['tl_module']['disclaimer_text']['default'] ?? 'Unser Chatbot ist ein Serviceangebot unseres Unternehmens und soll die Kommunikation sowie den Informationszugang erleichtern. Die Antworten werden automatisch generiert und dienen ausschließlich allgemeinen Informations- und Unterstützungszwecken. Trotz sorgfältiger Entwicklung können Inhalte unvollständig, missverständlich oder fehlerhaft sein. Wir übernehmen daher keine Gewähr für die inhaltliche Richtigkeit oder Vollständigkeit der Antworten. Verbindliche Auskünfte, individuelle Beratung oder rechtliche Empfehlungen werden durch den Chatbot nicht erteilt. Bitte nutze die bereitgestellten Informationen als Orientierung und wende dich für wichtige Anliegen direkt an unser Team oder an eine entsprechend qualifizierte Fachperson.';
        $template->set('default_disclaimer_text', $defaultDisclaimerText);

        // Color configuration
        $template->set('dark_toggle_icon_color', $model->dark_toggle_icon_color ?? 'ff6600');
        $template->set('light_toggle_icon_color', $model->light_toggle_icon_color ?? '007bff');
        $template->set('dark_bg_primary', $model->dark_bg_primary ?? '121212');
        $template->set('dark_bg_secondary', $model->dark_bg_secondary ?? '1e1e1e');
        $template->set('dark_text_primary', $model->dark_text_primary ?? 'ffffff');
        $template->set('dark_text_secondary', $model->dark_text_secondary ?? 'b0b0b0');
        $template->set('dark_accent', $model->dark_accent ?? 'ff6600');
        $template->set('light_bg_primary', $model->light_bg_primary ?? 'ffffff');
        $template->set('light_bg_secondary', $model->light_bg_secondary ?? 'f8f9fa');
        $template->set('light_text_primary', $model->light_text_primary ?? '212529');
        $template->set('light_text_secondary', $model->light_text_secondary ?? '6c757d');
        $template->set('light_accent', $model->light_accent ?? '007bff');

        return $template->getResponse();
    }
}
