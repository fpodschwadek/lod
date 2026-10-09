<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) Torsten Schrade <Torsten.Schrade@adwmainz.de>
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

namespace Digicademy\Lod\Utility\Backend;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

class LabelUtility
{
    /**
     * Builds the backend title of an IRI record from the display pattern in page TSconfig.
     *
     * Registered as both label_userFunc and formattedLabel_userFunc of tx_lod_domain_model_iri. TYPO3 also calls it
     * for records that are not saved yet (uid "NEW…"), e.g. when an IRI is created from a statement's field control,
     * so every row key has to be treated as optional.
     *
     * @param array<string, mixed> $parameters table, row, title, options and, for inline children, parent
     * @return array<string, mixed>
     */
    public function iriLabel(array &$parameters): array
    {
        $row = $parameters['row'] ?? [];

        // get PageTSConfig for current record (the page where the record is stored, not necessary the current page)
        $TSConfig = BackendUtility::getPagesTSconfig((int)($row['pid'] ?? 0));

        // check for display pattern and initialize $iriLabel
        if (
            isset($TSConfig['tx_lod.']['settings.']['iriLabel.']['displayPattern'])
        ) {
            $iriLabel = $TSConfig['tx_lod.']['settings.']['iriLabel.']['displayPattern'];

            // label_userFunc does not always get the full row (since TYPO3 11), so fetch the full IRI. A record that
            // is not saved yet has a "NEW…" placeholder uid and nothing to fetch: keep the row that was passed in.
            $uid = $row['uid'] ?? 0;
            if (MathUtility::canBeInterpretedAsInteger($uid) && (int)$uid > 0) {
                $iri = BackendUtility::getRecord('tx_lod_domain_model_iri', (int)$uid);
                if (is_array($iri)) {
                    $row = $iri;
                }
            }
            $parameters['row'] = $row;
        } else {
            $iriLabel = '###NAMESPACE_PREFIX###:###IRI_VALUE###';
        }

        // replace namespace markers
        if (preg_match('/###NAMESPACE_PREFIX###/', $iriLabel) > 0 || preg_match('/###NAMESPACE_IRI###/', $iriLabel) > 0) {
            // initialize namespace var
            $namespace = [];

            // if called in the context of an edit form title the namespace field (strangely) is an array and not an integer - reset
            if (isset($parameters['row']['namespace']) && is_array($parameters['row']['namespace'])) {
                $parameters['row']['namespace'] = $parameters['row']['namespace'][0];
            }

            // if namespace fetch namespace record
            if (isset($parameters['row']['namespace']) && $parameters['row']['namespace'] > 0) {
                $namespace = BackendUtility::getRecord('tx_lod_domain_model_namespace', (int)$parameters['row']['namespace']);
            }

            // replace ###NAMESPACE_PREFIX### (str_replace, so "$1" or "\1" in a value is not read as a back-reference)
            if (isset($namespace['prefix'])) {
                $iriLabel = str_replace('###NAMESPACE_PREFIX###', (string)$namespace['prefix'], $iriLabel);
            }

            // replace ###NAMESPACE_IRI###
            if (isset($namespace['iri'])) {
                $iriLabel = str_replace('###NAMESPACE_IRI###', (string)$namespace['iri'], $iriLabel);
            }
        }

        // replace iri markers
        if (str_contains($iriLabel, '###IRI_VALUE###') && !empty($parameters['row']['value'])) {
            $iriLabel = str_replace('###IRI_VALUE###', (string)$parameters['row']['value'], $iriLabel);
        }

        if (str_contains($iriLabel, '###IRI_LABEL###') && !empty($parameters['row']['label'])) {
            $iriLabel = str_replace('###IRI_LABEL###', (string)$parameters['row']['label'], $iriLabel);
        }

        // set title
        $parameters['title'] = $iriLabel;

        return $parameters;
    }
}
