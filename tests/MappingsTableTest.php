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

use GlpiPlugin\Fleetview\MappingsTable;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Row from MappingsTable
 */
final class MappingsTableTest extends TestCase
{
    /**
     * @return list<Row>
     */
    private function fleet(): array
    {
        $make = static fn(string $id, string $name, string $registration, string $group, string $type, string $status_label, int $users_id, int $suggested_id): array => [
            'id'           => $id,
            'name'         => $name,
            'registration' => $registration,
            'group'        => $group,
            'type'         => $type,
            'make_model'   => 'Renault Kangoo',
            'status'       => 'IN_CIRCULATION',
            'status_label' => $status_label,
            'users_id'     => $users_id,
            'suggested_id' => $suggested_id,
        ];

        return [
            $make('1', 'Dupont Jean', 'AA-111-AA', 'Zone Alpha', 'Van', 'In circulation', 10, 0),
            $make('2', 'Martin Sophie', 'BB-222-BB', 'Zone Beta', 'Car', 'In maintenance', 0, 20),
            $make('3', 'Durand Paul', 'CC-333-CC', 'Zone Alpha', 'Van', 'In circulation', 0, 0),
            $make('4', 'Élodie Bernard', 'DD-444-DD', '', 'Car', 'In circulation', 40, 0),
            $make('5', 'dupont jacques', 'EE-555-EE', 'Zone Beta', 'Truck', 'In circulation', 0, 0),
        ];
    }

    public function testStateOfARow(): void
    {
        [$mapped, $suggested, $none] = $this->fleet();

        $this->assertSame('mapped', MappingsTable::state($mapped));
        $this->assertSame('suggested', MappingsTable::state($suggested));
        $this->assertSame('none', MappingsTable::state($none));
    }

    public function testFiltersAreNormalized(): void
    {
        $this->assertSame([], MappingsTable::filters(null));
        $this->assertSame([], MappingsTable::filters('name=x'));
        $this->assertSame(
            ['name' => 'dup', 'group' => 'Zone Alpha', 'state' => 'none'],
            MappingsTable::filters(['name' => '  dup ', 'registration' => '', 'group' => 'Zone Alpha', 'state' => 'none', 'unknown' => 'x', 'type' => ['array']]),
        );
        // Unknown state dropped
        $this->assertSame([], MappingsTable::filters(['state' => 'whatever']));
    }

    public function testChoicesComeFromTheWholeFleetSorted(): void
    {
        $choices = MappingsTable::choices($this->fleet());

        $this->assertSame(['Zone Alpha', 'Zone Beta'], $choices['group']);
        $this->assertSame(['Car', 'Truck', 'Van'], $choices['type']);
        $this->assertSame(['In circulation', 'In maintenance'], $choices['status_label']);
    }

    public function testTextFiltersAreCaseInsensitiveSubstrings(): void
    {
        $page = MappingsTable::page($this->fleet(), ['name' => 'DUPONT'], '', '', 0, 20);
        $this->assertSame(['1', '5'], array_column($page['rows'], 'id'));
        $this->assertSame(2, $page['total']);

        $page = MappingsTable::page($this->fleet(), ['registration' => '333'], '', '', 0, 20);
        $this->assertSame(['3'], array_column($page['rows'], 'id'));
    }

    public function testExactAndStateFiltersCombine(): void
    {
        $page = MappingsTable::page($this->fleet(), ['group' => 'Zone Alpha', 'state' => 'none'], '', '', 0, 20);
        $this->assertSame(['3'], array_column($page['rows'], 'id'));

        $page = MappingsTable::page($this->fleet(), ['state' => 'suggested'], '', '', 0, 20);
        $this->assertSame(['2'], array_column($page['rows'], 'id'));

        $page = MappingsTable::page($this->fleet(), ['type' => 'Car', 'status_label' => 'In circulation'], '', '', 0, 20);
        $this->assertSame(['4'], array_column($page['rows'], 'id'));
    }

    public function testSortIsNaturalCaseInsensitiveAndReversible(): void
    {
        $page = MappingsTable::page($this->fleet(), [], 'name', 'asc', 0, 20);
        $this->assertSame(['5', '1', '3', '4', '2'], array_column($page['rows'], 'id'));
        $this->assertSame(['sort' => 'name', 'order' => 'ASC'], ['sort' => $page['sort'], 'order' => $page['order']]);

        $page = MappingsTable::page($this->fleet(), [], 'name', 'DESC', 0, 20);
        $this->assertSame(['2', '4', '3', '1', '5'], array_column($page['rows'], 'id'));

        // Associated first, then suggestions, then the rest
        $page = MappingsTable::page($this->fleet(), [], 'state', 'ASC', 0, 20);
        $this->assertSame(['1', '4', '2', '3', '5'], array_column($page['rows'], 'id'));

        // Unknown sort: fleet order kept
        $page = MappingsTable::page($this->fleet(), [], 'id', 'ASC', 0, 20);
        $this->assertSame(['1', '2', '3', '4', '5'], array_column($page['rows'], 'id'));
        $this->assertSame('', $page['sort']);
    }

    public function testPagesAreSlicedAfterFilteringAndSorting(): void
    {
        $page = MappingsTable::page($this->fleet(), [], 'name', 'ASC', 0, 2);
        $this->assertSame(['5', '1'], array_column($page['rows'], 'id'));
        $this->assertSame(5, $page['total']);
        $this->assertSame(0, $page['start']);

        $page = MappingsTable::page($this->fleet(), [], 'name', 'ASC', 2, 2);
        $this->assertSame(['3', '4'], array_column($page['rows'], 'id'));

        $page = MappingsTable::page($this->fleet(), [], 'name', 'ASC', 4, 2);
        $this->assertSame(['2'], array_column($page['rows'], 'id'));

        // Beyond the end (a filter narrowed the list): last page
        $page = MappingsTable::page($this->fleet(), ['type' => 'Van'], '', '', 4, 2);
        $this->assertSame(['1', '3'], array_column($page['rows'], 'id'));
        $this->assertSame(0, $page['start']);
        $page = MappingsTable::page($this->fleet(), [], '', '', 99, 2);
        $this->assertSame(['5'], array_column($page['rows'], 'id'));
        $this->assertSame(4, $page['start']);

        // Nothing matches
        $page = MappingsTable::page($this->fleet(), ['name' => 'zzz'], '', '', 4, 2);
        $this->assertSame([], $page['rows']);
        $this->assertSame(0, $page['total']);
        $this->assertSame(0, $page['start']);

        // No limit: everything
        $page = MappingsTable::page($this->fleet(), [], '', '', 0, 0);
        $this->assertCount(5, $page['rows']);
    }
}
