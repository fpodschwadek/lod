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

namespace Digicademy\Lod\Resolver;

use Digicademy\Lod\Domain\Model\Representation;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

class T3Resolver extends AbstractResolver implements ResolverInterface
{
    /**
     * @param Representation $representation
     * @return string
     */
    public function resolveToUrl(Representation $representation): string
    {
        // @TODO: call different typolink handlers according to $representation->getAuthority();

        $url = '';
        $tsfe = $this->getTypoScriptFrontendController();
        $pageTsConfig = $tsfe->getPagesTSconfig();
        $linkDetails = $this->getLinkDetails($representation->getQuery());

        if (!empty($linkDetails['identifier']) && !empty($linkDetails['uid'])) {
            $configurationKey = $linkDetails['identifier'] . '.';
            $configuration = $GLOBALS['TYPO3_REQUEST']->getAttribute('frontend.typoscript')->getSetupArray()['config.']['recordLinks.'];
            $linkHandlerConfiguration = $pageTsConfig['TCEMAIN.']['linkHandler.'][$configurationKey]['configuration.'];
            $typoScriptConfiguration = $configuration[$configurationKey]['typolink.'];
            $typoScriptConfiguration['forceAbsoluteUrl'] = '1';

            if ($configuration && $linkHandlerConfiguration && $typoScriptConfiguration) {
                $record = $tsfe->sys_page->checkRecord($linkHandlerConfiguration['table'], $linkDetails['uid']);

                if ($record) {
                    $representation->getFragment() ? $record['fragment'] = $representation->getFragment() : false;
                    $this->contentObjectRenderer->start($record, $linkHandlerConfiguration['table']);
                    $url = $this->contentObjectRenderer->typoLink_URL($typoScriptConfiguration);

                    // in some cases (e.g. TYPO3 9 with site configuration) forceAbsoluteUrl seems not to be
                    // evaluated by typoLink_URL function; therefore append request host to make sure that the
                    // url is absolute
                    if (!preg_match('#://#', $url)) {
                        $url = rtrim($GLOBALS['TYPO3_REQUEST']->getAttribute('normalizedParams')->getSiteUrl(), '/') . $url;
                    }
                }
            }
        }

        return $url;
    }

    /**
     * @param string $query
     * @return array
     */
    protected function getLinkDetails(string $query): array
    {
        $queryParameters = GeneralUtility::trimExplode('&', $query, true);
        $linkDetails = [];

        foreach ($queryParameters as $parameter) {
            if (preg_match('/identifier=/', $parameter)) {
                $linkDetails['identifier'] = substr($parameter, 11);
            }
            if (preg_match('/uid=/', $parameter)) {
                $linkDetails['uid'] = (int)substr($parameter, 4);
            }
        }
        return $linkDetails;
    }

    /**
     * @return TypoScriptFrontendController
     */
    protected function getTypoScriptFrontendController(): TypoScriptFrontendController
    {
        return $GLOBALS['TSFE'];
    }
}
