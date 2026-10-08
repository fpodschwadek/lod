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
 * The service is exercised purely through its public API: a PSR-7 request carrying the
 * frontend TypoScript setup (`frontend.typoscript` attribute), the resolved page arguments
 * (`routing` attribute), an optional `type` query argument and an `Accept` header are passed
 * to {@see ContentNegotiationService::negotiate()}, and the negotiated result is read back
 * from the getters. No TYPO3 bootstrap is required.
 */
final class ContentNegotiationServiceTest extends Unit
{
    /**
     * A representative frontend TypoScript `types` configuration:
     *   - page type 0   => `page`   (the default HTML type, always skipped)
     *   - page type 100 => `jsonld` (serves application/ld+json)
     *   - page type 101 => `turtle` (serves text/turtle)
     *   - page type 102 => `broken` (no `additionalHeaders.` — must be skipped silently)
     *   - page type 103 => `orphan` (no matching top-level object — must be skipped silently)
     *
     * @return array<string, mixed>
     */
    private function typoScriptSetup(): array
    {
        return [
            'types.' => [
                0 => 'page',
                100 => 'jsonld',
                101 => 'turtle',
                102 => 'broken',
                103 => 'orphan',
            ],
            'jsonld.' => [
                'typeNum' => 100,
                'config.' => [
                    'additionalHeaders.' => [
                        '10.' => ['header' => 'Content-type:application/ld+json'],
                    ],
                ],
            ],
            'turtle.' => [
                'typeNum' => 101,
                'config.' => [
                    'additionalHeaders.' => [
                        '10.' => ['header' => 'Content-type:text/turtle'],
                    ],
                ],
            ],
            // page type 102: has a config block but no additionalHeaders — regression guard
            'broken.' => [
                'typeNum' => 102,
                'config.' => [],
            ],
            // page type 103 ('orphan') deliberately has no `orphan.` object at all
        ];
    }

    /**
     * Builds a PSR-7 request carrying everything the service reads.
     *
     * @param array<string, mixed>   $setup           Frontend TypoScript setup array
     * @param array<string, mixed>   $queryParams     Query arguments (e.g. ['type' => '100'])
     * @param string                 $accept          Value of the HTTP Accept header
     * @param string|null            $routingPageType Page type reported by the routing attribute
     */
    private function makeRequest(
        array $setup,
        array $queryParams = [],
        string $accept = '',
        ?string $routingPageType = '0'
    ): ServerRequestInterface {
        // Minimal stand-in for TYPO3\CMS\Core\TypoScript\FrontendTypoScript
        $frontendTypoScript = new class ($setup) {
            /** @param array<string, mixed> $setup */
            public function __construct(private readonly array $setup) {}

            /** @return array<string, mixed> */
            public function getSetupArray(): array
            {
                return $this->setup;
            }
        };

        // Minimal stand-in for TYPO3\CMS\Core\Routing\PageArguments
        $routing = $routingPageType === null ? null : new class ($routingPageType) {
            public function __construct(private readonly string $pageType) {}

            public function getPageType(): string
            {
                return $this->pageType;
            }
        };

        $request = (new ServerRequest('https://agate.local/', 'GET'))
            ->withAttribute('frontend.typoscript', $frontendTypoScript)
            ->withAttribute('routing', $routing)
            ->withQueryParams($queryParams);

        if ($accept !== '') {
            $request = $request->withHeader('Accept', $accept);
        }

        return $request;
    }

    /**
     * Available mime types are compiled from every `types` entry that declares a
     * `Content-type:` additional header, keyed by the page type number.
     */
    public function testCompilesAvailableMimeTypesFromTypoScript(): void
    {
        $service = new ContentNegotiationService();
        $service->negotiate($this->makeRequest($this->typoScriptSetup()));

        $this->assertSame(
            [
                100 => 'application/ld+json',
                101 => 'text/turtle',
            ],
            $service->getAvailableMimeTypes()
        );
    }

    /**
     * Regression test for the PHP 8.4 "Undefined array key" warning: types whose
     * configuration lacks `additionalHeaders.` (page type 102) or whose top-level page
     * object is missing entirely (page type 103) must be skipped without raising a warning.
     *
     * A temporary error handler turns any emitted warning/notice into a failure, so this test
     * catches the bug independently of the test runner's warning configuration.
     */
    public function testSkipsTypesWithMissingHeaderConfigurationWithoutWarning(): void
    {
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $service = new ContentNegotiationService();
            $service->negotiate($this->makeRequest($this->typoScriptSetup()));
            $available = $service->getAvailableMimeTypes();
        } finally {
            restore_error_handler();
        }

        $this->assertArrayNotHasKey(102, $available);
        $this->assertArrayNotHasKey(103, $available);
    }

    /**
     * An explicit `type` query argument selects the content type and template format directly,
     * regardless of the Accept header.
     */
    public function testUsesExplicitPageTypeFromQueryArgument(): void
    {
        $service = new ContentNegotiationService();
        $service->negotiate(
            $this->makeRequest($this->typoScriptSetup(), ['type' => '100'], 'text/turtle')
        );

        $this->assertSame('application/ld+json', $service->getContentType());
        $this->assertSame('jsonld', $service->getFormat());
    }

    /**
     * With no `type` query argument, the page type resolved by routing is used.
     */
    public function testFallsBackToRoutingPageType(): void
    {
        $service = new ContentNegotiationService();
        $service->negotiate(
            $this->makeRequest($this->typoScriptSetup(), [], '', '101')
        );

        $this->assertSame('text/turtle', $service->getContentType());
        $this->assertSame('turtle', $service->getFormat());
    }

    /**
     * When no page type is pre-selected, the best available mime type is negotiated from the
     * client's Accept header.
     *
     * @dataProvider acceptHeaderProvider
     */
    public function testNegotiatesContentTypeFromAcceptHeader(
        string $accept,
        string $expectedContentType,
        string $expectedFormat
    ): void {
        $service = new ContentNegotiationService();
        $service->negotiate($this->makeRequest($this->typoScriptSetup(), [], $accept));

        $this->assertSame($expectedContentType, $service->getContentType());
        $this->assertSame($expectedFormat, $service->getFormat());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public function acceptHeaderProvider(): array
    {
        return [
            'single turtle' => ['text/turtle', 'text/turtle', 'turtle'],
            'single json-ld' => ['application/ld+json', 'application/ld+json', 'jsonld'],
            'quality weighting prefers turtle' => [
                'application/ld+json;q=0.5, text/turtle;q=0.9',
                'text/turtle',
                'turtle',
            ],
        ];
    }

    /**
     * When neither a page type nor a negotiable Accept header match an available mime type,
     * the service keeps its HTML defaults.
     */
    public function testDefaultsToHtmlWhenNothingMatches(): void
    {
        $service = new ContentNegotiationService();
        $service->negotiate($this->makeRequest($this->typoScriptSetup(), [], 'text/html'));

        $this->assertSame('text/html', $service->getContentType());
        $this->assertSame('html', $service->getFormat());
    }
}
