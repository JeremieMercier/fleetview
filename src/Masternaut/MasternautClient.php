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
 * @link      https://github.com/pluginsGLPI/fleetview
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Fleetview\Masternaut;

use GlpiPlugin\Fleetview\PluginConfig;
use GuzzleHttp\Exception\GuzzleException;
use Psr\SimpleCache\CacheInterface;
use Toolbox;

/**
 * Masternaut (Michelin Connected Fleet) "Connect API" client.
 *
 * See docs/api/ — MCF Connect API Reference v1.38:
 * - base URL: https://api.masternautconnect.com/connect-webservices/services/public
 *   endpoints under /v1/customer/{customerId}/
 * - HTTP Basic authentication (Connect Partner user)
 * - "Live Position Latest" is rate-limited to 1 request / 15 seconds, hence
 *   the mandatory server-side cache on positions.
 */
final class MasternautClient
{
    /** Minimal cache lifetime, aligned with the API rate limit (seconds) */
    private const MIN_CACHE_LIFETIME = 15;

    /** @var array<string, string> */
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? PluginConfig::getConfig();
    }

    public function isConfigured(): bool
    {
        foreach (['api_base_url', 'customer_id', 'api_username', 'api_secret'] as $key) {
            if (trim($this->config[$key] ?? '') === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Vehicles near a point, closest first.
     *
     * Distances are computed locally (haversine) from "Live Position Latest"
     * data instead of calling "Find Nearest Vehicle": the latter silently
     * excludes vehicles that have not moved during the last 24 hours, which
     * would hide parked technicians (weekends, leaves...). This also saves
     * an API call, as positions are cached.
     *
     * @return list<array{
     *      id: string,
     *      label: string,
     *      registration: string,
     *      distance_km: float,
     *      latitude: float,
     *      longitude: float,
     *      driver_name: ?string,
     *      updated_at: ?string,
     *      event_type: ?string,
     * }>
     *
     * @throws MasternautApiException
     */
    public function getNearbyVehicles(float $latitude, float $longitude): array
    {
        $radius = min(500.0, max(1.0, (float) $this->config['search_radius']));
        $max    = max(1, (int) $this->config['max_results']);

        $vehicles = [];
        foreach ($this->getLatestPositions() as $item) {
            $distance = self::haversineKm($latitude, $longitude, (float) $item['latitude'], (float) $item['longitude']);
            if ($distance > $radius) {
                continue;
            }

            $name         = (string) ($item['assetName'] ?? '');
            $registration = (string) ($item['assetRegistration'] ?? '');

            $vehicles[] = [
                'id'           => (string) ($item['assetId'] ?? ''),
                'label'        => $name !== '' ? $name : $registration,
                'registration' => $registration,
                'distance_km'  => round($distance, 1),
                'latitude'     => (float) $item['latitude'],
                'longitude'    => (float) $item['longitude'],
                'driver_name'  => $item['driverName'] ?? null,
                'updated_at'   => $item['date'] ?? null,
                'event_type'   => $item['eventType'] ?? null,
            ];
        }

        usort($vehicles, static fn(array $a, array $b) => $a['distance_km'] <=> $b['distance_km']);

        return array_slice($vehicles, 0, $max);
    }

    /**
     * Latest live position of every vehicle of the fleet (only vehicles with
     * coordinates; private journeys are excluded by the API itself).
     *
     * Cached server-side (see class docblock).
     *
     * @return list<array<string, mixed>>
     *
     * @throws MasternautApiException
     */
    public function getLatestPositions(): array
    {
        /** @var CacheInterface $GLPI_CACHE */
        global $GLPI_CACHE;

        $cache_key = 'fleetview_live_positions_' . md5($this->config['customer_id'] . $this->config['api_username']);

        $positions = $GLPI_CACHE->get($cache_key);
        if (is_array($positions)) {
            return $positions;
        }

        $response = $this->request('tracking/live/latest');
        $items    = is_array($response) ? ($response['items'] ?? []) : [];

        $positions = array_values(array_filter(
            $items,
            static fn($item) => is_array($item) && isset($item['latitude'], $item['longitude'])
        ));

        $lifetime = max(self::MIN_CACHE_LIFETIME, (int) $this->config['cache_lifetime']);
        $GLPI_CACHE->set($cache_key, $positions, $lifetime);

        return $positions;
    }

    /**
     * Every vehicle of the fleet (List Vehicle endpoint), for the vehicle to
     * user association screen. Sold vehicles are excluded. Cached 10 minutes.
     *
     * @return list<array{
     *      id: string,
     *      name: string,
     *      registration: string,
     *      group: string,
     *      type: string,
     *      make_model: string,
     *      status: string,
     * }>
     *
     * @throws MasternautApiException
     */
    public function getVehicles(): array
    {
        /** @var CacheInterface $GLPI_CACHE */
        global $GLPI_CACHE;

        $cache_key = 'fleetview_vehicles_' . md5($this->config['customer_id'] . $this->config['api_username']);

        $vehicles = $GLPI_CACHE->get($cache_key);
        if (is_array($vehicles)) {
            return $vehicles;
        }

        $response = $this->request('vehicle');
        $items    = is_array($response) ? ($response['items'] ?? []) : [];

        $vehicles = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id']) || ($item['status'] ?? '') === 'SOLD') {
                continue;
            }
            $vehicles[] = [
                'id'           => (string) $item['id'],
                'name'         => (string) ($item['name'] ?? ''),
                'registration' => (string) ($item['registration'] ?? ''),
                'group'        => (string) ($item['groupName'] ?? ''),
                'type'         => (string) ($item['type'] ?? ''),
                'make_model'   => trim(((string) ($item['make'] ?? '')) . ' ' . ((string) ($item['model'] ?? ''))),
                'status'       => (string) ($item['status'] ?? ''),
            ];
        }

        usort($vehicles, static fn(array $a, array $b) => strnatcasecmp($a['name'], $b['name']));

        $GLPI_CACHE->set($cache_key, $vehicles, 600);

        return $vehicles;
    }

    /**
     * Great-circle distance between two points, in kilometers.
     */
    private static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dlat = deg2rad($lat2 - $lat1);
        $dlng = deg2rad($lng2 - $lng1);

        $a = sin($dlat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlng / 2) ** 2;

        return 6371.0 * 2 * asin(min(1.0, sqrt($a)));
    }

    /**
     * Perform an authenticated GET request against the Connect API.
     *
     * @param array<string, scalar> $query
     *
     * @return mixed Decoded JSON
     *
     * @throws MasternautApiException
     */
    private function request(string $path, array $query = [])
    {
        if (!$this->isConfigured()) {
            throw new MasternautApiException(__('The Masternaut API is not configured.', 'fleetview'));
        }

        $url = sprintf(
            '%s/v1/customer/%s/%s',
            rtrim($this->config['api_base_url'], '/'),
            rawurlencode(trim($this->config['customer_id'])),
            ltrim($path, '/')
        );

        $client = Toolbox::getGuzzleClient([
            'timeout' => 15,
            'headers' => [
                'Accept'          => 'application/json',
                'Accept-Encoding' => 'gzip',
            ],
        ]);

        try {
            $response = $client->request('GET', $url, [
                'auth'        => [$this->config['api_username'], $this->config['api_secret']],
                'query'       => $query,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            Toolbox::logInFile('fleetview', sprintf("GET %s failed: %s\n", $path, $e->getMessage()));
            throw new MasternautApiException(__('Unable to reach the Masternaut API.', 'fleetview'), 0, $e);
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();

        if ($status === 429) {
            throw new MasternautApiException(__('Masternaut API rate limit exceeded, please retry in a few seconds.', 'fleetview'));
        }

        if ($status === 401 || $status === 403) {
            throw new MasternautApiException(__('Masternaut API authentication failed, please check the plugin configuration.', 'fleetview'));
        }

        if ($status !== 200 && $status !== 204) {
            $error = json_decode($body, true);
            Toolbox::logInFile('fleetview', sprintf("GET %s returned HTTP %d: %s\n", $path, $status, $body));
            throw new MasternautApiException(sprintf(
                __('Masternaut API error (HTTP %1$d): %2$s', 'fleetview'),
                $status,
                $error['errorMsg'] ?? __('unknown error', 'fleetview')
            ));
        }

        return $body === '' ? [] : json_decode($body, true);
    }
}
