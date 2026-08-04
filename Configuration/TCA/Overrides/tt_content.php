<?php

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

ExtensionUtility::registerPlugin(
    'Lod',
    'Vocabulary',
    'LOD: Vocabulary'
);

ExtensionUtility::registerPlugin(
    'Lod',
    'Api',
    'LOD: Api'
);

ExtensionUtility::registerPlugin(
    'Lod',
    'Serializer',
    'LOD: Serializer'
);

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes('tt_content', '--div--;Configuration,pi_flexform,', 'lod_vocabulary', 'after:subheader');
ExtensionManagementUtility::addPiFlexFormValue('*', 'FILE:EXT:lod/Configuration/FlexForms/VocabularyPlugin.xml', 'lod_vocabulary');

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes('tt_content', '--div--;Configuration,pi_flexform,', 'lod_serializer', 'after:subheader');
ExtensionManagementUtility::addPiFlexFormValue('*', 'FILE:EXT:lod/Configuration/FlexForms/SerializerPlugin.xml', 'lod_serializer');
