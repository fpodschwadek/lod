<?php

/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) Torsten Schrade <Torsten.Schrade@adwmainz.de>, Academy of Sciences and Literature | Mainz
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

namespace Digicademy\Lod\Domain\Repository;

use Digicademy\Lod\Domain\Model\IriNamespace;
use TYPO3\CMS\Extbase\Exception;
use TYPO3\CMS\Extbase\Persistence\{
    QueryInterface,
    QueryResultInterface,
    Repository
};

class StatementRepository extends Repository
{
    // Map entity classes to table names.
    protected const ENTITY_CLASS_TABLES = [
        'Digicademy\Lod\Domain\Model\Bnode' => 'tx_lod_domain_model_bnode_',
        'Digicademy\Lod\Domain\Model\Iri' => 'tx_lod_domain_model_iri_',
    ];

    protected $defaultOrderings = [
        'subject' => QueryInterface::ORDER_ASCENDING,
    ];

    /**
     * @param string $position
     * @param object $resource
     * @param IriNamespace $graph
     *
     * @return QueryResultInterface
     */
    public function findByPosition(
        string $position,
        object $resource,
        ?IriNamespace $graph = null
    ): QueryResultInterface {
        $query = $this->createQuery();
        $query->getQuerySettings()
            ->setRespectStoragePage(false);

        $constraints = [];

        // Check for valid position value.
        if (!in_array($position, ['subject', 'predicate', 'object'])) {
            throw new Exception('Position string can only be subject, predicate or object', 1572638693);
        }

        // Check for valid resource class.
        $resourceClass = get_class($resource);
        if (
            !in_array(
                $resourceClass,
                array_keys(self::ENTITY_CLASS_TABLES)
            )
        ) {
            throw new Exception('Unknown entity class', 1572638672);
        }

        // Find statements with specific IRIs or Bnodes in subject, predicate or
        // object position.
        $resourceUid = $resource->getUid();
        $constraints[] = $query->equals(
            $position,
            self::ENTITY_CLASS_TABLES[$resourceClass] . $resourceUid,
        );

        // Possibly set graph name.
        if ($resourceClass == 'Digicademy\Lod\Domain\Model\Iri' && isset($graph)) {
            $constraints[] = $query->equals('name', $graph);
        }

        $query->matching(
            $query->logicalAnd(...$constraints)
        );

        $result = $query->execute();

        return $result;
    }
}
