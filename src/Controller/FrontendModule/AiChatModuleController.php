<?php

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Controller\FrontendModule;

use JuheItSolutions\ContaoOpenaiAssistant\Service\OpenAiAssistant;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\ModuleModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;

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
    ) {}

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