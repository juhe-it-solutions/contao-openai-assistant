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
 * There is no JavaScript or CSS test runner in this project, so the widget's assets are
 * checked here as text. That is a weak test in general, but a precise one for the property
 * below: whether a promise the widget makes to a visitor is present in the file at all.
 */
class ChatWidgetAssetsTest extends TestCase
{
    /**
     * A visitor who has asked their operating system to reduce motion gets that honoured
     * across the whole widget, not only for the toggle's pulse. The panel's fade and the
     * auto-scrolling message log are the two that actually move content around, and they are
     * what such a visitor most needs switched off.
     */
    public function testReducedMotionIsHonouredBeyondTheTogglePulse(): void
    {
        $css = file_get_contents(__DIR__.'/../../public/css/ai-chat.css');
        $this->assertIsString($css);

        $this->assertSame(
            1,
            preg_match('/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{(.+?)\n\}/s', $css, $matches),
            'The widget must carry a reduced-motion block.',
        );

        $block = $matches[1];

        $this->assertStringContainsString('transition-duration', $block, 'Panel and hover transitions must be neutralised.');
        $this->assertStringContainsString('animation-duration', $block, 'Animations must be neutralised.');
        $this->assertStringContainsString('scroll-behavior', $block, 'The smooth auto-scroll of the log must be neutralised.');
        $this->assertStringContainsString('.mod_ai_chat', $block, 'The outer element owns the scale/fade transition and must be covered too.');
        $this->assertStringContainsString('.ai-chat-container', $block, 'The rule must reach the panel, not just the toggle.');
    }

    /**
     * Contao's optional Basic forms stylesheet sets a generic textarea:focus background.
     * The widget must repeat both theme colours in a more specific, widget-scoped focus rule,
     * otherwise the dark input turns light while focused and its text becomes unreadable.
     */
    public function testInputFocusKeepsThemeColorsAgainstHostStylesheets(): void
    {
        $css = file_get_contents(__DIR__.'/../../public/css/ai-chat.css');
        $this->assertIsString($css);

        $this->assertSame(
            1,
            preg_match('/\.mod_ai_chat\s+\.ai-chat-input:focus\s*\{([^}]*)\}/s', $css, $matches),
            'The focused input must have a widget-scoped rule that outranks generic form CSS.',
        );

        $this->assertStringContainsString('background: var(--ai-chat-input-bg)', $matches[1]);
        $this->assertStringContainsString('color: var(--ai-chat-text-secondary)', $matches[1]);
    }

    /**
     * The rule must stay inside the widget: this stylesheet ships on the customer's public
     * website, and switching off animations the site itself defines is not ours to do.
     */
    public function testTheReducedMotionRuleIsScopedToTheWidget(): void
    {
        $css = file_get_contents(__DIR__.'/../../public/css/ai-chat.css');
        $this->assertIsString($css);

        preg_match('/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{(.+?)\n\}/s', $css, $matches);

        foreach (preg_split('/\}/', $matches[1] ?? '') as $rule) {
            $selector = trim(explode('{', $rule)[0]);

            if ('' === $selector) {
                continue;
            }

            foreach (explode(',', $selector) as $single) {
                $single = trim($single);
                $this->assertTrue(
                    str_starts_with($single, '.ai-chat') || str_starts_with($single, '.mod_ai_chat'),
                    'Every selector must be anchored on the widget.',
                );
            }
        }
    }
}
