<?php
if (!defined('TYPO3_MODE')) {
    die('Access denied.');
}

// TYPOSCRIPT

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
    'lod', 'Configuration/TypoScript', 'Linked Open Data for TYPO3'
);

// TSCONFIG

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addPageTSConfig('
    <INCLUDE_TYPOSCRIPT: source="FILE:EXT:lod/Configuration/TSConfig/setup.txt">
');

// PLUGINS

\TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerPlugin(
    'Digicademy.lod',
    'Vocabulary',
    'LOD Vocabulary'
);

\TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerPlugin(
    'Digicademy.lod',
    'Api',
    'LOD API'
);

// FLEXFORMS

$TCA['tt_content']['types']['list']['subtypes_addlist']['lod_vocabulary'] = 'pi_flexform';
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addPiFlexFormValue('lod_vocabulary', 'FILE:EXT:lod/Configuration/FlexForms/VocabularyPlugin.xml');

// TABLES

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::allowTableOnStandardPages('tx_lod_domain_model_namespace');

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::allowTableOnStandardPages('tx_lod_domain_model_iri');

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::allowTableOnStandardPages('tx_lod_domain_model_bnode');

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::allowTableOnStandardPages('tx_lod_domain_model_literal');

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::allowTableOnStandardPages('tx_lod_domain_model_statement');

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::allowTableOnStandardPages('tx_lod_domain_model_term');

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::allowTableOnStandardPages('tx_lod_domain_model_vocabulary');

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::allowTableOnStandardPages('tx_lod_domain_model_representation');

// ICONS

$iconRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
   \TYPO3\CMS\Core\Imaging\IconRegistry::class
);

$iconRegistry->registerIcon(
   'tx_lod_actions_add_iri',
   \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
   ['source' => 'EXT:lod/Resources/Public/Icons/tx_lod_actions_add_iri.svg']
);

$iconRegistry->registerIcon(
   'tx_lod_actions_add_bnode',
   \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
   ['source' => 'EXT:lod/Resources/Public/Icons/tx_lod_actions_add_bnode.svg']
);

$iconRegistry->registerIcon(
   'tx_lod_actions_add_literal',
   \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
   ['source' => 'EXT:lod/Resources/Public/Icons/tx_lod_actions_add_literal.svg']
);

$iconRegistry->registerIcon(
   'tx_lod_domain_model_iri',
   \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
   ['source' => 'EXT:lod/Resources/Public/Icons/tx_lod_domain_model_iri.svg']
);

$iconRegistry->registerIcon(
   'tx_lod_type_entity',
   \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
   ['source' => 'EXT:lod/Resources/Public/Icons/tx_lod_type_entity.svg']
);

$iconRegistry->registerIcon(
   'tx_lod_type_property',
   \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
   ['source' => 'EXT:lod/Resources/Public/Icons/tx_lod_type_property.svg']
);
