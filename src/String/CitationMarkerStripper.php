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

/**
 * Removes the internal citation markers of OpenAI's file search from an answer.
 *
 * Newer models mark the passages they took from the vector store with a marker
 * built from private-use characters:
 *
 *     U+E200 "filecite" U+E202 "turn5file0" U+E202 "turn5file2" U+E201
 *
 * The Responses API is supposed to take these out of the text and report them as
 * annotations instead, but it does not do so reliably - reported from a live
 * site on 2026-09-30, where they reached the visitor as "filecite turn5file0
 * turn5file2" framed by placeholder glyphs. The markers are an internal reference
 * to a search result of that one turn and mean nothing to a visitor, so they are
 * removed.
 *
 * Instructions cannot switch them off: the format comes from the tool, not from
 * the prompt. That is why this has to happen here.
 *
 * Done on the server rather than in the chat widget so that the live reply and
 * the restored history are covered by one rule, including for custom templates.
 *
 * The older "【4:0†source】" markers are NOT handled here on purpose: the widget
 * owns those, because the same brackets are also used around URLs that must
 * survive as links (public/js/ai-chat.js, fmt()).
 */
final class CitationMarkerStripper
{
    /**
     * Private-use characters that are dropped. U+F8FF is left out: it is the
     * Apple logo on Apple devices and appears in real page content ("Apple Pay").
     * Everything else in the range has no glyph in a chat bubble and would reach
     * the visitor as a placeholder box.
     */
    private const PRIVATE_USE = '[\x{E000}-\x{F8FE}]';

    /**
     * One marker, in the three shapes it can arrive in.
     *
     * 1. Complete: start delimiter up to the end delimiter, on one line.
     * 2. Cut off at the very end of the answer (max_output_tokens). Marker
     *    characters only - an open-ended match would swallow the rest of the
     *    answer whenever an end delimiter is missing somewhere in the middle.
     * 3. Delimiters missing or incomplete: "fileciteturn5file0turn5file2". The
     *    "turn<n><kind><n>" reference is required, so the word "cite" or
     *    "filecite" on its own is never touched.
     */
    private const MARKER = '(?:'
        .'\x{E200}[^\x{E200}\x{E201}\n]*\x{E201}'
        .'|\x{E200}[\x{E202}A-Za-z0-9_\-]*\z'
        .'|'.self::PRIVATE_USE.'*\b(?:file)?cite(?:'.self::PRIVATE_USE.'*turn\d+[a-z]+\d+)+'.self::PRIVATE_USE.'*'
        .')';

    public static function strip(string $text): string
    {
        // An answer without any marker is returned byte for byte. preg_match()
        // yields false for invalid UTF-8, which takes the same exit.
        if (1 !== preg_match('/'.self::PRIVATE_USE.'|\b(?:file)?citeturn\d/u', $text)) {
            return $text;
        }

        $clean = preg_replace(
            [
                // A line that holds nothing but markers goes completely, so it
                // does not leave an empty line behind.
                '/^[ \t]*(?:'.self::MARKER.'[ \t]*)+(?:\R|\z)/mu',
                // A marker (or a run of them) at the end of a sentence takes the
                // blank in front of it along: "Text <marker>." becomes "Text.",
                // not "Text .".
                '/[ \t]+'.self::MARKER.'(?:[ \t]*'.self::MARKER.')*(?=[\s.,;:!?)\]}…]|\z)/u',
                // Anywhere else only the marker goes and the blanks stay, so two
                // words are never joined.
                '/'.self::MARKER.'/u',
                '/'.self::PRIVATE_USE.'/u',
            ],
            '',
            $text,
        );

        // null means PCRE gave up (backtrack limit). Never lose an answer over it.
        return null === $clean ? $text : rtrim($clean);
    }
}
