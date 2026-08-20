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

use GlpiPlugin\Fleetview\Masternaut\MasternautApiException;
use GlpiPlugin\Fleetview\Masternaut\MasternautClient;
use GlpiPlugin\Fleetview\PluginConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * All fleet data in these tests is fictional (classic test-dataset names,
 * fake credentials); no real person or credential is referenced.
 */
final class MasternautClientTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function fakeConfig(array $overrides = []): array
    {
        return array_merge(PluginConfig::getDefaults(), [
            'api_base_url' => 'https://fleet.example.test/public',
            // Unique per test: cache keys derive from it
            'customer_id'  => 'TEST_' . uniqid(),
            'api_username' => 'fake_api_user',
            'api_secret'   => 'fake-secret-value',
        ], $overrides);
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

    private static function vehiclesResponse(): Response
    {
        return new Response(200, [], json_encode(['items' => [
            [
                'id'           => '102',
                'name'         => 'Martin Sophie',
                'registration' => 'CC-456-DD',
                'groupName'    => 'Zone Bravo',
                'type'         => 'CAR',
                'make'         => 'Peugeot',
                'model'        => '208',
                'status'       => 'IN_MAINTENANCE',
            ],
            [
                'id'           => '101',
                'name'         => 'Dupont Jean',
                'registration' => 'AA-123-BB',
                'groupName'    => 'Zone Alpha',
                'type'         => 'VAN_SMALL',
                'make'         => 'Renault',
                'model'        => 'Kangoo',
                'status'       => 'IN_CIRCULATION',
            ],
            ['registration' => 'NO-ID-01'],
            'garbage-entry',
        ]]));
    }

    private static function positionsResponse(): Response
    {
        return new Response(200, [], json_encode(['items' => [
            // ~1.2 km from the fake ticket point (48.85, 2.35)
            [
                'assetId'           => '101',
                'assetName'         => 'Dupont Jean',
                'assetRegistration' => 'AA-123-BB',
                'latitude'          => 48.86,
                'longitude'         => 2.36,
                'date'              => '2030-01-01T10:00:00.000',
                'eventType'         => 'driving',
            ],
            // ~11 km, no name: label must fall back to the registration
            [
                'assetId'           => '102',
                'assetName'         => '',
                'assetRegistration' => 'CC-456-DD',
                'latitude'          => 48.95,
                'longitude'         => 2.35,
            ],
            // Unknown to the fleet list: must be kept (fail open)
            [
                'assetId'           => '999',
                'assetName'         => 'Lefèvre Kévin',
                'assetRegistration' => 'EE-789-FF',
                'latitude'          => 48.87,
                'longitude'         => 2.34,
            ],
            // Far beyond the search radius: must be excluded
            [
                'assetId'           => '103',
                'assetName'         => 'Durand Paul',
                'assetRegistration' => 'GG-000-HH',
                'latitude'          => 45.0,
                'longitude'         => 5.0,
            ],
            // No coordinates: must be ignored
            ['assetId' => '104', 'assetName' => 'Moreau Claire'],
        ]]));
    }

    public function testIsConfiguredRequiresEveryCredential(): void
    {
        $this->assertTrue((new MasternautClient($this->fakeConfig()))->isConfigured());
        $this->assertFalse((new MasternautClient($this->fakeConfig(['api_secret' => ''])))->isConfigured());
        $this->assertFalse((new MasternautClient($this->fakeConfig(['customer_id' => ' '])))->isConfigured());
    }

    public function testRequestFailsWithoutConfigurationAndWithoutHttpCall(): void
    {
        $client = new MasternautClient(
            $this->fakeConfig(['api_secret' => '']),
            $this->mockHttpClient([]),
        );

        $this->expectException(MasternautApiException::class);

        try {
            $client->getVehicles();
        } finally {
            $this->assertCount(0, $this->history);
        }
    }

    public function testGetVehiclesParsesSortsAndAuthenticates(): void
    {
        $config = $this->fakeConfig();
        $client = new MasternautClient($config, $this->mockHttpClient([self::vehiclesResponse()]));

        $vehicles = $client->getVehicles();

        // Malformed entries dropped, natural sort by name
        $this->assertSame(['Dupont Jean', 'Martin Sophie'], array_column($vehicles, 'name'));
        $this->assertSame(
            [
                'id'           => '101',
                'name'         => 'Dupont Jean',
                'registration' => 'AA-123-BB',
                'group'        => 'Zone Alpha',
                'type'         => 'VAN_SMALL',
                'make_model'   => 'Renault Kangoo',
                'status'       => 'IN_CIRCULATION',
            ],
            $vehicles[0],
        );

        // Endpoint URL and HTTP Basic authentication
        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame(
            '/public/v1/customer/' . $config['customer_id'] . '/vehicle',
            $request->getUri()->getPath(),
        );
        $this->assertSame(
            'Basic ' . base64_encode('fake_api_user:fake-secret-value'),
            $request->getHeaderLine('Authorization'),
        );
    }

    public function testGetVehiclesIsCached(): void
    {
        $config = $this->fakeConfig();
        $client = new MasternautClient($config, $this->mockHttpClient([self::vehiclesResponse()]));

        $first  = $client->getVehicles();
        $second = (new MasternautClient($config, $this->mockHttpClient([])))->getVehicles();

        // A single HTTP request served both calls
        $this->assertSame($first, $second);
    }

    public function testGetLatestPositionsKeepsOnlyLocatedItems(): void
    {
        $client = new MasternautClient($this->fakeConfig(), $this->mockHttpClient([self::positionsResponse()]));

        $positions = $client->getLatestPositions();

        $this->assertCount(4, $positions);
        foreach ($positions as $position) {
            $this->assertArrayHasKey('latitude', $position);
            $this->assertArrayHasKey('longitude', $position);
        }
    }

    public function testGetNearbyVehiclesSortsFiltersAndEnriches(): void
    {
        $client = new MasternautClient(
            $this->fakeConfig(['search_radius' => '100']),
            $this->mockHttpClient([self::vehiclesResponse(), self::positionsResponse()]),
        );

        $vehicles = $client->getNearbyVehicles(48.85, 2.35);

        // Sorted by distance; the far vehicle is outside the 100 km radius
        $this->assertSame(['101', '999', '102'], array_column($vehicles, 'id'));

        // Fleet status enrichment, and null for assets unknown to the fleet
        $this->assertSame('IN_CIRCULATION', $vehicles[0]['status']);
        $this->assertNull($vehicles[1]['status']);

        // Label falls back to the registration when the name is empty
        $this->assertSame('CC-456-DD', $vehicles[2]['label']);

        // Distances are consistent with the haversine computation
        $this->assertEqualsWithDelta(1.3, $vehicles[0]['distance_km'], 0.3);
        $this->assertEqualsWithDelta(11.1, $vehicles[2]['distance_km'], 0.5);
    }

    public function testGetNearbyVehiclesAppliesGroupAndStatusFilters(): void
    {
        $client = new MasternautClient(
            $this->fakeConfig(['modal_group' => '["Zone Alpha"]']),
            $this->mockHttpClient([self::vehiclesResponse(), self::positionsResponse()]),
        );

        // Zone Bravo excluded; unknown assets kept (fail open)
        $this->assertSame(
            ['101', '999'],
            array_column($client->getNearbyVehicles(48.85, 2.35), 'id'),
        );

        $client = new MasternautClient(
            $this->fakeConfig(['modal_status' => '["IN_MAINTENANCE"]']),
            $this->mockHttpClient([self::vehiclesResponse(), self::positionsResponse()]),
        );

        $this->assertSame(
            ['999', '102'],
            array_column($client->getNearbyVehicles(48.85, 2.35), 'id'),
        );
    }

    public function testGetNearbyVehiclesHonorsMaxResults(): void
    {
        $client = new MasternautClient(
            $this->fakeConfig(['max_results' => '1']),
            $this->mockHttpClient([self::vehiclesResponse(), self::positionsResponse()]),
        );

        $this->assertSame(['101'], array_column($client->getNearbyVehicles(48.85, 2.35), 'id'));
    }

    public function testAuthenticationFailureRaisesAnExplicitError(): void
    {
        $client = new MasternautClient($this->fakeConfig(), $this->mockHttpClient([new Response(401)]));

        $this->expectException(MasternautApiException::class);
        $this->expectExceptionMessageMatches('/authentication/i');

        $client->getVehicles();
    }

    public function testRateLimitRaisesAnExplicitError(): void
    {
        $client = new MasternautClient($this->fakeConfig(), $this->mockHttpClient([new Response(429)]));

        $this->expectException(MasternautApiException::class);
        $this->expectExceptionMessageMatches('/rate limit/i');

        $client->getLatestPositions();
    }

    public function testApiErrorMessageIsSurfaced(): void
    {
        $client = new MasternautClient(
            $this->fakeConfig(),
            $this->mockHttpClient([new Response(500, [], json_encode(['errorMsg' => 'fake upstream failure']))]),
        );

        $this->expectException(MasternautApiException::class);
        $this->expectExceptionMessageMatches('/fake upstream failure/');

        $client->getVehicles();
    }

    public function testUnparsableSuccessBodyRaisesAnError(): void
    {
        $client = new MasternautClient(
            $this->fakeConfig(),
            $this->mockHttpClient([new Response(200, [], 'this is not json')]),
        );

        $this->expectException(MasternautApiException::class);

        $client->getVehicles();
    }
}
