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

use Digicademy\Lod\Domain\Model\Iri;
use Digicademy\Lod\Domain\Repository\{
    GraphRepository,
    IriNamespaceRepository,
    IriRepository,
    StatementRepository
};
use Digicademy\Lod\Service\{
    ContentNegotiationService,
    ResolverService
};
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\ImmediateResponseException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Frontend\Controller\ErrorController;
use TYPO3\CMS\Frontend\Page\PageAccessFailureReasons;

class ApiController extends ActionController
{
    /**
     * @var Iri
     */
    protected $resource;

    /**
     * @var ResponseInterface
     */
    protected $response;

    /**
     * Initializes the controller and dependencies
     *
     * @param IriNamespaceRepository      $iriNamespaceRepository
     * @param IriRepository               $iriRepository
     * @param GraphRepository             $graphRepository
     * @param StatementRepository         $statementRepository
     * @param ContentNegotiationService   $contentNegotiationService
     * @param ResolverService             $resolverService
     */
    public function __construct(
        protected IriNamespaceRepository $iriNamespaceRepository,
        protected IriRepository $iriRepository,
        protected GraphRepository $graphRepository,
        protected StatementRepository $statementRepository,
        protected ContentNegotiationService $contentNegotiationService,
        protected ResolverService $resolverService
    ) {}

    /**
     * Main action and entry point of this controller. Returns metadata either about a single resource
     * OR a list of resources. In contrast to a standard TYPO3 action controller this action uses two
     * 'sub actions'. The reason for this logic is to keep request arguments as concise as possible:
     *
     * ROOT/ENTRYPOINT/ABOUT => list of resources
     * ROOT/ENTRYPOINT/VALUE/ABOUT => single resource
     *
     * @return ResponseInterface
     */
    public function aboutAction(): ResponseInterface
    {
        // check if pageType is set (either via param or masked through PageTypeSuffix)
        if ($this->request->getParsedBody()['type'] ?? $this->request->getQueryParams()['type'] ?? null) {
            $pageType = $this->request->getParsedBody()['type'] ?? $this->request->getQueryParams()['type'] ?? null;
        } elseif ($GLOBALS['TSFE']->getPageArguments()->getPageType() > 0) {
            $pageType = $GLOBALS['TSFE']->getPageArguments()->getPageType();
        } else {
            $pageType = 0;
        }

        // get configured mime types, expected content type and template format
        $availableMimeTypes = $this->contentNegotiationService->getAvailableMimeTypes();
        $contentType = $this->contentNegotiationService->getContentType();
        $format = $this->contentNegotiationService->getFormat();

        // if iri argument exists try to set resource
        if ($this->request->hasArgument('iri')) {
            $this->resource = $this->iriRepository->findByValue(
                $this->request->getArgument('iri'),
                'show'
            );
        }

        // Get an instance of NormalizedParams, which provides normalized server
        // parameters and substitutes GeneralUtility::getIndpEnv().
        $normalizedParams = $this->request->getAttribute('normalizedParams');

        // set environment
        $environment = [
            'TYPO3_SITE_BASE_URL' => rtrim($normalizedParams->getSiteUrl(), '/'),
            'TYPO3_REQUEST_URL' => $normalizedParams->getRequestUrl(),
            'TSFE' => ['pageArguments' => $GLOBALS['TSFE']->pageArguments, 'page' => $GLOBALS['TSFE']->page],
        ];

        // prepare response
        $this->response = $this->responseFactory->createResponse();
        if (array_key_exists($pageType, $availableMimeTypes)) {
            $this->response = $this->response->withAddedHeader('Content-Type', $availableMimeTypes[$pageType] . '; charset=utf-8');
        }

        // hydra link headers (@see: https://www.hydra-cg.com/spec/latest/core/#example-16-discovering-hydra-api-documentation-documents)
        if (is_array($this->settings['apiDocumentation']['keys'])) {
            if (array_key_exists($GLOBALS['TSFE']->id, $this->settings['apiDocumentation']['keys'])) {
                $apiDocumentationKey = $this->settings['apiDocumentation']['keys'][$GLOBALS['TSFE']->id];
            } else {
                $apiDocumentationKey = $this->settings['apiDocumentation']['keys'][0];
            }

            $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
            $uri = $uriBuilder
              ->reset()
              ->setTargetPageUid($GLOBALS['TSFE']->id)
              ->setArguments(['type' => '2014'])
              ->uriFor('about', ['apiDocumentation' => $apiDocumentationKey], 'Api', 'lod', 'api');
            $apiDocumentationPath = preg_replace('/(\?|\&)(cHash)(.*)$/', '', $uri);

            $this->response = $this->response->withAddedHeader('Access-Control-Allow-Origin', $this->settings['general']['CORS']['accessControlAllowOrigin'])
              ->withAddedHeader('Access-Control-Allow-Methods', $this->settings['general']['CORS']['accessControlAllowMethods'])
              ->withAddedHeader('Access-Control-Allow-Headers', $this->settings['general']['CORS']['accessControlAllowHeaders'])
              ->withAddedHeader('Access-Control-Expose-Headers', $this->settings['general']['CORS']['accessControlExposeHeaders'])
              ->withAddedHeader('Link', '<' . $environment['TYPO3_SITE_BASE_URL'] . $apiDocumentationPath . '>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        }

        // hydra JSON-LD entry point
        if (str_ends_with($normalizedParams->getRequestUri(), '/.json')) {
            $arguments = $this->request->getArguments();
            unset($arguments['iri']);
            $arguments['apiEntryPoint'] = 1;
            $this->request = $this->request->withArguments($arguments);
        }

        // if page type is set define Fluid template format directly
        if ($pageType > 0) {
            $this->request = $this->request->withFormat($format);

            // if not redirect to URL including a negotiated page type
        } else {
            // make sure request url does not end in a slash
            $requestUrl = $GLOBALS['TYPO3_REQUEST']->getAttributes()['normalizedParams']->getRequestUri();
            $cleanRequestUrl = rtrim($requestUrl, '/');

            // get current site configuration
            if (method_exists($GLOBALS['TYPO3_REQUEST']->getAttributes()['site'], 'getConfiguration')) {
                $siteConfiguration = $GLOBALS['TYPO3_REQUEST']->getAttributes()['site']->getConfiguration();
            } else {
                $siteConfiguration = [];
            }

            // get target page type via content negotiation
            $targetPageType = array_search($contentType, $availableMimeTypes);

            // generate url for redirection if routeEnhancers are configured
            if (
                array_key_exists('routeEnhancers', $siteConfiguration) &&
                array_key_exists('PageTypeSuffix', $siteConfiguration['routeEnhancers']) &&
                array_key_exists('map', $siteConfiguration['routeEnhancers']['PageTypeSuffix']) &&
                in_array($targetPageType, $siteConfiguration['routeEnhancers']['PageTypeSuffix']['map'])
            ) {
                $targetPageTypeSuffix = array_search($targetPageType, $siteConfiguration['routeEnhancers']['PageTypeSuffix']['map']);

                // possible tx_lod parameters from search or ld fragments
                if (preg_match('/\?/', $cleanRequestUrl)) {
                    $uriParts = GeneralUtility::trimExplode('?', $cleanRequestUrl);
                    $uri = $uriParts[0] . $targetPageTypeSuffix . '?' . $uriParts[1];
                } else {
                    $uri = $cleanRequestUrl . $targetPageTypeSuffix;
                }

                // parameterized URI (no configured routeEnhancers)
            } else {
                if (preg_match('/\?/', $cleanRequestUrl)) {
                    $typeParameterKeyword = '&type=';
                } else {
                    $typeParameterKeyword = '?type=';
                }
                $uri = $cleanRequestUrl . $typeParameterKeyword . $targetPageType;
            }

            // if called from BE without parameters and with routeEnhancers remove duplicate file endings
            if (substr_count($uri, '.html/') >= 1) {
                $uri = str_replace('.html/', '/', $uri);
            }

            // if dedicated representations for the resource are available go through each of them and
            // check if accepted media type fits representation content type; if so call according resolver
            if ($this->resource instanceof Iri && count($this->resource->getRepresentations()) > 0) {
                foreach ($this->contentNegotiationService->getAcceptedMimeTypes() as $mimeType) {
                    foreach ($this->resource->getRepresentations() as $key => $representation) {
                        $representationContentType = $this->contentNegotiationService->processContentType($representation->getContentType());
                        if ($representationContentType['mime'] == $mimeType && $representationContentType['mime'] == $contentType) {
                            // call representation resolver service
                            $url = $this->resolverService->resolve($representation, $this->settings['resolver']);
                            if (GeneralUtility::isValidUrl($url)) {
                                return $this->redirectToUri($url);
                            }
                        }
                    }
                    // if none of the representations fit redirect to a generated representation
                    if ($mimeType == $contentType) {
                        return $this->redirectToUri($uri);
                    }
                }
                // otherwise redirect to a generated about representation
            } else {
                return $this->redirectToUri($uri);
            }
        }

        // general assignments for all sub actions

        // assign current arguments
        $this->view->assign('arguments', $this->request->getArguments());

        // assign settings
        $this->view->assign('settings', $this->settings);

        // assign environment vars
        $this->view->assign('environment', $environment);

        // execute sub actions
        // show action
        if ($this->request->hasArgument('iri')) {
            // if the resource exist, forward to show action, else send 404
            if ($this->resource != null && $this->resource instanceof Iri) {
                $this->showAction($this->resource);
            } else {
                // throw PSR-7 compliant error response
                $response = GeneralUtility::makeInstance(ErrorController::class)->pageNotFoundAction(
                    $GLOBALS['TYPO3_REQUEST'],
                    'The requested page does not exist',
                    ['code' => PageAccessFailureReasons::PAGE_NOT_FOUND]
                );
                throw new ImmediateResponseException($response, 2467342644);
            }
            // api documentation action
        } elseif ($this->request->hasArgument('apiDocumentation')) {
            $this->apiDocumentationAction();
            // api entrypoint action
        } elseif ($this->request->hasArgument('apiEntryPoint')) {
            $this->apiEntryPointAction();
            // list action
        } else {
            $this->listAction();
        }

        // return PSR-7/PSR-17 compliant response
        return $this->response->withBody($this->streamFactory->createStream($this->view->render()));
    }

    /**
     * Returns list of resources in different content types / document representations
     */
    private function listAction(): void
    {
        $arguments = $this->request->getArguments();

        // calculate pagination
        ($arguments['limit']) ? $limit = (int)$arguments['limit'] : $limit = 50;
        if ($limit > 500) {
            $limit = 500;
        }

        if ($arguments['query'] || $arguments['subject'] || $arguments['predicate'] || $arguments['object']) {
            $totalItems = $this->iriRepository->findByArguments($arguments, $this->settings)->count();
            $findMethod = 'findByArguments';
        } else {
            $totalItems = $this->iriRepository->countAll();
            $findMethod = 'findAll';
        }

        $totalPages = (int)ceil($totalItems / $limit);
        if ($totalPages < 1) {
            $totalPages = 1;
        }

        if ($arguments['page']) {
            ($arguments['page'] <= $totalPages) ? $page = (int)$arguments['page'] : $page = $totalPages;
        } else {
            $page = 1;
            $this->request = $this->request->withArgument('page', 0);
        }

        $offset = ($page - 1) * $limit;

        // determine result order
        ($arguments['sorting']) ? $sorting = (int)$arguments['sorting'] : $sorting = 1;
        switch ($sorting) {
            case 1:
            default:
                $orderings = ['value' => QueryInterface::ORDER_ASCENDING];
                break;
            case 2:
                $orderings = ['value' => QueryInterface::ORDER_DESCENDING];
                break;
            case 3:
                $orderings = ['label' => QueryInterface::ORDER_ASCENDING];
                break;
            case 4:
                $orderings = ['label' => QueryInterface::ORDER_DESCENDING];
                break;
        }

        // fetch resources (possibly from a specific graph)
        $resources = $this->iriRepository->$findMethod($arguments, $this->settings)
            ->getQuery()
            ->setOffset($offset)
            ->setLimit($limit)
            ->setOrderings($orderings)
            ->execute();

        // pagination
        $pagination = ['first' => 1];
        $pagination['last'] = $totalPages;
        ($page <= 1) ? $pagination['previous'] = 1 : $pagination['previous'] = $page - 1;
        ($page < $totalPages) ? $pagination['next'] = $page + 1 : $pagination['next'] = $totalPages;

        $this->view->assign('action', 'list');

        $this->view->assign('totalItems', $totalItems);

        $this->view->assign('pagination', $pagination);

        $this->view->assign('resources', $resources);

        $this->view->assign('iriNamespaces', $this->iriNamespaceRepository->findSelected('show', $this->settings));
    }

    /**
     * Returns a single resource in different content types / document representations
     *
     * @param \Digicademy\Lod\Domain\Model\Iri $resource
     * @throws \TYPO3\CMS\Extbase\Exception
     */
    private function showAction(
        Iri $resource
    ): void {
        // assign current action for disambiguation in about template
        $this->view->assign('action', 'show');

        // assign the resource
        $this->view->assign('resource', $resource);

        // assign graph if IRI is a graph IRI
        $this->view->assign('graph', $this->graphRepository->findByIri($resource));

        // assign namespaces
        $this->view->assign('iriNamespaces', $this->iriNamespaceRepository->findSelected('show', $this->settings));
    }

    /**
     * Returns a Hydra API Documentation
     */
    private function apiDocumentationAction(): void
    {
        // if no valid API documentation key is given or format is not JSON-LD return 404
        $apiDocumentationKey = $this->request->getArgument('apiDocumentation');

        if (!in_array($apiDocumentationKey, $this->settings['apiDocumentation']['keys']) || $this->request->getFormat() != 'jsonld') {
            $response = GeneralUtility::makeInstance(ErrorController::class)->pageNotFoundAction(
                $GLOBALS['TYPO3_REQUEST'],
                'The requested page does not exist',
                ['code' => PageAccessFailureReasons::PAGE_NOT_FOUND]
            );
            throw new ImmediateResponseException($response, 4215392081);
        }

        // assign current action for disambiguation in about template
        $this->view->assign('action', 'apiDocumentation');
    }

    /**
     * Returns a Hydra API entry point
     */
    private function apiEntryPointAction(): void
    {
        // assign current action for disambiguation in about template
        $this->view->assign('action', 'apiEntryPoint');
    }
}
