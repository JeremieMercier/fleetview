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

namespace GlpiPlugin\Fleetview\Tests;

use CommonITILActor;
use Config;
use Entity;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Fleetview\Controller\MapController;
use GlpiPlugin\Fleetview\Masternaut\MasternautClient;
use GlpiPlugin\Fleetview\PluginConfig;
use GlpiPlugin\Fleetview\Routing\OsrmRouter;
use GlpiPlugin\Fleetview\VehicleMapping;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Location;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Ticket;
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
     * @return array<array-key, mixed>
     */
    private static function payload(JsonResponse $response): array
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
        $payload  = self::payload($response);

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

        $payload = self::payload((new MapController())->ticketContext($ticket->getID()));

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

        $payload = self::payload(
            (new MapController())->ticketVehicles(Request::create(''), $ticket->getID()),
        );

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
        $payload = self::payload((new MapController())->assignTechnician($request, $ticket->getID()));
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
        $payload = self::payload((new MapController())->assignTechnician($request, $ticket->getID()));
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
        ], $overrides));
    }

    /**
     * @param list<Response> $responses
     */
    private static function mockedHttpClient(array $responses): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
    }

    /**
     * Controller wired with mocked Masternaut (fleet + positions) and OSRM
     * transports. The fictional fleet: asset 201 (Dupont Jean, in
     * circulation, ~1.6 km) and asset 202 (Martin Sophie, in maintenance,
     * ~9 km) — OSRM makes 202 the fastest despite being the farthest.
     *
     * @param list<Response> $masternaut_responses
     */
    private function mockedController(?array $masternaut_responses = null): MapController
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

        $osrm_responses = [
            new Response(200, [], json_encode([
                'code'      => 'Ok',
                'durations' => [[0, 1800, 600]],
                'distances' => [[0, 12000, 9500]],
            ])),
        ];

        return new MapController(
            fn(array $config): MasternautClient => new MasternautClient(
                $config,
                self::mockedHttpClient($masternaut_responses),
            ),
            fn(string $base_url): OsrmRouter => new OsrmRouter(
                $base_url,
                self::mockedHttpClient($osrm_responses),
            ),
        );
    }

    public function testTicketVehiclesFullFlowWithMockedClients(): void
    {
        $this->login('glpi');
        $this->configurePluginApi();
        $ticket = $this->createTicket($this->createGeoLocation()->getID());
        VehicleMapping::save('202', 'Martin Sophie', 4242);

        $payload = self::payload(
            $this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()),
        );

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

        $payload = self::payload(
            $this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()),
        );

        $by_id = array_column($payload['vehicles'], 'user_id', 'id');
        $this->assertSame($technician_id, $by_id['201']);
    }

    public function testTicketVehiclesClampsTheRadiusOverride(): void
    {
        $this->login('glpi');
        $this->configurePluginApi();
        $ticket = $this->createTicket($this->createGeoLocation()->getID());

        $payload = self::payload(
            $this->mockedController()->ticketVehicles(Request::create('', 'GET', ['radius' => '9999']), $ticket->getID()),
        );

        $this->assertSame(500, (int) $payload['radius_km']);
    }

    public function testTicketVehiclesReportsApiFailures(): void
    {
        $this->login('glpi');
        $this->configurePluginApi();
        $ticket = $this->createTicket($this->createGeoLocation()->getID());

        $response = $this->mockedController([new Response(500, [], 'boom')])
            ->ticketVehicles(Request::create(''), $ticket->getID());
        $payload = self::payload($response);

        $this->assertSame(502, $response->getStatusCode());
        $this->assertTrue($payload['configured']);
        $this->assertNotSame('', $payload['error']);
        $this->assertSame([], $payload['vehicles']);
    }

    public function testTicketVehiclesHidesAssignmentsWithoutTheRight(): void
    {
        $this->login('glpi');
        $this->configurePluginApi();
        $requester_id = getItemByTypeName(User::class, 'post-only', true);
        $ticket = $this->createTicket($this->createGeoLocation()->getID(), $requester_id);
        VehicleMapping::save('202', 'Martin Sophie', 4242);

        $this->login('post-only');
        $payload = self::payload(
            $this->mockedController()->ticketVehicles(Request::create(''), $ticket->getID()),
        );

        $this->assertFalse($payload['can_assign']);
        $this->assertSame([null, null], array_column($payload['vehicles'], 'user_id'));
    }
}
