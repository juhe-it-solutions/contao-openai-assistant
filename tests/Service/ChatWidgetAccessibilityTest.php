<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Source-level regression checks for the widget's accessibility contract.
 *
 * The project has no browser/JavaScript test runner. These checks cannot replace
 * keyboard, screen-reader or real-phone testing, but they make sure the state,
 * focus and viewport hooks cannot disappear unnoticed in a later asset refactor.
 */
class ChatWidgetAccessibilityTest extends TestCase
{
    public function testTemplateExposesTheDisclosureAndPendingState(): void
    {
        $template = $this->read('contao/templates/frontend_module/ai_chat_module.html.twig');

        $this->assertStringContainsString('id="ai-chat-panel-', $template);
        $this->assertStringContainsString('class="ai-chat-status sr-only" role="status"', $template);
        $this->assertStringContainsString('aria-busy="false"', $template);
        $this->assertStringContainsString('enterkeyhint="enter"', $template);
        $this->assertStringContainsString('<dialog class="ai-chat-disclaimer-dialog"', $template);
        $this->assertStringContainsString('aria-haspopup="dialog"', $template);
        $this->assertStringContainsString('tabindex="0"', $template);
        $this->assertMatchesRegularExpression(
            '/class="ai-chat-disclaimer-body"[^>]*role="region"/',
            $template,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<dialog[^>]*role="dialog"/',
            $template,
            'Native <dialog> already supplies the dialog role.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<dialog[^>]*aria-describedby/',
            $template,
            'Structured disclaimer content must not be flattened into one announcement.',
        );

        $this->assertSame(5, substr_count($template, '<svg'), 'The bundled template should still contain its five control icons.');
        $this->assertSame(5, substr_count($template, 'aria-hidden="true" focusable="false"'), 'Every control icon must remain decorative.');

        $this->assertSame(
            1,
            preg_match('/<button[^>]*class="ai-chat-minimize"(.*?)<\/button>/s', $template, $minimize),
        );
        $this->assertStringNotContainsString('aria-expanded', $minimize[1], 'Only the launcher owns the panel disclosure state.');
    }

    public function testCollapsedAndExpandedStateStaySynchronized(): void
    {
        $js = $this->read('public/js/ai-chat.js');

        $this->assertStringContainsString("toggleButton.setAttribute('aria-controls', container.id)", $js);
        $this->assertStringContainsString("toggleButton.setAttribute('aria-expanded', collapsed ? 'false' : 'true')", $js);
        $this->assertStringContainsString("container.toggleAttribute('inert', collapsed)", $js);
        $this->assertStringContainsString("wrapper.setAttribute('aria-hidden', collapsed ? 'true' : 'false')", $js);
        $this->assertStringContainsString('toggleButton.focus();', $js, 'Minimize must return focus to the launcher.');
    }

    public function testPendingAndThemeLabelsAreLocalizedAndAlwaysCleared(): void
    {
        $js = $this->read('public/js/ai-chat.js');
        $controller = $this->read('src/Controller/FrontendModule/AiChatModuleController.php');

        foreach (['assistant_typing', 'theme_switch_to_dark', 'theme_switch_to_light'] as $key) {
            $this->assertStringContainsString("'{$key}'", $controller);
            $this->assertStringContainsString($key, $this->read('contao/languages/en/mod_ai_chat.php'));
            $this->assertStringContainsString($key, $this->read('contao/languages/de/mod_ai_chat.php'));
        }

        $this->assertStringContainsString("form.setAttribute('aria-busy', 'true')", $js);
        $this->assertStringContainsString("form.setAttribute('aria-busy', 'false')", $js);
        $this->assertStringContainsString("status.textContent = ''", $js);
        $this->assertStringContainsString("indicator.setAttribute('aria-hidden', 'true')", $js);
        $this->assertStringContainsString("themeToggleBtn.setAttribute('aria-label', actionLabel)", $js);
    }

    public function testTouchAndKeyboardInputUseOneCapabilityGate(): void
    {
        $js = $this->read('public/js/ai-chat.js');

        $this->assertStringContainsString("window.matchMedia('(hover: hover) and (pointer: fine)')", $js);
        $this->assertStringContainsString("prefersKeyboardInput() ? 'send' : 'enter'", $js);
        $this->assertStringContainsString('!e.isComposing && prefersKeyboardInput()', $js);
        $this->assertStringContainsString('if (!prefersKeyboardInput()) return;', $js);
        $this->assertSame(1, substr_count($js, 'input.focus();'), 'All input autofocus must go through the shared capability gate.');
    }

    public function testMobileViewportAndSafeAreasAreHandled(): void
    {
        $js = $this->read('public/js/ai-chat.js');
        $css = $this->read('public/css/ai-chat.css');

        $this->assertStringContainsString('window.visualViewport', $js);
        $this->assertStringContainsString("window.visualViewport.addEventListener('resize', appHeight)", $js);
        $this->assertStringContainsString("window.visualViewport.addEventListener('scroll', appHeight)", $js);
        $this->assertStringContainsString('--ai-chat-viewport-height', $js);

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $this->assertStringContainsString("env(safe-area-inset-{$side}, 0px)", $css);
        }
        $this->assertStringContainsString('var(--ai-chat-viewport-height, 100dvh)', $css);
        $this->assertStringContainsString('min-height: 0 !important', $css, 'The mobile log must be allowed to shrink above the software keyboard.');
    }

    public function testFocusTargetsAndModalRemainAccessible(): void
    {
        $css = $this->read('public/css/ai-chat.css');
        $js = $this->read('public/js/ai-chat.js');

        $this->assertStringNotContainsString('outline: none', $css);

        foreach (['toggle', 'send', 'minimize', 'theme-toggle', 'disclaimer-toggle', 'disclaimer-close', 'disclaimer-body', 'input'] as $control) {
            $this->assertStringContainsString(".ai-chat-{$control}:focus-visible", $css);
        }

        $this->assertStringContainsString('clip-path: inset(50%)', $css);
        $this->assertStringContainsString('white-space: nowrap', $css);

        foreach (['disclaimer-toggle', 'theme-toggle', 'minimize', 'disclaimer-close'] as $control) {
            $this->assertSame(1, preg_match("/(?:^|\\n)\\.ai-chat-{$control}\\s*\\{([^}]*)\\}/s", $css, $rule));
            $this->assertStringContainsString('min-width: 44px', $rule[1]);
            $this->assertStringContainsString('min-height: 44px', $rule[1]);
        }

        $this->assertSame(1, preg_match('/(?:^|\\n)\\.ai-chat-disclaimer-content\\s*\\{([^}]*)\\}/s', $css, $content));
        $this->assertStringContainsString('min-height: 0', $content[1]);
        $this->assertDoesNotMatchRegularExpression('/(?<!max-)height:\\s*100%/', $content[1]);
        $this->assertStringContainsString('::backdrop', $css);
        $this->assertStringContainsString('html.ai-chat-disclaimer-open', $css);
        $this->assertStringContainsString('overscroll-behavior: contain', $css);
        $this->assertStringContainsString('@media (max-width: 767px)', $css);
        $this->assertStringNotContainsString('max-height: 60vh', $css);
        $this->assertMatchesRegularExpression(
            '/div\.ai-chat-disclaimer-dialog \.ai-chat-disclaimer-content\s*\{[^}]*flex-grow:\s*0/s',
            $css,
            'The legacy div fallback must retain its configured desktop width.',
        );

        $this->assertStringContainsString('showModal', $js);
        $this->assertStringContainsString("wrapper.querySelector('.ai-chat-disclaimer-body')", $js);
        $this->assertStringContainsString('disclaimerBody.focus()', $js);
        $this->assertStringContainsString('inertDisclaimerBackground', $js);
        $this->assertStringContainsString('restoreDisclaimerBackground', $js);
        $this->assertStringContainsString('lockDisclaimerScroll', $js);
        $this->assertStringContainsString('previousBodyTop = document.body.style.top', $js);
        $this->assertStringContainsString('document.body.style.top = previousBodyTop', $js);
        $this->assertStringContainsString("'turbo:before-cache'", $js);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(__DIR__.'/../../'.$path);
        $this->assertIsString($contents);

        return $contents;
    }
}
