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

use Codeception\Test\Unit;
use Digicademy\Lod\ViewHelpers\RemoveEmptyLinesViewHelper;

/**
 * Unit tests for the clean-up wrapped around every serialisation template.
 */
final class RemoveEmptyLinesViewHelperTest extends Unit
{
    private function render(string $content): string
    {
        $viewHelper = new RemoveEmptyLinesViewHelper();
        $viewHelper->setRenderChildrenClosure(static fn(): string => $content);

        return $viewHelper->render();
    }

    public function testRemovesEmptyAndWhitespaceOnlyLines(): void
    {
        $this->assertSame("a\nb\nc", $this->render("a\n\n   \nb\n\t\nc"));
    }

    public function testCollapsesRunsOfLineBreaks(): void
    {
        $this->assertSame("a\nb", $this->render("a\n\n\n\nb"));
    }

    /**
     * Templates emit separators on lines of their own; they are joined to the preceding line.
     */
    public function testJoinsCommaOnlyLinesToThePreviousLine(): void
    {
        $this->assertSame("{\"a\": 1,\n\"b\": 2}", $this->render("{\"a\": 1\n,\n\"b\": 2}"));
    }

    public function testLeavesContentWithoutEmptyLinesUnchanged(): void
    {
        $content = "@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .\n<a> rdfs:label \"x\" .";
        $this->assertSame($content, $this->render($content));
    }
}
