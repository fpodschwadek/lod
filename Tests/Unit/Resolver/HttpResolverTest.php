<?php

declare(strict_types=1);

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2026 Frodo Podschwadek <frodo.podschwadek@adwmainz.de>
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

namespace Tests\Unit\Resolver;

use Codeception\Test\Unit;
use Digicademy\Lod\Domain\Model\Representation;
use Digicademy\Lod\Resolver\HttpResolver;
use Digicademy\Lod\Resolver\HttpsResolver;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Error\Exception;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * Unit tests for the resolvers of http:// and https:// representations, whose result is the
 * redirect target of content negotiation.
 */
final class HttpResolverTest extends Unit
{
    private function representation(string $scheme, string $authority, string $path = '', string $query = '', string $fragment = ''): Representation
    {
        $representation = new Representation();
        $representation->setScheme($scheme);
        $representation->setAuthority($authority);
        $representation->setPath($path);
        $representation->setQuery($query);
        $representation->setFragment($fragment);

        return $representation;
    }

    private function resolver(string $class = HttpResolver::class): HttpResolver
    {
        return new $class([], $this->createStub(ContentObjectRenderer::class), new ServerRequest('https://example.org/'));
    }

    public function testJoinsTheUrlParts(): void
    {
        $this->assertSame(
            'https://example.org/about/people.html?id=7#bio',
            $this->resolver()->resolveToUrl($this->representation('https', 'example.org', '/about/people.html', '?id=7', '#bio'))
        );
    }

    public function testHttpsResolverBehavesLikeTheHttpResolver(): void
    {
        $this->assertSame(
            'http://example.org/',
            $this->resolver(HttpsResolver::class)->resolveToUrl($this->representation('http', 'example.org', '/'))
        );
    }

    public function testThrowsForAnInvalidUrl(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(1555043405);

        $this->resolver()->resolveToUrl($this->representation('https', 'exa mple.org', '/'));
    }
}
