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

use Entity;
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
        $this->login('glpi');
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
        $this->login('glpi');
        $before = VehicleMapping::getMap();

        VehicleMapping::save('test_unknown_' . uniqid(), 'Martin Sophie', 0);

        $this->assertSame($before, VehicleMapping::getMap());
    }

    public function testGetMapIndexesByAssetId(): void
    {
        $this->login('glpi');
        $asset_a = 'test_asset_a_' . uniqid();
        $asset_b = 'test_asset_b_' . uniqid();

        VehicleMapping::save($asset_a, 'Lefèvre Kévin', 7);
        VehicleMapping::save($asset_b, 'Moreau Claire', 8);

        $map = VehicleMapping::getMap();

        $this->assertSame(7, $map[$asset_a]);
        $this->assertSame(8, $map[$asset_b]);
    }

    public function testGetMapIsScopedToTheGivenEntity(): void
    {
        $this->login('glpi');
        $root    = getItemByTypeName(Entity::class, '_test_root_entity', true);
        $child_1 = getItemByTypeName(Entity::class, '_test_child_1', true);
        $child_2 = getItemByTypeName(Entity::class, '_test_child_2', true);

        $tree  = 'test_asset_tree_' . uniqid();
        $one   = 'test_asset_one_' . uniqid();
        $other = 'test_asset_other_' . uniqid();
        VehicleMapping::save($tree, 'Whole tree', 7, $root, true);
        VehicleMapping::save($one, 'Child 1 only', 8, $child_1, false);
        VehicleMapping::save($other, 'Child 2 only', 9, $child_2, false);

        // Child 1: its own association and the recursive one of its parent
        $map = VehicleMapping::getMap($child_1);
        $this->assertSame(7, $map[$tree] ?? null);
        $this->assertSame(8, $map[$one] ?? null);
        $this->assertArrayNotHasKey($other, $map);

        // Root: the root association only (child ones do not apply upwards)
        $map = VehicleMapping::getMap($root);
        $this->assertSame(7, $map[$tree] ?? null);
        $this->assertArrayNotHasKey($one, $map);
        $this->assertArrayNotHasKey($other, $map);

        // No entity (configuration screen): everything, with the entities
        $all = VehicleMapping::getAll();
        $this->assertSame(9, VehicleMapping::getMap()[$other] ?? null);
        $this->assertSame(['users_id' => 8, 'entities_id' => $child_1, 'is_recursive' => false], $all[$one]);
        $this->assertSame(['users_id' => 7, 'entities_id' => $root, 'is_recursive' => true], $all[$tree]);
    }

    public function testSaveDefaultsToTheActiveEntityAndMovesAssociations(): void
    {
        $this->login('glpi');
        $this->setEntity('_test_child_1', false);
        $child_1  = getItemByTypeName(Entity::class, '_test_child_1', true);
        $child_2  = getItemByTypeName(Entity::class, '_test_child_2', true);
        $asset_id = 'test_asset_' . uniqid();

        VehicleMapping::save($asset_id, 'Dupont Jean', 42);
        $this->assertSame(['users_id' => 42, 'entities_id' => $child_1, 'is_recursive' => true], VehicleMapping::getAll()[$asset_id]);

        // Same user, another entity: updated, still a single row
        VehicleMapping::save($asset_id, 'Dupont Jean', 42, $child_2, false);
        $this->assertSame(['users_id' => 42, 'entities_id' => $child_2, 'is_recursive' => false], VehicleMapping::getAll()[$asset_id]);
        $this->assertSame(1, countElementsInTable(VehicleMapping::getTable(), ['asset_id' => $asset_id]));
        $this->assertArrayNotHasKey($asset_id, VehicleMapping::getMap($child_1));
    }
}
