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
use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QueryFunction;
use Group_User;
use Html;
use Planning;
use PlanningEventCategory;
use PlanningExternalEvent;
use Session;
use Ticket;
use TicketTask;

use function Safe\strtotime;

/**
 * Upcoming schedule of technicians, displayed in the vehicle marker popup
 * of the map: planned ticket tasks ("to do", not over yet, on open tickets
 * visible from the current entities) merged chronologically with their
 * external events (owned or as a guest, visible according to the core
 * planning rights).
 */
final class TechnicianAgenda
{
    /**
     * Upcoming entries of the given users, soonest first, at most `$limit`
     * per user. Private tasks are only included when the current user may
     * see them (right, author or technician); external events follow the
     * core visibility rules (own, guest, or planning "see all"/"see group").
     *
     * @param list<int> $users_ids
     *
     * @return array<int, array{tasks: list<array{
     *      type: 'task'|'event',
     *      id: int,
     *      tickets_id: int,
     *      ticket_name: string,
     *      category: string,
     *      color: string,
     *      url: string,
     *      begin: string,
     *      end: string,
     *      begin_label: string,
     *      end_label: string,
     *      when_label: string,
     *      in_progress: bool,
     *      day: ?string,
     * }>, more: int}> users_id => entries; users without entries are absent
     */
    public static function getPlannedTasks(array $users_ids, int $limit, bool $with_events = false): array
    {
        $users_ids = array_values(array_unique(array_filter($users_ids, static fn(int $id) => $id > 0)));
        $users_ids = self::filterViewablePlannings($users_ids);
        if ($users_ids === [] || $limit <= 0) {
            return [];
        }

        $entries = self::fetchTasks($users_ids);
        if ($with_events) {
            $entries = array_merge($entries, self::fetchEvents($users_ids));
        }

        usort($entries, static fn(array $a, array $b) => [$a['begin'], $a['type'], $a['id']] <=> [$b['begin'], $b['type'], $b['id']]);

        /** @var array<int, array{tasks: list<array{type: 'task'|'event', id: int, tickets_id: int, ticket_name: string, category: string, color: string, url: string, begin: string, end: string, begin_label: string, end_label: string, when_label: string, in_progress: bool, day: ?string}>, more: int}> $agenda */
        $agenda = [];
        foreach ($entries as $entry) {
            $users_id = $entry['users_id'];
            unset($entry['users_id']);

            $agenda[$users_id] ??= ['tasks' => [], 'more' => 0];
            if (count($agenda[$users_id]['tasks']) >= $limit) {
                $agenda[$users_id]['more']++;
                continue;
            }

            $agenda[$users_id]['tasks'][] = $entry;
        }

        return $agenda;
    }

    /**
     * Users whose planning the current user may consult, according to the
     * GLPI planning right: everyone ("see all"), the members of the user's
     * groups ("see group"), or only themselves ("see mine"). The same rule
     * drives the GLPI planning view and the external events visibility.
     *
     * @param list<int> $users_ids
     *
     * @return list<int>
     */
    public static function filterViewablePlannings(array $users_ids): array
    {
        if (Session::haveRight(Planning::$rightname, Planning::READALL)) {
            return $users_ids;
        }

        $viewable = [];
        if (Session::haveRight(Planning::$rightname, Planning::READMY)) {
            $viewable[] = (int) Session::getLoginUserID();
        }

        if (Session::haveRight(Planning::$rightname, Planning::READGROUP)) {
            $groups = [];
            foreach ((array) ($_SESSION['glpigroups'] ?? []) as $groups_id) {
                if (is_numeric($groups_id)) {
                    $groups[] = (int) $groups_id;
                }
            }

            if ($groups !== []) {
                foreach (Group_User::getGroupUsers($groups) as $member) {
                    if (is_array($member) && is_numeric($member['id'] ?? null)) {
                        $viewable[] = (int) $member['id'];
                    }
                }
            }
        }

        return array_values(array_intersect($users_ids, $viewable));
    }

    /**
     * Planned ticket tasks of the users, one entry per task.
     *
     * @param list<int> $users_ids
     *
     * @return list<array{users_id: int, type: 'task', id: int, tickets_id: int, ticket_name: string, category: string, color: string, url: string, begin: string, end: string, begin_label: string, end_label: string, when_label: string, in_progress: bool, day: ?string}>
     */
    private static function fetchTasks(array $users_ids): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $me       = Session::getLoginUserID();
        $task_tbl = TicketTask::getTable();
        $tkt_tbl  = Ticket::getTable();

        $where = [
            $task_tbl . '.users_id_tech' => $users_ids,
            $task_tbl . '.state'         => Planning::TODO,
            ['NOT' => [$task_tbl . '.begin' => null]],
            $task_tbl . '.end'           => ['>=', QueryFunction::now()],
            $tkt_tbl . '.is_deleted'     => 0,
            ['NOT' => [$tkt_tbl . '.status' => array_merge(Ticket::getSolvedStatusArray(), Ticket::getClosedStatusArray())]],
        ] + getEntitiesRestrictCriteria($tkt_tbl);

        if (!Session::haveRight(TicketTask::$rightname, TicketTask::SEEPRIVATE)) {
            $where[] = [
                'OR' => [
                    $task_tbl . '.is_private'    => 0,
                    $task_tbl . '.users_id'      => $me,
                    $task_tbl . '.users_id_tech' => $me,
                ],
            ];
        }

        $iterator = $DB->request([
            'SELECT'     => [
                $task_tbl . '.id',
                $task_tbl . '.users_id_tech',
                $task_tbl . '.begin',
                $task_tbl . '.end',
                $tkt_tbl . '.id AS tickets_id',
                $tkt_tbl . '.name AS ticket_name',
                // Compared by the database: stored dates and NOW() share the
                // same clock, unlike PHP whose timezone may differ
                new QueryExpression($DB::quoteName($task_tbl . '.begin') . ' <= NOW()', 'in_progress'),
                new QueryExpression('DATEDIFF(' . $DB::quoteName($task_tbl . '.begin') . ', CURDATE())', 'days_ahead'),
            ],
            'FROM'       => $task_tbl,
            'INNER JOIN' => [
                $tkt_tbl => ['ON' => [$task_tbl => 'tickets_id', $tkt_tbl => 'id']],
            ],
            'WHERE'      => $where,
            'ORDER'      => [$task_tbl . '.begin ASC', $task_tbl . '.id ASC'],
        ]);

        $entries = [];
        foreach ($iterator as $row) {
            if (
                !is_array($row)
                || !is_numeric($row['users_id_tech'] ?? null)
                || !is_numeric($row['id'] ?? null)
                || !is_numeric($row['tickets_id'] ?? null)
                || !is_scalar($row['begin'] ?? null)
                || !is_scalar($row['end'] ?? null)
            ) {
                continue;
            }

            $tickets_id = (int) $row['tickets_id'];
            $entries[]  = [
                'users_id'    => (int) $row['users_id_tech'],
                'type'        => 'task',
                'id'          => (int) $row['id'],
                'tickets_id'  => $tickets_id,
                'ticket_name' => is_scalar($row['ticket_name'] ?? null) ? (string) $row['ticket_name'] : '',
                'category'    => '',
                'color'       => '',
                'url'         => Ticket::getFormURLWithID($tickets_id, true),
            ] + self::periodFields((string) $row['begin'], (string) $row['end'], $row);
        }

        return $entries;
    }

    /**
     * Non-recurring external events the users own or are invited to, one
     * entry per (event, user). Visibility follows the core rules.
     *
     * @param list<int> $users_ids
     *
     * @return list<array{users_id: int, type: 'event', id: int, tickets_id: int, ticket_name: string, category: string, color: string, url: string, begin: string, end: string, begin_label: string, end_label: string, when_label: string, in_progress: bool, day: ?string}>
     */
    private static function fetchEvents(array $users_ids): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $evt_tbl = PlanningExternalEvent::getTable();
        $cat_tbl = PlanningEventCategory::getTable();

        $ownership = ['OR' => [$evt_tbl . '.users_id' => $users_ids]];
        foreach ($users_ids as $users_id) {
            $ownership['OR'][] = QueryFunction::jsonContains(
                $evt_tbl . '.users_id_guests',
                new QueryExpression((string) $users_id),
                '$',
            );
        }

        $where = [
            $ownership,
            ['NOT' => [$evt_tbl . '.begin' => null]],
            $evt_tbl . '.end' => ['>=', QueryFunction::now()],
            ['NOT' => [$evt_tbl . '.state' => Planning::DONE]],
            // Recurring events are not expanded (out of scope)
            ['OR' => [[$evt_tbl . '.rrule' => null], [$evt_tbl . '.rrule' => '']]],
        ] + getEntitiesRestrictCriteria($evt_tbl, '', '', true);

        $visibility = PlanningExternalEvent::getVisibilityCriteria();
        if ($visibility !== []) {
            $where[] = $visibility;
        }

        $iterator = $DB->request([
            'SELECT'    => [
                $evt_tbl . '.id',
                $evt_tbl . '.users_id',
                $evt_tbl . '.users_id_guests',
                $evt_tbl . '.name',
                $evt_tbl . '.begin',
                $evt_tbl . '.end',
                $cat_tbl . '.name AS category',
                $cat_tbl . '.color AS color',
                new QueryExpression($DB::quoteName($evt_tbl . '.begin') . ' <= NOW()', 'in_progress'),
                new QueryExpression('DATEDIFF(' . $DB::quoteName($evt_tbl . '.begin') . ', CURDATE())', 'days_ahead'),
            ],
            'FROM'      => $evt_tbl,
            'LEFT JOIN' => [
                $cat_tbl => ['ON' => [$evt_tbl => 'planningeventcategories_id', $cat_tbl => 'id']],
            ],
            'WHERE'     => $where,
        ]);

        $entries = [];
        foreach ($iterator as $row) {
            if (
                !is_array($row)
                || !is_numeric($row['id'] ?? null)
                || !is_scalar($row['begin'] ?? null)
                || !is_scalar($row['end'] ?? null)
            ) {
                continue;
            }

            $guests = is_string($row['users_id_guests'] ?? null) ? importArrayFromDB($row['users_id_guests']) : [];
            $guests = array_map(intval(...), array_filter($guests, is_numeric(...)));
            $owner  = is_numeric($row['users_id'] ?? null) ? (int) $row['users_id'] : 0;

            $id     = (int) $row['id'];
            $common = [
                'type'        => 'event',
                'id'          => $id,
                'tickets_id'  => 0,
                'ticket_name' => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
                'category'    => is_scalar($row['category'] ?? null) ? (string) $row['category'] : '',
                'color'       => is_scalar($row['color'] ?? null) ? (string) $row['color'] : '',
                'url'         => PlanningExternalEvent::getFormURLWithID($id, true),
            ] + self::periodFields((string) $row['begin'], (string) $row['end'], $row);

            foreach ($users_ids as $users_id) {
                if ($users_id === $owner || in_array($users_id, $guests, true)) {
                    $entries[] = ['users_id' => $users_id] + $common;
                }
            }
        }

        return $entries;
    }

    /**
     * Period fields shared by tasks and events, from a database row holding
     * the `in_progress` and `days_ahead` expressions.
     *
     * @param array<array-key, mixed> $row
     *
     * @return array{begin: string, end: string, begin_label: string, end_label: string, when_label: string, in_progress: bool, day: ?string}
     */
    private static function periodFields(string $begin, string $end, array $row): array
    {
        $begin_label = (string) Html::convDateTime($begin);
        $end_label   = (string) Html::convDateTime($end);

        return [
            'begin'       => $begin,
            'end'         => $end,
            'begin_label' => $begin_label,
            'end_label'   => $end_label,
            'when_label'  => self::whenLabel($begin, $end, $begin_label, $end_label),
            'in_progress' => (bool) ($row['in_progress'] ?? false),
            // "today" / "tomorrow" hint for entries starting soon
            'day'         => match (is_numeric($row['days_ahead'] ?? null) ? (int) $row['days_ahead'] : null) {
                0       => 'today',
                1       => 'tomorrow',
                default => null,
            },
        ];
    }

    /**
     * Human-readable period of a task, on one line:
     *  - full days (00:00 to 23:59 or midnight): dates only, "d1" or "d1 – d2"
     *  - same day: "date begin – end time"
     *  - otherwise: both full date-times
     */
    private static function whenLabel(string $begin, string $end, string $begin_label, string $end_label): string
    {
        $begin_day = substr($begin, 0, 10);
        $end_day   = substr($end, 0, 10);
        $same_day  = $begin_day === $end_day;

        $full_days = substr($begin, 11, 8) === '00:00:00'
            && (
                in_array(substr($end, 11, 8), ['23:59:59', '23:59:00'], true)
                || (substr($end, 11, 8) === '00:00:00' && !$same_day)
            );

        if ($full_days) {
            // An end at midnight belongs to the previous day
            if (substr($end, 11, 8) === '00:00:00') {
                $end_day = date('Y-m-d', strtotime($end_day . ' -1 day'));
            }

            $begin_date = (string) Html::convDate($begin_day);
            $end_date   = (string) Html::convDate($end_day);

            return $begin_day === $end_day ? $begin_date : $begin_date . ' – ' . $end_date;
        }

        return $same_day
            ? $begin_label . ' – ' . substr($end_label, -5)
            : $begin_label . ' – ' . $end_label;
    }
}
