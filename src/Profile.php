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
use DBmysql;
use Glpi\Application\View\TemplateRenderer;
use Profile as CoreProfile;
use ProfileRight;
use Session;
use Ticket;

/**
 * Dedicated "nearby technicians map" right, managed in a tab of the
 * profile form. It gates the fleet data (live positions, driver names)
 * exposed by the map endpoints: the ticket view right is not enough.
 */
final class Profile extends CommonGLPI
{
    /** Right name in the `glpi_profilerights` table */
    public const RIGHTNAME = 'plugin_fleetview_map';

    public static $rightname = 'profile';

    public static function getTypeName($nb = 0): string
    {
        return __('Fleetview', 'fleetview');
    }

    /**
     * Rights matrix rows, see Profile::displayRightsChoiceMatrix().
     *
     * @return list<array{label: string, field: string, rights: array<int, string>}>
     */
    public static function getAllRights(): array
    {
        return [
            [
                'label'  => __('Nearby technicians map', 'fleetview'),
                'field'  => self::RIGHTNAME,
                'rights' => [READ => __('Read')],
            ],
        ];
    }

    /**
     * Whether the current user can open the map (button, ticket context,
     * fleet positions).
     */
    public static function canViewMap(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof CoreProfile && ($item->fields['interface'] ?? '') === 'central') {
            return self::createTabEntry(self::getTypeName(), 0, $item::class, 'ti ti-map-pin');
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof CoreProfile) {
            TemplateRenderer::getInstance()->display('@fleetview/profile.html.twig', [
                'item'   => $item,
                'rights' => self::getAllRights(),
                'title'  => self::getTypeName(),
            ]);
            return true;
        }

        return false;
    }

    /**
     * Register the right (idempotent, also runs on plugin update). On first
     * registration, profiles allowed to assign tickets (to others or to
     * themselves) get the read right: technicians and dispatchers keep the
     * map, requesters never had the button.
     */
    public static function install(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        // Checked in the database rather than through the cached list of
        // possible rights: a stale cache must neither skip the registration
        // nor re-grant the right on a later update, erasing customizations
        if (countElementsInTable(ProfileRight::getTable(), ['name' => self::RIGHTNAME]) > 0) {
            return;
        }

        ProfileRight::addProfileRights([self::RIGHTNAME]);

        $iterator = $DB->request([
            'SELECT' => ['profiles_id'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => [
                'name'   => Ticket::$rightname,
                'rights' => ['&', Ticket::ASSIGN | Ticket::STEAL | Ticket::OWN],
            ],
        ]);
        foreach ($iterator as $row) {
            if (is_array($row) && is_numeric($row['profiles_id'] ?? null)) {
                ProfileRight::updateProfileRights((int) $row['profiles_id'], [self::RIGHTNAME => READ]);
            }
        }

        // Rights of the current session are loaded at login: refresh them so
        // the installer does not have to log out to see the map
        if (is_array($_SESSION['glpiactiveprofile'] ?? null) && isset($_SESSION['glpiactiveprofile']['id'])) {
            Session::reloadCurrentProfile();
        }
    }

    public static function uninstall(): void
    {
        ProfileRight::deleteProfileRights([self::RIGHTNAME]);

        if (is_array($_SESSION['glpiactiveprofile'] ?? null)) {
            unset($_SESSION['glpiactiveprofile'][self::RIGHTNAME]);
        }
    }
}
