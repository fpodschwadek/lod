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
use Digicademy\Lod\Dto\ContentNegotiationResult;
use Digicademy\Lod\Service\{
    ContentNegotiationService,
    ResolverService
};
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\PropagateResponseException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Frontend\Controller\ErrorController;
use TYPO3\CMS\Frontend\Page\PageAccessFailureReasons;

class ApiController extends ActionController
{
    /**
     * Resource the request is about; null until an "iri" argument has been resolved,
     * and null again when that argument does not match any known IRI.
     *
     * @var Iri|null
     */
    protected $resource;

    /**
     * @var ResponseInterface
     */
    protected $response;

    /**
     * Outcome of content negotiation for the current request
     *
     * @var ContentNegotiationResult
     */
    protected ContentNegotiationResult $negotiation;

    /**
     * Initializes the controller and dependencies
     *
     * @param IriNamespaceRepository      $iriNamespaceRepository
     * @param IriRepository               $iriRepository
     * @param GraphRepository             $graphRepository
     * @param StatementRepository         $statementRepository
     * @param ContentNegotiationService   $contentNegotiationService
     * @param ResolverService             $resolverService
     * @param UriBuilder                  $uriBuilder
     * @param ErrorController             $errorController
     */
    public function __construct(
        protected IriNamespaceRepository $iriNamespaceRepository,
        protected IriRepository $iriRepository,
        protected GraphRepository $graphRepository,
        protected StatementRepository $statementRepository,
        protected ContentNegotiationService $contentNegotiationService,
        protected ResolverService $resolverService,
        protected UriBuilder $uriBuilder,
        protected ErrorController $errorController
    ) {}

    /**
     * Negotiates the document representation and pins the Fluid format for this request.
     *
     * This has to happen here rather than in the action: `ActionController::processRequest()` calls
     * `initializeAction()` first but resolves the view -- and with it the template, its format and
     * its file extension -- before the action body runs, so `withFormat()` inside an action is too
     * late to influence template resolution. Up to TYPO3 v12 the format was pinned by setting
     * `plugin.tx_lod.format` per page type from a TypoScript condition, which Extbase reads as the
     * request's default format (`RequestBuilderDefaultValues::fromConfiguration()`). Deriving it
     * from the negotiation instead keeps format and negotiated representation from disagreeing, and
     * removes the last page type condition from this extension's TypoScript.
     */
    public function initializeAction(): void
    {
        $this->negotiation = $this->contentNegotiationService->negotiate(
            $this->request,
            (array)($this->settings['contentNegotiation'] ?? [])
        );

        $this->request = $this->request->withFormat($this->negotiation->format);
    }

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
        $pageInformation = $this->request->getAttribute('frontend.page.information');

        // Get an instance of NormalizedParams, which provides normalized server
        // parameters and substitutes GeneralUtility::getIndpEnv().
        $normalizedParams = $this->request->getAttribute('normalizedParams');

        // the representation to serve was negotiated in initializeAction(); the result knows both
        // the page type the request asked for and the page type the negotiation settled on
        $negotiation = $this->negotiation;

        // if iri argument exists try to set resource
        if ($this->request->hasArgument('iri')) {
            $this->resource = $this->iriRepository->findByValue(
                $this->request->getArgument('iri'),
                'show'
            );
        }

        // set environment
        $environment = [
            'TYPO3_SITE_BASE_URL' => rtrim($normalizedParams->getSiteUrl(), '/'),
            'TYPO3_REQUEST_URL' => $normalizedParams->getRequestUrl(),
            'pageArguments' => $this->request->getAttribute('routing'),
            'page' => $pageInformation->getPageRecord(),
        ];

        // prepare response
        $this->response = $this->responseFactory->createResponse();
        if ($negotiation->requestedMimeType !== null) {
            $this->response = $this->response->withHeader('Content-Type', $negotiation->requestedMimeType . '; charset=utf-8');
        }

        // hydra link headers (@see: https://www.hydra-cg.com/spec/latest/core/#example-16-discovering-hydra-api-documentation-documents)
        if (is_array($this->settings['apiDocumentation']['keys'] ?? null)) {
            if (array_key_exists($pageInformation->getId(), $this->settings['apiDocumentation']['keys'])) {
                $apiDocumentationKey = $this->settings['apiDocumentation']['keys'][$pageInformation->getId()];
            } else {
                $apiDocumentationKey = $this->settings['apiDocumentation']['keys'][0];
            }

            $uri = $this->uriBuilder
              ->reset()
              ->setTargetPageUid($pageInformation->getId())
              ->setTargetPageType($this->getApiDocumentationPageType())
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

        // the abstract resource was requested rather than one of its document representations:
        // redirect to the representation content negotiation picked
        $redirect = $this->redirectToNegotiatedRepresentation($negotiation, $pageInformation->getId(), $normalizedParams);
        if ($redirect instanceof ResponseInterface) {
            return $redirect;
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
            if ($this->resource instanceof Iri) {
                $this->showAction($this->resource);
            } else {
                // throw PSR-7 compliant error response
                throw new PropagateResponseException($this->pageNotFound(), 2467342644);
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
        $limit = (int)($arguments['limit'] ?? 0) ?: 50;
        if ($limit > 500) {
            $limit = 500;
        }

        if (($arguments['query'] ?? null) || ($arguments['subject'] ?? null) || ($arguments['predicate'] ?? null) || ($arguments['object'] ?? null)) {
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

        if ($arguments['page'] ?? null) {
            ($arguments['page'] <= $totalPages) ? $page = (int)$arguments['page'] : $page = $totalPages;
        } else {
            $page = 1;
            $this->request = $this->request->withArgument('page', 0);
        }

        $offset = ($page - 1) * $limit;

        // determine result order
        $sorting = (int)($arguments['sorting'] ?? 0) ?: 1;
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
            throw new PropagateResponseException($this->pageNotFound(), 4215392081);
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

    /**
     * Builds the redirect to the document representation content negotiation settled on.
     *
     * Only requests for the abstract resource (no page type in the URL) are redirected. The URL is
     * generated by the router, so whatever the site configuration maps the target page type to --
     * a `PageTypeSuffix` route enhancer, a plain `type` parameter -- is applied automatically.
     * Building the URL by hand used to require this method to read the site configuration's route
     * enhancers and to strip duplicate file endings afterwards.
     *
     * @return ResponseInterface|null A redirect response, or null when the request should be rendered
     */
    private function redirectToNegotiatedRepresentation(
        ContentNegotiationResult $negotiation,
        int $pageUid,
        NormalizedParams $normalizedParams
    ): ?ResponseInterface {
        if (!$negotiation->isRedirectRequired()) {
            return null;
        }

        $uri = $this->buildRepresentationUri($negotiation->pageType, $pageUid);

        // never redirect onto the URL that is being served already: a representation whose URL
        // cannot be told apart from the resource URL has to be rendered instead of looping
        if ($uri === '' || $this->isCurrentRequestUri($uri, $normalizedParams)) {
            return null;
        }

        // if dedicated representations for the resource are available go through each of them and
        // check if accepted media type fits representation content type; if so call according resolver
        if ($this->resource instanceof Iri && count($this->resource->getRepresentations()) > 0) {
            foreach ($this->contentNegotiationService->getAcceptedMimeTypes($this->request) as $mimeType) {
                foreach ($this->resource->getRepresentations() as $representation) {
                    $representationContentType = $this->contentNegotiationService->processContentType($representation->getContentType());
                    if ($representationContentType['mime'] === $mimeType && $representationContentType['mime'] === $negotiation->mimeType) {
                        // call representation resolver service
                        $url = $this->resolverService->resolve($representation, (array)($this->settings['resolver'] ?? []), $this->request);
                        if (GeneralUtility::isValidUrl($url)) {
                            return $this->redirectToUri($url);
                        }
                    }
                }
                // if none of the representations fit redirect to a generated representation
                if ($mimeType === $negotiation->mimeType) {
                    return $this->redirectToUri($uri);
                }
            }

            return null;
        }

        // otherwise redirect to a generated about representation
        return $this->redirectToUri($uri);
    }

    /**
     * Generates the URL of a document representation of the current resource, carrying the current
     * plugin arguments over so that search and paging state survives the redirect.
     */
    private function buildRepresentationUri(int $pageType, int $pageUid): string
    {
        $arguments = $this->request->getArguments();

        // controller, action and page type are expressed by the route itself
        unset($arguments['controller'], $arguments['action'], $arguments['type']);

        $uriBuilder = $this->uriBuilder
            ->reset()
            ->setTargetPageUid($pageUid)
            ->setTargetPageType($pageType)
            ->setCreateAbsoluteUri(true);

        // Without plugin arguments there is nothing for the Extbase route enhancer to encode. Asking
        // it for a controller/action route it cannot build would push both into the query string
        // instead; the plain page URL is what the enhancer's defaultController resolves back to.
        if ($arguments === []) {
            return $uriBuilder->build();
        }

        return $uriBuilder->uriFor('about', $arguments, 'Api', 'lod', 'api');
    }

    /**
     * Whether the given URL addresses the path that is currently being requested.
     */
    private function isCurrentRequestUri(string $uri, NormalizedParams $normalizedParams): bool
    {
        $targetPath = (string)(parse_url($uri, PHP_URL_PATH) ?: '');
        $currentPath = (string)(parse_url($normalizedParams->getRequestUri(), PHP_URL_PATH) ?: '');

        return $targetPath !== '' && rtrim($targetPath, '/') === rtrim($currentPath, '/');
    }

    /**
     * Page type serving the Hydra API documentation, which the Hydra spec requires to be JSON-LD.
     */
    private function getApiDocumentationPageType(): int
    {
        return (int)($this->settings['contentNegotiation']['apiDocumentationPageType'] ?? 0);
    }

    /**
     * Builds a PSR-7 compliant 404 response through the site's configured error handling.
     */
    private function pageNotFound(): ResponseInterface
    {
        return $this->errorController->pageNotFoundAction(
            $this->request,
            'The requested page does not exist',
            ['code' => PageAccessFailureReasons::PAGE_NOT_FOUND]
        );
    }
}
