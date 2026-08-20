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

namespace GlpiPlugin\Fleetview\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Fleetview\Masternaut\MasternautApiException;
use GlpiPlugin\Fleetview\Masternaut\MasternautClient;
use GlpiPlugin\Fleetview\PluginConfig;
use GlpiPlugin\Fleetview\Routing\OsrmRouter;
use Location;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ticket;

/**
 * AJAX endpoints backing the "nearby technicians" map modal on the ticket
 * form. Routes are exposed under `/plugins/fleetview/`. Authentication is
 * enforced by the GLPI firewall (default strategy: authenticated user).
 */
final class MapController extends AbstractController
{
    /**
     * Geographic context of a ticket: coordinates of its location, if any.
     * Used by the JS to decide whether the map button should be displayed.
     */
    #[Route(path: 'ticket/{id}/context', name: 'ticket_context', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function ticketContext(int $id): JsonResponse
    {
        $ticket = Ticket::getById($id);
        if ($ticket === false || !$ticket->canViewItem()) {
            return new JsonResponse(['error' => 'Ticket not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'available'  => $this->getTicketLocation($ticket) !== null,
            'configured' => PluginConfig::isApiConfigured(),
            'location'   => $this->getTicketLocation($ticket),
        ]);
    }

    /**
     * Fleet vehicles near the ticket location, with live positions.
     */
    #[Route(path: 'ticket/{id}/vehicles', name: 'ticket_vehicles', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function ticketVehicles(Request $request, int $id): JsonResponse
    {
        $ticket = Ticket::getById($id);
        if ($ticket === false || !$ticket->canViewItem()) {
            return new JsonResponse(['error' => 'Ticket not found'], Response::HTTP_NOT_FOUND);
        }

        $location = $this->getTicketLocation($ticket);
        if ($location === null) {
            return new JsonResponse(['error' => 'Ticket has no geolocated location'], Response::HTTP_BAD_REQUEST);
        }

        $config = PluginConfig::getConfig();

        // Optional radius override from the modal selector
        $radius = $request->query->get('radius');
        if (is_numeric($radius)) {
            $config['search_radius'] = (string) min(500, max(1, (int) $radius));
        }

        $client = new MasternautClient($config);
        if (!$client->isConfigured()) {
            return new JsonResponse(['configured' => false, 'vehicles' => []]);
        }

        try {
            $vehicles = $client->getNearbyVehicles($location['latitude'], $location['longitude']);
        } catch (MasternautApiException $e) {
            return new JsonResponse([
                'configured' => true,
                'error'      => $e->getMessage(),
                'vehicles'   => [],
            ], Response::HTTP_BAD_GATEWAY);
        }

        // Best-effort driving time estimations; vehicles keep null values
        // when the routing service is disabled or unavailable.
        $routes = (new OsrmRouter($config['routing_base_url']))
            ->getRoutesFromPoint($location['latitude'], $location['longitude'], $vehicles);
        foreach ($vehicles as $i => &$vehicle) {
            $vehicle['travel_time_min'] = $routes[$i]['duration_min'] ?? null;
            $vehicle['road_distance_km'] = $routes[$i]['distance_km'] ?? null;
        }
        unset($vehicle);

        // Closest by driving time first; vehicles without an estimation come
        // last, ordered by straight-line distance.
        usort($vehicles, static fn(array $a, array $b) => ($a['travel_time_min'] ?? PHP_INT_MAX) <=> ($b['travel_time_min'] ?? PHP_INT_MAX)
            ?: $a['distance_km'] <=> $b['distance_km']);

        return new JsonResponse([
            'configured'    => true,
            'radius_km'     => (float) $config['search_radius'],
            'marker_colors' => [
                'top1' => $config['marker_color_top1'],
                'top2' => $config['marker_color_top2'],
                'top3' => $config['marker_color_top3'],
            ],
            'vehicles'      => $vehicles,
        ]);
    }

    /**
     * Coordinates of the ticket location, or null when the ticket has no
     * location or the location has no latitude/longitude.
     *
     * @return ?array{name: string, latitude: float, longitude: float}
     */
    private function getTicketLocation(Ticket $ticket): ?array
    {
        $location = Location::getById((int) $ticket->fields['locations_id']);
        if ($location === false) {
            return null;
        }

        $latitude  = trim((string) $location->fields['latitude']);
        $longitude = trim((string) $location->fields['longitude']);

        if ($latitude === '' || $longitude === '' || !is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }

        return [
            'name'      => $location->getFriendlyName(),
            'latitude'  => (float) $latitude,
            'longitude' => (float) $longitude,
        ];
    }
}
