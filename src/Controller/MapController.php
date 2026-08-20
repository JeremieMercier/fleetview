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

namespace GlpiPlugin\Fleetview\Controller;

use Glpi\Controller\AbstractController;
use Closure;
use CommonITILActor;
use GlpiPlugin\Fleetview\Masternaut\MasternautApiException;
use GlpiPlugin\Fleetview\Masternaut\MasternautClient;
use GlpiPlugin\Fleetview\PluginConfig;
use GlpiPlugin\Fleetview\Routing\OsrmRouter;
use GlpiPlugin\Fleetview\TechnicianMatcher;
use GlpiPlugin\Fleetview\VehicleMapping;
use Location;
use Safe\Exceptions\JsonException;
use Ticket_User;
use User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ticket;

use function Safe\json_decode;

/**
 * AJAX endpoints backing the "nearby technicians" map modal on the ticket
 * form. Routes are exposed under `/plugins/fleetview/`. Authentication is
 * enforced by the GLPI firewall (default strategy: authenticated user).
 */
final class MapController extends AbstractController
{
    /**
     * The factories build the API clients from the runtime configuration
     * (radius override included); tests inject factories returning clients
     * with mocked HTTP transports.
     *
     * @param ?Closure(array<string, string>): MasternautClient $masternaut_factory
     * @param ?Closure(string): OsrmRouter                      $osrm_factory
     */
    public function __construct(
        private ?Closure $masternaut_factory = null,
        private ?Closure $osrm_factory = null,
    ) {}

    /**
     * Geographic context of a ticket: coordinates of its location, if any.
     * Used by the JS to decide whether the map button should be displayed.
     */
    #[Route(path: 'ticket/{id}/context', name: 'ticket_context', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function ticketContext(int $id): JsonResponse
    {
        $ticket = Ticket::getById($id);
        if ($ticket === false || !$ticket->canViewItem()) {
            return new JsonResponse(['error' => 'Ticket not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'available'     => $this->getTicketLocation($ticket) !== null,
            'configured'    => PluginConfig::isApiConfigured(),
            'location'      => $this->getTicketLocation($ticket),
            'marker_color'  => PluginConfig::getConfig()['marker_color_ticket'],
        ]);
    }

    /**
     * Fleet vehicles near the ticket location, with live positions.
     */
    #[Route(path: 'ticket/{id}/vehicles', name: 'ticket_vehicles', requirements: ['id' => '\d+'], methods: ['GET'])]
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

        $client = $this->buildMasternautClient($config);
        if (!$client->isConfigured()) {
            return new JsonResponse(['configured' => false, 'vehicles' => []]);
        }

        try {
            $vehicles = $client->getNearbyVehicles($location['latitude'], $location['longitude']);
        } catch (MasternautApiException $masternautApiException) {
            return new JsonResponse([
                'configured' => true,
                'error'      => $masternautApiException->getMessage(),
                'vehicles'   => [],
            ], Response::HTTP_BAD_GATEWAY);
        }

        // Best-effort driving time estimations; vehicles keep null values
        // when the routing service is disabled or unavailable.
        $routes = $this->buildOsrmRouter($config['routing_base_url'])
            ->getRoutesFromPoint($location['latitude'], $location['longitude'], $vehicles);
        foreach ($vehicles as $i => &$vehicle) {
            $vehicle['travel_time_min'] = $routes[$i]['duration_min'] ?? null;
            $vehicle['road_distance_km'] = $routes[$i]['distance_km'] ?? null;
            // Map-specific status labels ("Available" reads better than
            // "In circulation" when picking a technician)
            $vehicle['status_label'] = match ($vehicle['status']) {
                'IN_CIRCULATION' => __('Available', 'fleetview'),
                'IN_MAINTENANCE' => __('In maintenance', 'fleetview'),
                default          => null,
            };
        }

        unset($vehicle);

        // Closest by driving time first; vehicles without an estimation come
        // last, ordered by straight-line distance.
        usort($vehicles, static fn(array $a, array $b) => ($a['travel_time_min'] ?? PHP_INT_MAX) <=> ($b['travel_time_min'] ?? PHP_INT_MAX)
            ?: $a['distance_km'] <=> $b['distance_km']);

        // Link vehicles to GLPI users so they can be assigned from the modal:
        // explicit associations first, optional name matching as fallback.
        $can_assign = (bool) $ticket->canAssign();
        $mappings   = $can_assign ? VehicleMapping::getMap() : [];
        $matcher    = $config['name_matching_fallback'] ? new TechnicianMatcher() : null;
        foreach ($vehicles as &$vehicle) {
            $user_id = null;
            if ($can_assign) {
                $user_id = $mappings[$vehicle['id']]
                    ?? $matcher?->match($vehicle['label'])
                    ?? $matcher?->match($vehicle['driver_name']);
            }

            $vehicle['user_id'] = $user_id;
        }

        unset($vehicle);

        return new JsonResponse([
            'configured'    => true,
            'can_assign'    => $can_assign,
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
     * Assign a technician (matched from a fleet vehicle) to the ticket.
     */
    #[Route(path: 'ticket/{id}/assign', name: 'ticket_assign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function assignTechnician(Request $request, int $id): JsonResponse
    {
        $ticket = Ticket::getById($id);
        if ($ticket === false || !$ticket->canViewItem()) {
            return new JsonResponse(['error' => __('Ticket not found', 'fleetview')], Response::HTTP_NOT_FOUND);
        }

        if (!$ticket->canAssign()) {
            return new JsonResponse(['error' => __('You are not allowed to assign this ticket.', 'fleetview')], Response::HTTP_FORBIDDEN);
        }

        try {
            $payload = json_decode((string) $request->getContent(), true);
        } catch (JsonException) {
            $payload = null;
        }

        $users_id = is_array($payload) && is_numeric($payload['users_id'] ?? null) ? (int) $payload['users_id'] : 0;

        $user = User::getById($users_id);
        if ($user === false || !$user->fields['is_active'] || $user->fields['is_deleted']) {
            return new JsonResponse(['error' => __('User not found', 'fleetview')], Response::HTTP_BAD_REQUEST);
        }

        $ticket_user = new Ticket_User();
        $already = $ticket_user->getFromDBByCrit([
            'tickets_id' => $id,
            'users_id'   => $users_id,
            'type'       => CommonITILActor::ASSIGN,
        ]);

        if (!$already) {
            $added = $ticket_user->add([
                'tickets_id'       => $id,
                'users_id'         => $users_id,
                'type'             => CommonITILActor::ASSIGN,
                'use_notification' => 1,
            ]);

            if (!$added) {
                return new JsonResponse(['error' => __('Unable to assign the technician.', 'fleetview')], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        return new JsonResponse([
            'success'   => true,
            'already'   => $already,
            'user_name' => $user->getFriendlyName(),
        ]);
    }

    /**
     * @param array<string, string> $config
     */
    private function buildMasternautClient(array $config): MasternautClient
    {
        if ($this->masternaut_factory !== null) {
            return ($this->masternaut_factory)($config);
        }

        return new MasternautClient($config);
    }

    private function buildOsrmRouter(string $base_url): OsrmRouter
    {
        if ($this->osrm_factory !== null) {
            return ($this->osrm_factory)($base_url);
        }

        return new OsrmRouter($base_url);
    }

    /**
     * Coordinates of the ticket location, or null when the ticket has no
     * location or the location has no latitude/longitude.
     *
     * @return ?array{name: string, latitude: float, longitude: float}
     */
    private function getTicketLocation(Ticket $ticket): ?array
    {
        $locations_id = $ticket->fields['locations_id'] ?? null;
        if (!is_numeric($locations_id)) {
            return null;
        }

        $location = Location::getById((int) $locations_id);
        if ($location === false) {
            return null;
        }

        $raw_latitude  = $location->fields['latitude'] ?? null;
        $raw_longitude = $location->fields['longitude'] ?? null;
        if (!is_scalar($raw_latitude) || !is_scalar($raw_longitude)) {
            return null;
        }

        $latitude  = trim((string) $raw_latitude);
        $longitude = trim((string) $raw_longitude);

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
