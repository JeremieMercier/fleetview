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
use Html;
use Planning;
use Session;
use Ticket;
use TicketTask;

use function Safe\strtotime;

/**
 * Planned interventions (ticket tasks) of technicians, displayed in the
 * vehicle marker popup of the map. Only "to do" tasks with a planning that
 * is not over yet, on open tickets visible from the current entities.
 */
final class TechnicianAgenda
{
    /**
     * Planned tasks of the given users, soonest first, at most `$limit` per
     * user. Private tasks are only included when the current user may see
     * them (right, author or technician).
     *
     * @param list<int> $users_ids
     *
     * @return array<int, array{tasks: list<array{
     *      id: int,
     *      tickets_id: int,
     *      ticket_name: string,
     *      url: string,
     *      begin: string,
     *      end: string,
     *      begin_label: string,
     *      end_label: string,
     *      in_progress: bool,
     * }>, more: int}> users_id => tasks; users without tasks are absent
     */
    public static function getPlannedTasks(array $users_ids, int $limit): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $users_ids = array_values(array_unique(array_filter($users_ids, static fn(int $id) => $id > 0)));
        if ($users_ids === [] || $limit <= 0) {
            return [];
        }

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
            ],
            'FROM'       => $task_tbl,
            'INNER JOIN' => [
                $tkt_tbl => ['ON' => [$task_tbl => 'tickets_id', $tkt_tbl => 'id']],
            ],
            'WHERE'      => $where,
            'ORDER'      => [$task_tbl . '.begin ASC', $task_tbl . '.id ASC'],
        ]);

        /** @var array<int, array{tasks: list<array{id: int, tickets_id: int, ticket_name: string, url: string, begin: string, end: string, begin_label: string, end_label: string, when_label: string, in_progress: bool}>, more: int}> $agenda */
        $agenda = [];
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

            $users_id = (int) $row['users_id_tech'];
            $entry    = $agenda[$users_id] ?? ['tasks' => [], 'more' => 0];

            if (count($entry['tasks']) >= $limit) {
                $entry['more']++;
                $agenda[$users_id] = $entry;
                continue;
            }

            $tickets_id  = (int) $row['tickets_id'];
            $begin       = (string) $row['begin'];
            $end         = (string) $row['end'];
            $begin_label = (string) Html::convDateTime($begin);
            $end_label   = (string) Html::convDateTime($end);

            $entry['tasks'][] = [
                'id'          => (int) $row['id'],
                'tickets_id'  => $tickets_id,
                'ticket_name' => is_scalar($row['ticket_name'] ?? null) ? (string) $row['ticket_name'] : '',
                'url'         => Ticket::getFormURLWithID($tickets_id, true),
                'begin'       => $begin,
                'end'         => $end,
                'begin_label' => $begin_label,
                'end_label'   => $end_label,
                'when_label'  => self::whenLabel($begin, $end, $begin_label, $end_label),
                'in_progress' => (bool) ($row['in_progress'] ?? false),
            ];
            $agenda[$users_id] = $entry;
        }

        return $agenda;
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
