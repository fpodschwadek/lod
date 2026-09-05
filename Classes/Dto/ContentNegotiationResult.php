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

namespace Digicademy\Lod\Dto;

/**
 * Immutable outcome of a content negotiation run.
 *
 * A LOD resource is requested either without a page type (`/resource/{iri}`, the abstract
 * resource) or with one (`/resource/{iri}/about.json`, a concrete document representation).
 * This object carries both sides of that distinction so that callers do not have to re-derive
 * it:
 *
 * - `requestedPageType` is what the current request actually asked for (0 = no representation),
 * - `pageType` is the representation the negotiation settled on and therefore the redirect target.
 */
final readonly class ContentNegotiationResult
{
    /**
     * @param int         $requestedPageType Page type of the current request; 0 when none was given
     * @param int         $pageType          Page type serving the negotiated representation; 0 when none is configured
     * @param string      $mimeType          MIME type of the negotiated representation
     * @param string      $format            Fluid format of the negotiated representation
     * @param string|null $requestedMimeType MIME type configured for `requestedPageType`; null when it is unconfigured
     */
    public function __construct(
        public int $requestedPageType,
        public int $pageType,
        public string $mimeType,
        public string $format,
        public ?string $requestedMimeType = null,
    ) {}

    /**
     * Whether the request already names a concrete document representation and can be rendered as is.
     */
    public function hasExplicitPageType(): bool
    {
        return $this->requestedPageType > 0;
    }

    /**
     * Whether the request asked for the abstract resource and should be redirected to a representation.
     */
    public function isRedirectRequired(): bool
    {
        return $this->requestedPageType === 0 && $this->pageType > 0;
    }
}
