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

use Html;
use CommonITILActor;
use Config;
use Entity;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Fleetview\Controller\MapController;
use GlpiPlugin\Fleetview\Masternaut\MasternautClient;
use GlpiPlugin\Fleetview\PluginConfig;
use GlpiPlugin\Fleetview\Profile;
use GlpiPlugin\Fleetview\Routing\OsrmRouter;
use GlpiPlugin\Fleetview\VehicleMapping;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Group;
use Group_User;
use Location;
use Planning;
use PlanningEventCategory;
use PlanningExternalEvent;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Ticket;
use TicketTask;
use Ticket_User;
use User;

/**
 * Functional tests against the GLPI test database. Every test runs inside a
 * rolled-back transaction; all people and places are fictional.
 */
final class MapControllerTest extends DbTestCase
{
    private function rootEntityId(): int
    {
        return getItemByTypeName(Entity::class, '_test_root_entity', true);
    }

    private function createGeoLocation(): Location
    {
        $location = new Location();
        $id = $location->add([
            'name'         => 'Site Test Alpha',
            'entities_id'  => $this->rootEntityId(),
            'latitude'     => '48.8566',
            'longitude'    => '2.3522',
        ]);
        $this->assertGreaterThan(0, $id);

        return $location;
    }

    private function createTicket(int $locations_id = 0, int $requester_id = 0): Ticket
    {
        $ticket = new Ticket();
        $input  = [
            'name'         => 'Ticket test fictif',
            'content'      => 'Contenu de test fictif',
            'entities_id'  => $this->rootEntityId(),
            'locations_id' => $locations_id,
        ];
        if ($requester_id > 0) {
            $input['_actors'] = [
                'requester' => [['itemtype' => 'User', 'items_id' => $requester_id]],
            ];
        }

        $id = $ticket->add($input);
        $this->assertGreaterThan(0, $id);

        return $ticket;
    }

    /**
     * Planned task of a technician on a ticket, offset in hours from now
     * (negative = already started / over).
     */
    private function createPlannedTask(Ticket $ticket, int $users_id_tech, int $begin_hours, int $end_hours, array $extra = []): int
    {
        global $DB;

        // Offsets from the database clock: "in progress" and "over" are
        // decided by MySQL, whose timezone may differ from PHP's
        $now = $DB->doQuery('SELECT NOW() AS now')->fetch_assoc()['now'] ?? null;
        $this->assertIsString($now);

        $task = new TicketTask();
        $id   = $task->add($extra + [
            'tickets_id'    => $ticket->getID(),
            'content'       => 'Intervention fictive',
            'users_id_tech' => $users_id_tech,
            'begin'         => date('Y-m-d H:i:s', strtotime(sprintf('%s %+d hours', $now, $begin_hours))),
            'end'           => date('Y-m-d H:i:s', strtotime(sprintf('%s %+d hours', $now, $end_hours))),
            'state'         => Planning::TODO,
        ]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function payload(JsonResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testTicketContextWithoutGeolocatedLocation(): void
    {
        $this->login('glpi');
        $ticket = $this->createTicket();

        $response = (new MapController())->ticketContext($ticket->getID());
        $payload  = $this->payload($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($payload['available']);
        // The API is not configured in the test database
        $this->assertFalse($payload['configured']);
        $this->assertNull($payload['location']);
        $this->assertSame('#d63939', $payload['marker_color']);
    }

    public function testTicketContextWithGeolocatedLocation(): void
    {
        $this->login('glpi');
        $ticket = $this->createTicket($this->createGeoLocation()->getID());

        $payload = $this->payload((new MapController())->ticketContext($ticket->getID()));

        $this->assertTrue($payload['available']);
        $this->assertSame(48.8566, $payload['location']['latitude']);
        $this->assertSame(2.3522, $payload['location']['longitude']);
        $this->assertStringContainsString('Site Test Alpha', $payload['location']['name']);
    }

    public function testTicketContextRejectsUnknownTicket(): void
    {
        $this->login('glpi');

        $this->assertSame(404, (new MapController())->ticketContext(999999999)->getStatusCode());
    }

    public function testTicketVehiclesRequiresAGeolocatedTicket(): void
    {
        $this->login('glpi');
        $ticket = $this->createTicket();

        $response = (new MapController())->ticketVehicles(Request::create(''), $ticket->getID());

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testTicketVehiclesReportsUnconfiguredApi(): void
    {
        $this->login('glpi');
        $ticket = $this->createTicket($this->createGeoLocation()->getID());

        $payload = $this->payload((new MapController())->ticketVehicles(Request::create(''), $ticket->getID()));

        $this->assertFalse($payload['configured']);
        $this->assertSame([], $payload['vehicles']);
    }

    public function testAssignTechnicianAddsTheActorOnce(): void
    {
        $this->login('glpi');
        $ticket = $this->createTicket();

        $technician = new User();
        $technician_id = $technician->add([
            'name'      => 'fake_tech_' . uniqid(),
            'firstname' => 'Jean',
            'realname'  => 'Dupont',
            'is_active' => 1,
        ]);
        $this->assertGreaterThan(0, $technician_id);

        $request = Request::create('', 'POST', content: json_encode(['users_id' => $technician_id]));

        // First call: actor added
        $payload = $this->payload((new MapController())->assignTechnician($request, $ticket->getID()));
        $this->assertTrue($payload['success']);
        $this->assertFalse($payload['already']);
        $this->assertSame(
            1,
            countElementsInTable(Ticket_User::getTable(), [
                'tickets_id' => $ticket->getID(),
                'users_id'   => $technician_id,
                'type'       => CommonITILActor::ASSIGN,
            ]),
        );

        // Second call: idempotent
        $payload = $this->payload((new MapController())->assignTechnician($request, $ticket->getID()));
        $this->assertTrue($payload['success']);
        $this->assertTrue($payload['already']);
        $this->assertSame(
            1,
            countElementsInTable(Ticket_User::getTable(), [
                'tickets_id' => $ticket->getID(),
                'users_id'   => $technician_id,
                'type'       => CommonITILActor::ASSIGN,
            ]),
        );
    }

    public function testAssignTechnicianRejectsInvalidUsers(): void
    {
        $this->login('glpi');
        $ticket = $this->createTicket();

        // Unknown user id
        $request  = Request::create('', 'POST', content: json_encode(['users_id' => 999999999]));
        $this->assertSame(400, (new MapController())->assignTechnician($request, $ticket->getID())->getStatusCode());

        // Unparsable payload
        $request = Request::create('', 'POST', content: 'not json');
        $this->assertSame(400, (new MapController())->assignTechnician($request, $ticket->getID())->getStatusCode());
    }

    public function testAssignTechnicianRequiresTheAssignRight(): void
    {
        // A post-only user can see their own ticket but cannot assign it
        $this->login('glpi');
        $requester_id = getItemByTypeName(User::class, 'post-only', true);
        $ticket = $this->createTicket(0, $requester_id);

        $this->login('post-only');
        $request  = Request::create('', 'POST', content: json_encode(['users_id' => $requester_id]));
        $response = (new MapController())->assignTechnician($request, $ticket->getID());

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Store a fake, complete API configuration in the test database
     * (unique identifiers per test: cache keys derive from them).
     */
    private function configurePluginApi(array $overrides = []): void
    {
        Config::setConfigurationValues(PluginConfig::CONTEXT, array_merge([
            'api_base_url'     => 'https://fleet.example.test/public',
            'customer_id'      => 'TEST_' . uniqid(),
            'api_username'     => 'fake_api_user',
            'api_secret'       => 'fake-secret-value',
            'routing_base_url' => 'https://osrm.example.test/' . uniqid(),
            // Routes need one extra mocked response per vehicle: opt-in
            'map_show_routes'  => '0',
            // The fixtures mix linked and unlinked vehicles: show them all,
            // the unlinked vehicles filter has its own tests
            'modal_show_unlinked' => '1',
        ], $overrides));
    }

    /**
     * @param list<Response> $responses
     */
    private function mockedHttpClient(array $responses): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
    }

    /**
     * Controller wired with mocked Masternaut (fleet + positions) and OSRM
     * transports. The fictional fleet: asset 201 (Dupont Jean, in
     * circulation, ~1.6 km) and asset 202 (Martin Sophie, in maintenance,
     * ~9 km) — OSRM makes 202 the fastest despite being the farthest.
     *
     * @param list<Response>  $masternaut_responses
     * @param ?list<Response> $osrm_responses       Table response first, then
     *        one route response per vehicle when routes are enabled
     */
    private function mockedController(?array $masternaut_responses = null, ?array $osrm_responses = null): MapController
    {
        $masternaut_responses ??= [
            new Response(200, [], json_encode(['items' => [
                [
                    'id'        => '201',
                    'name'      => 'Dupont Jean',
                    'groupName' => 'Zone Alpha',
                    'status'    => 'IN_CIRCULATION',
                ],
                [
                    'id'        => '202',
                    'name'      => 'Martin Sophie',
                    'groupName' => 'Zone Alpha',
                    'status'    => 'IN_MAINTENANCE',
                ],
            ]])),
            new Response(200, [], json_encode(['items' => [
                [
                    'assetId'   => '201',
                    'assetName' => 'Dupont Jean',
                    'latitude'  => 48.87,
                    'longitude' => 2.36,
                ],
                [
                    'assetId'   => '202',
                    'assetName' => 'Martin Sophie',
                    'latitude'  => 48.93,
                    'longitude' => 2.40,
                ],
            ]])),
        ];

        $osrm_responses ??= [
            new Response(200, [], json_encode([
                'code'      => 'Ok',
                'durations' => [[0, 1800, 600]],
                'distances' => [[0, 12000, 9500]],
            ])),
        ];

        return new MapController(
            fn(array $config): MasternautClient => new MasternautClient(
                $config,
                $this->mockedHttpClient($masternaut_responses),
            ),
            fn(string $base_url): OsrmRouter => new OsrmRouter(
                $base_url,
                $this->mockedHttpClient($osrm_responses),
            ),
        );
    }

    public function testTicketVehiclesFullFlowWithMockedClients(): void
    {
        $this->login('glpi');
        $this->configurePluginApi();
        $ticket = $this->createTicket($this->createGeoLocation()->getID());
        VehicleMapping::save('202', 'Martin Sophie', 4242);

        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));

        $this->assertTrue($payload['configured']);
        $this->assertTrue($payload['can_assign']);
        $this->assertSame(50, (int) $payload['radius_km']);
        $this->assertSame('#2fb344', $payload['marker_colors']['top1']);

        // Sorted by driving time: 202 is farther but faster
        $this->assertSame(['202', '201'], array_column($payload['vehicles'], 'id'));

        [$fastest, $other] = $payload['vehicles'];
        $this->assertSame(10, $fastest['travel_time_min']);
        $this->assertSame(9.5, $fastest['road_distance_km']);
        $this->assertSame(30, $other['travel_time_min']);

        // Map-specific status labels
        $this->assertSame('In maintenance', $fastest['status_label']);
        $this->assertSame('Available', $other['status_label']);

        // Explicit association resolved; no name fallback by default
        $this->assertSame(4242, $fastest['user_id']);
        $this->assertNull($other['user_id']);
    }

    public function testTicketVehiclesUsesNameMatchingWhenEnabled(): void
    {
        $this->login('glpi');
        $this->configurePluginApi(['name_matching_fallback' => '1']);
        $ticket = $this->createTicket($this->createGeoLocation()->getID());

        $technician = new User();
        $technician_id = $technician->add([
            'name'      => 'fake_tech_' . uniqid(),
            'firstname' => 'Jean',
            'realname'  => 'Dupont',
            'is_active' => 1,
        ]);
        $this->assertGreaterThan(0, $technician_id);

        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));

        $by_id = array_column($payload['vehicles'], 'user_id', 'id');
        $this->assertSame($technician_id, $by_id['201']);
    }

    public function testTicketVehiclesHidesUnlinkedVehiclesWhenConfigured(): void
    {
        $this->login('glpi');
        $this->configurePluginApi(['modal_show_unlinked' => '0']);
        $ticket = $this->createTicket($this->createGeoLocation()->getID());
        VehicleMapping::save('202', 'Martin Sophie', 4242);

        // Only the linked vehicle reaches the routing service
        $controller = $this->mockedController(null, [
            new Response(200, [], json_encode([
                'code'      => 'Ok',
                'durations' => [[0, 600]],
                'distances' => [[0, 9500]],
            ])),
        ]);
        $payload = $this->payload($controller->ticketVehicles(Request::create(''), $ticket->getID()));

        $this->assertFalse($payload['show_unlinked']);
        $this->assertSame(['202'], array_column($payload['vehicles'], 'id'));
        $this->assertSame(10, $payload['vehicles'][0]['travel_time_min']);
    }

    public function testTicketVehiclesShowsUnlinkedVehiclesWhenConfigured(): void
    {
        $this->login('glpi');
        $this->configurePluginApi(['modal_show_unlinked' => '1']);
        $ticket = $this->createTicket($this->createGeoLocation()->getID());
        VehicleMapping::save('202', 'Martin Sophie', 4242);

        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));

        $this->assertTrue($payload['show_unlinked']);
        $this->assertSame(['202', '201'], array_column($payload['vehicles'], 'id'));
    }

    public function testTicketVehiclesHonoursTheUnlinkedVehiclesToggle(): void
    {
        $this->login('glpi');
        $this->configurePluginApi(['modal_show_unlinked' => '0']);
        $ticket = $this->createTicket($this->createGeoLocation()->getID());
        VehicleMapping::save('202', 'Martin Sophie', 4242);

        // The modal toggle overrides the configured default, both ways
        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create('', 'GET', ['show_unlinked' => '1']), $ticket->getID()));
        $this->assertTrue($payload['show_unlinked']);
        $this->assertSame(['202', '201'], array_column($payload['vehicles'], 'id'));

        $one_route = [new Response(200, [], json_encode(['code' => 'Ok', 'durations' => [[0, 600]], 'distances' => [[0, 9500]]]))];
        $this->configurePluginApi(['modal_show_unlinked' => '1']);
        $payload = $this->payload($this->mockedController(null, $one_route)->ticketVehicles(Request::create('', 'GET', ['show_unlinked' => '0']), $ticket->getID()));
        $this->assertFalse($payload['show_unlinked']);
        $this->assertSame(['202'], array_column($payload['vehicles'], 'id'));

        // Anything else falls back on the configuration
        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create('', 'GET', ['show_unlinked' => 'yes']), $ticket->getID()));
        $this->assertTrue($payload['show_unlinked']);
        $this->assertCount(2, $payload['vehicles']);
    }

    public function testTicketVehiclesCountsNameMatchedVehiclesAsLinked(): void
    {
        $this->login('glpi');
        $this->configurePluginApi(['modal_show_unlinked' => '0', 'name_matching_fallback' => '1']);
        $ticket = $this->createTicket($this->createGeoLocation()->getID());

        $technician = new User();
        $technician_id = $technician->add([
            'name'      => 'fake_tech_' . uniqid(),
            'firstname' => 'Jean',
            'realname'  => 'Dupont',
            'is_active' => 1,
        ]);
        $this->assertGreaterThan(0, $technician_id);

        $one_route = [new Response(200, [], json_encode(['code' => 'Ok', 'durations' => [[0, 1800]], 'distances' => [[0, 12000]]]))];
        $payload = $this->payload($this->mockedController(null, $one_route)->ticketVehicles(Request::create(''), $ticket->getID()));

        $this->assertSame(['201'], array_column($payload['vehicles'], 'id'));
        $this->assertSame($technician_id, $payload['vehicles'][0]['user_id']);
    }

    public function testTicketVehiclesFiltersUnlinkedVehiclesBeforeTheResultsLimit(): void
    {
        $this->login('glpi');
        // 201 is the closest as the crow flies: with one result only, it
        // would be the one kept without the filter
        $this->configurePluginApi(['modal_show_unlinked' => '0', 'max_results' => '1']);
        $ticket = $this->createTicket($this->createGeoLocation()->getID());
        VehicleMapping::save('202', 'Martin Sophie', 4242);

        $one_route = [new Response(200, [], json_encode(['code' => 'Ok', 'durations' => [[0, 600]], 'distances' => [[0, 9500]]]))];
        $payload = $this->payload($this->mockedController(null, $one_route)->ticketVehicles(Request::create(''), $ticket->getID()));

        $this->assertSame(['202'], array_column($payload['vehicles'], 'id'));
    }

    public function testTicketVehiclesClampsTheRadiusOverrideToTheConfiguredMaximum(): void
    {
        $this->login('glpi');
        $this->configurePluginApi(['search_radius' => '50', 'search_radius_max' => '75']);
        $ticket = $this->createTicket($this->createGeoLocation()->getID());

        // No override: the configured radius
        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));
        $this->assertSame(50, (int) $payload['radius_km']);
        $this->assertSame(75, (int) $payload['radius_max_km']);

        // Wider than the maximum: the maximum, not the provider limit of 500 km
        foreach (['9999', '500', '76'] as $radius) {
            $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create('', 'GET', ['radius' => $radius]), $ticket->getID()));
            $this->assertSame(75, (int) $payload['radius_km'], "radius=$radius");
        }

        // Within the maximum: honoured, wider than the default included
        foreach (['25', '75'] as $radius) {
            $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create('', 'GET', ['radius' => $radius]), $ticket->getID()));
            $this->assertSame((int) $radius, (int) $payload['radius_km'], "radius=$radius");
        }

        // Below the minimum
        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create('', 'GET', ['radius' => '0']), $ticket->getID()));
        $this->assertSame(1, (int) $payload['radius_km']);
    }

    public function testTicketVehiclesBoundsTheDefaultRadiusByTheConfiguredMaximum(): void
    {
        $this->login('glpi');
        // Inconsistent settings (default wider than the maximum, maximum
        // above the provider limit): the maximum wins, capped at 500 km
        $this->configurePluginApi(['search_radius' => '300', 'search_radius_max' => '9999']);
        $ticket = $this->createTicket($this->createGeoLocation()->getID());

        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));
        $this->assertSame(300, (int) $payload['radius_km']);
        $this->assertSame(500, (int) $payload['radius_max_km']);

        $this->configurePluginApi(['search_radius' => '300', 'search_radius_max' => '100']);
        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));
        $this->assertSame(100, (int) $payload['radius_km']);
        $this->assertSame(100, (int) $payload['radius_max_km']);
    }

    public function testTicketVehiclesReportsApiFailures(): void
    {
        $this->login('glpi');
        $this->configurePluginApi();
        $ticket = $this->createTicket($this->createGeoLocation()->getID());

        $response = $this->mockedController([new Response(500, [], 'boom')])
            ->ticketVehicles(Request::create(''), $ticket->getID());
        $payload = $this->payload($response);

        $this->assertSame(502, $response->getStatusCode());
        $this->assertTrue($payload['configured']);
        $this->assertNotSame('', $payload['error']);
        $this->assertSame([], $payload['vehicles']);
    }

    public function testMapEndpointsRequireTheMapRightWhateverTheTicketRights(): void
    {
        $this->login('glpi');
        $this->configurePluginApi();
        $ticket = $this->createTicket($this->createGeoLocation()->getID());
        $this->assertTrue($ticket->canViewItem());
        $this->assertNotEmpty($ticket->canAssign());

        $_SESSION['glpiactiveprofile'][Profile::RIGHTNAME] = 0;
        $controller = $this->mockedController();

        $response = $controller->ticketContext($ticket->getID());
        $this->assertSame(403, $response->getStatusCode());
        $this->assertArrayNotHasKey('location', $this->payload($response));

        $response = $controller->ticketVehicles(Request::create(''), $ticket->getID());
        $this->assertSame(403, $response->getStatusCode());
        $this->assertArrayNotHasKey('vehicles', $this->payload($response));

        $response = $controller->assignTechnician(
            Request::create('', 'POST', [], [], [], [], json_encode(['users_id' => getItemByTypeName(User::class, 'tech', true)])),
            $ticket->getID(),
        );
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, $ticket->countUsers(CommonITILActor::ASSIGN));
    }

    public function testRequestersHaveNoAccessToTheFleetData(): void
    {
        // The security review scenario: a self-service requester opening
        // the vehicles endpoint of their own geolocated ticket
        $this->login('glpi');
        $this->configurePluginApi();
        $requester_id = getItemByTypeName(User::class, 'post-only', true);
        $ticket = $this->createTicket($this->createGeoLocation()->getID(), $requester_id);
        VehicleMapping::save('202', 'Martin Sophie', 4242);

        $this->login('post-only');
        $this->assertTrue($ticket->canViewItem());
        $this->assertSame(0, (int) ($_SESSION['glpiactiveprofile'][Profile::RIGHTNAME] ?? 0));

        $controller = $this->mockedController();
        $this->assertSame(403, $controller->ticketContext($ticket->getID())->getStatusCode());

        $response = $controller->ticketVehicles(Request::create(''), $ticket->getID());
        $this->assertSame(403, $response->getStatusCode());
        $this->assertArrayNotHasKey('vehicles', $this->payload($response));
    }

    public function testTicketVehiclesHidesAssignmentsWithoutTheAssignRight(): void
    {
        $this->login('glpi');
        $this->configurePluginApi();
        $ticket = $this->createTicket($this->createGeoLocation()->getID());
        VehicleMapping::save('202', 'Martin Sophie', 4242);

        // Map right kept, ticket assignment right removed
        $_SESSION['glpiactiveprofile']['ticket'] &= ~Ticket::ASSIGN;
        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));

        $this->assertFalse($payload['can_assign']);
        $this->assertSame([null, null], array_column($payload['vehicles'], 'user_id'));
        // The link itself is still reported: it drives the planned interventions
        $this->assertSame([true, false], array_column($payload['vehicles'], 'technician_linked'));
    }

    public function testTicketVehiclesDrawsTheRoutesOfTheClosestVehicles(): void
    {
        $this->login('glpi');
        $this->configurePluginApi(['map_show_routes' => '1']);
        $ticket = $this->createTicket($this->createGeoLocation()->getID());

        $route = static fn(array $coordinates): Response => new Response(200, [], json_encode([
            'code'   => 'Ok',
            'routes' => [['geometry' => ['type' => 'LineString', 'coordinates' => $coordinates]]],
        ]));
        $controller = $this->mockedController(null, [
            new Response(200, [], json_encode([
                'code'      => 'Ok',
                'durations' => [[0, 1800, 600]],
                'distances' => [[0, 12000, 9500]],
            ])),
            // Vehicles are ranked 202 then 201: route responses follow that order
            $route([[2.40, 48.93], [2.38, 48.90], [2.35, 48.85]]),
            $route([[2.36, 48.87], [2.35, 48.85]]),
        ]);

        $payload = $this->payload($controller->ticketVehicles(Request::create(''), $ticket->getID()));

        [$first, $second] = $payload['vehicles'];
        $this->assertSame('202', $first['id']);
        $this->assertSame([[2.40, 48.93], [2.38, 48.90], [2.35, 48.85]], $first['route_geometry']);
        $this->assertSame([[2.36, 48.87], [2.35, 48.85]], $second['route_geometry']);
    }

    public function testTicketVehiclesSkipsRoutesWhenDisabled(): void
    {
        $this->login('glpi');
        $this->configurePluginApi();
        $ticket = $this->createTicket($this->createGeoLocation()->getID());

        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));

        $this->assertSame([null, null], array_column($payload['vehicles'], 'route_geometry'));
    }

    public function testTicketVehiclesFlagsTechniciansAlreadyAssignedToTheTicket(): void
    {
        $this->login('glpi');
        $this->configurePluginApi();
        $ticket  = $this->createTicket($this->createGeoLocation()->getID());
        $tech_id = getItemByTypeName(User::class, 'tech', true);
        VehicleMapping::save('202', 'Martin Sophie', $tech_id);

        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));
        $this->assertSame([false, false], array_column($payload['vehicles'], 'assigned'));

        $this->assertGreaterThan(0, (new Ticket_User())->add([
            'tickets_id' => $ticket->getID(),
            'users_id'   => $tech_id,
            'type'       => CommonITILActor::ASSIGN,
        ]));
        $ticket->getFromDB($ticket->getID());

        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));
        [$linked, $unlinked] = $payload['vehicles'];
        $this->assertSame('202', $linked['id']);
        $this->assertTrue($linked['assigned']);
        $this->assertFalse($unlinked['assigned']);
    }

    public function testTicketVehiclesExposesTheLinkedTechnicianNameAndTitleSource(): void
    {
        $this->login('glpi');
        $this->configurePluginApi(['popup_title_source' => 'technician']);
        $ticket  = $this->createTicket($this->createGeoLocation()->getID());
        $tech_id = getItemByTypeName(User::class, 'tech', true);
        VehicleMapping::save('202', 'Martin Sophie', $tech_id);

        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));

        $this->assertSame('technician', $payload['title_source']);
        $this->assertTrue($payload['show_registration']);
        [$linked, $unlinked] = $payload['vehicles'];
        $this->assertSame(getUserName($tech_id), $linked['technician_name']);
        $this->assertNull($unlinked['technician_name']);
    }

    public function testTicketVehiclesHonoursTheVehicleNameTitleAndHiddenRegistration(): void
    {
        $this->login('glpi');
        $this->configurePluginApi(['popup_title_source' => 'vehicle', 'popup_show_registration' => '0']);
        $ticket = $this->createTicket($this->createGeoLocation()->getID());

        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));

        $this->assertSame('vehicle', $payload['title_source']);
        $this->assertFalse($payload['show_registration']);
    }

    public function testTicketVehiclesListsPlannedTasksOfLinkedTechnicians(): void
    {
        $this->login('glpi');
        $this->configurePluginApi(['popup_max_tasks' => '2']);
        $ticket  = $this->createTicket($this->createGeoLocation()->getID());
        $tech_id = getItemByTypeName(User::class, 'tech', true);
        VehicleMapping::save('202', 'Martin Sophie', $tech_id);

        $job    = $this->createTicket();
        $other  = $this->createTicket();
        $closed = $this->createTicket();

        $in_progress = $this->createPlannedTask($job, $tech_id, -1, 1);
        $tomorrow    = $this->createPlannedTask($other, $tech_id, 24, 26);
        $this->createPlannedTask($job, $tech_id, 48, 50);            // beyond the limit
        $this->createPlannedTask($job, $tech_id, -48, -46);          // over
        $this->createPlannedTask($job, $tech_id, 72, 74, ['state' => Planning::DONE]);
        $this->createPlannedTask($closed, $tech_id, 96, 98);
        $this->assertTrue($closed->update(['id' => $closed->getID(), 'status' => Ticket::CLOSED]));

        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));
        $this->assertSame(2, $payload['max_tasks']);
        $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $payload['warning_color']);

        [$linked, $unlinked] = $payload['vehicles'];
        $this->assertSame('202', $linked['id']);
        $this->assertTrue($linked['technician_linked']);
        $this->assertSame([$in_progress, $tomorrow], array_column($linked['planned_tasks'], 'id'));
        $this->assertSame(1, $linked['planned_tasks_more']);

        $first = $linked['planned_tasks'][0];
        $this->assertTrue($first['in_progress']);
        $this->assertContains($first['day'], ['today', null]); // started an hour ago: today, or yesterday around midnight
        $this->assertSame($job->getID(), $first['tickets_id']);
        $this->assertSame('Ticket test fictif', $first['ticket_name']);
        $this->assertStringContainsString('ticket.form.php?id=' . $job->getID(), $first['url']);
        $this->assertNotSame('', $first['begin_label']);
        $this->assertFalse($linked['planned_tasks'][1]['in_progress']);

        // Same-day task: "begin – HH:MM" (the end time is the label's tail);
        // both full date-times when the 2-hour slot crosses midnight, which
        // happens when the suite runs late in the evening
        $next     = $linked['planned_tasks'][1];
        $same_day = substr($next['begin'], 0, 10) === substr($next['end'], 0, 10);
        $this->assertSame(
            $same_day
                ? $next['begin_label'] . ' – ' . substr($next['end_label'], -5)
                : $next['begin_label'] . ' – ' . $next['end_label'],
            $next['when_label'],
        );

        $this->assertFalse($unlinked['technician_linked']);
        $this->assertSame([], $unlinked['planned_tasks']);
        $this->assertSame(0, $unlinked['planned_tasks_more']);
    }

    public function testTicketVehiclesHidesPrivateTasksFromOtherUsers(): void
    {
        $this->login('glpi');
        $this->configurePluginApi();
        $requester_id = getItemByTypeName(User::class, 'post-only', true);
        $ticket  = $this->createTicket($this->createGeoLocation()->getID(), $requester_id);
        $tech_id = getItemByTypeName(User::class, 'tech', true);
        VehicleMapping::save('202', 'Martin Sophie', $tech_id);

        // A ticket the requester may read (its own), so that only the
        // private task right decides
        $job    = $this->createTicket(0, $requester_id);
        $public = $this->createPlannedTask($job, $tech_id, 24, 26);
        $this->createPlannedTask($job, $tech_id, 48, 50, ['is_private' => 1]);

        // Map right and "see all plannings" granted, private tasks right
        // still that of the requester profile
        $this->login('post-only');
        $_SESSION['glpiactiveprofile'][Profile::RIGHTNAME] = READ;
        $_SESSION['glpiactiveprofile']['planning']         = Planning::READALL;
        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));

        $this->assertSame([$public], array_column($payload['vehicles'][0]['planned_tasks'], 'id'));
    }

    public function testTicketVehiclesHidesTasksOfTicketsTheUserMayNotRead(): void
    {
        $this->login('glpi');
        $this->configurePluginApi();
        $requester_id = getItemByTypeName(User::class, 'post-only', true);
        $ticket  = $this->createTicket($this->createGeoLocation()->getID(), $requester_id);
        $tech_id = getItemByTypeName(User::class, 'tech', true);
        VehicleMapping::save('202', 'Martin Sophie', $tech_id);

        // The technician is planned on a ticket of the requester and on a
        // ticket of somebody else; the requester profile only reads its own
        // tickets ("see my tickets"), the second one is out of reach
        $mine  = $this->createPlannedTask($this->createTicket(0, $requester_id), $tech_id, 24, 26);
        $other = $this->createPlannedTask($this->createTicket(), $tech_id, 48, 50);

        // Map right and "see all plannings" granted, ticket and task rights
        // still those of the requester profile
        $this->login('post-only');
        $_SESSION['glpiactiveprofile'][Profile::RIGHTNAME] = READ;
        $_SESSION['glpiactiveprofile']['planning']         = Planning::READALL;
        $this->assertFalse((bool) Session::haveRight('ticket', Ticket::READALL));
        $this->assertTrue((bool) Session::haveRight('ticket', Ticket::READMY));
        $this->assertTrue((bool) Session::haveRight('task', TicketTask::SEEPUBLIC));

        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));
        $this->assertSame([$mine], array_column($payload['vehicles'][0]['planned_tasks'], 'id'));
        $this->assertSame(0, $payload['vehicles'][0]['planned_tasks_more']);

        // Without any task right, no task at all (the section stays, for
        // the external events)
        $_SESSION['glpiactiveprofile']['task'] = 0;
        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));
        $this->assertSame([], $payload['vehicles'][0]['planned_tasks']);

        // "See all tickets": both listed
        $_SESSION['glpiactiveprofile']['task']   = TicketTask::SEEPUBLIC;
        $_SESSION['glpiactiveprofile']['ticket'] = Ticket::READALL;
        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));
        $this->assertSame([$mine, $other], array_column($payload['vehicles'][0]['planned_tasks'], 'id'));
    }

    public function testTicketVehiclesLabelsMultiDayTasksWithBothDates(): void
    {
        $this->login('glpi');
        $this->configurePluginApi();
        $ticket  = $this->createTicket($this->createGeoLocation()->getID());
        $tech_id = getItemByTypeName(User::class, 'tech', true);
        VehicleMapping::save('202', 'Martin Sophie', $tech_id);
        $this->createPlannedTask($this->createTicket(), $tech_id, 24, 72);

        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));
        $task    = $payload['vehicles'][0]['planned_tasks'][0];

        $this->assertSame($task['begin_label'] . ' – ' . $task['end_label'], $task['when_label']);
    }

    public function testTicketVehiclesLabelsFullDayTasksWithDatesOnly(): void
    {
        $this->login('glpi');
        $this->configurePluginApi();
        $ticket  = $this->createTicket($this->createGeoLocation()->getID());
        $tech_id = getItemByTypeName(User::class, 'tech', true);
        VehicleMapping::save('202', 'Martin Sophie', $tech_id);

        $job  = $this->createTicket();
        $task = new TicketTask();
        $base = [
            'tickets_id'    => $job->getID(),
            'content'       => 'Journée entière fictive',
            'users_id_tech' => $tech_id,
            'state'         => Planning::TODO,
        ];
        $one_day   = $task->add($base + ['begin' => '2030-03-10 00:00:00', 'end' => '2030-03-10 23:59:59']);
        $several   = $task->add($base + ['begin' => '2030-04-01 00:00:00', 'end' => '2030-04-03 23:59:59']);
        $midnight  = $task->add($base + ['begin' => '2030-05-01 00:00:00', 'end' => '2030-05-03 00:00:00']);
        $this->assertGreaterThan(0, min($one_day, $several, $midnight));

        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));
        $labels  = array_column($payload['vehicles'][0]['planned_tasks'], 'when_label', 'id');

        $this->assertSame([null, null, null], array_column($payload['vehicles'][0]['planned_tasks'], 'day'));
        $this->assertSame(Html::convDate('2030-03-10'), $labels[$one_day]);
        $this->assertSame(Html::convDate('2030-04-01') . ' – ' . Html::convDate('2030-04-03'), $labels[$several]);
        $this->assertSame(Html::convDate('2030-05-01') . ' – ' . Html::convDate('2030-05-02'), $labels[$midnight]);
    }

    public function testTicketVehiclesFlagsTasksOfTodayAndTomorrow(): void
    {
        global $DB;

        $this->login('glpi');
        $this->configurePluginApi();
        $ticket  = $this->createTicket($this->createGeoLocation()->getID());
        $tech_id = getItemByTypeName(User::class, 'tech', true);
        VehicleMapping::save('202', 'Martin Sophie', $tech_id);

        $today = $DB->doQuery('SELECT CURDATE() AS today')->fetch_assoc()['today'] ?? null;
        $this->assertIsString($today);

        $job  = $this->createTicket();
        $task = new TicketTask();
        $base = [
            'tickets_id'    => $job->getID(),
            'content'       => 'Intervention fictive',
            'users_id_tech' => $tech_id,
            'state'         => Planning::TODO,
        ];
        $day = static fn(int $offset, string $time): string => date('Y-m-d', strtotime(sprintf('%s +%d day', $today, $offset))) . ' ' . $time;
        $tonight   = $task->add($base + ['begin' => $day(0, '23:58:00'), 'end' => $day(0, '23:59:00')]);
        $tomorrow  = $task->add($base + ['begin' => $day(1, '09:00:00'), 'end' => $day(1, '10:00:00')]);
        $later     = $task->add($base + ['begin' => $day(2, '09:00:00'), 'end' => $day(2, '10:00:00')]);
        $this->assertGreaterThan(0, min($tonight, $tomorrow, $later));

        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));
        $days    = array_column($payload['vehicles'][0]['planned_tasks'], 'day', 'id');

        $this->assertSame('today', $days[$tonight]);
        $this->assertSame('tomorrow', $days[$tomorrow]);
        $this->assertNull($days[$later]);
    }

    /**
     * External event offset in hours from the database clock.
     */
    private function createExternalEvent(int $users_id, int $begin_hours, int $end_hours, array $extra = []): int
    {
        global $DB;

        $now = $DB->doQuery('SELECT NOW() AS now')->fetch_assoc()['now'] ?? null;
        $this->assertIsString($now);

        $event = new PlanningExternalEvent();
        $id    = $event->add($extra + [
            'name'        => 'Congés fictifs',
            'entities_id' => $this->rootEntityId(),
            'users_id'    => $users_id,
            'plan'        => [
                'begin' => date('Y-m-d H:i:s', strtotime(sprintf('%s %+d hours', $now, $begin_hours))),
                'end'   => date('Y-m-d H:i:s', strtotime(sprintf('%s %+d hours', $now, $end_hours))),
            ],
            'state'       => Planning::INFO,
        ]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    public function testTicketVehiclesMergesExternalEventsWithTasks(): void
    {
        $this->login('glpi');
        $this->configurePluginApi();
        $ticket   = $this->createTicket($this->createGeoLocation()->getID());
        $tech_id  = getItemByTypeName(User::class, 'tech', true);
        $other_id = getItemByTypeName(User::class, 'normal', true);
        VehicleMapping::save('202', 'Martin Sophie', $tech_id);

        $category = new PlanningEventCategory();
        $cat_id   = $category->add(['name' => 'RTT', 'color' => '#1e90ff']);
        $this->assertGreaterThan(0, $cat_id);

        $task  = $this->createPlannedTask($this->createTicket(), $tech_id, 30, 32);
        $owned = $this->createExternalEvent($tech_id, 10, 12, ['planningeventcategories_id' => $cat_id]);
        $guest = $this->createExternalEvent($other_id, 50, 52, ['users_id_guests' => [$tech_id], 'name' => 'Réunion fictive']);
        $this->createExternalEvent($other_id, 60, 62);                                   // someone else's
        $this->createExternalEvent($tech_id, -10, -8);                                   // over
        $this->createExternalEvent($tech_id, 70, 72, ['state' => Planning::DONE]);
        $this->createExternalEvent($tech_id, 80, 82, ['rrule' => ['freq' => 'weekly', 'interval' => 1]]); // recurring: ignored

        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));
        $this->assertTrue($payload['with_events']);

        $entries = $payload['vehicles'][0]['planned_tasks'];
        $this->assertSame(
            [['event', $owned], ['task', $task], ['event', $guest]],
            array_map(static fn(array $e) => [$e['type'], $e['id']], $entries),
        );

        $this->assertSame('RTT', $entries[0]['category']);
        $this->assertSame('#1e90ff', $entries[0]['color']);
        $this->assertSame('Congés fictifs', $entries[0]['ticket_name']);
        $this->assertStringContainsString('planningexternalevent.form.php?id=' . $owned, $entries[0]['url']);
        $this->assertSame('', $entries[1]['category']);
        $this->assertSame('Réunion fictive', $entries[2]['ticket_name']);
    }

    public function testTicketVehiclesHidesThePlanningSectionWithoutPlanningRights(): void
    {
        $this->login('glpi');
        $this->configurePluginApi();
        $ticket  = $this->createTicket($this->createGeoLocation()->getID());
        $tech_id = getItemByTypeName(User::class, 'tech', true);
        VehicleMapping::save('202', 'Martin Sophie', $tech_id);
        $this->createPlannedTask($this->createTicket(), $tech_id, 24, 26);
        $this->createExternalEvent($tech_id, 10, 12);

        // "See my planning" only: the linked technician is somebody else
        $_SESSION['glpiactiveprofile']['planning'] = Planning::READMY;
        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));

        [$linked, $unlinked] = $payload['vehicles'];
        $this->assertTrue($linked['technician_linked']);
        $this->assertNull($linked['planned_tasks']);
        $this->assertSame(0, $linked['planned_tasks_more']);
        // Unlinked vehicles keep their "not linked" section
        $this->assertSame([], $unlinked['planned_tasks']);

        // No planning right at all: hidden as well
        $_SESSION['glpiactiveprofile']['planning'] = 0;
        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));
        $this->assertNull($payload['vehicles'][0]['planned_tasks']);
    }

    public function testTicketVehiclesShowsOwnPlanningWithSeeMineOnly(): void
    {
        $this->login('glpi');
        $this->configurePluginApi();
        $ticket = $this->createTicket($this->createGeoLocation()->getID());
        $me     = (int) Session::getLoginUserID();
        VehicleMapping::save('202', 'Martin Sophie', $me);
        $task = $this->createPlannedTask($this->createTicket(), $me, 24, 26);

        $_SESSION['glpiactiveprofile']['planning'] = Planning::READMY;
        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));

        $this->assertSame([$task], array_column($payload['vehicles'][0]['planned_tasks'], 'id'));
    }

    public function testTicketVehiclesLimitsThePlanningToGroupMembersWithSeeGroup(): void
    {
        $this->login('glpi');
        $this->configurePluginApi();
        $ticket  = $this->createTicket($this->createGeoLocation()->getID());
        $tech_id = getItemByTypeName(User::class, 'tech', true);
        $other   = new User();
        $other_id = $other->add(['name' => 'fake_other_' . uniqid(), 'is_active' => 1]);
        $this->assertGreaterThan(0, $other_id);
        VehicleMapping::save('202', 'Martin Sophie', $tech_id);
        VehicleMapping::save('201', 'Dupont Jean', $other_id);
        $tech_task = $this->createPlannedTask($this->createTicket(), $tech_id, 24, 26);
        $this->createPlannedTask($this->createTicket(), $other_id, 24, 26);

        // The current user and "tech" share a group, "other" does not
        $group    = new Group();
        $group_id = $group->add(['name' => 'fake_group_' . uniqid(), 'entities_id' => $this->rootEntityId(), 'is_recursive' => 1]);
        $this->assertGreaterThan(0, $group_id);
        foreach ([(int) Session::getLoginUserID(), $tech_id] as $users_id) {
            $this->assertGreaterThan(0, (new Group_User())->add(['groups_id' => $group_id, 'users_id' => $users_id]));
        }

        $_SESSION['glpigroups']                    = [$group_id];
        $_SESSION['glpiactiveprofile']['planning'] = Planning::READGROUP | Planning::READMY;
        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));

        $by_id = array_column($payload['vehicles'], null, 'id');
        $this->assertSame([$tech_task], array_column($by_id['202']['planned_tasks'], 'id'));
        $this->assertNull($by_id['201']['planned_tasks']);
    }

    public function testTicketVehiclesSkipsExternalEventsWhenDisabled(): void
    {
        $this->login('glpi');
        $this->configurePluginApi(['popup_external_events' => '0']);
        $ticket  = $this->createTicket($this->createGeoLocation()->getID());
        $tech_id = getItemByTypeName(User::class, 'tech', true);
        VehicleMapping::save('202', 'Martin Sophie', $tech_id);
        $this->createExternalEvent($tech_id, 10, 12);
        $task = $this->createPlannedTask($this->createTicket(), $tech_id, 30, 32);

        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));

        $this->assertFalse($payload['with_events']);
        $this->assertSame([$task], array_column($payload['vehicles'][0]['planned_tasks'], 'id'));
    }

    public function testTicketVehiclesSkipsPlannedTasksWhenDisabled(): void
    {
        $this->login('glpi');
        $this->configurePluginApi(['popup_max_tasks' => '0']);
        $ticket  = $this->createTicket($this->createGeoLocation()->getID());
        $tech_id = getItemByTypeName(User::class, 'tech', true);
        VehicleMapping::save('202', 'Martin Sophie', $tech_id);
        $this->createPlannedTask($this->createTicket(), $tech_id, 24, 26);

        $payload = $this->payload($this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()));

        $this->assertSame(0, $payload['max_tasks']);
        $this->assertSame([null, null], array_column($payload['vehicles'], 'planned_tasks'));
    }
}
