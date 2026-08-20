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
use Session;

/**
 * Plugin configuration, stored in the `glpi_configs` table under the
 * `plugin:fleetview` context. The `api_secret` value is encrypted (see the
 * SECURED_CONFIGS hook in setup.php).
 */
final class PluginConfig extends CommonGLPI
{
    public const CONTEXT = 'plugin:fleetview';

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
            'api_base_url'   => '',
            'api_username'   => '',
            'api_secret'     => '',
            'search_radius'  => '50',  // km around the ticket location
            'cache_lifetime' => '60',  // seconds, positions cache
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
        return array_merge(
            self::getDefaults(),
            Config::getConfigurationValues(self::CONTEXT)
        );
    }

    /**
     * Whether the Masternaut API access is configured.
     */
    public static function isApiConfigured(): bool
    {
        $config = self::getConfig();

        return $config['api_base_url'] !== '' && $config['api_secret'] !== '';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Config) {
            return self::createTabEntry(self::getTypeName(), 0, $item::class, self::getIcon());
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Config) {
            self::showConfigForm();
            return true;
        }

        return false;
    }

    private static function showConfigForm(): void
    {
        if (!Session::haveRight(self::$rightname, UPDATE)) {
            return;
        }

        $config = self::getConfig();

        // Never expose the stored secret in the page source
        $config['api_secret'] = '';

        TemplateRenderer::getInstance()->display('@fleetview/config.html.twig', [
            'config'      => $config,
            'form_action' => Config::getFormURL(),
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
