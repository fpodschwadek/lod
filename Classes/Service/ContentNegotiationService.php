<?php

declare(strict_types=1);

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

namespace Digicademy\Lod\Service;

use Digicademy\Lod\Dto\ContentNegotiationResult;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Provides content negotiation between the MIME types a client accepts and the document
 * representations this installation offers.
 *
 * The available representations are declared in TypoScript as
 * `plugin.tx_lod.settings.contentNegotiation`, next to the PAGE object that renders them:
 *
 *     plugin.tx_lod.settings.contentNegotiation {
 *       default = 1991
 *       types {
 *         1991 {
 *           mimeType = text/html
 *           format = html
 *         }
 *       }
 *     }
 *
 * Up to TYPO3 v12 this map was read from the `types.` entry of the frontend TypoScript setup
 * array, which `TemplateService::generateConfig()` derived from the registered PAGE objects.
 * `TemplateService` was removed in TYPO3 v13 (#100963) and nothing recreates `types.`, so the
 * map is now declared explicitly. That also decouples the Fluid format from the PAGE object's
 * TypoScript variable name, which used to double as the format by convention.
 *
 * This service is stateless: everything it needs arrives as a method argument. It must not hold
 * the request, both because it is a shared service and because request-bound services do not
 * survive into TYPO3 v14.
 */
class ContentNegotiationService
{
    /**
     * MIME type assumed when a client sends no usable Accept header
     */
    public const DEFAULT_MIME_TYPE = 'text/html';

    /**
     * Fluid format assumed when no representation could be negotiated
     */
    public const DEFAULT_FORMAT = 'html';

    /**
     * Negotiates the document representation to serve for the given request.
     *
     * @param ServerRequestInterface $request  Current request; supplies the Accept header and the page type
     * @param array                  $settings The `contentNegotiation` settings sub array
     */
    public function negotiate(ServerRequestInterface $request, array $settings): ContentNegotiationResult
    {
        $types = $this->getConfiguredTypes($settings);
        $requestedPageType = $this->getRequestedPageType($request);
        $requestedMimeType = $types[$requestedPageType]['mimeType'] ?? null;

        // the request already names a representation, so there is nothing to negotiate
        if ($requestedPageType > 0) {
            return new ContentNegotiationResult(
                $requestedPageType,
                $requestedPageType,
                $requestedMimeType ?? self::DEFAULT_MIME_TYPE,
                $types[$requestedPageType]['format'] ?? self::DEFAULT_FORMAT,
                $requestedMimeType
            );
        }

        // the abstract resource was requested: pick the best representation the client accepts
        $availableMimeTypes = $this->getMimeTypeMap($types);
        foreach ($this->getAcceptedMimeTypes($request) as $mimeType) {
            $pageType = array_search($mimeType, $availableMimeTypes, true);
            if ($pageType !== false) {
                return new ContentNegotiationResult(
                    0,
                    (int)$pageType,
                    $types[$pageType]['mimeType'],
                    $types[$pageType]['format'],
                    $requestedMimeType
                );
            }
        }

        // nothing the client asked for is on offer: fall back to the configured default
        $fallbackPageType = $this->getFallbackPageType($settings, $types);
        if ($fallbackPageType > 0) {
            return new ContentNegotiationResult(
                0,
                $fallbackPageType,
                $types[$fallbackPageType]['mimeType'],
                $types[$fallbackPageType]['format'],
                $requestedMimeType
            );
        }

        // no representation is configured at all; the caller has to render the request as it is
        return new ContentNegotiationResult(0, 0, self::DEFAULT_MIME_TYPE, self::DEFAULT_FORMAT, $requestedMimeType);
    }

    /**
     * Compiles the MIME types a client accepts, best match first.
     *
     * @return string[]
     */
    public function getAcceptedMimeTypes(ServerRequestInterface $request): array
    {
        $acceptHeader = $request->getHeaderLine('Accept');
        if ($acceptHeader === '') {
            return [self::DEFAULT_MIME_TYPE];
        }

        $mediaRanges = [];
        foreach (GeneralUtility::trimExplode(',', $acceptHeader, true) as $mediaRange) {
            $parameters = GeneralUtility::trimExplode(';', $mediaRange, true);
            $mimeType = (string)array_shift($parameters);
            if ($mimeType === '') {
                continue;
            }
            $quality = 1.0;
            foreach ($parameters as $parameter) {
                if (str_starts_with($parameter, 'q=')) {
                    $quality = (float)substr($parameter, 2);
                }
            }
            $mediaRanges[] = ['mimeType' => $mimeType, 'quality' => $quality];
        }

        // usort() is stable as of PHP 8.0, so media ranges of equal quality keep header order
        usort($mediaRanges, static fn(array $left, array $right): int => $right['quality'] <=> $left['quality']);

        return array_column($mediaRanges, 'mimeType');
    }

    /**
     * Splits a Content-Type header value into its MIME type and charset.
     *
     * @return array{mime: string, charset?: string}
     */
    public function processContentType(string $httpContentType): array
    {
        $splitHttpContentType = GeneralUtility::trimExplode(';', $httpContentType);

        $contentType = ['mime' => $splitHttpContentType[0]];
        if (count($splitHttpContentType) === 2) {
            $contentType['charset'] = trim(str_replace('charset=', '', $splitHttpContentType[1]));
        }

        return $contentType;
    }

    /**
     * Normalises the configured representations into `[pageType => ['mimeType' => ..., 'format' => ...]]`.
     *
     * Incompletely configured entries are skipped rather than half applied, so a typo in one
     * representation cannot silently redirect clients to it.
     *
     * @return array<int, array{pageType: int, mimeType: string, format: string}>
     */
    private function getConfiguredTypes(array $settings): array
    {
        $types = [];

        foreach ((array)($settings['types'] ?? []) as $pageType => $configuration) {
            $pageType = (int)$pageType;
            $mimeType = is_array($configuration) ? trim((string)($configuration['mimeType'] ?? '')) : '';
            $format = is_array($configuration) ? trim((string)($configuration['format'] ?? '')) : '';
            if ($pageType <= 0 || $mimeType === '' || $format === '') {
                continue;
            }
            $types[$pageType] = ['pageType' => $pageType, 'mimeType' => $mimeType, 'format' => $format];
        }

        return $types;
    }

    /**
     * Maps the configured representations to `[pageType => mimeType]`.
     *
     * The `pageType` index key is what keeps the page types as array keys; array_column() would
     * otherwise renumber them and turn every lookup into a meaningless positional index.
     *
     * @param array<int, array{pageType: int, mimeType: string, format: string}> $types
     * @return array<int, string>
     */
    private function getMimeTypeMap(array $types): array
    {
        return array_column($types, 'mimeType', 'pageType');
    }

    /**
     * Page type of the current request, either from the `type` query parameter or from the
     * page type the router resolved out of the URL suffix.
     */
    private function getRequestedPageType(ServerRequestInterface $request): int
    {
        $pageType = $request->getQueryParams()['type'] ?? null;
        if (!is_scalar($pageType)) {
            $pageType = $request->getAttribute('routing')?->getPageType() ?? 0;
        }

        return (int)$pageType;
    }

    /**
     * Representation to redirect to when the client accepts nothing this installation offers.
     *
     * @param array<int, array{pageType: int, mimeType: string, format: string}> $types
     */
    private function getFallbackPageType(array $settings, array $types): int
    {
        $configuredDefault = (int)($settings['default'] ?? 0);
        if (isset($types[$configuredDefault])) {
            return $configuredDefault;
        }

        // no explicit default: serve whichever representation carries the default MIME type
        $pageType = array_search(self::DEFAULT_MIME_TYPE, $this->getMimeTypeMap($types), true);

        return $pageType === false ? 0 : (int)$pageType;
    }
}
