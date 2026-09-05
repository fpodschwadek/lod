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

use Digicademy\Lod\Domain\Model\Representation;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * The resolver service resolves IRIs to URLs (by representations).
 * For each type of representation (by scheme: t3, http, https, ftp etc.)
 * a custom resolver can be registered.
 */
class ResolverService
{
    /**
     * @var array
     */
    protected $availableResolvers = [];

    /**
     * ResolverService constructor
     *
     * @todo: implement hook for resolvers from extensions
     */
    public function __construct()
    {
        $this->availableResolvers = (array)($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['lod']['resolver'] ?? []);
    }

    /**
     * @param Representation         $representation
     * @param array                  $settings
     * @param ServerRequestInterface $request Current request, forwarded to the resolver and its cObj
     * @return string
     */
    public function resolve(
        Representation $representation,
        array $settings,
        ServerRequestInterface $request
    ): string {
        $url = '';
        $scheme = $representation->getScheme();

        if ($this->availableResolvers[$scheme] ?? null) {
            // ContentObjectRenderer needs the request before it can build any link in TYPO3 v13
            $contentObjectRenderer = GeneralUtility::makeInstance(ContentObjectRenderer::class);
            $contentObjectRenderer->setRequest($request);

            $resolver = GeneralUtility::makeInstance(
                $this->availableResolvers[$scheme],
                $settings[$scheme] ?? [],
                $contentObjectRenderer,
                $request
            );
            $url = $resolver->resolveToUrl($representation);
        }

        return $url;
    }
}
