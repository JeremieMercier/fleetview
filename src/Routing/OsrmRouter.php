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

namespace GlpiPlugin\Fleetview\Routing;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\SimpleCache\CacheInterface;
use Safe\Exceptions\JsonException;
use Toolbox;

use function Safe\json_decode;

/**
 * Driving time/distance estimations using an OSRM "table" service
 * (https://project-osrm.org). Best effort: any failure returns null
 * estimations so the map keeps working without them.
 */
final class OsrmRouter
{
    /** Routes barely change for near-identical coordinates: cache aggressively (seconds) */
    private const CACHE_LIFETIME = 300;

    /**
     * @param ?ClientInterface $http_client HTTP client override, mainly for
     *        tests (defaults to the GLPI Guzzle client, proxy-aware)
     */
    public function __construct(private string $base_url, private ?ClientInterface $http_client = null)
    {
        $this->base_url = rtrim(trim($base_url), '/');
    }

    public function isEnabled(): bool
    {
        return $this->base_url !== '';
    }

    /**
     * Driving duration/distance from one point to each destination.
     *
     * @param list<array{latitude: float, longitude: float}> $destinations
     *
     * @return list<?array{duration_min: int, distance_km: float}>
     *         Same order/length as $destinations; null entries when unavailable.
     */
    public function getRoutesFromPoint(float $latitude, float $longitude, array $destinations): array
    {
        $none = array_fill(0, count($destinations), null);

        if (!$this->isEnabled() || $destinations === []) {
            return $none;
        }

        /** @var CacheInterface $GLPI_CACHE */
        global $GLPI_CACHE;

        $coordinates = sprintf('%.6f,%.6f', $longitude, $latitude);
        foreach ($destinations as $destination) {
            $coordinates .= sprintf(';%.6f,%.6f', $destination['longitude'], $destination['latitude']);
        }

        $cache_key = 'fleetview_osrm_' . md5($this->base_url . $coordinates);
        $cached    = $GLPI_CACHE->get($cache_key);
        if (is_array($cached) && count($cached) === count($destinations)) {
            return $this->sanitizeRoutes($cached);
        }

        $url = sprintf('%s/table/v1/driving/%s', $this->base_url, $coordinates);

        try {
            $client   = $this->http_client ?? Toolbox::getGuzzleClient(['timeout' => 8]);
            $response = $client->request('GET', $url, [
                'query'       => [
                    'sources'     => '0',
                    'annotations' => 'duration,distance',
                ],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $guzzleException) {
            Toolbox::logInFile('fleetview', sprintf("OSRM request failed: %s\n", $guzzleException->getMessage()));
            return $none;
        }

        try {
            $data = json_decode((string) $response->getBody(), true);
        } catch (JsonException) {
            $data = null;
        }

        if ($response->getStatusCode() !== 200 || !is_array($data) || ($data['code'] ?? '') !== 'Ok') {
            $code = is_array($data) && is_scalar($data['code'] ?? null) ? (string) $data['code'] : 'unparsable';
            Toolbox::logInFile('fleetview', sprintf(
                "OSRM returned HTTP %d (code: %s)\n",
                $response->getStatusCode(),
                $code,
            ));
            return $none;
        }

        $durations = is_array($data['durations'] ?? null) && is_array($data['durations'][0] ?? null)
            ? $data['durations'][0]
            : [];
        $distances = is_array($data['distances'] ?? null) && is_array($data['distances'][0] ?? null)
            ? $data['distances'][0]
            : [];

        $routes = [];
        foreach (array_keys($destinations) as $i) {
            $duration = $durations[$i + 1] ?? null;
            $distance = $distances[$i + 1] ?? null;

            $routes[] = is_numeric($duration) && is_numeric($distance)
                ? [
                    'duration_min' => (int) round((float) $duration / 60),
                    'distance_km'  => round((float) $distance / 1000, 1),
                ]
                : null;
        }

        $GLPI_CACHE->set($cache_key, $routes, self::CACHE_LIFETIME);

        return $routes;
    }

    /**
     * Re-validate route entries coming from the cache.
     *
     * @param array<array-key, mixed> $entries
     *
     * @return list<?array{duration_min: int, distance_km: float}>
     */
    private function sanitizeRoutes(array $entries): array
    {
        $routes = [];
        foreach ($entries as $entry) {
            $routes[] = is_array($entry) && is_int($entry['duration_min'] ?? null) && is_float($entry['distance_km'] ?? null)
                ? ['duration_min' => $entry['duration_min'], 'distance_km' => $entry['distance_km']]
                : null;
        }

        return $routes;
    }
}
