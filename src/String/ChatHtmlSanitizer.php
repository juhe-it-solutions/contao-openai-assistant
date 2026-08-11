<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\String;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitizes the editor-entered chat texts (header title, welcome line, first bot
 * message) so that formatting markup survives to the browser instead of being
 * shown as literal "<br>".
 *
 * Why an own sanitizer instead of Contao's: the named "contao" HTML sanitizer
 * (service contao.html_sanitizer, Twig filter sanitize_html('contao')) only
 * exists from Contao 5.7 on - it is absent in 5.3, which this bundle still
 * supports. Symfony's HtmlSanitizer is available in all three versions and gives
 * the same allowlist everywhere, so a chat greeting renders identically on 5.3,
 * 5.7 and 6.0.
 *
 * The allowlist is deliberately small: these fields end up inside a chat bubble
 * and a widget header, so text formatting, line breaks, lists and links are
 * enough. Everything scriptable (script, iframe, event handlers, javascript:
 * URLs, style attributes) is dropped.
 */
final class ChatHtmlSanitizer
{
    /**
     * Text-level elements, mapped to the attributes each of them may keep. These
     * are valid inside the widget's <h3> and <p>, which is why the header fields
     * are limited to them.
     */
    private const INLINE_ELEMENTS = [
        'a' => ['href', 'title', 'target', 'rel'],
        'abbr' => ['title'],
        'b' => [],
        'br' => [],
        'code' => [],
        'em' => [],
        'i' => [],
        'mark' => [],
        's' => [],
        'small' => [],
        'span' => [],
        'strong' => [],
        'sub' => [],
        'sup' => [],
        'u' => [],
    ];

    /**
     * Block-level elements, additionally allowed in the chat bubble, which is a
     * <div> and can hold them.
     */
    private const BLOCK_ELEMENTS = [
        'blockquote' => [],
        'div' => [],
        'h1' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'h5' => [],
        'h6' => [],
        'hr' => [],
        'li' => [],
        'ol' => [],
        'p' => [],
        'ul' => [],
    ];

    private readonly HtmlSanitizer $blockSanitizer;

    private readonly HtmlSanitizer $inlineSanitizer;

    public function __construct()
    {
        // In the inline context the block elements are unwrapped rather than
        // dropped: an editor who pastes "<p>Titel</p>" into the header field loses
        // the tag, not the words. (Symfony's default for an unknown element is to
        // drop it with its content, which is what keeps <script> out.)
        $inlineConfig = $this->createConfig(self::INLINE_ELEMENTS);

        foreach (array_keys(self::BLOCK_ELEMENTS) as $element) {
            $inlineConfig = $inlineConfig->blockElement($element);
        }

        $this->inlineSanitizer = new HtmlSanitizer($inlineConfig);
        $this->blockSanitizer = new HtmlSanitizer($this->createConfig([...self::INLINE_ELEMENTS, ...self::BLOCK_ELEMENTS]));
    }

    /**
     * Sanitizes a value that is rendered as its own block: the first bot message
     * inside the chat bubble.
     */
    public function sanitize(string $html): string
    {
        return $this->blockSanitizer->sanitize($this->decode($html));
    }

    /**
     * Sanitizes a value that is rendered inside a text element - the chat title in
     * its <h3>, the welcome line in its <p>. Block elements are unwrapped here, so
     * a pasted <div> cannot break the widget header.
     */
    public function sanitizeInline(string $html): string
    {
        return $this->inlineSanitizer->sanitize($this->decode($html));
    }

    /**
     * Decodes entities before sanitizing, mirroring what Contao does for allowHtml
     * fields on input (Input::postHtml() with $blnDecodeEntities).
     *
     * Two reasons: values saved on Contao 5.3/5.7 before this field became an HTML
     * field sit in the database in their encoded form ("&#60;br&#62;"), and would
     * otherwise stay visible as encoded text forever instead of formatting the
     * greeting. And markup smuggled in as entities is turned into real markup here,
     * so the sanitizer below judges what the browser would end up seeing rather
     * than a harmless-looking text - the decoding is never the last step.
     */
    private function decode(string $html): string
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        // A "<" that cannot open a tag is text ("Antwort < 1 Minute", "<3"). The HTML
        // parser behind the sanitizer would swallow it together with the words up to
        // the next ">", so it is encoded back before parsing - the same distinction
        // Contao makes in Input::stripTags().
        return preg_replace('/<(?![a-zA-Z!\/])/', '&lt;', $html) ?? $html;
    }

    /**
     * @param array<string, list<string>> $elements
     */
    private function createConfig(array $elements): HtmlSanitizerConfig
    {
        $config = (new HtmlSanitizerConfig())
            // tel:/mailto: are the two schemes a chat greeting realistically needs
            // besides http(s); everything else (javascript:, data:) is dropped.
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks()
            // A link opened with target="_blank" must not hand the opener over.
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
        ;

        foreach ($elements as $element => $attributes) {
            $config = $config->allowElement($element, $attributes);
        }

        // Editors style the chat texts through the module's custom CSS class, so
        // class is kept while style (and every other attribute) is not.
        return $config->allowAttribute('class', '*');
    }
}
