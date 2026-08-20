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
use GlpiPlugin\Fleetview\PluginConfig;
use GlpiPlugin\Fleetview\VehicleMapping;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Saves the two forms of the plugin configuration page (API settings and
 * vehicle to user associations). CSRF is enforced by the core
 * CheckCsrfListener (`_glpi_csrf_token` field of the forms).
 */
final class ConfigController extends AbstractController
{
    #[Route(path: 'config', name: 'config_save', methods: ['POST'])]
    public function saveConfig(Request $request): RedirectResponse
    {
        Session::checkRight(PluginConfig::$rightname, UPDATE);

        // Only known configuration keys, filtered (an empty secret means
        // "keep the current one"); values are encrypted as declared to the
        // SECURED_CONFIGS hook.
        $input = array_intersect_key(
            PluginConfig::configUpdate($request->request->all()),
            PluginConfig::getDefaults()
        );
        Config::setConfigurationValues(PluginConfig::CONTEXT, $input);

        Session::addMessageAfterRedirect(__('Configuration has been saved.', 'fleetview'));
        Session::setActiveTab(PluginConfig::class, PluginConfig::class . '$1');

        return $this->redirectToPluginPage();
    }

    #[Route(path: 'mappings', name: 'mappings_save', methods: ['POST'])]
    public function saveMappings(Request $request): RedirectResponse
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

        // Land back on the associations tab (forcetab cannot resolve the
        // itemtype from a plugin front URL, so set the session tab directly)
        Session::setActiveTab(PluginConfig::class, PluginConfig::class . '$2');

        return $this->redirectToPluginPage();
    }

    private function redirectToPluginPage(): RedirectResponse
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return new RedirectResponse($CFG_GLPI['root_doc'] . '/plugins/fleetview/front/config.form.php');
    }
}
