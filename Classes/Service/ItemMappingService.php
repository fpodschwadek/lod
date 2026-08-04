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

use Digicademy\Lod\Domain\Model\Record;
use TYPO3\CMS\Backend\Form\FormDataProvider\TcaRecordTitle;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Service to load and map records from generic TCA group fields
 */
class ItemMappingService
{
    protected array $classesCache = [];

    public function __construct(
        protected readonly DataMapper $dataMapper,
        protected readonly PackageManager $packageManager,
        private readonly \TYPO3\CMS\Core\Localization\LanguageServiceFactory $languageServiceFactory,
        private readonly \TYPO3\CMS\Core\Database\ConnectionPool $connectionPool
    ) {}

    /**
     * @param string $record
     * @return object
     */
    public function mapItem(string $record): ?object
    {
        $item = null;

        $result = $this->load($record);

        if ($result['row']) {
            $item = $this->map($result['row'], $result['tablename']);
        }

        return $item;
    }

    /**
     * @param string $record
     * @return Record
     */
    public function mapGenericItem(string $record): ?Record
    {
        $item = null;

        $result = $this->load($record);

        if ($result['row']) {
            $formDataProvider = GeneralUtility::makeInstance(TcaRecordTitle::class);
            $titleLabel = $GLOBALS['TCA'][$result['tablename']]['ctrl']['title'];
            if (($GLOBALS['TSFE'] ?? null) !== null) {
                $translatedTitle = $GLOBALS['TSFE']->sL($titleLabel);
            } else {
                $translatedTitle = $this->languageServiceFactory
                    ->create('default')
                    ->sL($titleLabel);
            }
            $tcaProcessing = $formDataProvider->addData([
                'databaseRow' => $result['row'],
                'processedTca' => $GLOBALS['TCA'][$result['tablename']],
                'tablename' => $result['tablename'],
                'title' => $translatedTitle,
            ]);

            $item = GeneralUtility::makeInstance(Record::class);
            $item->setLabel($tcaProcessing['recordTitle']);
            $item->setComment($tcaProcessing['title']);
            $item->setTablename($result['tablename']);
            $item->setRow($tcaProcessing['databaseRow']);
            $item->_setProperty('uid', (int)$tcaProcessing['databaseRow']['uid']);
            $item->setPid($tcaProcessing['databaseRow']['pid']);
            $domainObject = $this->map($result['row'], $result['tablename']);
            if ($domainObject !== null) {
                $item->setDomainObject($domainObject);
            }
        }

        return $item;
    }

    /**
     * Loads a record (syntax: tablename_uid)
     *
     * @param string $record
     * @return array
     */
    protected function load(string $record): ?array
    {
        $result = [];

        // split incoming record into tablename and uid
        $tableNameAndUid = BackendUtility::splitTable_Uid($record);
        $tablename = $tableNameAndUid[0];
        $uid = $tableNameAndUid[1];

        // if class and tablename exist perform MM query for items, map them and add them to the object storage
        if ($tablename && $uid) {
            $row = $this->connectionPool
                ->getConnectionForTable($tablename)
                ->select(
                    ['*'], // fields
                    $tablename, // from
                    [ 'uid' => (int)$uid ] // where
                )->fetchAssociative();

            if ($row) {
                $result = [
                    'tablename'  => $tablename,
                    'uid' => $uid,
                    'row' => $row,
                ];
            }
        }

        return $result;
    }

    /**
     * Loads the merged class configuration from all active packages' Classes.php files.
     *
     * @return array<class-string, array<string, mixed>>
     */
    protected function getClasses(): array
    {
        if (!$this->classesCache) {
            foreach ($this->packageManager->getActivePackages() as $activePackage) {
                $persistenceClassesFile = $activePackage->getPackagePath() . 'Configuration/Extbase/Persistence/Classes.php';
                if (file_exists($persistenceClassesFile)) {
                    $definedClasses = require $persistenceClassesFile;
                    if (is_array($definedClasses)) {
                        $this->classesCache = array_replace_recursive($this->classesCache, $definedClasses);
                    }
                }
            }
        }
        return $this->classesCache;
    }

    /**
     * Maps record row to a configured domain object
     *
     * @param array $row
     * @param string $tablename
     * @return object
     */
    protected function map(array $row, string $tablename): ?object
    {
        $result = null;
        $className = '';

        // find a class mapping for the given tablename from Classes.php configuration
        foreach ($this->getClasses() as $key => $value) {
            // if current table name matches a configured table name
            if (
                array_key_exists('tableName', $value) &&
                $value['tableName'] === $tablename
            ) {
                // check if recordType is configured and matches the row
                if (array_key_exists('recordType', $value)) {
                    $typeColumnName = $GLOBALS['TCA'][$tablename]['ctrl']['type'];
                    if ($row[$typeColumnName] == $value['recordType']) {
                        $className = $key;
                    } else {
                        continue;
                    }
                    // if no recordType is configured directly match table name to configured class
                } else {
                    $className = $key;
                }
            }
        }

        // if a class name exists, map row to domain object
        if ($className) {
            $mappedRecord = $this->dataMapper->map($className, [$row]);
            $result = $mappedRecord[0];
        }

        return $result;
    }

    /**
     * Signal/Slot method that maps tablename_uid strings from TCA group fields to objects
     *
     * @param object $domainObject
     */
    public function mapGenericProperty(object $domainObject): void
    {
        // map record property of IRI object (if not empty)
        if (get_class($domainObject) == 'Digicademy\Lod\Domain\Model\Iri') {
            if (!empty($domainObject->getRecord())) {
                $domainObject->setRecord($this->mapGenericItem($domainObject->getRecord()));
            }
        }

        // map subject, predicate and object in statements
        if (get_class($domainObject) == 'Digicademy\Lod\Domain\Model\Statement') {
            if (!empty($domainObject->getSubject())) {
                $domainObject->setSubject($this->mapItem($domainObject->getSubject()));
            }
            if (!empty($domainObject->getPredicate())) {
                $domainObject->setPredicate($this->mapItem($domainObject->getPredicate()));
            }
            if (!empty($domainObject->getObject())) {
                $domainObject->setObject($this->mapItem($domainObject->getObject()));
            }
        }
    }
}
