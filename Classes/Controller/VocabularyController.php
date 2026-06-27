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

namespace Digicademy\Lod\Controller;

use Digicademy\Lod\Domain\Model\{
    Graph,
    Vocabulary
};
use Digicademy\Lod\Domain\Repository\{
    GraphRepository,
    IriNamespaceRepository,
    VocabularyRepository
};
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\Exception\InvalidQueryException;

class VocabularyController extends ActionController
{
    /**
     * Initializes the controller and dependencies
     *
     * @param IriNamespaceRepository $iriNamespaceRepository
     * @param GraphRepository        $graphRepository
     * @param VocabularyRepository   $vocabularyRepository
     */
    public function __construct(
        protected IriNamespaceRepository $iriNamespaceRepository,
        protected GraphRepository $graphRepository,
        protected VocabularyRepository $vocabularyRepository
    ) {}

    /**
     * show selected vocabulary
     *
     * @return ResponseInterface
     * @throws InvalidQueryException
     */
    public function showAction(): ResponseInterface
    {
        // if a vocabulary is set in the plugin
        if ((int)$selectedVocabularyUid = $this->settings['general']['selectedVocabulary']) {
            // assign the selected vocabulary

            /**
             * $selectedVocabulary
             * @var Vocabulary
             */
            $selectedVocabulary = $this->vocabularyRepository->findByUid($selectedVocabularyUid);

            $this->view->assign('vocabulary', $selectedVocabulary);

            // potentially assign vocabulary IRI graph

            /**
             * $graph
             * @var Graph
             */
            $graph = $this->graphRepository->findByIri($selectedVocabulary->getIri());

            $this->view->assign('graph', $graph);

            // assign existing namespaces

            /**
             * $apiSettings
             * @var array
             */
            $apiSettings = $this->configurationManager->getConfiguration('Settings', 'lod', 'api');

            $this->view->assign('iriNamespaces', $this->iriNamespaceRepository->findSelected('show', $apiSettings));
        }

        // assign current arguments
        $this->view->assign('arguments', $this->request->getArguments());

        // Get an instance of NormalizedParams, which provides normalized server
        // parameters and substitutes GeneralUtility::getIndpEnv().
        $normalizedParams = $this->request->getAttribute('normalizedParams');

        // provide environment vars
        $environment = [
            'TYPO3_SITE_BASE_URL' => rtrim($normalizedParams->getSiteUrl(), '/'),
            'TYPO3_REQUEST_URL' => $normalizedParams->getRequestUrl(),
            'TSFE' => ['pageArguments' => $GLOBALS['TSFE']->pageArguments],
        ];
        $this->view->assign('environment', $environment);

        return $this->htmlResponse();
    }
}
