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

namespace Digicademy\Lod\Service;

use Doctrine\DBAL\ArrayParameterType;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\{
    Connection,
    ConnectionPool
};
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

class TableTrackingService
{
    /**
     * Service constructor
     */
    public function __construct(
        protected string $action,
        protected string $table,
        protected array $record,
        protected array $configuration
    ) {}

    /**
     * Creates IRI records for records in tracked tables
     */
    public function track(): void
    {
        $existingIRIs = $this->iriExists();
        $tableAndUid = $this->table . '_' . $this->record['uid'];
        $dataMap = [];
        $cmdMap = [];

        // create contentObjectRenderer for TypoScript functionality during record creation
        $contentObjectRenderer = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $contentObjectRenderer->start($this->record, $this->table);

        // first of all check if IRI exists for the current record
        if ($existingIRIs) {
            // action update and hideUnhide = 1 is set
            if ($this->action == 'update' && array_key_exists('hidden', $this->record) && ($this->configuration['hideUnhide'] ?? '') == '1') {
                foreach ($existingIRIs as $iri) {
                    if ($this->record['hidden'] != $iri['hidden']) {
                        $dataMap = [
                            'tx_lod_domain_model_iri' => [
                                $iri['uid'] => ['hidden' => $this->record['hidden']],
                            ],
                        ];
                    }
                }
            }
            // action delete and deleteUndelete = 1 is set
            if ($this->action == 'delete' && ($this->configuration['deleteUndelete'] ?? '') == '1') {
                foreach ($existingIRIs as $iri) {
                    $cmdMap = [
                        'tx_lod_domain_model_iri' => [
                            $iri['uid'] => ['delete' => 1],
                        ],
                    ];
                }
            }
            // action undelete and deleteUndelete = 1 is set
            if ($this->action == 'undelete' && ($this->configuration['deleteUndelete'] ?? '') == '1') {
                foreach ($existingIRIs as $iri) {
                    $cmdMap = [
                        'tx_lod_domain_model_iri' => [
                            $iri['uid'] => ['undelete' => 1],
                        ],
                    ];
                }
            }
        } else {
            // this case covers all three conditions - new, copy, update - if no iri exists for the current record
            // in case of 'new' tracked record an iri is created
            // in case of an 'updated' tracked record that has no iri (this is why we are in else) also leads to iri creation
            // a copied tracked record is the same as a new record - no iri will yet exists with a 'tablename_uid' in the iri record field
            if ($this->action == 'new' || $this->action == 'update') {
                $iriUid = 'NEW' . uniqid('');
                $iriConfiguration = $this->configuration['iri.'] ?? [];

                if ($iriConfiguration['pid'] ?? '') {
                    $pid = (int)$contentObjectRenderer->stdWrap($iriConfiguration['pid'], $iriConfiguration['pid.'] ?? []);
                } else {
                    $pid = (int)$this->record['pid'];
                }

                $type = (int)$this->stdWrapOptional($contentObjectRenderer, $iriConfiguration, 'type', 1);

                $namespace = (int)$this->stdWrapOptional($contentObjectRenderer, $iriConfiguration, 'namespace', 0);

                $label = $this->stdWrapOptional($contentObjectRenderer, $iriConfiguration, 'label', '');

                $label_language = (int)$this->stdWrapOptional($contentObjectRenderer, $iriConfiguration, 'label_language', 0);

                $comment = $this->stdWrapOptional($contentObjectRenderer, $iriConfiguration, 'comment', '');

                $comment_language = (int)$this->stdWrapOptional($contentObjectRenderer, $iriConfiguration, 'comment_language', 0);

                $dataMap = [
                    'tx_lod_domain_model_iri' => [
                        $iriUid => [
                            'pid' => $pid,
                            'type' => $type,
                            'hidden' => $this->record['hidden'] ?? 0,
                            'namespace' => $namespace,
                            'label' => $label,
                            'label_language' => $label_language,
                            'comment' => $comment,
                            'comment_language' => $comment_language,
                            'record' => $tableAndUid,
                            'record_uid' => $this->record['uid'],
                            'record_tablename' => $this->table,
                        ],
                    ],
                ];

                if (is_array($this->configuration['representations.'] ?? null)) {
                    foreach ($this->configuration['representations.'] as $representationToCreate) {
                        $representationUid = 'NEW' . uniqid('');

                        $representationPid = (int)$this->stdWrapOptional($contentObjectRenderer, $representationToCreate, 'pid', 1);

                        $scheme = $this->stdWrapOptional($contentObjectRenderer, $representationToCreate, 'scheme', '');

                        $authority = $this->stdWrapOptional($contentObjectRenderer, $representationToCreate, 'authority', '');

                        $path = $this->stdWrapOptional($contentObjectRenderer, $representationToCreate, 'path', '');

                        $query = $this->stdWrapOptional($contentObjectRenderer, $representationToCreate, 'query', '');

                        $fragment = $this->stdWrapOptional($contentObjectRenderer, $representationToCreate, 'fragment', '');

                        $contentType = $this->stdWrapOptional($contentObjectRenderer, $representationToCreate, 'content_type', '');

                        $contentLanguage = $this->stdWrapOptional($contentObjectRenderer, $representationToCreate, 'content_language', '');

                        $dataMap['tx_lod_domain_model_representation'][$representationUid] = [
                            'pid' => $representationPid,
                            'parent' => $iriUid,
                            'scheme' => $scheme,
                            'authority'  => $authority,
                            'path' => $path,
                            'query' => $query,
                            'fragment' => $fragment,
                            'content_type' => $contentType,
                            'content_language' => $contentLanguage,
                        ];
                    }
                }

                if (is_array($this->configuration['statements.'] ?? null)) {
                    foreach ($this->configuration['statements.'] as $statementToCreate) {
                        $statementUid = 'NEW' . uniqid('');

                        $statementPid = (int)$this->stdWrapOptional($contentObjectRenderer, $statementToCreate, 'pid', 1);

                        $predicateUid = $this->stdWrapOptional($contentObjectRenderer, $statementToCreate, 'predicate', '');

                        $objectUid = $this->stdWrapOptional($contentObjectRenderer, $statementToCreate, 'object', '');

                        $objectType = $this->stdWrapOptional($contentObjectRenderer, $statementToCreate, 'object_type', 'tx_lod_domain_model_iri');

                        $graph = $this->stdWrapOptional($contentObjectRenderer, $statementToCreate, 'graph', '');

                        $objectRecursion = $this->stdWrapOptional($contentObjectRenderer, $statementToCreate, 'recursion', 0);

                        $dataMap['tx_lod_domain_model_statement'][$statementUid] = [
                            'pid' => $statementPid,
                            'subject_uid' => $iriUid,
                            'predicate' => 'tx_lod_domain_model_iri_' . $predicateUid,
                            'predicate_type' => 'tx_lod_domain_model_iri',
                            'predicate_uid' => $predicateUid,
                            'object'  => $objectType . '_' . $objectUid,
                            'object_type'  => $objectType,
                            'object_uid'  => $objectUid,
                            'object_recursion' => $objectRecursion,
                            'graph'  => $graph,
                        ];
                    }
                }
            }

            // in case of a deleted tracked record that has no IRI nothing is done
        }

        $tce = GeneralUtility::makeInstance(DataHandler::class);

        if ($dataMap) {
            $tce->start($dataMap, []);
            $tce->process_datamap();
        } elseif ($cmdMap) {
            $tce->start([], $cmdMap);
            $tce->process_cmdmap();
        }
    }

    /**
     * Applies stdWrap to an optional TSConfig value.
     *
     * Table tracking configuration usually sets either the plain key (a constant) or the
     * "key." stdWrap array (e.g. a dataWrap), rarely both. Returns $default if neither
     * is set, otherwise the stdWrap result of whichever parts are present.
     *
     * @param array<string, mixed> $configuration
     */
    private function stdWrapOptional(
        ContentObjectRenderer $contentObjectRenderer,
        array $configuration,
        string $key,
        mixed $default
    ): mixed {
        $value = $configuration[$key] ?? '';
        $stdWrapConfiguration = $configuration[$key . '.'] ?? [];
        if (!$value && !$stdWrapConfiguration) {
            return $default;
        }
        return $contentObjectRenderer->stdWrap($value, $stdWrapConfiguration);
    }

    /**
     * Checks if an IRI exists for the tracked record (by looking at the record field and the uid)
     *
     * @return array A result array with iri records if existing
     */
    private function iriExists(): array
    {
        if ($this->configuration['iriPidList'] ?? '') {
            (is_array($this->configuration['iriPidList.'] ?? null) && array_key_exists('recursive', $this->configuration['iriPidList.'])) ?
                $recursive = $this->configuration['iriPidList.']['recursive'] : $recursive = 0;
            $iriPidList = $this->getIriPidList($this->configuration['iriPidList'], $recursive);
        } else {
            $iriPidList = $this->record['pid'];
        }

        $pidList = GeneralUtility::intExplode(',', $iriPidList);

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_lod_domain_model_iri');

        /* remove hidden restriction and definitely select an IRI if it exists (despite deleted IRIs) */
        $queryBuilder
            ->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $result = $queryBuilder
            ->select('*')
            ->from('tx_lod_domain_model_iri')
            ->where(
                $queryBuilder->expr()->and(
                    $queryBuilder->expr()->eq('record', ':record'),
                    $queryBuilder->expr()->in('pid', ':pidList')
                )
            )
            ->setParameter('record', $this->table . '_' . $this->record['uid'], Connection::PARAM_STR)
            ->setParameter('pidList', $pidList, ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();

        return $result;
    }

    /**
     * Compiles a potentially recursive pid list on which to look for IRI records
     *
     * @param string $pidList
     * @param int $recursive
     * @return string
     */
    protected function getIriPidList(string $pidList, int $recursive): string
    {
        $recursiveIriPids = '';
        $storagePids = GeneralUtility::intExplode(',', $pidList);
        $permsClause = $GLOBALS['BE_USER']->getPagePermsClause(1);
        foreach ($storagePids as $startPid) {
            $pids = $this->getTreeList($startPid, $recursive, 0, $permsClause);
            if ((string)$pids !== '') {
                $recursiveIriPids .= $pids . ',';
            }
        }

        return rtrim($recursiveIriPids, ',');
    }

    /**
     * Recursively fetch all descendants of a given page
     *
     * Copied from \TYPO3\CMS\Core\Database\QueryGenerator::getTreeList() from
     * previous TYPO3 11 because removed in 12.
     *
     * @see https://docs.typo3.org/c/typo3/cms-core/main/en-us/Changelog/11.0/Deprecation-92080-DeprecatedQueryGeneratorAndQueryView.html#deprecation-92080-querygenerator-and-queryview
     *
     * @param int $id uid of the page
     * @param int $depth
     * @param int $begin
     * @param string $permClause
     * @return string comma separated list of descendant pages
     */
    public function getTreeList(
        int $id,
        int $depth,
        int $begin = 0,
        string $permClause = ''
    ): string {
        $depth = $depth;
        $begin = $begin;
        $id = $id;
        if ($id < 0) {
            $id = abs($id);
        }
        if ($begin == 0) {
            $theList = (string)$id;
        } else {
            $theList = '';
        }
        if ($id && $depth > 0) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $queryBuilder->select('uid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($id, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('sys_language_uid', 0)
                )
                ->orderBy('uid');
            if ($permClause !== '') {
                $queryBuilder->andWhere(QueryHelper::stripLogicalOperatorPrefix($permClause));
            }
            $statement = $queryBuilder->executeQuery();
            while ($row = $statement->fetchAssociative()) {
                if ($begin <= 0) {
                    $theList .= ',' . $row['uid'];
                }
                if ($depth > 1) {
                    $theSubList = $this->getTreeList($row['uid'], $depth - 1, $begin - 1, $permClause);
                    if (!empty($theList) && !empty($theSubList) && ($theSubList[0] !== ',')) {
                        $theList .= ',';
                    }
                    $theList .= $theSubList;
                }
            }
        }
        return $theList;
    }
}
