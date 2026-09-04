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
use GlpiPlugin\Fleetview\Profile;
use GlpiPlugin\Fleetview\Routing\OsrmRouter;
use GlpiPlugin\Fleetview\TechnicianAgenda;
use GlpiPlugin\Fleetview\TechnicianMatcher;
use GlpiPlugin\Fleetview\VehicleMapping;
use Location;
use Profile_User;
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
/**
 * @phpstan-import-type Vehicle from MasternautClient
 */
final class MapController extends AbstractController
{
    /** Closest vehicles whose road route is drawn (matches the ranked marker colors) */
    private const DRAWN_ROUTES = 3;

    /**
     * The factories build the API clients from the runtime configuration
     * (radius override included); tests inject factories returning clients
     * with mocked HTTP transports.
     *
     * @param ?Closure(array<string, string>): MasternautClient $masternaut_factory
     * @param ?Closure(string): OsrmRouter                      $osrm_factory
     */
    public function __construct(
        private readonly ?Closure $masternaut_factory = null,
        private readonly ?Closure $osrm_factory = null,
    ) {}

    /**
     * Geographic context of a ticket: coordinates of its location, if any.
     * Used by the JS to decide whether the map button should be displayed
     * (a 403 hides it as well).
     */
    #[Route(path: 'ticket/{id}/context', name: 'ticket_context', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function ticketContext(int $id): JsonResponse
    {
        if (!Profile::canViewMap()) {
            return $this->forbiddenResponse();
        }

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
        if (!Profile::canViewMap()) {
            return $this->forbiddenResponse();
        }

        $ticket = Ticket::getById($id);
        if ($ticket === false || !$ticket->canViewItem()) {
            return new JsonResponse(['error' => 'Ticket not found'], Response::HTTP_NOT_FOUND);
        }

        $location = $this->getTicketLocation($ticket);
        if ($location === null) {
            return new JsonResponse(['error' => 'Ticket has no geolocated location'], Response::HTTP_BAD_REQUEST);
        }

        $config = PluginConfig::getConfig();

        // Optional radius override from the modal selector, bounded by the
        // configured maximum radius (the configured radius is the default
        // of the selector, the maximum is the widest search allowed).
        $max_radius = min(500, max(1, (int) $config['search_radius_max']));
        $radius     = $request->query->get('radius');
        if (!is_numeric($radius)) {
            $radius = $config['search_radius'];
        }

        $config['search_radius'] = (string) min($max_radius, max(1, (int) $radius));

        // Optional override of the unlinked vehicles toggle from the modal
        // (the configured value is its default state)
        $show_unlinked = (bool) $config['modal_show_unlinked'];
        $toggle        = $request->query->get('show_unlinked');
        if (in_array($toggle, ['0', '1'], true)) {
            $show_unlinked = $toggle === '1';
        }

        $client = $this->buildMasternautClient($config);
        if (!$client->isConfigured()) {
            return new JsonResponse(['configured' => false, 'vehicles' => []]);
        }

        // Link vehicles to GLPI users: explicit associations first, optional
        // name matching as fallback. The link drives the unlinked vehicles
        // filter and the planned interventions of the popup; the assignment
        // button additionally needs the right.
        $mappings = VehicleMapping::getMap();
        $matcher  = $config['name_matching_fallback'] ? new TechnicianMatcher() : null;

        try {
            $vehicles = $client->getNearbyVehicles(
                $location['latitude'],
                $location['longitude'],
                // Filtered before the closest ones are kept, so the top 3
                // ranking and `max_results` only count displayed vehicles
                fn(array $vehicle): bool => $show_unlinked || $this->resolveTechnician($vehicle, $mappings, $matcher) !== null,
            );
        } catch (MasternautApiException $masternautApiException) {
            return new JsonResponse([
                'configured' => true,
                'error'      => $masternautApiException->getMessage(),
                'vehicles'   => [],
            ], Response::HTTP_BAD_GATEWAY);
        }

        // Best-effort driving time estimations; vehicles keep null values
        // when the routing service is disabled or unavailable.
        $router = $this->buildOsrmRouter($config['routing_base_url']);
        $routes = $router->getRoutesFromPoint($location['latitude'], $location['longitude'], $vehicles);
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

        // Road geometry of the closest vehicles (the ones with a ranked
        // marker color), drawn on the map; best effort as well.
        $geometries = $config['map_show_routes']
            ? $router->getRouteGeometriesToPoint($location['latitude'], $location['longitude'], array_slice($vehicles, 0, self::DRAWN_ROUTES))
            : [];
        foreach ($vehicles as $i => &$vehicle) {
            $vehicle['route_geometry'] = $geometries[$i] ?? null;
        }

        unset($vehicle);

        $can_assign = (bool) $ticket->canAssign();
        $assignees  = array_column($ticket->getUsers(CommonITILActor::ASSIGN), 'users_id');
        $linked     = [];
        foreach ($vehicles as $i => &$vehicle) {
            $user_id = $this->resolveTechnician($vehicle, $mappings, $matcher);

            $linked[$i]                   = $user_id;
            $vehicle['user_id']           = $can_assign ? $user_id : null;
            $vehicle['technician_linked'] = $user_id !== null;
            $vehicle['technician_name']   = $user_id !== null ? getUserName($user_id) : null;
            // Already assigned (individually) to the ticket
            $vehicle['assigned']          = $user_id !== null && in_array($user_id, $assignees, false);
        }

        unset($vehicle);

        // Planned interventions of the linked technicians (0 = disabled).
        // The section is hidden (null) for technicians whose planning the
        // user may not consult, per the GLPI planning right.
        $max_tasks   = max(0, (int) $config['popup_max_tasks']);
        $with_events = (bool) $config['popup_external_events'];
        $viewable    = $max_tasks > 0
            ? TechnicianAgenda::filterViewablePlannings(array_values(array_filter($linked)))
            : [];
        $agenda      = $viewable !== []
            ? TechnicianAgenda::getPlannedTasks($viewable, $max_tasks, $with_events)
            : [];
        foreach ($vehicles as $i => &$vehicle) {
            $users_id = $linked[$i];
            $hidden   = $max_tasks <= 0 || ($users_id !== null && !in_array($users_id, $viewable, true));
            $entry    = $users_id !== null ? ($agenda[$users_id] ?? null) : null;
            $vehicle['planned_tasks']      = $hidden ? null : ($entry['tasks'] ?? []);
            $vehicle['planned_tasks_more'] = $entry['more'] ?? 0;
        }

        unset($vehicle);

        return new JsonResponse([
            'configured'    => true,
            'can_assign'    => $can_assign,
            'radius_km'     => (float) $config['search_radius'],
            'radius_max_km' => (float) $max_radius,
            'max_tasks'     => $max_tasks,
            'with_events'   => $with_events,
            'title_source'  => $config['popup_title_source'] === 'technician' ? 'technician' : 'vehicle',
            'show_registration' => (bool) $config['popup_show_registration'],
            // Effective state of the unlinked vehicles toggle
            'show_unlinked' => $show_unlinked,
            // User's "due date" warning color, reused for the in-progress
            // badge (the technician is already busy)
            'warning_color' => is_string($_SESSION['glpiduedatewarning_color'] ?? null) ? $_SESSION['glpiduedatewarning_color'] : '#f39f5a',
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
        if (!Profile::canViewMap()) {
            return $this->forbiddenResponse();
        }

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

        // The assignee is not taken on trust from the request: it must be
        // one of the technicians the map may offer for this ticket, and be
        // allowed to take tickets in its entity, as the native actor
        // dropdown requires ("own ticket" right, entity restricted).
        if (!in_array($users_id, $this->getAssignableTechnicians($ticket), true)) {
            return new JsonResponse(
                ['error' => __('This technician cannot be assigned from the map.', 'fleetview')],
                Response::HTTP_FORBIDDEN,
            );
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
     * The map right is checked before anything else: without it, the fleet
     * data (live positions, driver names) must not be reachable, whatever
     * the ticket rights.
     */
    private function forbiddenResponse(): JsonResponse
    {
        return new JsonResponse(
            ['error' => __('You are not allowed to view the fleet map.', 'fleetview')],
            Response::HTTP_FORBIDDEN,
        );
    }

    /**
     * Technicians the map may offer for the ticket: users linked to a fleet
     * vehicle (explicit association, or name matching of the vehicles the
     * map can display around the ticket when the fallback is enabled),
     * restricted to those holding the "own ticket" right in the ticket
     * entity. The eligibility of the assignee is decided here, server-side.
     *
     * @return list<int>
     */
    private function getAssignableTechnicians(Ticket $ticket): array
    {
        $config     = PluginConfig::getConfig();
        $mappings   = VehicleMapping::getMap();
        $candidates = array_values(array_unique(array_values($mappings)));

        $location = $this->getTicketLocation($ticket);
        if ($config['name_matching_fallback'] && $location !== null) {
            // Widest search the modal allows, so that every vehicle it may
            // have displayed is considered (positions are cached)
            $config['search_radius'] = $config['search_radius_max'];
            $client = $this->buildMasternautClient($config);
            if ($client->isConfigured()) {
                $matcher = new TechnicianMatcher();
                try {
                    foreach ($client->getNearbyVehicles($location['latitude'], $location['longitude']) as $vehicle) {
                        $users_id = $this->resolveTechnician($vehicle, $mappings, $matcher);
                        if ($users_id !== null) {
                            $candidates[] = $users_id;
                        }
                    }
                } catch (MasternautApiException) {
                    // No name-matched candidate when the fleet is unreachable
                }
            }
        }

        $entities_id = $ticket->getEntityID();
        $assignable  = [];
        foreach (array_unique($candidates) as $users_id) {
            foreach (Profile_User::getUserEntitiesForRight($users_id, Ticket::$rightname, Ticket::OWN) as $entity) {
                if (is_numeric($entity) && (int) $entity === $entities_id) {
                    $assignable[] = $users_id;
                    break;
                }
            }
        }

        return $assignable;
    }

    /**
     * GLPI user linked to a vehicle: explicit association first, optional
     * name matching (vehicle name, then driver name) as fallback.
     *
     * @param Vehicle            $vehicle
     * @param array<string, int> $mappings asset id => users_id
     */
    private function resolveTechnician(array $vehicle, array $mappings, ?TechnicianMatcher $matcher): ?int
    {
        return $mappings[$vehicle['id']]
            ?? $matcher?->match($vehicle['label'])
            ?? $matcher?->match($vehicle['driver_name']);
    }

    /**
     * @param array<string, string> $config
     */
    private function buildMasternautClient(array $config): MasternautClient
    {
        if ($this->masternaut_factory instanceof Closure) {
            return ($this->masternaut_factory)($config);
        }

        return new MasternautClient($config);
    }

    private function buildOsrmRouter(string $base_url): OsrmRouter
    {
        if ($this->osrm_factory instanceof Closure) {
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
