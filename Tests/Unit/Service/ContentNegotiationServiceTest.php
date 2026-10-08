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

namespace Digicademy\Lod\Tests\Unit\Service;

use Codeception\Test\Unit;
use Digicademy\Lod\Service\ContentNegotiationService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * Unit tests for the content negotiation service.
 *
 * The service is stateless: a PSR-7 request (carrying the `Accept` header, an optional `type`
 * query argument and the routing attribute) and the `contentNegotiation` settings sub array are
 * passed to {@see ContentNegotiationService::negotiate()}, which returns an immutable
 * {@see \Digicademy\Lod\Dto\ContentNegotiationResult}. No TYPO3 bootstrap is required.
 */
final class ContentNegotiationServiceTest extends Unit
{
    /**
     * A representative `contentNegotiation` settings array:
     *   - page type 1991 => text/html  (the configured default representation)
     *   - page type 2011 => text/turtle
     *   - page type 2014 => application/ld+json
     *   - page type 9998 => incomplete (no `format`) and therefore ignored
     *
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return [
            'default' => '1991',
            'types' => [
                '1991' => ['mimeType' => 'text/html', 'format' => 'html'],
                '2011' => ['mimeType' => 'text/turtle', 'format' => 'ttl'],
                '2014' => ['mimeType' => 'application/ld+json', 'format' => 'jsonld'],
                '9998' => ['mimeType' => 'application/broken'],
            ],
        ];
    }

    /**
     * Builds a PSR-7 request carrying everything the service reads.
     *
     * @param array<string, mixed> $queryParams     Query arguments (e.g. ['type' => '2011'])
     * @param string               $accept          Value of the HTTP Accept header
     * @param string|null          $routingPageType Page type reported by the routing attribute
     */
    private function makeRequest(
        array $queryParams = [],
        string $accept = '',
        ?string $routingPageType = '0'
    ): ServerRequestInterface {
        // Minimal stand-in for TYPO3\CMS\Core\Routing\PageArguments
        $routing = $routingPageType === null ? null : new class ($routingPageType) {
            public function __construct(private readonly string $pageType) {}

            public function getPageType(): string
            {
                return $this->pageType;
            }
        };

        $request = (new ServerRequest('https://agate.local/', 'GET'))
            ->withAttribute('routing', $routing)
            ->withQueryParams($queryParams);

        if ($accept !== '') {
            $request = $request->withHeader('Accept', $accept);
        }

        return $request;
    }

    /**
     * An explicit `type` query argument names a representation directly: no negotiation happens,
     * the result mirrors that page type and reports that no redirect is needed.
     */
    public function testServesExplicitPageTypeFromQueryArgument(): void
    {
        $result = (new ContentNegotiationService())->negotiate(
            $this->makeRequest(['type' => '2011'], 'text/html'),
            $this->settings()
        );

        $this->assertSame(2011, $result->requestedPageType);
        $this->assertSame(2011, $result->pageType);
        $this->assertSame('text/turtle', $result->mimeType);
        $this->assertSame('ttl', $result->format);
        $this->assertTrue($result->hasExplicitPageType());
        $this->assertFalse($result->isRedirectRequired());
    }

    /**
     * With no `type` query argument, the page type resolved by routing is used.
     */
    public function testFallsBackToRoutingPageType(): void
    {
        $result = (new ContentNegotiationService())->negotiate(
            $this->makeRequest([], '', '2011'),
            $this->settings()
        );

        $this->assertSame(2011, $result->requestedPageType);
        $this->assertSame('ttl', $result->format);
        $this->assertTrue($result->hasExplicitPageType());
    }

    /**
     * When no page type is pre-selected, the best available representation is negotiated from the
     * client's Accept header, and the result asks for a redirect to it.
     *
     * @dataProvider acceptHeaderProvider
     */
    public function testNegotiatesRepresentationFromAcceptHeader(
        string $accept,
        int $expectedPageType,
        string $expectedMimeType,
        string $expectedFormat
    ): void {
        $result = (new ContentNegotiationService())->negotiate(
            $this->makeRequest([], $accept),
            $this->settings()
        );

        $this->assertSame(0, $result->requestedPageType);
        $this->assertSame($expectedPageType, $result->pageType);
        $this->assertSame($expectedMimeType, $result->mimeType);
        $this->assertSame($expectedFormat, $result->format);
        $this->assertTrue($result->isRedirectRequired());
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string, 3: string}>
     */
    public function acceptHeaderProvider(): array
    {
        return [
            'single turtle' => ['text/turtle', 2011, 'text/turtle', 'ttl'],
            'single json-ld' => ['application/ld+json', 2014, 'application/ld+json', 'jsonld'],
            'quality weighting prefers turtle' => [
                'application/ld+json;q=0.5, text/turtle;q=0.9',
                2011,
                'text/turtle',
                'ttl',
            ],
        ];
    }

    /**
     * When the client accepts nothing on offer, the configured `default` representation is served.
     */
    public function testFallsBackToConfiguredDefaultWhenNothingAccepted(): void
    {
        $result = (new ContentNegotiationService())->negotiate(
            $this->makeRequest([], 'application/xml'),
            $this->settings()
        );

        $this->assertSame(1991, $result->pageType);
        $this->assertSame('text/html', $result->mimeType);
        $this->assertSame('html', $result->format);
    }

    /**
     * With no Accept header the client is treated as accepting text/html, which maps to the
     * configured HTML representation.
     */
    public function testDefaultsToHtmlRepresentationWhenNoAcceptHeader(): void
    {
        $result = (new ContentNegotiationService())->negotiate(
            $this->makeRequest(),
            $this->settings()
        );

        $this->assertSame(1991, $result->pageType);
        $this->assertSame('text/html', $result->mimeType);
    }

    /**
     * Regression guard (the v13 analogue of guarding the old `types.`/`additionalHeaders.` lookup):
     * an incompletely configured representation — here page type 9998, which has a `mimeType` but
     * no `format` — must be ignored rather than half applied, and resolving a request for it must
     * not raise a PHP warning. A temporary error handler turns any warning/notice into a failure,
     * so the test is independent of the runner's warning configuration.
     */
    public function testSkipsIncompletelyConfiguredTypesWithoutWarning(): void
    {
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $result = (new ContentNegotiationService())->negotiate(
                $this->makeRequest(['type' => '9998']),
                $this->settings()
            );
        } finally {
            restore_error_handler();
        }

        // 9998 is requested but unconfigured (skipped), so it carries no representation of its own
        $this->assertSame(9998, $result->requestedPageType);
        $this->assertNull($result->requestedMimeType);
        $this->assertSame(ContentNegotiationService::DEFAULT_MIME_TYPE, $result->mimeType);
        $this->assertSame(ContentNegotiationService::DEFAULT_FORMAT, $result->format);
    }

    /**
     * The Accept header is parsed into MIME types ordered by quality, highest first, with header
     * order preserved among equal qualities.
     */
    public function testParsesAcceptHeaderByQuality(): void
    {
        $accepted = (new ContentNegotiationService())->getAcceptedMimeTypes(
            $this->makeRequest([], 'application/ld+json;q=0.5, text/turtle;q=0.9, text/html')
        );

        $this->assertSame(['text/html', 'text/turtle', 'application/ld+json'], $accepted);
    }

    /**
     * A Content-Type header value is split into its MIME type and optional charset.
     */
    public function testProcessContentTypeSplitsMimeAndCharset(): void
    {
        $service = new ContentNegotiationService();

        $this->assertSame(['mime' => 'text/turtle'], $service->processContentType('text/turtle'));
        $this->assertSame(
            ['mime' => 'text/turtle', 'charset' => 'utf-8'],
            $service->processContentType('text/turtle; charset=utf-8')
        );
    }
}
