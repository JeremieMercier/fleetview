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

use Config;
use Glpi\Controller\AbstractController;
use GlpiPlugin\Fleetview\VehicleMapping;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Saves the vehicle to user associations submitted from the plugin
 * configuration screen. CSRF is enforced by the core CheckCsrfListener
 * (`_glpi_csrf_token` field of the form).
 */
final class MappingController extends AbstractController
{
    #[Route(path: 'mappings', name: 'mappings_save', methods: ['POST'])]
    public function save(Request $request): RedirectResponse
    {
        Session::checkRight(VehicleMapping::$rightname, UPDATE);

        $mappings = $request->request->all('mappings');
        $labels   = $request->request->all('labels');

        foreach ($mappings as $asset_id => $users_id) {
            VehicleMapping::save(
                (string) $asset_id,
                (string) ($labels[$asset_id] ?? ''),
                (int) $users_id
            );
        }

        Session::addMessageAfterRedirect(__('Vehicle associations have been saved.', 'fleetview'));

        return new RedirectResponse(
            Config::getFormURL() . '?forcetab=' . urlencode('GlpiPlugin\Fleetview\PluginConfig$1')
        );
    }
}
