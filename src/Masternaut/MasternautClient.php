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

namespace GlpiPlugin\Fleetview\Masternaut;

use GlpiPlugin\Fleetview\PluginConfig;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\SimpleCache\CacheInterface;
use Safe\Exceptions\JsonException;
use Toolbox;

use function Safe\json_decode;

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

    /**
     * @param ?array<string, string> $config      Configuration override
     *        (defaults to the stored plugin configuration)
     * @param ?ClientInterface       $http_client HTTP client override, mainly
     *        for tests (defaults to the GLPI Guzzle client, proxy-aware)
     */
    public function __construct(?array $config = null, private ?ClientInterface $http_client = null)
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
     *      status: ?string,
     * }>
     *
     * @throws MasternautApiException
     */
    public function getNearbyVehicles(float $latitude, float $longitude): array
    {
        $radius = min(500.0, max(1.0, (float) $this->config['search_radius']));
        $max    = max(1, (int) $this->config['max_results']);

        // Fleet details (status shown in popups, optional group/status
        // filters); vehicles missing from the fleet list are kept rather
        // than silently hiding a technician on a data mismatch.
        $filter_groups   = PluginConfig::decodeListValue($this->config['modal_group']);
        $filter_statuses = PluginConfig::decodeListValue($this->config['modal_status']);
        $fleet_info      = [];
        foreach ($this->getVehicles() as $info) {
            $fleet_info[$info['id']] = $info;
        }

        $vehicles = [];
        foreach ($this->getLatestPositions() as $item) {
            $info = $fleet_info[$this->toString($item['assetId'] ?? null)] ?? null;
            if (
                ($filter_groups !== [] && $info !== null && !in_array($info['group'], $filter_groups, true))
                || ($filter_statuses !== [] && $info !== null && !in_array($info['status'], $filter_statuses, true))
            ) {
                continue;
            }

            $item_latitude  = $this->toFloat($item['latitude'] ?? null);
            $item_longitude = $this->toFloat($item['longitude'] ?? null);

            $distance = $this->haversineKm($latitude, $longitude, $item_latitude, $item_longitude);
            if ($distance > $radius) {
                continue;
            }

            $name         = $this->toString($item['assetName'] ?? null);
            $registration = $this->toString($item['assetRegistration'] ?? null);

            $vehicles[] = [
                'id'           => $this->toString($item['assetId'] ?? null),
                'label'        => $name !== '' ? $name : $registration,
                'registration' => $registration,
                'distance_km'  => round($distance, 1),
                'latitude'     => $item_latitude,
                'longitude'    => $item_longitude,
                'driver_name'  => $this->toStringOrNull($item['driverName'] ?? null),
                'updated_at'   => $this->toStringOrNull($item['date'] ?? null),
                'event_type'   => $this->toStringOrNull($item['eventType'] ?? null),
                'status'       => $info['status'] ?? null,
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
     * @return list<array<array-key, mixed>>
     *
     * @throws MasternautApiException
     */
    public function getLatestPositions(): array
    {
        /** @var CacheInterface $GLPI_CACHE */
        global $GLPI_CACHE;

        $cache_key = 'fleetview_live_positions_' . md5($this->config['customer_id'] . $this->config['api_username']);

        $cached = $GLPI_CACHE->get($cache_key);
        if (is_array($cached)) {
            return $this->filterPositionItems($cached);
        }

        $response  = $this->request('tracking/live/latest');
        $positions = [];
        if (is_array($response) && isset($response['items']) && is_array($response['items'])) {
            $positions = $this->filterPositionItems($response['items']);
        }

        $lifetime = max(self::MIN_CACHE_LIFETIME, (int) $this->config['cache_lifetime']);
        $GLPI_CACHE->set($cache_key, $positions, $lifetime);

        return $positions;
    }

    /**
     * Keep only located position entries.
     *
     * @param array<array-key, mixed> $items
     *
     * @return list<array<array-key, mixed>>
     */
    private function filterPositionItems(array $items): array
    {
        $positions = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['latitude'], $item['longitude'])) {
                $positions[] = $item;
            }
        }

        return $positions;
    }

    /**
     * Every vehicle of the fleet (List Vehicle endpoint), for the vehicle to
     * user association screen. Cached 10 minutes.
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

        $cached = $GLPI_CACHE->get($cache_key);
        if (is_array($cached)) {
            return $this->sanitizeVehicleList($cached);
        }

        $response = $this->request('vehicle');
        $items    = [];
        if (is_array($response) && isset($response['items']) && is_array($response['items'])) {
            $items = $response['items'];
        }

        $vehicles = $this->buildVehicleList($items);

        $GLPI_CACHE->set($cache_key, $vehicles, 600);

        return $vehicles;
    }

    /**
     * @param array<array-key, mixed> $items
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
     */
    private function buildVehicleList(array $items): array
    {
        $vehicles = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }

            $vehicles[] = [
                'id'           => $this->toString($item['id']),
                'name'         => $this->toString($item['name'] ?? null),
                'registration' => $this->toString($item['registration'] ?? null),
                'group'        => $this->toString($item['groupName'] ?? null),
                'type'         => $this->toString($item['type'] ?? null),
                'make_model'   => trim($this->toString($item['make'] ?? null) . ' ' . $this->toString($item['model'] ?? null)),
                'status'       => $this->toString($item['status'] ?? null),
            ];
        }

        usort($vehicles, static fn(array $a, array $b) => strnatcasecmp($a['name'], $b['name']));

        return $vehicles;
    }

    /**
     * Re-validate already-built vehicle entries coming from the cache
     * (built shape, not the raw API payload handled by buildVehicleList).
     *
     * @param array<array-key, mixed> $entries
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
     */
    private function sanitizeVehicleList(array $entries): array
    {
        $vehicles = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['id'])) {
                continue;
            }

            $vehicles[] = [
                'id'           => $this->toString($entry['id']),
                'name'         => $this->toString($entry['name'] ?? null),
                'registration' => $this->toString($entry['registration'] ?? null),
                'group'        => $this->toString($entry['group'] ?? null),
                'type'         => $this->toString($entry['type'] ?? null),
                'make_model'   => $this->toString($entry['make_model'] ?? null),
                'status'       => $this->toString($entry['status'] ?? null),
            ];
        }

        return $vehicles;
    }

    private function toString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function toStringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Great-circle distance between two points, in kilometers.
     */
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
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
            ltrim($path, '/'),
        );

        $client = $this->http_client ?? Toolbox::getGuzzleClient([
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
        } catch (GuzzleException $guzzleException) {
            Toolbox::logInFile('fleetview', sprintf("GET %s failed: %s\n", $path, $guzzleException->getMessage()));
            throw new MasternautApiException(__('Unable to reach the Masternaut API.', 'fleetview'), 0, $guzzleException);
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
            $message = __('unknown error', 'fleetview');
            try {
                $error = json_decode($body, true);
                if (is_array($error) && is_string($error['errorMsg'] ?? null)) {
                    $message = $error['errorMsg'];
                }
            } catch (JsonException) {
                // Unparsable error body: keep the generic message
            }

            Toolbox::logInFile('fleetview', sprintf("GET %s returned HTTP %d: %s\n", $path, $status, $body));
            throw new MasternautApiException(sprintf(
                __('Masternaut API error (HTTP %1$d): %2$s', 'fleetview'),
                $status,
                $message,
            ));
        }

        if ($body === '') {
            return [];
        }

        try {
            return json_decode($body, true);
        } catch (JsonException $jsonException) {
            Toolbox::logInFile('fleetview', sprintf("GET %s returned unparsable JSON\n", $path));
            throw new MasternautApiException(__('Unable to reach the Masternaut API.', 'fleetview'), 0, $jsonException);
        }
    }
}
