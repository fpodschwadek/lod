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

namespace Tests\Unit\Generator;

use Codeception\Attribute\DataProvider;
use Codeception\Test\Unit;
use Digicademy\Lod\Generator\ForeignRecordTablenameUidIdentifierGenerator;
use Digicademy\Lod\Generator\UidIdentifierGenerator;
use Digicademy\Lod\Generator\UuidIdentifierGenerator;
use Digicademy\Lod\Service\IdentifierGeneratorService;
use Tests\Support\FailOnPhpErrorsTrait;

/**
 * Unit tests for the generators that fill tx_lod_domain_model_iri.value and
 * tx_lod_domain_model_bnode.value, and for the service that instantiates them.
 *
 * The prefix is chosen by the record's type: 1 entity, 2 property, anything else (bnodes have no
 * type) bnode.
 */
final class IdentifierGeneratorTest extends Unit
{
    use FailOnPhpErrorsTrait;

    private const PREFIXES = ['entityPrefix' => 'E', 'propertyPrefix' => 'P', 'bnodePrefix' => 'b'];

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function prefixProvider(): array
    {
        return [
            'entity' => [['uid' => 42, 'type' => '1'], 'E42'],
            'property' => [['uid' => 42, 'type' => '2'], 'P42'],
            'bnode (no type)' => [['uid' => 42], 'b42'],
            'unknown type falls back to bnode' => [['uid' => 42, 'type' => '9'], 'b42'],
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    #[DataProvider('prefixProvider')]
    public function testUidGeneratorPrefixesByType(array $record, string $expected): void
    {
        $this->assertSame($expected, (new UidIdentifierGenerator(self::PREFIXES, $record))->generate());
    }

    public function testUidGeneratorWithoutConfiguredPrefixReturnsTheUid(): void
    {
        $this->assertSame('42', (new UidIdentifierGenerator([], ['uid' => 42, 'type' => '1']))->generate());
    }

    public function testUuidGeneratorCreatesVersion4Uuids(): void
    {
        $identifier = (new UuidIdentifierGenerator([], ['type' => '1']))->generate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $identifier);
    }

    public function testUuidGeneratorPrefixesByType(): void
    {
        $this->assertStringStartsWith('E', (new UuidIdentifierGenerator(self::PREFIXES, ['type' => '1']))->generate());
    }

    /**
     * XML names must not start with a digit, so xmlConformance retries until the UUID starts with a letter.
     */
    public function testUuidGeneratorWithXmlConformanceStartsWithALetter(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $identifier = (new UuidIdentifierGenerator(['xmlConformance' => '1'], ['type' => '1']))->generate();
            $this->assertMatchesRegularExpression('/^[a-f]/', $identifier);
        }
    }

    public function testForeignRecordGeneratorUsesTheRecordUid(): void
    {
        $generator = new ForeignRecordTablenameUidIdentifierGenerator(
            ['includeTablename' => '0'] + self::PREFIXES,
            ['type' => '1', 'record' => 'tx_academy_domain_model_persons_17']
        );

        $this->assertSame('E17', $generator->generate());
    }

    public function testForeignRecordGeneratorCanIncludeTheTablename(): void
    {
        $generator = new ForeignRecordTablenameUidIdentifierGenerator(
            ['includeTablename' => '1'],
            ['type' => '1', 'record' => 'tx_academy_domain_model_persons_17']
        );

        $this->assertSame('tx_academy_domain_model_persons_17', $generator->generate());
    }

    public function testForeignRecordGeneratorWithoutForeignRecordReturnsOnlyThePrefix(): void
    {
        $generator = new ForeignRecordTablenameUidIdentifierGenerator(['includeTablename' => '0'] + self::PREFIXES, ['type' => '1', 'record' => '']);

        $this->assertSame('E', $generator->generate());
    }

    /**
     * includeTablename is optional configuration; leaving it out must not raise a warning.
     */
    public function testForeignRecordGeneratorWithoutIncludeTablenameSettingUsesTheUid(): void
    {
        $generator = new ForeignRecordTablenameUidIdentifierGenerator([], ['type' => '1', 'record' => 'tx_academy_domain_model_persons_17']);

        $this->assertSame('17', $this->withPhpErrorsAsExceptions(static fn(): string => $generator->generate()));
    }

    /**
     * A record without the record field (e.g. a bnode) must not raise a warning either.
     */
    public function testForeignRecordGeneratorWithoutRecordFieldReturnsOnlyThePrefix(): void
    {
        $generator = new ForeignRecordTablenameUidIdentifierGenerator(['includeTablename' => '0'], []);

        $this->assertSame('', $this->withPhpErrorsAsExceptions(static fn(): string => $generator->generate()));
    }

    public function testServicePassesConfigurationAndRecordToTheGenerator(): void
    {
        $identifier = (new IdentifierGeneratorService())->generateIdentifier(
            UidIdentifierGenerator::class,
            ['entityPrefix' => 'E'],
            ['uid' => 7, 'type' => '1']
        );

        $this->assertSame('E7', $identifier);
    }
}
