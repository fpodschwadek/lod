<?php

declare(strict_types=1);

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2026 Frodo Podschwadek <frodo.podschwadek@adwmainz.de>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

namespace Tests\Unit\ViewHelpers;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use Digicademy\Lod\ViewHelpers\EscapeLiteralViewHelper;
use TYPO3\CMS\Extbase\Exception;

/**
 * Unit tests for the literal escaping used by every RDF serialisation template.
 *
 * The expected values follow the string grammars of the target formats: N-Triples and Turtle
 * allow the escapes \t \b \n \r \f \" \' \\ and \uXXXX, JSON-LD allows JSON's.
 */
final class EscapeLiteralViewHelperTest extends Unit
{
    private function escape(string $literal, string $format): string
    {
        $viewHelper = new EscapeLiteralViewHelper();
        $viewHelper->setArguments(['literal' => $literal, 'format' => $format]);

        return $viewHelper->render();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function literalProvider(): array
    {
        return [
            'turtle: plain' => ['Mainz', 'turtle', '"Mainz"'],
            'turtle: double quote' => ['say "hi"', 'turtle', '"say \"hi\""'],
            'turtle: backslash' => ['a\b', 'turtle', '"a\\\\b"'],
            'turtle: newline switches to a long string' => ["line 1\nline 2", 'turtle', "\"\"\"line 1\nline 2\"\"\""],
            'turtle: tab switches to a long string' => ["a\tb", 'turtle', "\"\"\"a\tb\"\"\""],
            'turtle: unicode is kept' => ['Kölner Straße', 'turtle', '"Kölner Straße"'],
            'turtle: slash is kept' => ['https://example.org/a', 'turtle', '"https://example.org/a"'],
            'turtle: surrounding whitespace stays inside the quotes' => [' padded ', 'turtle', '" padded "'],
            'ntriples: plain' => ['Mainz', 'ntriples', '"Mainz"'],
            'ntriples: double quote' => ['say "hi"', 'ntriples', '"say \"hi\""'],
            'ntriples: newline is escaped' => ["line 1\nline 2", 'ntriples', '"line 1\nline 2"'],
            'jsonld: plain' => ['Mainz', 'jsonld', '"Mainz"'],
            'jsonld: double quote' => ['say "hi"', 'jsonld', '"say \"hi\""'],
            'jsonld: newline is escaped' => ["line 1\nline 2", 'jsonld', '"line 1\nline 2"'],
        ];
    }

    #[DataProvider('literalProvider')]
    public function testEscapesLiteralForFormat(string $literal, string $format, string $expected): void
    {
        $this->assertSame($expected, $this->escape($literal, $format));
    }

    /**
     * N-Triples has no \/ escape, so a slash must be written as is.
     */
    public function testKeepsSlashesInNTriples(): void
    {
        $this->assertSame('"https://example.org/a"', $this->escape('https://example.org/a', 'ntriples'));
    }

    /**
     * Turtle has no \0 escape; a NUL character has to be written as \u0000.
     */
    public function testEscapesNulAsUnicodeEscapeInTurtle(): void
    {
        $this->assertSame('"a\u0000b"', $this->escape("a\0b", 'turtle'));
    }

    public function testThrowsForUnknownFormat(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(1577109174);

        $this->escape('Mainz', 'csv');
    }
}
