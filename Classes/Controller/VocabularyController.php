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
use Psr\Log\LoggerInterface;
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
     * @param LoggerInterface        $logger
     */
    public function __construct(
        protected IriNamespaceRepository $iriNamespaceRepository,
        protected GraphRepository $graphRepository,
        protected VocabularyRepository $vocabularyRepository,
        protected LoggerInterface $logger
    ) {}

    /**
     * show selected vocabulary
     *
     * Renders without a vocabulary if none is selected in the plugin (e.g. its FlexForm was never saved). If one is
     * selected but cannot be loaded (deleted, hidden, out of its start/end time), the element renders the same way
     * and a warning is logged, so the broken reference can be found.
     *
     * @return ResponseInterface
     * @throws InvalidQueryException
     */
    public function showAction(): ResponseInterface
    {
        $selectedVocabularyUid = (int)($this->settings['general']['selectedVocabulary'] ?? 0);
        $selectedVocabulary = null;

        // if a vocabulary is set in the plugin
        if ($selectedVocabularyUid > 0) {
            $selectedVocabulary = $this->vocabularyRepository->findByUid($selectedVocabularyUid);

            if (!$selectedVocabulary instanceof Vocabulary) {
                $this->logger->warning(
                    'Vocabulary {vocabulary} selected in content element {contentElement} cannot be loaded; it may be deleted, hidden or outside its start/end time.',
                    [
                        'vocabulary' => $selectedVocabularyUid,
                        'contentElement' => $this->request->getAttribute('currentContentObject')?->data['uid'] ?? 0,
                    ]
                );
            }
        }

        if ($selectedVocabulary instanceof Vocabulary) {
            // assign the selected vocabulary
            $this->view->assign('vocabulary', $selectedVocabulary);

            // potentially assign vocabulary IRI graph; a vocabulary need not have an IRI

            $vocabularyIri = $selectedVocabulary->getIri();

            /**
             * $graph
             * @var Graph|null
             */
            $graph = $vocabularyIri !== null ? $this->graphRepository->findByIri($vocabularyIri) : null;

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
            'pageArguments' => $this->request->getAttribute('routing'),
        ];
        $this->view->assign('environment', $environment);

        return $this->htmlResponse();
    }
}
