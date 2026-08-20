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

use Psr\Http\Message\RequestInterface;
use GlpiPlugin\Fleetview\Routing\OsrmRouter;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * All coordinates and hosts in these tests are fictional.
 */
final class OsrmRouterTest extends TestCase
{
    private const ORIGIN_LAT = 48.85;

    private const ORIGIN_LNG = 2.35;

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

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
     * @param list<Response> $responses
     */
    private function mockHttpClient(array $responses): Client
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new Client(['handler' => $stack]);
    }

    private function fakeBaseUrl(): string
    {
        // Unique per test: OSRM cache keys derive from the base URL
        return 'https://osrm.example.test/' . uniqid();
    }

    private function tableResponse(): Response
    {
        return new Response(200, [], json_encode([
            'code'      => 'Ok',
            'durations' => [[0, 600, 1224]],
            'distances' => [[0, 5000, 10240]],
        ]));
    }

    public function testDisabledRouterReturnsNullsWithoutHttpCall(): void
    {
        $router = new OsrmRouter('   ', $this->mockHttpClient([]));

        $this->assertFalse($router->isEnabled());
        $this->assertSame(
            [null, null],
            $router->getRoutesFromPoint(self::ORIGIN_LAT, self::ORIGIN_LNG, $this->destinations()),
        );
        $this->assertCount(0, $this->history);
    }

    public function testEmptyDestinationsShortCircuit(): void
    {
        $router = new OsrmRouter($this->fakeBaseUrl(), $this->mockHttpClient([]));

        $this->assertSame([], $router->getRoutesFromPoint(self::ORIGIN_LAT, self::ORIGIN_LNG, []));
        $this->assertCount(0, $this->history);
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
        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertStringContainsString(
            '/table/v1/driving/2.350000,48.850000;2.360000,48.860000;2.400000,48.950000',
            $request->getUri()->getPath(),
        );
        $this->assertSame('sources=0&annotations=duration%2Cdistance', $request->getUri()->getQuery());
    }

    public function testMissingMatrixEntriesYieldNulls(): void
    {
        $router = new OsrmRouter($this->fakeBaseUrl(), $this->mockHttpClient([
            new Response(200, [], json_encode([
                'code'      => 'Ok',
                'durations' => [[0, 600]],
                'distances' => [[0, 5000]],
            ])),
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
                new Response(200, [], json_encode(['code' => 'NoTable'])),
                new Response(500, [], 'boom'),
                new Response(200, [], 'this is not json'),
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
        $this->assertCount(0, $this->history);
    }

    public function testFailedLookupsAreNotCached(): void
    {
        $base_url = $this->fakeBaseUrl();

        $first = (new OsrmRouter($base_url, $this->mockHttpClient([new Response(500, [], 'boom')])))
            ->getRoutesFromPoint(self::ORIGIN_LAT, self::ORIGIN_LNG, $this->destinations());
        $this->assertSame([null, null], $first);

        // The failure must not poison the cache: the next call retries and succeeds
        $second = (new OsrmRouter($base_url, $this->mockHttpClient([$this->tableResponse()])))
            ->getRoutesFromPoint(self::ORIGIN_LAT, self::ORIGIN_LNG, $this->destinations());

        $this->assertSame(10, $second[0]['duration_min'] ?? null);
    }
}
