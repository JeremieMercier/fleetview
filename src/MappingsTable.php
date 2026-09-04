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

use Collator;

/**
 * Filtering, sorting and paging of the vehicle to technician associations
 * table, server-side: the fleet list comes from the (cached) Masternaut API
 * in one go, but only one page of rows is rendered, each row carrying two
 * dropdowns. Pure functions, so that the behaviour is unit-testable.
 *
 * @phpstan-type Row array{
 *      id: string,
 *      name: string,
 *      registration: string,
 *      group: string,
 *      type: string,
 *      make_model: string,
 *      status: string,
 *      status_label: string,
 *      users_id: int,
 *      suggested_id: int,
 * }
 */
final class MappingsTable
{
    /** Text filters (substring, case-insensitive) and exact-match filters */
    private const TEXT_FILTERS  = ['name', 'registration', 'make_model'];
    private const EXACT_FILTERS = ['group', 'type', 'status_label'];

    public const SORTABLE = ['name', 'registration', 'group', 'type', 'make_model', 'status_label', 'state'];

    /**
     * Association state of a row: explicitly associated, suggested by name
     * matching, or nothing.
     *
     * @param Row $row
     */
    public static function state(array $row): string
    {
        if ($row['users_id'] > 0) {
            return 'mapped';
        }

        return $row['suggested_id'] > 0 ? 'suggested' : 'none';
    }

    /**
     * Normalized filters from the request: known keys only, trimmed, empty
     * values dropped.
     *
     * @return array<string, string>
     */
    public static function filters(mixed $input): array
    {
        $filters = [];
        if (!is_array($input)) {
            return $filters;
        }

        foreach ([...self::TEXT_FILTERS, ...self::EXACT_FILTERS, 'state'] as $key) {
            $value = $input[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $filters[$key] = trim((string) $value);
            }
        }

        if (isset($filters['state']) && !in_array($filters['state'], ['mapped', 'suggested', 'none'], true)) {
            unset($filters['state']);
        }

        return $filters;
    }

    /**
     * Distinct values offered by the exact-match filters, from the whole
     * fleet (not only the current page).
     *
     * @param list<Row> $rows
     *
     * @return array<string, list<string>> filter key => sorted values
     */
    public static function choices(array $rows): array
    {
        $choices = [];
        foreach (self::EXACT_FILTERS as $key) {
            $values = array_values(array_unique(array_filter(array_column($rows, $key), static fn(string $value): bool => $value !== '')));
            sort($values, SORT_NATURAL | SORT_FLAG_CASE);
            $choices[$key] = $values;
        }

        return $choices;
    }

    /**
     * Rows matching the filters, sorted, then the requested page of them.
     *
     * @param list<Row>             $rows
     * @param array<string, string> $filters see `filters()`
     * @param string                $sort    one of SORTABLE ('' = fleet order)
     * @param string                $order   'ASC' or 'DESC'
     * @param int                   $start   offset of the page
     * @param int                   $limit   rows per page (< 1 = everything)
     *
     * @return array{rows: list<Row>, total: int, start: int, sort: string, order: string}
     */
    public static function page(array $rows, array $filters, string $sort, string $order, int $start, int $limit): array
    {
        $rows = array_values(array_filter($rows, static fn(array $row): bool => self::matches($row, $filters)));

        $sort  = in_array($sort, self::SORTABLE, true) ? $sort : '';
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
        if ($sort !== '') {
            // Locale-aware (accents), numeric-aware ("10" after "9") ordering
            $collator = new Collator('root');
            $collator->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
            $direction = $order === 'DESC' ? -1 : 1;
            usort($rows, static fn(array $a, array $b): int => $direction * (int) $collator->compare(self::sortValue($a, $sort), self::sortValue($b, $sort)));
        }

        $total = count($rows);
        if ($limit < 1) {
            $limit = max(1, $total);
        }

        $start = max(0, $start);
        if ($start >= $total) {
            // Beyond the end (a filter narrowed the list): last page
            $start = $total > 0 ? intdiv($total - 1, $limit) * $limit : 0;
        }

        return [
            'rows'  => array_slice($rows, $start, $limit),
            'total' => $total,
            'start' => $start,
            'sort'  => $sort,
            'order' => $order,
        ];
    }

    /**
     * @param Row                   $row
     * @param array<string, string> $filters
     */
    private static function matches(array $row, array $filters): bool
    {
        foreach ($filters as $key => $value) {
            if ($key === 'state') {
                if (self::state($row) !== $value) {
                    return false;
                }
            } elseif (in_array($key, self::EXACT_FILTERS, true)) {
                if (self::text($row, $key) !== $value) {
                    return false;
                }
            } elseif (!str_contains(mb_strtolower(self::text($row, $key)), mb_strtolower($value))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Row $row
     */
    private static function sortValue(array $row, string $sort): string
    {
        if ($sort === 'state') {
            // Associated first, then suggestions, then the rest
            return match (self::state($row)) {
                'mapped'    => '1',
                'suggested' => '2',
                default     => '3',
            };
        }

        return self::text($row, $sort);
    }

    /**
     * Text column of a row (the id, users_id and suggested_id columns are
     * neither filtered nor sorted on).
     *
     * @param Row $row
     */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
