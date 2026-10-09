<?php

declare(strict_types=1);

namespace Digicademy\Lod\Backend\Form\FieldControl;

use TYPO3\CMS\Backend\Form\FieldControl\AddRecord;

/**
 * Core's "add record" field control with a configurable icon.
 *
 * The triple composer places several of these controls on one group field, one per record
 * type that can be created (IRI, blank node, literal), so each needs its own icon. Core's
 * control always renders "actions-plus"; everything else - the wizard_add route, the
 * resolution of pid markers such as ###PAGE_TSCONFIG_ID###, writing the new record into
 * the parent field and the unsaved-changes prompt of its JavaScript module - is core's own.
 *
 * TCA usage, as fieldControl of a group or select field:
 *
 *   'renderType' => 'enhancedAddRecord',
 *   'options' => [
 *       'table' => 'tx_lod_domain_model_iri',
 *       'pid' => '###PAGE_TSCONFIG_ID###',
 *       'setValue' => 'set',
 *       'title' => 'Create new IRI',
 *       'iconIdentifier' => 'tx_lod_actions_add_iri',
 *   ],
 */
class EnhancedAddRecord extends AddRecord
{
    /**
     * @return array<string, mixed> As defined by FieldControl class
     */
    public function render(): array
    {
        $result = parent::render();
        $iconIdentifier = $this->data['renderData']['fieldControlOptions']['iconIdentifier'] ?? '';
        if ($result !== [] && $iconIdentifier !== '') {
            $result['iconIdentifier'] = $iconIdentifier;
        }
        return $result;
    }
}
