<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) Torsten Schrade <Torsten.Schrade@adwmainz.de>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
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

namespace Digicademy\Lod\ViewHelpers;

use TYPO3\CMS\Extbase\Exception;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class EscapeLiteralViewHelper extends AbstractViewHelper
{
    /**
     * Initialize ViewHelper arguments
     */
    public function initializeArguments(): void
    {
        $this->registerArgument(
            'literal',
            'string',
            'Literal to escape',
            true
        );
        $this->registerArgument(
            'format',
            'string',
            'Serialisation format for which to escape the literal',
            true
        );
    }

    /**
     * Escapes RDF literals according to Turtle, JSON or N-Triples specification
     *
     * @return string
     */
    public function render(): string
    {
        $content = '';
        $literal = $this->arguments['literal'];
        $format = $this->arguments['format'];

        switch ($format) {
            case 'jsonld':
            case 'ntriples':
                // JSON string escaping is valid N-Triples except for "\/", which N-Triples does not know
                $content = json_encode($literal, JSON_UNESCAPED_SLASHES);
                break;
            case 'turtle':
                // escape backslash and double quote, and write control characters other than tab, line feed and
                // carriage return as \uXXXX (addslashes() produced "\0", which is not a Turtle escape); tab, line feed
                // and carriage return are allowed as is in a long string
                $escaped = preg_replace_callback(
                    '/[\\\\"\x00-\x08\x0B\x0C\x0E-\x1F]/',
                    static fn(array $match): string => match ($match[0]) {
                        '\\' => '\\\\',
                        '"' => '\\"',
                        default => sprintf('\\u%04X', ord($match[0])),
                    },
                    $literal
                );
                (preg_match('/[\t\n\r]/', $literal)) ?
                    $content = '"""' . $escaped . '"""' :
                    $content = '"' . $escaped . '"';
                break;
            default:
                throw new Exception('Unknown serialisation format for escaping the literal', 1577109174);
        }

        return trim($content);
    }
}
