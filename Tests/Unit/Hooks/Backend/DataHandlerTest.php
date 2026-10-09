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

namespace Tests\Unit\Hooks\Backend;

use Codeception\Test\Unit;
use Digicademy\Lod\Hooks\Backend\DataHandler;
use stdClass;
use Tests\Support\FailOnPhpErrorsTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Unit tests for the DataHandler hook of EXT:lod.
 *
 * Only paths that need no database are covered: every case passes sys_language_uid in the field
 * array, so the hook never looks up the stored record. The DataHandler instance is represented by
 * a plain object carrying the two properties the hook reads, datamap and substNEWwithIDs.
 */
final class DataHandlerTest extends Unit
{
    use FailOnPhpErrorsTrait;

    private function hook(): DataHandler
    {
        return new DataHandler($this->createStub(ConnectionPool::class));
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     * @param array<string, int>                                     $substNEWwithIDs
     */
    private function dataHandler(array $datamap = [], array $substNEWwithIDs = []): stdClass
    {
        $dataHandler = new stdClass();
        $dataHandler->datamap = $datamap;
        $dataHandler->substNEWwithIDs = $substNEWwithIDs;

        return $dataHandler;
    }

    /**
     * @param array<string, mixed> $fieldArray
     * @return array<string, mixed>
     */
    private function postProcess(string $status, string $table, int|string $id, array $fieldArray, stdClass $dataHandler): array
    {
        $this->withPhpErrorsAsExceptions(function () use ($status, $table, $id, &$fieldArray, $dataHandler): void {
            $this->hook()->processDatamap_postProcessFieldArray($status, $table, $id, $fieldArray, $dataHandler);
        });

        return $fieldArray;
    }

    public function testLeavesOtherTablesUntouched(): void
    {
        $fieldArray = ['title' => 'Mainz', 'sys_language_uid' => 1];

        $this->assertSame($fieldArray, $this->postProcess('new', 'tx_academy_domain_model_persons', 'NEW1', $fieldArray, $this->dataHandler()));
    }

    public function testEmptiesTheFieldArrayOfIrisInOtherLanguages(): void
    {
        $this->assertSame([], $this->postProcess('new', 'tx_lod_domain_model_iri', 'NEW1', ['value' => 'E1', 'sys_language_uid' => 1], $this->dataHandler()));
    }

    public function testEmptiesTheFieldArrayOfStatementsAndRepresentationsInOtherLanguages(): void
    {
        $this->assertSame([], $this->postProcess('new', 'tx_lod_domain_model_statement', 'NEW1', ['sys_language_uid' => 2], $this->dataHandler()));
        $this->assertSame([], $this->postProcess('new', 'tx_lod_domain_model_representation', 'NEW1', ['sys_language_uid' => 2], $this->dataHandler()));
    }

    public function testForcesIrisToAllLanguages(): void
    {
        $fieldArray = $this->postProcess('update', 'tx_lod_domain_model_iri', 5, ['label' => 'x', 'sys_language_uid' => 0], $this->dataHandler());

        $this->assertSame(-1, $fieldArray['sys_language_uid']);
    }

    public function testSplitsTheRecordFieldOfAnIri(): void
    {
        $fieldArray = $this->postProcess(
            'update',
            'tx_lod_domain_model_iri',
            5,
            ['record' => 'tx_academy_domain_model_persons_17', 'sys_language_uid' => 0],
            $this->dataHandler()
        );

        $this->assertSame('tx_academy_domain_model_persons', $fieldArray['record_tablename']);
        $this->assertSame('17', $fieldArray['record_uid']);
    }

    /**
     * A new IRI created inline in a parent record points to that parent once it has its uid.
     */
    public function testLinksAnInlineIriToItsNewParent(): void
    {
        $dataHandler = $this->dataHandler(
            [
                'tx_academy_domain_model_persons' => ['NEWperson' => ['iri' => 'NEWiri']],
                'tx_lod_domain_model_iri' => ['NEWiri' => ['sys_language_uid' => 0]],
            ],
            ['NEWperson' => 33]
        );

        $fieldArray = $this->postProcess('new', 'tx_lod_domain_model_iri', 'NEWiri', ['sys_language_uid' => 0], $dataHandler);

        $this->assertSame('tx_academy_domain_model_persons_33', $fieldArray['record']);
        $this->assertSame(33, $fieldArray['record_uid']);
        $this->assertSame('tx_academy_domain_model_persons', $fieldArray['record_tablename']);
    }

    public function testSplitsSubjectPredicateAndObjectOfAStatement(): void
    {
        $fieldArray = $this->postProcess(
            'update',
            'tx_lod_domain_model_statement',
            8,
            [
                'subject' => 'tx_lod_domain_model_iri_1',
                'predicate' => 'tx_lod_domain_model_iri_2',
                'object' => 'tx_lod_domain_model_literal_3',
                'sys_language_uid' => 0,
            ],
            $this->dataHandler()
        );

        $this->assertSame(['tx_lod_domain_model_iri', '1'], [$fieldArray['subject_type'], $fieldArray['subject_uid']]);
        $this->assertSame(['tx_lod_domain_model_iri', '2'], [$fieldArray['predicate_type'], $fieldArray['predicate_uid']]);
        $this->assertSame(['tx_lod_domain_model_literal', '3'], [$fieldArray['object_type'], $fieldArray['object_uid']]);
        $this->assertSame(-1, $fieldArray['sys_language_uid']);
    }

    /**
     * A new statement created inline in a new IRI gets that IRI as its subject.
     */
    public function testUsesTheNewParentIriAsSubjectOfAnInlineStatement(): void
    {
        $dataHandler = $this->dataHandler(
            [
                'tx_lod_domain_model_iri' => ['NEWiri' => ['statements' => 'NEWstatement']],
                'tx_lod_domain_model_statement' => ['NEWstatement' => ['predicate' => 'tx_lod_domain_model_iri_2']],
            ],
            ['NEWiri' => 12]
        );

        $fieldArray = $this->postProcess('new', 'tx_lod_domain_model_statement', 'NEWstatement', ['sys_language_uid' => 0], $dataHandler);

        $this->assertSame('tx_lod_domain_model_iri_12', $fieldArray['subject']);
        $this->assertSame(12, $fieldArray['subject_uid']);
        $this->assertSame('tx_lod_domain_model_iri', $fieldArray['subject_type']);
    }

    /**
     * A new record whose insert was suppressed (see above) is passed on with its NEW... id but has
     * no uid; the hook must return before it looks anything up. Reaching the database would fail
     * here, as no database is configured in this suite.
     */
    public function testDoesNothingAfterASuppressedInsert(): void
    {
        $this->withPhpErrorsAsExceptions(function (): void {
            $this->hook()->processDatamap_afterDatabaseOperations('new', 'tx_lod_domain_model_iri', 'NEW6ac8b96a8bb95903853014', [], $this->dataHandler());
        });

        $this->assertTrue(true);
    }
}
