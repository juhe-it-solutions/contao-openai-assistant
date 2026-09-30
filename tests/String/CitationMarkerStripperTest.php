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

use JuheItSolutions\ContaoOpenaiAssistant\String\CitationMarkerStripper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CitationMarkerStripperTest extends TestCase
{
    private const START = "\u{E200}";

    private const END = "\u{E201}";

    private const SEP = "\u{E202}";

    /**
     * The markers are internal references to one turn's search results. None of
     * their shapes may reach the visitor.
     */
    #[DataProvider('markerProvider')]
    public function testRemovesCitationMarkers(string $input, string $expected): void
    {
        $this->assertSame($expected, CitationMarkerStripper::strip($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function markerProvider(): iterable
    {
        $marker = static fn (string $kind, string ...$refs): string => self::START.$kind.self::SEP.implode(self::SEP, $refs).self::END;

        // The shape of the customer report: a marker with several references
        // after each paragraph.
        yield 'reported shape' => [
            'our internal organisation. '.$marker('filecite', 'turn5file0', 'turn5file2')
                ."\n\nin external communication. ".$marker('filecite', 'turn5file1', 'turn5file4', 'turn5file6'),
            "our internal organisation.\n\nin external communication.",
        ];

        yield 'before punctuation' => ['Siehe Leitfaden '.$marker('filecite', 'turn0file0').'.', 'Siehe Leitfaden.'];
        yield 'between two words' => ['Größe '.$marker('filecite', 'turn0file3').' und Farbe', 'Größe und Farbe'];
        yield 'two markers in a row' => ['X '.$marker('filecite', 'turn1file0').$marker('filecite', 'turn1file1').' Y', 'X Y'];
        yield 'two markers with a blank between' => ['X '.$marker('filecite', 'turn1file0').' '.$marker('filecite', 'turn1file1').' Y', 'X Y'];
        yield 'web search marker' => ['Text '.$marker('cite', 'turn0search0').' weiter', 'Text weiter'];

        // Words must never be joined by the removal.
        yield 'no blank after the marker' => ['Wort '.$marker('filecite', 'turn0file0').'Wort', 'Wort Wort'];
        yield 'start of a line' => [$marker('filecite', 'turn0file0').' Text', ' Text'];
        yield 'list item' => ['- '.$marker('filecite', 'turn0file0').' Punkt', '- Punkt'];

        // A marker on a line of its own must not leave an empty line behind.
        yield 'own line between two lines' => ["Eins.\n".$marker('filecite', 'turn0file0')."\nZwei.", "Eins.\nZwei."];
        yield 'own line at the end' => ["Eins.\n\n".$marker('filecite', 'turn0file0'), 'Eins.'];

        // Damaged markers.
        yield 'delimiters lost' => ['Text. fileciteturn5file0turn5file2', 'Text.'];
        yield 'delimiters lost, web search' => ['Text citeturn0search0 weiter', 'Text weiter'];
        yield 'cut off at the end of the answer' => ['Text '.self::START.'filecite'.self::SEP.'turn5fi', 'Text'];
        yield 'stray delimiter' => ['Text'.self::SEP.' weiter', 'Text weiter'];

        // The end delimiter is missing in the middle: only the marker goes, the
        // sentences behind it stay.
        yield 'end delimiter missing mid-text' => [
            'A '.self::START.'filecite'.self::SEP.'turn5file0 B. Zweiter Satz. '.$marker('filecite', 'turn5file1').' C',
            'A B. Zweiter Satz. C',
        ];
    }

    /**
     * An answer without a marker has to come back byte for byte - including
     * everything the chat widget still needs to see.
     */
    #[DataProvider('untouchedProvider')]
    public function testLeavesEverythingElseAlone(string $input): void
    {
        $this->assertSame($input, CitationMarkerStripper::strip($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function untouchedProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'plain answer' => ['Die Öffnungszeiten sind Mo-Fr 8-17 Uhr.'];
        yield 'the words cite and filecite' => ['Please cite turn 5 of file 0. The filecite tool is internal.'];
        yield 'indentation and trailing blanks' => ["Schritte:\n    1. Übung\n    2. Maß\n\n"];
        yield 'markdown and links' => ["**Fett** und [Link](https://example.com/a.pdf)\n- Punkt"];
        // The widget owns the older marker format and the URL unwrapping.
        yield 'legacy marker' => ['Quelle【4:0†source】.'];
        yield 'URL in CJK brackets' => ['Download: 【https://example.com/a.pdf】'];
        yield 'emoji and CJK text' => ['Gerne 😊 日本語のテキスト'];
        yield 'Apple logo' => ["\u{F8FF} Pay wird akzeptiert."];
        yield 'invalid UTF-8' => ["kaputt \xC3\x28 ".self::START.'filecite'.self::END];
    }
}
