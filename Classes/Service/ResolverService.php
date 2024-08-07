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

use TYPO3\CMS\Extbase\Object\Container\Container;
use Digicademy\Lod\Domain\Model\Representation;

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
    public function __construct(protected readonly Container $container)
    {
        $this->availableResolvers = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['lod']['resolver'];
    }

    /**
     * @param Representation $representation
     * @param array $settings
     *
     * @return string
     */
     public function resolve(
        Representation $representation,
        array $settings
    ): string
    {
        $url = '';
        $scheme = $representation->getScheme();

        if ($this->availableResolvers[$scheme]) {
            $resolver = $this->container->getInstance(
                $this->availableResolvers[$scheme],
                $settings[$scheme]
            );
            $url = $resolver->resolveToUrl($representation);
        }

        return $url;
     }
}
