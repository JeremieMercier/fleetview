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
use GlpiPlugin\Fleetview\Masternaut\MasternautClient;
use GlpiPlugin\Fleetview\PluginConfig;
use Location;
use Symfony\Component\HttpFoundation\JsonResponse;
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

        $payload = [
            'available'  => false,
            'configured' => PluginConfig::isApiConfigured(),
            'location'   => null,
        ];

        $location = Location::getById((int) $ticket->fields['locations_id']);
        if ($location !== false) {
            $latitude  = trim((string) $location->fields['latitude']);
            $longitude = trim((string) $location->fields['longitude']);

            if ($latitude !== '' && $longitude !== '' && is_numeric($latitude) && is_numeric($longitude)) {
                $payload['available'] = true;
                $payload['location']  = [
                    'name'      => $location->getFriendlyName(),
                    'latitude'  => (float) $latitude,
                    'longitude' => (float) $longitude,
                ];
            }
        }

        return new JsonResponse($payload);
    }

    /**
     * Latest known positions of the fleet vehicles (technicians).
     */
    #[Route(path: 'vehicles', name: 'vehicles', methods: ['GET'])]
    public function vehicles(): JsonResponse
    {
        $client = new MasternautClient();

        return new JsonResponse([
            'configured' => $client->isConfigured(),
            'vehicles'   => $client->getVehiclePositions(),
        ]);
    }
}
