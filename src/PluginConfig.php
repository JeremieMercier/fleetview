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

namespace GlpiPlugin\Fleetview;

use CommonGLPI;
use Config;
use Glpi\Application\View\TemplateRenderer;
use GLPIKey;
use GlpiPlugin\Fleetview\Masternaut\MasternautApiException;
use GlpiPlugin\Fleetview\Masternaut\MasternautClient;
use Session;

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
            'search_radius'  => '50',  // km around the ticket location (API max: 500)
            'max_results'    => '10',  // maximum number of vehicles returned
            'cache_lifetime' => '60',  // seconds, positions cache (API limit: 1 req/15s)
            // Restrict the vehicles shown in the map modal; empty = no filter,
            // multiple choices stored as a JSON array
            'modal_group'  => '',      // Masternaut group names
            'modal_status' => '',      // IN_CIRCULATION / IN_MAINTENANCE / SOLD
            // OSRM-compatible routing service for driving time estimations.
            // Coordinates are sent to this third-party service; empty = disabled.
            'routing_base_url' => 'https://router.project-osrm.org',
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
        $values = Config::getConfigurationValues(self::CONTEXT);

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
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        if (!Session::haveRight(self::$rightname, UPDATE)) {
            return;
        }

        $config = self::getConfig();

        // Never expose the stored secret in the page source
        $config['api_secret'] = '';

        TemplateRenderer::getInstance()->display('@fleetview/config_api.html.twig', [
            'config'      => $config,
            'form_action' => $CFG_GLPI['root_doc'] . '/plugins/fleetview/config',
        ]);
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

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_map(strval(...), $decoded)) : [$value];
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
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        if (!Session::haveRight(self::$rightname, UPDATE)) {
            return;
        }

        $config = self::getConfig();

        // Same radius choices as the modal selector; keep the current value
        // selectable even if it is not part of the presets
        $radius_choices = [25, 50, 100, 150, 200, 300, 500];
        $radius_choices[] = (int) $config['search_radius'];
        $radius_choices = array_unique($radius_choices);
        sort($radius_choices);
        $radius_choices = array_combine(
            $radius_choices,
            array_map(static fn(int $km) => sprintf('%d km', $km), $radius_choices)
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
            'form_action'       => $CFG_GLPI['root_doc'] . '/plugins/fleetview/config',
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
        ]);
    }

    private static function showMappingsForm(): void
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

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
                $mappings = VehicleMapping::getMap();
                $matcher  = new TechnicianMatcher();
                foreach ($client->getVehicles() as $vehicle) {
                    $users_id = $mappings[$vehicle['id']] ?? 0;
                    $vehicle['status_label'] = self::getStatusLabel($vehicle['status']);
                    $vehicles[] = $vehicle + [
                        'users_id'     => $users_id,
                        'suggested_id' => $users_id === 0 ? ($matcher->match($vehicle['name']) ?? 0) : 0,
                    ];
                }
            } catch (MasternautApiException $e) {
                $vehicles_error = $e->getMessage();
            }
        }

        TemplateRenderer::getInstance()->display('@fleetview/config_mappings.html.twig', [
            'vehicles'        => $vehicles,
            'vehicles_error'  => $vehicles_error,
            'mappings_action' => $CFG_GLPI['root_doc'] . '/plugins/fleetview/mappings',
        ]);
    }

    /**
     * Filter the input coming from the core config form
     * (called by Config::prepareInputForUpdate() through `config_class`).
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
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
                    static fn($value) => $value !== '' && $value !== '0' && $value !== 0
                ));
                $input[$key] = $values === [] ? '' : json_encode($values);
            }
        }

        return $input;
    }

    public static function install(): void
    {
        $missing = array_diff_key(
            self::getDefaults(),
            Config::getConfigurationValues(self::CONTEXT)
        );

        if ($missing !== []) {
            Config::setConfigurationValues(self::CONTEXT, $missing);
        }
    }

    public static function uninstall(): void
    {
        Config::deleteConfigurationValues(
            self::CONTEXT,
            array_keys(Config::getConfigurationValues(self::CONTEXT))
        );
    }
}
