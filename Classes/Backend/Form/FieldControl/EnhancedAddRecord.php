<?php

declare(strict_types=1);

namespace Digicademy\Lod\Backend\Form\FieldControl;

use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\{
    GeneralUtility,
    StringUtility
};

/*
 * Copy of the core add record script with the difference of making it possible to create records
 * in popup windows that can be closed directly; this is much more usable for the triple composer
 * search for @metacontext to find changes
 */
class EnhancedAddRecord extends AbstractNode
{
    public function __construct(private readonly \TYPO3\CMS\Backend\Routing\UriBuilder $uriBuilder)
    {
    }
    /**
     * Add button control
     *
     * @return array As defined by FieldControl class
     * @throws \TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException
     */
    public function render(): array
    {
        $options = $this->data['renderData']['fieldControlOptions'];
        $parameterArray = $this->data['parameterArray'];
        $itemName = $parameterArray['itemFormElName'];

        // Handle options and fallback
        $title = $options['title'] ?? 'LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.createNew';
        $setValue = $options['setValue'] ?? 'append';

        // @metacontext: add window parameters for record creation popup
        $windowOpenParameters = $options['windowOpenParameters'] ?? 'height=730,width=900,status=0,menubar=0,scrollbars=1';

        $table = '';
        if (isset($options['table'])) {
            // Table given in options - use it
            $table = $options['table'];
        } elseif ($parameterArray['fieldConf']['config']['type'] === 'group'
            && isset($parameterArray['fieldConf']['config']['internal_type'])
            && $parameterArray['fieldConf']['config']['internal_type'] === 'db'
            && !empty($parameterArray['fieldConf']['config']['allowed'])
        ) {
            // Use first table from allowed list if specific table is not set in options
            $allowedTables = GeneralUtility::trimExplode(',', $parameterArray['fieldConf']['config']['allowed'], true);
            $table = array_pop($allowedTables);
        } elseif ($parameterArray['fieldConf']['config']['type'] === 'select'
            && !empty($parameterArray['fieldConf']['config']['foreign_table'])
        ) {
            // Use foreign_table if given for type=select
            $table = $parameterArray['fieldConf']['config']['foreign_table'];
        }
        if (empty($table)) {
            // Still no table - this element can not handle the add control.
            return [];
        }

        // Target pid of new records is current pid by default
        $pid = $this->data['effectivePid'];
        if (isset($options['pid'])) {
            // @metacontext: process PageTSConfig marker for wizard pid
            if (
                preg_match('/###PAGE_TSCONFIG_ID###/', $options['pid'])
                && $this->data['pageTsConfig']['TCEFORM.'][$this->data['tableName'] . '.'][$this->data['fieldName'] . '.']['PAGE_TSCONFIG_ID']
            ) {
                $pid = $this->data['pageTsConfig']['TCEFORM.'][$this->data['tableName'] . '.'][$this->data['fieldName'] . '.']['PAGE_TSCONFIG_ID'];
            } else {
                // pid configured in options - use it
                $pid = $options['pid'];
            }
        } elseif (
            isset($GLOBALS['TCA'][$table]['ctrl']['rootLevel'])
            && (int)$GLOBALS['TCA'][$table]['ctrl']['rootLevel'] === 1
        ) {
            // Target table can only exist on root level - set 0 as pid
            $pid = 0;
        }

        $prefixOfFormElName = 'data[' . $this->data['tableName'] . '][' . $this->data['databaseRow']['uid'] . '][' . $this->data['fieldName'] . ']';
        $flexFormPath = '';
        if (str_starts_with($itemName, $prefixOfFormElName)) {
            $flexFormPath = str_replace('][', '/', substr($itemName, strlen($prefixOfFormElName) + 1, -1));
        }

        // @metacontext: add parameters for opening record creation form in popup
        $urlParameters = [
            'P' => [
                'params' => [
                    'table' => $table,
                    'pid' => $pid,
                    'setValue' => $setValue,
                ],
                'formName' => 'editform',
                'table' => $this->data['tableName'],
                'field' => $this->data['fieldName'],
                'uid' => $this->data['databaseRow']['uid'],
                'flexFormPath' => $flexFormPath,
                'hmac' => \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Crypto\HashService::class)->hmac('editform' . $itemName, 'wizard_js'),
                'fieldChangeFunc' => $parameterArray['fieldChangeFunc'],
                'fieldChangeFuncHash' => \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Crypto\HashService::class)->hmac(serialize($parameterArray['fieldChangeFunc']), 'backend-link-browser'),
                //                'returnUrl' => $this->data['returnUrl'],
            ],
        ];

        $id = StringUtility::getUniqueId('t3js-formengine-fieldcontrol-');

        /** @var \TYPO3\CMS\Backend\Routing\UriBuilder $uriBuilder */
        $uriBuilder = $this->uriBuilder;

        // @metacontext: include JS module and window parameters for record creation popup
        return [
            'iconIdentifier' => $options['iconIdentifier'],
            'title' => $title,
            'linkAttributes' => [
                'id' => htmlspecialchars($id),
                'href' => (string)$uriBuilder->buildUriFromRoute('wizard_enhanced_add', $urlParameters),
                'data-element' => $itemName,
                'data-window-parameters' => $windowOpenParameters,
            ],
            $resultArray['javaScriptModules'][] = JavaScriptModuleInstruction::create(
                '../typo3conf/ext/lod/Resources/Public/JavaScript/EnhancedAddRecord.js'
            )->instance('#' . $id),
        ];
    }
}
