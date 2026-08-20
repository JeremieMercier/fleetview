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

/**
 * Masternaut (Michelin Connected Fleet) API client.
 *
 * TODO: implement once the API documentation (docs/api/) and credentials are
 * available. Authentication, endpoints and payloads will be filled in then.
 */
final class MasternautClient
{
    /** @var array<string, string> */
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? PluginConfig::getConfig();
    }

    public function isConfigured(): bool
    {
        return $this->config['api_base_url'] !== '' && $this->config['api_secret'] !== '';
    }

    /**
     * Latest known position of each vehicle of the fleet.
     *
     * @return list<array{
     *      id: string,
     *      label: string,
     *      latitude: float,
     *      longitude: float,
     *      updated_at: string,
     * }>
     */
    public function getVehiclePositions(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        // TODO: call the Masternaut API (auth + positions endpoint), map the
        // response to the structure documented above, and cache the result for
        // `cache_lifetime` seconds.
        return [];
    }
}
