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

namespace Digicademy\Lod\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Provides content negotiation based on ACCEPT header and TYPO3 page type
 */
class ContentNegotiationService
{
    /**
     * MIME types accepted by the client, ordered best to least preferred
     *
     * @var array<int, string>
     */
    protected array $acceptedMimeTypes = [];

    /**
     * MIME types available on the server, keyed by configured TYPO3 page type
     *
     * @var array<int, string>
     */
    protected array $availableMimeTypes = [];

    /**
     * Negotiated content type (defaults to text/html)
     */
    protected string $contentType = 'text/html';

    /**
     * Extbase format
     */
    protected string $format = 'html';

    /**
     * Frontend TypoScript setup array.
     *
     * @var array<string, mixed>
     */
    protected array $typoScriptSetup;

    /**
     * Content negotiation: Determines the best mime type for a response by negotiating
     * between mime types accepted by the client and mime types available from TypoScript.
     *
     * The current PSR-7 request is passed in by the caller so the service reads the live
     * request state (TypoScript setup, resolved page arguments, Accept header) rather than
     * relying on `$GLOBALS`; this is required because a PSR-7 request cannot be autowired
     * through the DI container.
     *
     * @param ServerRequestInterface $request The current frontend request
     */
    public function negotiate(ServerRequestInterface $request): void
    {
        $this->typoScriptSetup = $request->getAttribute('frontend.typoscript')->getSetupArray();

        // Prefer an explicit `type` query argument, otherwise fall back to the page type
        // resolved by routing (PSR-7 `routing` attribute = PageArguments), defaulting to 0.
        $pageType = $request->getQueryParams()['type']
            ?? $request->getAttribute('routing')?->getPageType()
            ?? 0;

        $this->setAcceptedMimeTypes($request);
        $this->setAvailableMimeTypes();

        // if a page type is already set, format and content type can be set directly
        if ($pageType > 0) {
            $this->setContentType($this->availableMimeTypes[$pageType]);
            $this->setFormat($this->typoScriptSetup['types.'][$pageType]);

            // if no page type is set compare accepted mime types with available mime types and set best format
            // reminder: $this->acceptedMimeTypes is in order from best to least format
        } else {
            foreach ($this->acceptedMimeTypes as $mimeType) {
                if (in_array($mimeType, $this->availableMimeTypes)) {
                    $type = array_search($mimeType, $this->availableMimeTypes);
                    if ($type == 0) {
                        continue;
                    }
                    $this->setFormat($this->typoScriptSetup['types.'][$type]);

                    $this->setContentType($this->availableMimeTypes[$type]);
                    break;
                }
            }
        }
    }

    /**
     * Getter for content type
     *
     * @return string
     */
    public function getContentType(): string
    {
        return $this->contentType;
    }

    /**
     * Setter for content type
     *
     * @param string $contentType
     */
    public function setContentType(string $contentType): void
    {
        $this->contentType = $contentType;
    }

    /**
     * Getter for format
     *
     * @return string
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Setter for format
     * @param string $format
     */
    public function setFormat(string $format): void
    {
        $this->format = $format;
    }

    /**
     * Getter for accepted mime types
     *
     * @return array<int, string>
     */
    public function getAcceptedMimeTypes(): array
    {
        return $this->acceptedMimeTypes;
    }

    /**
     * Setter for accepted mime types:
     * Compiles an array of accepted mime types from client
     *
     * @param ServerRequestInterface $request The current frontend request
     */
    public function setAcceptedMimeTypes(ServerRequestInterface $request): void
    {
        // Use PSR-7 request to get Accept header
        $httpAcceptHeader = $request->getHeaderLine('Accept');
        if ($httpAcceptHeader) {
            $this->acceptedMimeTypes = $this->processAcceptHeader($httpAcceptHeader);
        } else {
            $this->acceptedMimeTypes[] = 'text/html';
        }
    }

    /**
     * Getter for available mime types
     *
     * @return array<int, string>
     */
    public function getAvailableMimeTypes(): array
    {
        return $this->availableMimeTypes;
    }

    /**
     * Setter for available mime types:
     * Compiles available mime types by page type from TypoScript configuration
     * (header: Content-type:XY must be set in TypoScript)
     */
    public function setAvailableMimeTypes(): void
    {
        foreach ($this->typoScriptSetup['types.'] as $key => $type) {
            if ($type == 'page') {
                continue;
            }
            $type = $type . '.';
            if (
                ($this->typoScriptSetup[$type]['typeNum'] ?? null) == $key
                && !empty($this->typoScriptSetup[$type]['config.']['additionalHeaders.'])
            ) {
                $additionalHeaders = $this->typoScriptSetup[$type]['config.']['additionalHeaders.'];
                foreach ($additionalHeaders as $additionalHeader) {
                    if (preg_match('/Content-type:/', $additionalHeader['header'] ?? '')) {
                        $this->availableMimeTypes[$key] = str_replace('Content-type:', '', $additionalHeader['header']);
                    }
                }
            }
        }
    }

    /**
     * @param string $httpAcceptHeader
     * @return array<int, string>
     */
    private function processAcceptHeader(string $httpAcceptHeader): array
    {
        $acceptedMediaTypes = GeneralUtility::trimExplode(',', $httpAcceptHeader);
        $weightedMediaTypes = [];
        foreach ($acceptedMediaTypes as $key => $mediaType) {
            if (strpos($mediaType, ';q')) {
                $mediaTypeWithQFactor = GeneralUtility::trimExplode(';', $mediaType);
                $qFactor = substr($mediaTypeWithQFactor[1], 2);
                $weightedMediaTypes[$qFactor][] = $mediaTypeWithQFactor[0];
            } else {
                $weightedMediaTypes['1.0'][] = $mediaType;
            }
        }
        krsort($weightedMediaTypes);

        // call_user_func_array will interpret the top-level array keys as
        // parameter names to be passed into the array_merge. To avoid errors,
        // we make a keyless array from the values.
        $sortedHttpAcceptHeaders = call_user_func_array('array_merge', array_values($weightedMediaTypes));

        return $sortedHttpAcceptHeaders;
    }

    /**
     * @param string $httpContentType
     * @return array
     */
    public function processContentType(string $httpContentType): array
    {
        $splitHttpContentType = GeneralUtility::trimExplode(';', $httpContentType);
        if (count($splitHttpContentType) == 2) {
            $contentType['mime'] = $splitHttpContentType[0];
            $contentType['charset'] = trim(str_replace('charset=', '', $splitHttpContentType[1]));
        } else {
            $contentType['mime'] = $splitHttpContentType[0];
        }

        return $contentType;
    }
}
