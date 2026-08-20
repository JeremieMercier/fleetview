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

namespace GlpiPlugin\Fleetview\Tests;

use Glpi\Tests\DbTestCase;
use GlpiPlugin\Fleetview\VehicleMapping;

/**
 * Functional tests against the GLPI test database. Every test runs inside a
 * rolled-back transaction; all data is fictional.
 */
final class VehicleMappingTest extends DbTestCase
{
    public function testSaveCreatesUpdatesAndRemovesAssociations(): void
    {
        $asset_id = 'test_asset_' . uniqid();

        // Create
        VehicleMapping::save($asset_id, 'Dupont Jean', 42);
        $this->assertSame(42, VehicleMapping::getMap()[$asset_id] ?? null);

        // Idempotent save: still a single row
        VehicleMapping::save($asset_id, 'Dupont Jean', 42);
        $this->assertSame(
            1,
            countElementsInTable(VehicleMapping::getTable(), ['asset_id' => $asset_id]),
        );

        // Update to another user, label refreshed
        VehicleMapping::save($asset_id, 'Dupont Jean (MAJ)', 43);
        $this->assertSame(43, VehicleMapping::getMap()[$asset_id] ?? null);

        $mapping = new VehicleMapping();
        $this->assertTrue($mapping->getFromDBByCrit(['asset_id' => $asset_id]));
        $this->assertSame('Dupont Jean (MAJ)', $mapping->fields['asset_label']);

        // Saving user 0 removes the association
        VehicleMapping::save($asset_id, 'Dupont Jean (MAJ)', 0);
        $this->assertArrayNotHasKey($asset_id, VehicleMapping::getMap());
    }

    public function testRemovingAnUnknownAssetIsANoOp(): void
    {
        $before = VehicleMapping::getMap();

        VehicleMapping::save('test_unknown_' . uniqid(), 'Martin Sophie', 0);

        $this->assertSame($before, VehicleMapping::getMap());
    }

    public function testGetMapIndexesByAssetId(): void
    {
        $asset_a = 'test_asset_a_' . uniqid();
        $asset_b = 'test_asset_b_' . uniqid();

        VehicleMapping::save($asset_a, 'Lefèvre Kévin', 7);
        VehicleMapping::save($asset_b, 'Moreau Claire', 8);

        $map = VehicleMapping::getMap();

        $this->assertSame(7, $map[$asset_a]);
        $this->assertSame(8, $map[$asset_b]);
    }
}
