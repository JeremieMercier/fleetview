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

use DBmysql;
use Profile_User;
use User;

use function Safe\preg_replace;

/**
 * Matches fleet vehicle names against GLPI users. The fleet names vehicles
 * after their technician ("Lastname Firstname"), so a vehicle is linked to
 * the active GLPI user whose "lastname firstname" (or "firstname lastname")
 * matches the vehicle name, ignoring case and accents. Ambiguous names
 * (several users) are never matched.
 */
final class TechnicianMatcher
{
    /** @var ?array<string, int> normalized full name => user id (-1 = ambiguous) */
    private ?array $index = null;

    /**
     * @param ?iterable<array<array-key, mixed>> $users User rows override
     *        (`id`, `firstname`, `realname`), mainly for unit tests; defaults
     *        to the active, non-deleted GLPI users.
     * @param ?int $entities_id Entity whose tickets the matching serves:
     *        only the users holding a profile covering it are candidates
     *        (the entities of the session when null, e.g. the configuration
     *        screen suggestions)
     */
    public function __construct(private readonly ?iterable $users = null, private readonly ?int $entities_id = null) {}

    /**
     * GLPI user id matching the given vehicle/driver name, or null.
     */
    public function match(?string $name): ?int
    {
        $normalized = $this->normalize((string) $name);
        if ($normalized === '') {
            return null;
        }

        $user_id = $this->getIndex()[$normalized] ?? null;

        return $user_id !== null && $user_id > 0 ? $user_id : null;
    }

    /**
     * @return array<string, int>
     */
    private function getIndex(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        if ($this->index !== null) {
            return $this->index;
        }

        $this->index = [];

        // Active users holding a profile covering the entity (their own,
        // or an ancestor with child entities enabled): a vehicle name is
        // only matched to somebody the ticket entity may be assigned to
        $user_tbl = User::getTable();
        $pu_tbl   = Profile_User::getTable();
        $iterator = $this->users ?? $DB->request([
            'SELECT'     => [$user_tbl . '.id', $user_tbl . '.firstname', $user_tbl . '.realname'],
            'DISTINCT'   => true,
            'FROM'       => $user_tbl,
            'INNER JOIN' => [
                $pu_tbl => ['ON' => [$pu_tbl => 'users_id', $user_tbl => 'id']],
            ],
            'WHERE'      => [
                $user_tbl . '.is_active'  => 1,
                $user_tbl . '.is_deleted' => 0,
            ] + getEntitiesRestrictCriteria($pu_tbl, '', $this->entities_id ?? '', true),
        ]);

        foreach ($iterator as $user) {
            if (!is_array($user) || !is_numeric($user['id'] ?? null)) {
                continue;
            }

            $firstname = is_scalar($user['firstname'] ?? null) ? trim((string) $user['firstname']) : '';
            $realname  = is_scalar($user['realname'] ?? null) ? trim((string) $user['realname']) : '';
            if ($firstname === '' || $realname === '') {
                continue;
            }

            $user_id = (int) $user['id'];
            foreach ([$realname . ' ' . $firstname, $firstname . ' ' . $realname] as $full_name) {
                $key = $this->normalize($full_name);
                // Ambiguous names must never auto-assign someone
                $this->index[$key] = isset($this->index[$key]) && $this->index[$key] !== $user_id
                    ? -1
                    : $user_id;
            }
        }

        return $this->index;
    }

    private function normalize(string $name): string
    {
        $name = (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $name));
    }
}
