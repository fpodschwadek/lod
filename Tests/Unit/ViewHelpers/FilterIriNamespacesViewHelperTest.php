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
use Digicademy\Lod\Domain\Model\IriNamespace;
use Digicademy\Lod\ViewHelpers\FilterIriNamespacesViewHelper;

/**
 * Unit tests for the namespace list behind the @prefix blocks and RDFa prefix attributes.
 */
final class FilterIriNamespacesViewHelperTest extends Unit
{
    /**
     * @param array<string, string> $namespaces prefix => IRI, as stored in the database
     * @param array<string, mixed>  $arguments  further ViewHelper arguments
     * @return array<string, string>
     */
    private function render(array $namespaces, array $arguments = []): array
    {
        $iriNamespaces = [];
        foreach ($namespaces as $prefix => $iri) {
            $iriNamespace = new IriNamespace();
            $iriNamespace->setPrefix($prefix);
            $iriNamespace->setIri($iri);
            $iriNamespaces[] = $iriNamespace;
        }

        $viewHelper = new FilterIriNamespacesViewHelper();
        $viewHelper->setArguments($arguments + [
            'iriNamespaces' => $iriNamespaces,
            'returnDifference' => true,
            'predefinedNamespaces' => [
                'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
                'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
            ],
        ]);

        return $viewHelper->render();
    }

    public function testReturnsOnlyNamespacesThatAreNotPredefined(): void
    {
        $this->assertSame(
            ['n4c' => 'https://nfdi4culture.de/id/'],
            $this->render([
                'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
                'n4c' => 'https://nfdi4culture.de/id/',
            ])
        );
    }

    public function testMergesPredefinedAndAdditionalNamespacesWithoutDifferenceFlag(): void
    {
        $this->assertSame(
            [
                'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
                'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
                'n4c' => 'https://nfdi4culture.de/id/',
            ],
            $this->render(['n4c' => 'https://nfdi4culture.de/id/'], ['returnDifference' => false])
        );
    }

    /**
     * A predefined IRI stored under another prefix is not repeated under that prefix.
     */
    public function testDropsDatabaseNamespacesWhoseIriIsPredefined(): void
    {
        $this->assertSame([], $this->render(['schema' => 'http://www.w3.org/2000/01/rdf-schema#']));
    }

    public function testReturnsNothingForNoDatabaseNamespaces(): void
    {
        $this->assertSame([], $this->render([]));
    }
}
