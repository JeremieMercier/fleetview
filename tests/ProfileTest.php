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

namespace GlpiPlugin\Fleetview\Tests;

use Glpi\Tests\DbTestCase;
use GlpiPlugin\Fleetview\Profile;
use Profile as CoreProfile;
use ProfileRight;
use Ticket;

final class ProfileTest extends DbTestCase
{
    private function rightOf(string $profile_name): int
    {
        $rights = ProfileRight::getProfileRights(
            getItemByTypeName(CoreProfile::class, $profile_name, true),
            [Profile::RIGHTNAME],
        );

        return (int) ($rights[Profile::RIGHTNAME] ?? -1);
    }

    public function testInstallGrantsTheMapToProfilesAllowedToAssignTickets(): void
    {
        // Installed by the plugin bootstrap: every profile has the right,
        // set according to its ticket assignment right
        $this->assertArrayHasKey(Profile::RIGHTNAME, ProfileRight::getAllPossibleRights());

        // Assign to others (Super-Admin, Hotliner) or to themselves (Technician)
        $this->assertSame(READ, $this->rightOf('Super-Admin'));
        $this->assertSame(READ, $this->rightOf('Hotliner'));
        $this->assertSame(READ, $this->rightOf('Technician'));
        $this->assertSame(0, $this->rightOf('Self-Service'));
        $this->assertSame(0, $this->rightOf('Read-Only'));
    }

    public function testInstallIsIdempotent(): void
    {
        // A later install (plugin update) must not reset customized rights
        $profiles_id = getItemByTypeName(CoreProfile::class, 'Technician', true);
        ProfileRight::updateProfileRights($profiles_id, [Profile::RIGHTNAME => 0]);

        Profile::install();

        $this->assertSame(0, $this->rightOf('Technician'));
        $this->assertSame(READ, $this->rightOf('Super-Admin'));
    }

    public function testUninstallAndReinstall(): void
    {
        Profile::uninstall();
        $this->assertArrayNotHasKey(Profile::RIGHTNAME, ProfileRight::getAllPossibleRights());

        // Fresh install: granted again from the ticket assignment right
        Profile::install();
        $this->assertSame(READ, $this->rightOf('Super-Admin'));
        $this->assertSame(0, $this->rightOf('Self-Service'));
    }

    public function testCanViewMapFollowsTheSessionRight(): void
    {
        $this->login('glpi');
        $this->assertTrue(Profile::canViewMap());

        $_SESSION['glpiactiveprofile'][Profile::RIGHTNAME] = 0;
        $this->assertFalse(Profile::canViewMap());

        $this->login('post-only');
        $this->assertFalse(Profile::canViewMap());
    }

    public function testTabIsOfferedOnCentralProfilesOnly(): void
    {
        $this->login('glpi');
        $tab = new Profile();

        $central = new CoreProfile();
        $this->assertTrue($central->getFromDB(getItemByTypeName(CoreProfile::class, 'Technician', true)));
        $this->assertNotSame('', $tab->getTabNameForItem($central));

        $helpdesk = new CoreProfile();
        $this->assertTrue($helpdesk->getFromDB(getItemByTypeName(CoreProfile::class, 'Self-Service', true)));
        $this->assertSame('', $tab->getTabNameForItem($helpdesk));

        $this->assertSame('', $tab->getTabNameForItem(new Ticket()));
    }
}
