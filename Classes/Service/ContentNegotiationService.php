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

use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Provides content negotiation based on ACCEPT header and TYPO3 page type
 */
class ContentNegotiationService
{
    /**
     * MIME types accepted by the client
     *
     * @var array
     */
    protected $acceptedMimeTypes = [];

    /**
     * MIME types available on the server (configured TYPO3 page types)
     *
     * @var array
     */
    protected $availableMimeTypes = [];

    /**
     * Negotiated content type (defaults to text/html)
     *
     * @var string
     */
    protected $contentType = 'text/html';

    /**
     * Extbase format
     *
     * @var string
     */
    protected $format = 'html';

    /**
     * Frontend TypoScript setup array.
     * @var array
     */
    protected $typoScriptSetup;

    /**
     * Content negotiation: Determines the best mime type for a response by negotiating
     * between mime types accepted by the client and mime types available from TypoScript.
     */
    public function __construct(
        protected readonly ServerRequest $request
    ) {
        $this->typoScriptSetup = $GLOBALS['TYPO3_REQUEST']->getAttribute('frontend.typoscript')->getSetupArray();
        //To do: make sure the request is passed on to this service!

        $pageType = $request->getQueryParams()['type'] ?? $GLOBALS['TYPO3_REQUEST']->getAttribute('routing')->getPageType();

        $this->setAcceptedMimeTypes();
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
     * @return array
     */
    public function getAcceptedMimeTypes(): array
    {
        return $this->acceptedMimeTypes;
    }

    /**
     * Setter for accepted mime types:
     * Compiles an array of accepted mime types from client
     */
    public function setAcceptedMimeTypes(): void
    {
        // Use PSR-7 request to get Accept header
        $httpAcceptHeader = $this->request->getHeaderLine('Accept');
        if ($httpAcceptHeader) {
            $this->acceptedMimeTypes = $this->processAcceptHeader($httpAcceptHeader);
        } else {
            $this->acceptedMimeTypes[] = 'text/html';
        }
    }

    /**
     * Getter for available mime types
     *
     * @return array
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
                $this->typoScriptSetup[$type]['typeNum'] == $key
                && $this->typoScriptSetup[$type]['config.']['additionalHeaders.']
            ) {
                $additionalHeaders = $this->typoScriptSetup[$type]['config.']['additionalHeaders.'];
                foreach ($additionalHeaders as $additionalHeader) {
                    if (preg_match('/Content-type:/', $additionalHeader['header'])) {
                        $this->availableMimeTypes[$key] = str_replace('Content-type:', '', $additionalHeader['header']);
                    }
                }
            }
        }
    }

    /**
     * @param string $httpAcceptHeader
     * @return array
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
