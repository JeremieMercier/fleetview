<?php

/**
 * -------------------------------------------------------------------------
 * Fleetview plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Fleetview plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/JeremieMercier/fleetview
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Fleetview\Tests;

use GlpiPlugin\Fleetview\Routing\OsrmRouter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * All coordinates and hosts in these tests are fictional.
 */
final class OsrmRouterTest extends TestCase
{
    private const ORIGIN_LAT = 48.85;

    private const ORIGIN_LNG = 2.35;

    /** @var list<MockResponse> Queued responses, each one recording the request it served */
    private array $responses = [];

    private MockHttpClient $http;

    /**
     * @return list<array{latitude: float, longitude: float}>
     */
    private function destinations(): array
    {
        return [
            ['latitude' => 48.86, 'longitude' => 2.36],
            ['latitude' => 48.95, 'longitude' => 2.40],
        ];
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function mockHttpClient(array $responses): MockHttpClient
    {
        $this->responses = $responses;

        return $this->http = new MockHttpClient($responses);
    }

    private function jsonResponse(mixed $data, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $status]);
    }

    /**
     * @return array{path: string, query: array<string, string>}
     */
    private function requestedUrl(int $index): array
    {
        $url = $this->responses[$index]->getRequestUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return ['path' => (string) parse_url($url, PHP_URL_PATH), 'query' => $query];
    }

    private function fakeBaseUrl(): string
    {
        // Unique per test: OSRM cache keys derive from the base URL
        return 'https://osrm.example.test/' . uniqid();
    }

    private function tableResponse(): MockResponse
    {
        return $this->jsonResponse([
            'code'      => 'Ok',
            'durations' => [[0, 600, 1224]],
            'distances' => [[0, 5000, 10240]],
        ]);
    }

    private function routeResponse(array $coordinates): MockResponse
    {
        return $this->jsonResponse([
            'code'   => 'Ok',
            'routes' => [['geometry' => ['type' => 'LineString', 'coordinates' => $coordinates]]],
        ]);
    }

    public function testGeometriesAreMappedFromTheRouteResponses(): void
    {
        $router = new OsrmRouter($this->fakeBaseUrl(), $this->mockHttpClient([
            $this->routeResponse([[2.36, 48.86], [2.355, 48.855], [2.35, 48.85]]),
            $this->routeResponse([[2.40, 48.95], [2.35, 48.85]]),
        ]));

        $geometries = $router->getRouteGeometriesToPoint(48.85, 2.35, $this->destinations());

        $this->assertSame([
            [[2.36, 48.86], [2.355, 48.855], [2.35, 48.85]],
            [[2.40, 48.95], [2.35, 48.85]],
        ], $geometries);

        // One route request per origin, from the vehicle to the point
        $this->assertSame(2, $this->http->getRequestsCount());
        $first = $this->requestedUrl(0);
        $this->assertStringEndsWith('/route/v1/driving/2.360000,48.860000;2.350000,48.850000', $first['path']);
        $this->assertSame(['overview' => 'full', 'geometries' => 'geojson'], $first['query']);
        $this->assertStringEndsWith('/route/v1/driving/2.400000,48.950000;2.350000,48.850000', $this->requestedUrl(1)['path']);
    }

    public function testDisabledRouterReturnsNoGeometryWithoutHttpCall(): void
    {
        $router = new OsrmRouter('', $this->mockHttpClient([]));

        $this->assertSame([null, null], $router->getRouteGeometriesToPoint(48.85, 2.35, $this->destinations()));
        $this->assertSame(0, $this->http->getRequestsCount());
    }

    public function testRouteErrorsDegradeToNullPerOrigin(): void
    {
        $router = new OsrmRouter($this->fakeBaseUrl(), $this->mockHttpClient([
            $this->jsonResponse(['code' => 'NoRoute']),
            $this->routeResponse([[2.40, 48.95], [2.35, 48.85]]),
        ]));

        $this->assertSame(
            [null, [[2.40, 48.95], [2.35, 48.85]]],
            $router->getRouteGeometriesToPoint(48.85, 2.35, $this->destinations()),
        );
    }

    public function testGeometriesAreCached(): void
    {
        $base_url = $this->fakeBaseUrl();
        $origins  = [$this->destinations()[0]];

        $first = (new OsrmRouter($base_url, $this->mockHttpClient([$this->routeResponse([[2.36, 48.86], [2.35, 48.85]])])))
            ->getRouteGeometriesToPoint(48.85, 2.35, $origins);
        $second = (new OsrmRouter($base_url, $this->mockHttpClient([])))
            ->getRouteGeometriesToPoint(48.85, 2.35, $origins);

        $this->assertSame($first, $second);
        $this->assertSame(0, $this->http->getRequestsCount());
    }

    public function testDisabledRouterReturnsNullsWithoutHttpCall(): void
    {
        $router = new OsrmRouter('   ', $this->mockHttpClient([]));

        $this->assertFalse($router->isEnabled());
        $this->assertSame(
            [null, null],
            $router->getRoutesFromPoint(self::ORIGIN_LAT, self::ORIGIN_LNG, $this->destinations()),
        );
        $this->assertSame(0, $this->http->getRequestsCount());
    }

    public function testEmptyDestinationsShortCircuit(): void
    {
        $router = new OsrmRouter($this->fakeBaseUrl(), $this->mockHttpClient([]));

        $this->assertSame([], $router->getRoutesFromPoint(self::ORIGIN_LAT, self::ORIGIN_LNG, []));
        $this->assertSame(0, $this->http->getRequestsCount());
    }

    public function testRoutesAreMappedFromTheTableResponse(): void
    {
        $router = new OsrmRouter($this->fakeBaseUrl(), $this->mockHttpClient([$this->tableResponse()]));

        $routes = $router->getRoutesFromPoint(self::ORIGIN_LAT, self::ORIGIN_LNG, $this->destinations());

        $this->assertSame(
            [
                ['duration_min' => 10, 'distance_km' => 5.0],
                ['duration_min' => 20, 'distance_km' => 10.2],
            ],
            $routes,
        );

        // One table request: origin first (lng,lat), then each destination,
        // with sources=0 and both annotations
        $this->assertSame(1, $this->http->getRequestsCount());
        $request = $this->requestedUrl(0);
        $this->assertStringContainsString(
            '/table/v1/driving/2.350000,48.850000;2.360000,48.860000;2.400000,48.950000',
            $request['path'],
        );
        $this->assertSame(['sources' => '0', 'annotations' => 'duration,distance'], $request['query']);
    }

    public function testMissingMatrixEntriesYieldNulls(): void
    {
        $router = new OsrmRouter($this->fakeBaseUrl(), $this->mockHttpClient([
            $this->jsonResponse([
                'code'      => 'Ok',
                'durations' => [[0, 600]],
                'distances' => [[0, 5000]],
            ]),
        ]));

        $this->assertSame(
            [['duration_min' => 10, 'distance_km' => 5.0], null],
            $router->getRoutesFromPoint(self::ORIGIN_LAT, self::ORIGIN_LNG, $this->destinations()),
        );
    }

    public function testServiceErrorsDegradeToNulls(): void
    {
        foreach (
            [
                $this->jsonResponse(['code' => 'NoTable']),
                new MockResponse('boom', ['http_code' => 500]),
                new MockResponse('this is not json'),
            ] as $response
        ) {
            $router = new OsrmRouter($this->fakeBaseUrl(), $this->mockHttpClient([$response]));

            $this->assertSame(
                [null, null],
                $router->getRoutesFromPoint(self::ORIGIN_LAT, self::ORIGIN_LNG, $this->destinations()),
            );
        }
    }

    public function testRoutesAreCached(): void
    {
        $base_url = $this->fakeBaseUrl();

        $first = (new OsrmRouter($base_url, $this->mockHttpClient([$this->tableResponse()])))
            ->getRoutesFromPoint(self::ORIGIN_LAT, self::ORIGIN_LNG, $this->destinations());

        // No response queued: a second router instance must be served by the cache
        $second = (new OsrmRouter($base_url, $this->mockHttpClient([])))
            ->getRoutesFromPoint(self::ORIGIN_LAT, self::ORIGIN_LNG, $this->destinations());

        $this->assertSame($first, $second);
        $this->assertSame(0, $this->http->getRequestsCount());
    }

    public function testFailedLookupsAreNotCached(): void
    {
        $base_url = $this->fakeBaseUrl();

        $first = (new OsrmRouter($base_url, $this->mockHttpClient([new MockResponse('boom', ['http_code' => 500])])))
            ->getRoutesFromPoint(self::ORIGIN_LAT, self::ORIGIN_LNG, $this->destinations());
        $this->assertSame([null, null], $first);

        // The failure must not poison the cache: the next call retries and succeeds
        $second = (new OsrmRouter($base_url, $this->mockHttpClient([$this->tableResponse()])))
            ->getRoutesFromPoint(self::ORIGIN_LAT, self::ORIGIN_LNG, $this->destinations());

        $this->assertSame(10, $second[0]['duration_min'] ?? null);
    }
}
