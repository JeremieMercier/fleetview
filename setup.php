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

use Glpi\Plugin\Hooks;
use GlpiPlugin\Fleetview\PluginConfig;

/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_FLEETVIEW_VERSION', '0.2.7');

// Minimal GLPI version, inclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define("PLUGIN_FLEETVIEW_MIN_GLPI_VERSION", "11.0.0");

// Maximum GLPI version, exclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define("PLUGIN_FLEETVIEW_MAX_GLPI_VERSION", "11.0.99");

/**
 * Init hooks of the plugin.
 * REQUIRED
 */
function plugin_init_fleetview(): void
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['fleetview'] = true;

    // API credentials are encrypted with GLPIKey in the `glpi_configs` table
    $PLUGIN_HOOKS[Hooks::SECURED_CONFIGS]['fleetview'] = PluginConfig::SECURED;

    if (!Session::getLoginUserID()) {
        return;
    }

    // Dedicated configuration page (wrench icon on the plugin card)
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['fleetview'] = 'front/config.form.php';

    // Ticket form integration (button + map modal); the JS guards itself so it
    // only acts on the ticket form.
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['fleetview'][] = 'js/fleetview.js';
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['fleetview'][]        = 'css/fleetview.css';
}

/**
 * Get the name and the version of the plugin
 * REQUIRED
 *
 * @return array{
 *      name: string,
 *      version: string,
 *      author: string,
 *      license: string,
 *      homepage: string,
 *      requirements: array{
 *          glpi: array{
 *              min: string,
 *              max: string,
 *          }
 *      }
 * }
 */
function plugin_version_fleetview(): array
{
    return [
        'name'           => 'Fleetview',
        'version'        => PLUGIN_FLEETVIEW_VERSION,
        'author'         => 'Jérémie Mercier',
        'license'        => 'MIT',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_FLEETVIEW_MIN_GLPI_VERSION,
                'max' => PLUGIN_FLEETVIEW_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

/**
 * Check pre-requisites before install
 * OPTIONAL
 */
function plugin_fleetview_check_prerequisites(): bool
{
    return true;
}

/**
 * Check configuration process
 * OPTIONAL
 *
 * @param bool $verbose Whether to display message on failure. Defaults to false.
 */
function plugin_fleetview_check_config(bool $verbose = false): bool
{
    return true;
}
