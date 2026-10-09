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
use Digicademy\Lod\Domain\Model\Literal;
use Digicademy\Lod\ViewHelpers\LangDatatypeViewHelper;

/**
 * Unit tests for the language tag / datatype suffix written after a literal.
 *
 * RDF allows a literal to carry either a language tag or a datatype, not both, so the helper
 * returns nothing when both or neither are set.
 */
final class LangDatatypeViewHelperTest extends Unit
{
    private function render(string $language, string $datatype, string $format): string
    {
        $literal = new Literal();
        $literal->setLanguage($language);
        $literal->setDatatype($datatype);

        $viewHelper = new LangDatatypeViewHelper();
        $viewHelper->setArguments(['literal' => $literal, 'format' => $format]);

        return $viewHelper->render();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function languageProvider(): array
    {
        return [
            'rdfxml' => ['rdfxml', ' xml:lang="en"', ' rdf:datatype="http://www.w3.org/2001/XMLSchema#date"'],
            'rdfa' => ['rdfa', ' lang="en"', ' datatype="http://www.w3.org/2001/XMLSchema#date"'],
            'jsonld' => ['jsonld', '"@language": "en",', '"@type": "http://www.w3.org/2001/XMLSchema#date",'],
            'turtle' => ['turtle', '@en', '^^<http://www.w3.org/2001/XMLSchema#date>'],
            'ntriples' => ['ntriples', '@en', '^^<http://www.w3.org/2001/XMLSchema#date>'],
        ];
    }

    #[DataProvider('languageProvider')]
    public function testWritesLanguageTag(string $format, string $expectedLanguage, string $expectedDatatype): void
    {
        $this->assertSame($expectedLanguage, $this->render('en', '', $format));
    }

    #[DataProvider('languageProvider')]
    public function testWritesDatatype(string $format, string $expectedLanguage, string $expectedDatatype): void
    {
        $this->assertSame($expectedDatatype, $this->render('', 'http://www.w3.org/2001/XMLSchema#date', $format));
    }

    #[DataProvider('languageProvider')]
    public function testWritesNothingWhenLanguageAndDatatypeAreBothSet(string $format): void
    {
        $this->assertSame('', $this->render('en', 'http://www.w3.org/2001/XMLSchema#date', $format));
    }

    #[DataProvider('languageProvider')]
    public function testWritesNothingWhenNeitherIsSet(string $format): void
    {
        $this->assertSame('', $this->render('', '', $format));
    }

    public function testWritesNothingForUnknownFormat(): void
    {
        $this->assertSame('', $this->render('en', '', 'csv'));
    }
}
