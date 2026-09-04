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

use CommonDBTM;
use DBConnection;
use DBmysql;
use Migration;
use Session;

/**
 * Explicit association between a Masternaut vehicle (asset id) and a GLPI
 * user, managed from the plugin configuration screen. This is the reliable
 * source for the "assign this technician" feature; name matching is only an
 * optional fallback (see `name_matching_fallback` configuration).
 *
 * Associations belong to an entity, with the usual "child entities" flag:
 * the fleet itself is global (one Masternaut account), the GLPI side of it
 * (which technician drives which vehicle) is only taken into account for
 * the tickets of the entities the association covers, as the native actor
 * dropdown only offers the technicians of the ticket entity.
 */
final class VehicleMapping extends CommonDBTM
{
    public static $rightname = 'config';

    /**
     * Associations covering an entity (their own entity, or an ancestor
     * with child entities enabled), indexed by Masternaut asset id. Every
     * association when no entity is given (configuration screen).
     *
     * @return array<string, int> asset id => users_id
     */
    public static function getMap(?int $entities_id = null): array
    {
        $map = [];
        foreach (self::getAll($entities_id) as $asset_id => $mapping) {
            $map[$asset_id] = $mapping['users_id'];
        }

        return $map;
    }

    /**
     * Associations with their entity, indexed by Masternaut asset id (see
     * `getMap()` for the entity parameter).
     *
     * @return array<string, array{users_id: int, entities_id: int, is_recursive: bool}>
     */
    public static function getAll(?int $entities_id = null): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $criteria = ['FROM' => self::getTable()];
        if ($entities_id !== null) {
            $criteria['WHERE'] = getEntitiesRestrictCriteria(self::getTable(), '', $entities_id, true);
        }

        $all = [];
        foreach ($DB->request($criteria) as $row) {
            if (is_array($row) && is_scalar($row['asset_id'] ?? null) && is_numeric($row['users_id'] ?? null)) {
                $all[(string) $row['asset_id']] = [
                    'users_id'     => (int) $row['users_id'],
                    'entities_id'  => is_numeric($row['entities_id'] ?? null) ? (int) $row['entities_id'] : 0,
                    'is_recursive' => (bool) ($row['is_recursive'] ?? false),
                ];
            }
        }

        return $all;
    }

    /**
     * Create, update or remove the association of one asset. The entity
     * defaults to the active one, with child entities enabled.
     */
    public static function save(string $asset_id, string $asset_label, int $users_id, ?int $entities_id = null, bool $is_recursive = true): void
    {
        $entities_id ??= Session::getActiveEntity();

        $mapping = new self();
        $exists  = $mapping->getFromDBByCrit(['asset_id' => $asset_id]);

        if ($users_id <= 0) {
            if ($exists) {
                $mapping->delete(['id' => $mapping->getID()], true);
            }

            return;
        }

        $input = [
            'users_id'     => $users_id,
            'asset_label'  => $asset_label,
            'entities_id'  => $entities_id,
            'is_recursive' => $is_recursive ? 1 : 0,
        ];

        if ($exists) {
            $changed = false;
            foreach ($input as $field => $value) {
                $current = $mapping->fields[$field] ?? null;
                $same    = is_int($value)
                    ? (is_numeric($current) && (int) $current === $value)
                    : $current === $value;
                if (!$same) {
                    $changed = true;
                }
            }

            if ($changed) {
                $mapping->update(['id' => $mapping->getID()] + $input);
            }

            return;
        }

        $mapping->add(['asset_id' => $asset_id] + $input);
    }

    public static function install(Migration $migration): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $charset   = DBConnection::getDefaultCharset();
            $collation = DBConnection::getDefaultCollation();
            $sign      = DBConnection::getDefaultPrimaryKeySignOption();

            $DB->doQuery(
                "CREATE TABLE `{$table}` (
                    `id` int {$sign} NOT NULL AUTO_INCREMENT,
                    `asset_id` varchar(255) NOT NULL,
                    `asset_label` varchar(255) NOT NULL DEFAULT '',
                    `users_id` int {$sign} NOT NULL DEFAULT '0',
                    `entities_id` int {$sign} NOT NULL DEFAULT '0',
                    `is_recursive` tinyint NOT NULL DEFAULT '0',
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `asset_id` (`asset_id`),
                    KEY `users_id` (`users_id`),
                    KEY `entities_id` (`entities_id`),
                    KEY `is_recursive` (`is_recursive`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC",
            );
        } else {
            // 0.6.0: associations are entity-scoped. The existing ones are
            // attached to the root entity with child entities enabled, so
            // they stay visible from everywhere, as they used to be.
            if ($migration->addField($table, 'entities_id', 'fkey', ['after' => 'users_id'])) {
                $migration->addKey($table, 'entities_id');
            }

            if ($migration->addField($table, 'is_recursive', 'bool', ['after' => 'entities_id', 'update' => '1'])) {
                $migration->addKey($table, 'is_recursive');
            }
        }

        $migration->executeMigration();
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::getTable());
        $migration->executeMigration();
    }
}
