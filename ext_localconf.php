<?php

defined('TYPO3') or die();

use Digicademy\Lod\Backend\Form\Element\EnhancedGroupElement;
use Digicademy\Lod\Backend\Form\FieldControl\EnhancedAddRecord;
use Digicademy\Lod\Backend\Form\FieldWizard\EnhancedTableList;
use Digicademy\Lod\Controller\{
    ApiController,
    SerializerController,
    VocabularyController
};
use Digicademy\Lod\Resolver\{
    HttpResolver,
    HttpsResolver,
    T3Resolver
};
use TYPO3\CMS\Backend\Form\Element\GroupElement;
use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Imaging\IconRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

// PLUGINS
//
// All plugins are registered as content elements (own "CType") rather than as the
// legacy "list_type" sub type of CType "list". The "list_type" plugin sub type was
// deprecated in TYPO3 13.4 (@see Changelog 13.4, #105076) and will be removed in
// TYPO3 v14. Registering as a content element makes TYPO3 generate the TypoScript
// object "tt_content.lod_<pluginname>" (a "lib.contentElement" copy with the Extbase
// plugin at ".20") instead of "tt_content.list.20.lod_<pluginname>".
//
// Existing "tt_content" records are migrated by the upgrade wizard
// @see \Digicademy\Lod\Updates\DigicademyLodCTypeMigration

ExtensionUtility::configurePlugin(
    'Lod',
    'Vocabulary',
    [
        VocabularyController::class => 'show',
    ],
    [
        VocabularyController::class => '',
    ],
    ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT
);

ExtensionUtility::configurePlugin(
    'Lod',
    'Api',
    [
        ApiController::class => 'about',
    ],
    [
        ApiController::class => 'about',
    ],
    ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT
);

ExtensionUtility::configurePlugin(
    'Lod',
    'Serializer',
    [
        SerializerController::class => 'iri',
    ],
    [
        SerializerController::class => '',
    ],
    ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT
);

// REGISTERES URI RESOLVER

$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['lod']['resolver'] = [
    't3' => T3Resolver::class,
    'http' => HttpResolver::class,
    'https' => HttpsResolver::class,
];

// TYPE ICONS

// register icons for IRIs
$icons = [
    'ext-lod-type-default' => 'tx_lod_domain_model_iri.svg',
    'ext-lod-type-class' => 'tx_lod_domain_model_iri.svg',
    'ext-lod-type-property' => 'tx_lod_domain_model_property.svg',
];
$iconRegistry = GeneralUtility::makeInstance(IconRegistry::class);
foreach ($icons as $identifier => $path) {
    if (!$iconRegistry->isRegistered($identifier)) {
        $iconRegistry->registerIcon(
            $identifier,
            SvgIconProvider::class,
            ['source' => 'EXT:lod/Resources/Public/Icons/' . $path]
        );
    }
}

// register tcemain hooks for table tracking, statement synchronization and identifier generation
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = 'Digicademy\Lod\Hooks\Backend\DataHandler';
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][] = 'Digicademy\Lod\Hooks\Backend\DataHandler';

// add modified addRecord fieldControl (make it reusable for different types of records)
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][138471234123] = [
    'nodeName' => 'enhancedAddRecord',
    'priority' => 30,
    'class' => EnhancedAddRecord::class,
];

// add modified tableList fieldWizard (take out hard coded connection to elementBrowser)
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1617617718] = [
    'nodeName' => 'enhancedTableList',
    'priority' => 30,
    'class' => EnhancedTableList::class,
];

// XCLASS group field to change hardcoded HTML arrangement of fieldControl
// we don't register a new formEngine node and use XCLASS since has problems in data handling (tested)
$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][GroupElement::class] = [
    'className' => EnhancedGroupElement::class,
];

// exclude extension parameters from cHash generation
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'tx_lod_api[iri]';
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'tx_lod_api[page]';
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'tx_lod_api[limit]';
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'tx_lod_api[query]';
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'tx_lod_api[subject]';
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'tx_lod_api[predicate]';
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'tx_lod_api[object]';
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'tx_lod_api[sorting]';
$GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['excludedParameters'][] = 'tx_lod_api[apiDocumentation]';
