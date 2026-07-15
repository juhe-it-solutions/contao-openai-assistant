<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Controller\FrontendModule;

use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\ModuleModel;
use Contao\System;
use JuheItSolutions\ContaoOpenaiAssistant\Service\BundleVersionService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

#[AsFrontendModule(
    category: 'ai_tools',
    type: 'ai_chat',
    template: 'frontend_module/ai_chat_module',
    name: 'AI-Chatbot',
)]
class AiChatModuleController extends AbstractFrontendModuleController
{
    public function __construct(
        private readonly ContaoCsrfTokenManager $csrfTokenManager,
        private readonly ContaoFramework $framework,
        private readonly RequestStack $requestStack,
        private readonly BundleVersionService $bundleVersion,
        #[Autowire('%contao.web_dir%')]
        private readonly string $webDir,
    ) {
    }

    /**
     * Cache-busting value for the module's CSS/JS assets. The deployed file's
     * mtime is preferred over the Composer version: it changes on every deploy,
     * including dev branches where the version string stays the same. Without
     * this, browsers keep a stale ai-chat.js across releases (the script tag
     * had no version parameter).
     */
    private function resolveAssetVersion(): string
    {
        $file = $this->webDir.'/bundles/contaoopenaiassistant/js/ai-chat.js';
        $mtime = is_file($file) ? @filemtime($file) : false;

        if (false !== $mtime) {
            return (string) $mtime;
        }

        return $this->bundleVersion->getVersion() ?? '';
    }

    protected function getResponse(FragmentTemplate $template, ModuleModel $model, Request $request): Response
    {
        $this->framework->initialize();

        // Detect frontend language from browser (Accept-Language), fallback to page
        // language. Use main request so we see the real browser headers (fragment may be
        // rendered in a sub-request).
        $language = $this->detectFrontendLanguage($request);
        $lang = $this->loadChatTranslations($language);

        // Generate CSRF token for the template
        $csrfToken = $this->csrfTokenManager->getDefaultTokenValue();

        $template->set('chat_endpoint', '/ai-chat/send');
        $template->set('token_endpoint', '/ai-chat/token');
        $template->set('asset_version', $this->resolveAssetVersion());
        $template->set('csrf_token', $csrfToken);
        $template->set('module_id', 'ai-chat-'.$model->id);
        $template->set('module_class', 'mod_ai_chat');
        $template->set('chat_position', $model->chatPosition ?? 'right-bottom');
        $template->set('custom_css', $model->custom_css ?? '');
        $template->set('theme', $model->theme ?? 'dark');
        $template->set('base_font_size', $model->base_font_size ?? '14px');

        // Chat text: use module value or translated default
        $template->set('chat_title', $model->chat_title ?: ($lang['chat_title'] ?? 'Assistant'));
        $template->set('welcome_message', $model->welcome_message ?: ($lang['welcome_message'] ?? 'How can I help you?'));
        $template->set('initial_bot_message', $model->initial_bot_message ?: ($lang['initial_bot_message'] ?? 'Hello! How can I help you?'));
        $template->set('initial_state', $model->initial_state ?? 'collapsed');
        // Default ON: null (column not yet migrated) and '1' enable, '' disables
        $template->set('shorten_urls', (bool) ($model->shorten_urls ?? '1'));
        $template->set('disclaimer_text', $model->disclaimer_text);

        // Default disclaimer from chat language file (translated)
        $defaultDisclaimerText = $lang['disclaimer_default'] ?? '';
        $template->set('default_disclaimer_text', $defaultDisclaimerText);

        // i18n labels for template (aria-labels, titles, placeholder)
        $template->set('aria_label_region', $lang['aria_label_region'] ?? 'AI Chat');
        $template->set('aria_label_disclaimer_show', $lang['aria_label_disclaimer_show'] ?? 'Show disclaimer');
        $template->set('title_disclaimer', $lang['title_disclaimer'] ?? 'Disclaimer');
        $template->set('aria_label_theme', $lang['aria_label_theme'] ?? 'Switch theme');
        $template->set('title_theme', $lang['title_theme'] ?? 'Switch theme');
        $template->set('aria_label_minimize', $lang['aria_label_minimize'] ?? 'Minimize chat');
        $template->set('placeholder_message', $lang['placeholder_message'] ?? 'Type your message here...');
        $template->set('aria_label_message', $lang['aria_label_message'] ?? 'Enter message');
        $template->set('title_send', $lang['title_send'] ?? 'Send message');
        $template->set('aria_label_send', $lang['aria_label_send'] ?? 'Send message');
        $template->set('disclaimer_title', $lang['disclaimer_title'] ?? 'Disclaimer');
        $template->set('aria_label_close_dialog', $lang['aria_label_close_dialog'] ?? 'Close dialog');

        // JSON map for JavaScript (user-facing strings only)
        $jsI18n = [
            'ai_chat_open' => $lang['js_ai_chat_open'] ?? 'Open AI Chat',
            'initial_message_fallback' => $lang['js_initial_message_fallback'] ?? 'Hello! How can I help you?',
            'error_generic' => $lang['js_error_generic'] ?? 'An error occurred. Please try again.',
            'error_reload_page' => $lang['js_error_reload_page'] ?? 'Please reload the page and try again.',
            'link_label_download' => $lang['js_link_label_download'] ?? 'Download',
            'link_label_page' => $lang['js_link_label_page'] ?? 'Visit page',
        ];
        $template->set('i18n_json', json_encode($jsI18n, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

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

    /**
     * Detect frontend language from Accept-Language (main request), respecting
     * order/priority. Header format is e.g. "en,de;q=0.9,nl;q=0.8" — first listed
     * language has highest priority.
     */
    private function detectFrontendLanguage(Request $request): string
    {
        $acceptLanguage = $this->getAcceptLanguageFromMainRequest($request);
        if ('' !== $acceptLanguage) {
            $segments = array_map('trim', explode(',', $acceptLanguage));

            foreach ($segments as $segment) {
                $lang = strtolower((string) preg_replace('/;.*/', '', $segment));
                $primary = str_contains($lang, '-') ? substr($lang, 0, (int) strpos($lang, '-')) : $lang;
                if ('de' === $primary) {
                    return 'de';
                }
                if ('en' === $primary) {
                    return 'en';
                }
            }
        }

        return $GLOBALS['TL_LANGUAGE'] ?? 'en';
    }

    /**
     * Get Accept-Language from the main request (fragment may be rendered in a
     * sub-request without browser headers).
     */
    private function getAcceptLanguageFromMainRequest(Request $currentRequest): string
    {
        $mainRequest = $this->requestStack->getMainRequest();
        $requestToUse = $mainRequest ?? $currentRequest;

        return $requestToUse->headers->get('Accept-Language', '');
    }

    /**
     * Load mod_ai_chat language file and return the label array for the given language.
     *
     * @return array<string, string>
     */
    private function loadChatTranslations(string $language): array
    {
        $language = 'de' === $language ? 'de' : 'en';
        System::loadLanguageFile('mod_ai_chat', $language);
        $lang = $GLOBALS['TL_LANG']['mod_ai_chat'] ?? [];

        return \is_array($lang) ? $lang : [];
    }
}
