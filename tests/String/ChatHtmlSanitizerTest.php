<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\String;

use JuheItSolutions\ContaoOpenaiAssistant\String\ChatHtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChatHtmlSanitizerTest extends TestCase
{
    /**
     * The whole point of the field: formatting an editor types in the module
     * settings has to survive to the chat bubble instead of being shown as text.
     */
    #[DataProvider('formattingProvider')]
    public function testKeepsTheFormattingEditorsUse(string $input, string $expected): void
    {
        $this->assertSame($expected, (new ChatHtmlSanitizer())->sanitize($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function formattingProvider(): iterable
    {
        yield 'line break' => ['Moin!<br>Wie kann ich helfen?', 'Moin!<br />Wie kann ich helfen?'];
        yield 'heading' => ['<h3>Moin!</h3>', '<h3>Moin!</h3>'];
        yield 'bold and italic' => ['<strong>Hallo</strong> <em>Welt</em>', '<strong>Hallo</strong> <em>Welt</em>'];
        yield 'list' => ['<ul><li>Preise</li><li>Termine</li></ul>', '<ul><li>Preise</li><li>Termine</li></ul>'];
        yield 'plain text is untouched' => ['Hallo! Wie kann ich dir helfen?', 'Hallo! Wie kann ich dir helfen?'];

        // A "<" that opens no tag is text and must not swallow the words behind it.
        yield 'less-than sign in text' => ['Antwort in < 1 Minute > garantiert', 'Antwort in &lt; 1 Minute &gt; garantiert'];
        yield 'less-than sign before a digit' => ['Preis <100 Euro', 'Preis &lt;100 Euro'];
    }

    /**
     * The value is rendered unescaped, so anything scriptable has to be gone by
     * the time it leaves this class - no matter that only backend users can enter it.
     */
    #[DataProvider('dangerousMarkupProvider')]
    public function testDropsScriptableMarkup(string $input, string $mustNotContain): void
    {
        $this->assertStringNotContainsStringIgnoringCase($mustNotContain, (new ChatHtmlSanitizer())->sanitize($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dangerousMarkupProvider(): iterable
    {
        yield 'script element' => ['Hallo<script>alert(1)</script>', '<script'];
        yield 'event handler' => ['<span onmouseover="alert(1)">Hallo</span>', 'onmouseover'];
        yield 'javascript URL' => ['<a href="javascript:alert(1)">Klick</a>', 'javascript:'];
        yield 'iframe' => ['<iframe src="https://example.com"></iframe>', '<iframe'];
        yield 'inline style' => ['<span style="position:fixed">Hallo</span>', 'style='];
        yield 'svg handler' => ['<svg onload=alert(1)>', 'onload'];
        yield 'link handler beside a valid href' => ['<a href="https://ok.tld" onmouseover="alert(1)">x</a>', 'onmouseover'];
        yield 'obfuscated scheme' => ['<a href="  jAvAsCrIpT:alert(1)">x</a>', 'script:'];
        yield 'data URL' => ['<a href="data:text/html;base64,PHN2Zz4=">x</a>', 'data:'];
        yield 'mutation XSS through noscript' => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>">', 'onerror'];
        yield 'style element' => ['<style>body{display:none}</style>', '<style'];
        yield 'form' => ['<form action="https://evil.tld"><input name="pw"></form>', '<input'];
    }

    /**
     * Contao 5.3/5.7 encoded the input of these fields before they became HTML
     * fields, so existing installations carry "&#60;br&#62;" in the database. Those
     * values have to format the greeting after the update, not show their entities.
     */
    public function testAppliesMarkupThatIsStoredInItsEncodedForm(): void
    {
        $this->assertSame(
            '<h3>Moin!<br />Wie kann ich helfen?</h3>',
            (new ChatHtmlSanitizer())->sanitize('&#60;h3&#62;Moin!&#60;br&#62;Wie kann ich helfen?&#60;/h3&#62;'),
        );
    }

    /**
     * The counterpart of the test above: decoding happens BEFORE sanitizing, so
     * markup smuggled in as entities is judged as the markup it would become in the
     * browser instead of passing through as innocent-looking text.
     */
    public function testDropsMarkupSmuggledInAsEntities(): void
    {
        $result = (new ChatHtmlSanitizer())->sanitize('&lt;img src=x onerror=alert(1)&gt;');

        $this->assertStringNotContainsString('onerror', $result);
        $this->assertStringNotContainsString('img', $result);
    }

    /**
     * Ampersands are encoded exactly once. A second round must not turn "&amp;"
     * into "&amp;amp;", otherwise repeated rendering would pile entities up.
     */
    public function testEncodesAnAmpersandOnlyOnce(): void
    {
        $sanitizer = new ChatHtmlSanitizer();

        $this->assertSame('Fragen &amp; Antworten', $sanitizer->sanitize('Fragen & Antworten'));
        $this->assertSame('Fragen &amp; Antworten', $sanitizer->sanitize($sanitizer->sanitize('Fragen & Antworten')));
    }

    /**
     * The first bot message is written into the chat bubble with innerHTML, so
     * unbalanced markup must not be able to close the bubble and escape its styling.
     */
    public function testReturnsBalancedMarkup(): void
    {
        $this->assertSame(
            '<div class="evil">x</div>',
            (new ChatHtmlSanitizer())->sanitize('</div><div class="evil">x'),
        );
    }

    /**
     * Links are the one thing editors regularly put into the greeting.
     */
    public function testKeepsLinksAndForcesASafeRel(): void
    {
        $result = (new ChatHtmlSanitizer())->sanitize('<a href="https://example.com" target="_blank">Zur Preisliste</a>');

        $this->assertStringContainsString('href="https://example.com"', $result);
        $this->assertStringContainsString('target="_blank"', $result);
        $this->assertStringContainsString('rel="noopener noreferrer"', $result);
    }

    /**
     * Relative links point at pages of the same site and must not be swallowed.
     */
    public function testKeepsRelativeLinks(): void
    {
        $this->assertStringContainsString(
            'href="/kontakt.html"',
            (new ChatHtmlSanitizer())->sanitize('<a href="/kontakt.html">Kontakt</a>'),
        );
    }

    /**
     * The chat title lives inside an <h3> and the welcome line inside a <p>: a
     * block element pasted in there is unwrapped instead of breaking the header
     * markup, while text formatting is kept.
     */
    public function testSanitizeInlineUnwrapsBlockElements(): void
    {
        $result = (new ChatHtmlSanitizer())->sanitizeInline('<div>Brand Center</div> <strong>Assistent</strong><br>Team');

        $this->assertStringNotContainsString('<div', $result);
        $this->assertStringContainsString('Brand Center', $result);
        $this->assertStringContainsString('<strong>Assistent</strong>', $result);
        $this->assertStringContainsString('<br />', $result);
    }
}
