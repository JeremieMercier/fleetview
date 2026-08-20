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

/**
 * Explicit association between a Masternaut vehicle (asset id) and a GLPI
 * user, managed from the plugin configuration screen. This is the reliable
 * source for the "assign this technician" feature; name matching is only an
 * optional fallback (see `name_matching_fallback` configuration).
 */
final class VehicleMapping extends CommonDBTM
{
    public static $rightname = 'config';

    /**
     * All associations, indexed by Masternaut asset id.
     *
     * @return array<string, int> asset id => users_id
     */
    public static function getMap(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $map = [];
        foreach ($DB->request(['FROM' => self::getTable()]) as $row) {
            if (is_array($row) && is_scalar($row['asset_id'] ?? null) && is_numeric($row['users_id'] ?? null)) {
                $map[(string) $row['asset_id']] = (int) $row['users_id'];
            }
        }

        return $map;
    }

    /**
     * Create, update or remove the association of one asset.
     */
    public static function save(string $asset_id, string $asset_label, int $users_id): void
    {
        $mapping = new self();
        $exists  = $mapping->getFromDBByCrit(['asset_id' => $asset_id]);

        if ($users_id <= 0) {
            if ($exists) {
                $mapping->delete(['id' => $mapping->getID()], true);
            }

            return;
        }

        if ($exists) {
            $current_users_id = is_numeric($mapping->fields['users_id'] ?? null) ? (int) $mapping->fields['users_id'] : 0;
            if ($current_users_id !== $users_id || $mapping->fields['asset_label'] !== $asset_label) {
                $mapping->update([
                    'id'          => $mapping->getID(),
                    'users_id'    => $users_id,
                    'asset_label' => $asset_label,
                ]);
            }

            return;
        }

        $mapping->add([
            'asset_id'    => $asset_id,
            'asset_label' => $asset_label,
            'users_id'    => $users_id,
        ]);
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
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `asset_id` (`asset_id`),
                    KEY `users_id` (`users_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC",
            );
        }

        $migration->executeMigration();
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->dropTable(self::getTable());
        $migration->executeMigration();
    }
}
