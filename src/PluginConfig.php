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

namespace GlpiPlugin\Fleetview;

use CommonGLPI;
use Config;
use Glpi\Application\View\TemplateRenderer;
use GLPIKey;
use GlpiPlugin\Fleetview\Masternaut\MasternautApiException;
use GlpiPlugin\Fleetview\Masternaut\MasternautClient;
use Safe\Exceptions\JsonException;
use Safe\Exceptions\UrlException;
use Session;

use function Safe\json_decode;
use function Safe\json_encode;
use function Safe\parse_url;

/**
 * Plugin configuration, stored in the `glpi_configs` table under the
 * `plugin:fleetview` context. The `api_secret` value is encrypted (see the
 * SECURED_CONFIGS hook in setup.php).
 */
final class PluginConfig extends CommonGLPI
{
    public const CONTEXT = 'plugin:fleetview';

    /**
     * Values encrypted with GLPIKey in the `glpi_configs` table.
     * Also declared to the SECURED_CONFIGS hook in setup.php.
     */
    public const SECURED = ['api_secret'];

    public static $rightname = 'config';

    public static function getTypeName($nb = 0): string
    {
        return __('Fleetview', 'fleetview');
    }

    public static function getIcon(): string
    {
        return 'ti ti-map-pin';
    }

    /**
     * Default configuration values, also used at install time.
     *
     * @return array<string, string>
     */
    public static function getDefaults(): array
    {
        return [
            'api_base_url'   => 'https://api.masternautconnect.com/connect-webservices/services/public',
            'customer_id'    => '',    // Connect customer number, part of endpoint URLs
            'api_username'   => '',    // Connect Partner user (HTTP Basic auth)
            'api_secret'     => '',
            'search_radius'  => '50',  // km around the ticket location, default of the map selector
            'search_radius_max' => '150', // km, upper limit of the map selector (API max: 500)
            'max_results'    => '10',  // maximum number of vehicles returned
            'cache_lifetime' => '60',  // seconds, positions cache (API limit: 1 req/15s)
            // Restrict the vehicles shown in the map modal; empty = no filter,
            // multiple choices stored as a JSON array
            'modal_group'  => '',      // Masternaut group names
            'modal_status' => '',      // IN_CIRCULATION / IN_MAINTENANCE / SOLD
            // OSRM-compatible routing service for driving time estimations.
            // Coordinates (ticket site, vehicles) are sent to this service:
            // opt-in, empty = disabled. The public demo server
            // (https://router.project-osrm.org) may be entered explicitly.
            'routing_base_url' => '',
            // Fall back on name matching (vehicle/driver name vs GLPI users)
            // when a vehicle has no explicit association. Off by default:
            // it only makes sense when vehicles are named after technicians.
            'name_matching_fallback' => '0',
            // Ticket location marker color
            'marker_color_ticket' => '#d63939',
            // Vehicle marker colors by proximity rank (road ranking); other
            // vehicles keep the native Leaflet blue marker.
            'marker_color_top1' => '#2fb344', // closest vehicle
            'marker_color_top2' => '#f59f00', // 2nd closest
            'marker_color_top3' => '#ae3ec9', // 3rd closest
            // Planned interventions (ticket tasks) listed in the vehicle
            // marker popup; 0 = section hidden
            'popup_max_tasks' => '6',
            // Also list the GLPI planning external events of the linked
            // technician, merged chronologically with the tasks
            'popup_external_events' => '1',
            // Marker popup title: the linked GLPI technician name
            // ('technician', falls back on the vehicle name when no
            // technician is linked) or the Masternaut vehicle name ('vehicle')
            'popup_title_source' => 'technician',
            // Show the vehicle registration plate in the marker popup
            'popup_show_registration' => '1',
            // Draw the road route of the 3 closest vehicles (needs the
            // routing service; one extra request per route)
            'map_show_routes' => '1',
            // Default state of the modal toggle showing the vehicles that
            // are not linked to a GLPI technician (explicit association or
            // name matching). Off by default: the map is meant to pick a
            // technician, unlinked vehicles cannot be assigned.
            'modal_show_unlinked' => '0',
        ];
    }

    /**
     * Get current configuration (defaults merged with stored values,
     * secured values already decrypted by core).
     *
     * @return array<string, string>
     */
    public static function getConfig(): array
    {
        $values = [];
        foreach (Config::getConfigurationValues(self::CONTEXT) as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $values[$name] = $value;
            }
        }

        // Core encrypts secured values on write (setConfigurationValues) but
        // getConfigurationValues returns them as-is: decrypt them here.
        $glpikey = new GLPIKey();
        foreach (self::SECURED as $name) {
            if (($values[$name] ?? '') !== '') {
                $values[$name] = (string) $glpikey->decrypt($values[$name]);
            }
        }

        return array_merge(self::getDefaults(), $values);
    }

    /**
     * URL prefix of the GLPI instance, for plugin routes and pages.
     */
    public static function getRootDoc(): string
    {
        /** @var array<string, mixed> $CFG_GLPI */
        global $CFG_GLPI;

        $root_doc = $CFG_GLPI['root_doc'] ?? '';

        return is_string($root_doc) ? $root_doc : '';
    }

    /**
     * Whether the Masternaut API access is configured.
     */
    public static function isApiConfigured(): bool
    {
        $config = self::getConfig();

        foreach (['api_base_url', 'customer_id', 'api_username', 'api_secret'] as $key) {
            if (trim($config[$key]) === '') {
                return false;
            }
        }

        return true;
    }

    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addStandardTab(self::class, $ong, $options);

        return $ong;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof self) {
            return [
                1 => self::createTabEntry(__('API', 'fleetview'), 0, $item::class, 'ti ti-plug'),
                2 => self::createTabEntry(__('Customization', 'fleetview'), 0, $item::class, 'ti ti-palette'),
                3 => self::createTabEntry(__('Vehicle to technician associations', 'fleetview'), 0, $item::class, 'ti ti-car'),
            ];
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof self) {
            match ((int) $tabnum) {
                2       => self::showDisplayForm(),
                3       => self::showMappingsForm(),
                default => self::showApiForm(),
            };
            return true;
        }

        return false;
    }

    private static function showApiForm(): void
    {
        if (!Session::haveRight(self::$rightname, UPDATE)) {
            return;
        }

        $config = self::getConfig();

        // Never expose the stored secret in the page source
        $config['api_secret'] = '';

        TemplateRenderer::getInstance()->display('@fleetview/config_api.html.twig', [
            'config'           => $config,
            'form_action'      => self::getRootDoc() . '/plugins/fleetview/config',
            'routing_external' => self::isExternalHost($config['routing_base_url']),
        ]);
    }

    /**
     * Whether a service URL points outside the organisation network:
     * anything but the loopback, the private IP ranges and hostnames
     * without a public domain. Used to warn about location data sent to a
     * third party.
     */
    public static function isExternalHost(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        try {
            $host = parse_url($url, PHP_URL_HOST);
        } catch (UrlException) {
            return false;
        }

        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            // Public IP: external; loopback, private and link-local: internal
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        // Bare hostname (no domain): the local network
        return str_contains($host, '.');
    }

    /**
     * Decode a stored list value ('' = empty, JSON array, or a legacy single
     * value from when these settings were single-choice).
     *
     * @return list<string>
     */
    public static function decodeListValue(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true);
        } catch (JsonException) {
            // Legacy single value, stored before these settings were lists
            return [$value];
        }

        if (!is_array($decoded)) {
            return [$value];
        }

        $list = [];
        foreach ($decoded as $item) {
            if (is_scalar($item)) {
                $list[] = (string) $item;
            }
        }

        return $list;
    }

    public static function getStatusLabel(string $status): string
    {
        return match ($status) {
            'IN_CIRCULATION' => __('In circulation', 'fleetview'),
            'IN_MAINTENANCE' => __('In maintenance', 'fleetview'),
            'SOLD'           => __('Sold', 'fleetview'),
            default          => $status,
        };
    }

    private static function showDisplayForm(): void
    {
        if (!Session::haveRight(self::$rightname, UPDATE)) {
            return;
        }

        $config = self::getConfig();

        // Same radius choices as the modal selector (keep in sync with
        // RADIUS_CHOICES in fleetview.js); the current values stay
        // selectable even if they are not part of the presets
        $radius_choices = [25, 50, 75, 100, 125, 150, 175, 200, 250, 300, 400, 500];
        $radius_choices[] = (int) $config['search_radius'];
        $radius_choices[] = (int) $config['search_radius_max'];
        $radius_choices = array_unique($radius_choices);
        sort($radius_choices);
        $radius_choices = array_combine(
            $radius_choices,
            array_map(static fn(int $km) => sprintf('%d km', $km), $radius_choices),
        );

        // Group choices for the map filter, from the fleet itself
        $groups = [];
        $client = new MasternautClient($config);
        if ($client->isConfigured()) {
            try {
                foreach ($client->getVehicles() as $vehicle) {
                    if ($vehicle['group'] !== '') {
                        $groups[$vehicle['group']] = $vehicle['group'];
                    }
                }

                ksort($groups);
            } catch (MasternautApiException) {
                // The dropdown simply stays empty when the API is unreachable
            }
        }

        TemplateRenderer::getInstance()->display('@fleetview/config_display.html.twig', [
            'config'            => $config,
            'form_action'       => self::getRootDoc() . '/plugins/fleetview/config',
            'radius_choices'    => $radius_choices,
            'groups'            => $groups,
            'selected_groups'   => self::decodeListValue($config['modal_group']),
            'selected_statuses' => self::decodeListValue($config['modal_status']),
            // SOLD is deliberately not offered: the positions endpoint never
            // returns sold vehicles, selecting it would display nothing
            'statuses'          => [
                'IN_CIRCULATION' => self::getStatusLabel('IN_CIRCULATION'),
                'IN_MAINTENANCE' => self::getStatusLabel('IN_MAINTENANCE'),
            ],
            'title_sources'     => [
                'technician' => __('Linked GLPI technician name', 'fleetview'),
                'vehicle'    => __('Masternaut vehicle name', 'fleetview'),
            ],
        ]);
    }

    private static function showMappingsForm(): void
    {
        if (!Session::haveRight(self::$rightname, UPDATE)) {
            return;
        }

        $config = self::getConfig();

        // Vehicle to user associations, with name-matching suggestions for
        // vehicles that are not associated yet.
        $vehicles       = [];
        $vehicles_error = null;
        $client         = new MasternautClient($config);
        if ($client->isConfigured()) {
            try {
                // Every association, whatever its entity: the screen manages
                // them all (new ones default to the active entity, with
                // child entities enabled)
                $mappings = VehicleMapping::getAll();
                $matcher  = new TechnicianMatcher();
                foreach ($client->getVehicles() as $vehicle) {
                    $mapping  = $mappings[$vehicle['id']] ?? null;
                    $users_id = $mapping['users_id'] ?? 0;
                    $vehicle['status_label'] = self::getStatusLabel($vehicle['status']);
                    $vehicles[] = $vehicle + [
                        'users_id'     => $users_id,
                        'suggested_id' => $users_id === 0 ? ($matcher->match($vehicle['name']) ?? 0) : 0,
                        'entities_id'  => $mapping['entities_id'] ?? Session::getActiveEntity(),
                        'is_recursive' => $mapping['is_recursive'] ?? true,
                    ];
                }
            } catch (MasternautApiException $e) {
                $vehicles_error = $e->getMessage();
            }
        }

        $active_entities = [];
        foreach ((array) ($_SESSION['glpiactiveentities'] ?? []) as $entities_id) {
            if (is_numeric($entities_id)) {
                $active_entities[] = (int) $entities_id;
            }
        }

        TemplateRenderer::getInstance()->display('@fleetview/config_mappings.html.twig', [
            'vehicles'        => $vehicles,
            'vehicles_error'  => $vehicles_error,
            'mappings_action' => self::getRootDoc() . '/plugins/fleetview/mappings',
            'active_entities' => $active_entities,
        ]);
    }

    /**
     * Filter the input coming from the core config form
     * (called by Config::prepareInputForUpdate() through `config_class`).
     *
     * @param array<array-key, mixed> $input
     *
     * @return array<array-key, mixed>
     */
    public static function configUpdate(array $input): array
    {
        // An empty secret means "keep the current one"
        if (array_key_exists('api_secret', $input) && $input['api_secret'] === '') {
            unset($input['api_secret']);
        }

        // Multi-select map filters: normalize to a JSON array, empty = no
        // filter. Only when the customization form is submitted (an absent
        // multiple select means "cleared", but only for its own form).
        if (($input['_tab'] ?? null) === '2') {
            foreach (['modal_group', 'modal_status'] as $key) {
                $values = array_values(array_filter(
                    (array) ($input[$key] ?? []),
                    static fn($value) => !in_array($value, ['', '0', 0], true),
                ));
                $input[$key] = $values === [] ? '' : json_encode($values);
            }

            if (isset($input['popup_title_source']) && !in_array($input['popup_title_source'], ['vehicle', 'technician'], true)) {
                $input['popup_title_source'] = 'technician';
            }
        }

        return $input;
    }

    public static function install(): void
    {
        $missing = array_diff_key(
            self::getDefaults(),
            Config::getConfigurationValues(self::CONTEXT),
        );

        if ($missing !== []) {
            Config::setConfigurationValues(self::CONTEXT, $missing);
        }
    }

    public static function uninstall(): void
    {
        Config::deleteConfigurationValues(
            self::CONTEXT,
            array_keys(Config::getConfigurationValues(self::CONTEXT)),
        );
    }
}
