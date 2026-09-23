<?php

namespace Digicademy\Lod\Backend\Form\Element;

use TYPO3\CMS\Backend\Form\Element\GroupElement;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/*
 * Adjusts core's group element in two places: the field controls are laid out horizontally
 * (needed for statement table group fields / triple composer), and a field whose value arrives
 * as a bare uid rather than a resolved item list is normalised before core sees it.
 *
 * Both are done around parent::render() rather than inside a copy of it. This class used to be
 * a full copy of the core method body — roughly 300 lines to change one CSS class — and that is
 * exactly why it broke on the TYPO3 13 upgrade: core moved $iconFactory off AbstractFormElement
 * into a private promoted property of GroupElement, the copy went on reading it as though it
 * were still inherited, and every group field in the backend died with "Call to a member
 * function getIcon() on null". Delegating to core means there is no body left to fall behind,
 * and the worst a future core change can now do is make the button layout revert to vertical.
 *
 * Registered as an XClass of GroupElement in ext_localconf.php, so it applies to every group
 * field, which is how it has always behaved.
 */
class EnhancedGroupElement extends GroupElement
{
    /**
     * Matches the "btn-group-vertical" that opens the field control group, and only that one.
     *
     * Core emits two button groups in a group field: one for the move and delete controls and
     * one for the field controls, both classed "btn-group-vertical" but sitting in asides that
     * 13.4 distinguishes with "--move" and "--field-control" modifiers. Only the field control
     * group is meant to be horizontal here; the move controls stay vertical, as they always have.
     */
    private const FIELD_CONTROL_BUTTON_GROUP = '/(form-wizards-item-aside--field-control">\s*<div class=")btn-group-vertical(")/';

    /**
     * @return array<string, mixed> As defined in initializeResultArray() of AbstractNode
     */
    public function render(): array
    {
        $parameterArray = $this->data['parameterArray'] ?? [];

        $this->data['parameterArray']['itemFormElValue'] = $this->resolveSelectedItems(
            $parameterArray['itemFormElValue'] ?? null,
            $parameterArray['fieldConf']['config'] ?? []
        );

        $resultArray = parent::render();

        // @metacontext: horizontal instead of vertical field controls
        $html = preg_replace(
            self::FIELD_CONTROL_BUTTON_GROUP,
            '${1}btn-group-horizontal${2}',
            (string)($resultArray['html'] ?? '')
        );

        // preg_replace() answers null on failure; keeping core's markup is better than losing it
        if ($html !== null) {
            $resultArray['html'] = $html;
        }

        return $resultArray;
    }

    /**
     * Turns a bare uid into the item list core expects.
     *
     * A read-only field — in practice any of the group fields carrying
     * "l10n_display => defaultAsReadonly" — hands FormEngine the plain uid of the foreign record
     * instead of a resolved item array. Core cannot work with that: GroupElement iterates the
     * value and reads 'table', 'uid' and 'title' off each entry, so it has to be resolved here.
     * A value that is already an array, of items or empty, is passed through untouched.
     *
     * @param mixed $selectedItems The raw itemFormElValue
     * @param array<string, mixed> $config TCA config of the field
     * @return array<int, array<string, mixed>>|mixed The item list, or the value unchanged
     */
    private function resolveSelectedItems(mixed $selectedItems, array $config): mixed
    {
        if (is_array($selectedItems)) {
            return $selectedItems;
        }

        $foreignTable = (string)($config['allowed'] ?? '');

        if ($foreignTable === '' || !is_numeric($selectedItems) || (int)$selectedItems <= 0) {
            // covers an empty value and a uid of 0, both of which mean "nothing selected"
            return [];
        }

        $foreignRow = BackendUtility::getRecord($foreignTable, (int)$selectedItems);

        return [
            [
                'table' => $foreignTable,
                'uid' => (int)$selectedItems,
                'title' => BackendUtility::getRecordTitle($foreignTable, $foreignRow ?? []),
                'row' => $foreignRow,
            ],
        ];
    }
}
