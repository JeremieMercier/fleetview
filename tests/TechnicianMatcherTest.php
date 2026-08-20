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

use GlpiPlugin\Fleetview\TechnicianMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * All user data in these tests is fictional (classic test-dataset names);
 * no real person is referenced.
 */
final class TechnicianMatcherTest extends TestCase
{
    /**
     * @return list<array<array-key, mixed>>
     */
    private function fakeUsers(): array
    {
        return [
            ['id' => 1, 'firstname' => 'Jean', 'realname' => 'Dupont'],
            ['id' => 2, 'firstname' => 'Sophie', 'realname' => 'Martin'],
            ['id' => 3, 'firstname' => 'Kévin', 'realname' => 'Lefèvre'],
            // Trailing whitespace, as sometimes found in directory data
            ['id' => 4, 'firstname' => 'Paul ', 'realname' => ' Durand'],
            // Homonyms: two distinct users sharing the same full name
            ['id' => 5, 'firstname' => 'Claire', 'realname' => 'Moreau'],
            ['id' => 6, 'firstname' => 'Claire', 'realname' => 'Moreau'],
            // Incomplete records must be ignored
            ['id' => 7, 'firstname' => 'SansNom', 'realname' => ''],
            ['id' => 8, 'firstname' => '', 'realname' => 'SansPrenom'],
            // Malformed rows must be ignored
            ['firstname' => 'Pas', 'realname' => 'DIdentifiant'],
            'not-an-array',
        ];
    }

    private function newMatcher(): TechnicianMatcher
    {
        return new TechnicianMatcher($this->fakeUsers());
    }

    /**
     * @return array<string, array{?string, ?int}>
     */
    public static function matchProvider(): array
    {
        return [
            'lastname firstname'            => ['Dupont Jean', 1],
            'firstname lastname'            => ['Jean Dupont', 1],
            'uppercase'                     => ['DUPONT JEAN', 1],
            'trailing dots'                 => ['Martin Sophie..', 2],
            'accented both sides'           => ['Lefèvre Kévin', 3],
            'accents stripped on input'     => ['Lefevre Kevin', 3],
            'accents added on input'        => ['Léfèvre Kévin', 3],
            'whitespace in directory data'  => ['Durand Paul', 4],
            'extra inner spaces'            => ['Dupont   Jean', 1],
            'ambiguous name never matches'  => ['Moreau Claire', null],
            'unknown name'                  => ['Petit Nicolas', null],
            'partial name'                  => ['Dupont', null],
            'name with extra tokens'        => ['AB123CD OEM Dupont Jean', null],
            'empty string'                  => ['', null],
            'punctuation only'              => ['...', null],
            'null'                          => [null, null],
        ];
    }

    #[DataProvider('matchProvider')]
    public function testMatch(?string $vehicle_name, ?int $expected_user_id): void
    {
        $this->assertSame($expected_user_id, $this->newMatcher()->match($vehicle_name));
    }

    public function testIncompleteUsersAreNeverMatched(): void
    {
        $matcher = $this->newMatcher();

        $this->assertNull($matcher->match('SansNom'));
        $this->assertNull($matcher->match('SansPrenom'));
        $this->assertNull($matcher->match('Pas DIdentifiant'));
    }

    public function testIndexIsReusedAcrossCalls(): void
    {
        // A generator can only be consumed once: a second match() call
        // succeeding proves the index is built a single time.
        $users   = $this->fakeUsers();
        $matcher = new TechnicianMatcher((static function () use ($users) {
            yield from $users;
        })());

        $this->assertSame(1, $matcher->match('Dupont Jean'));
        $this->assertSame(2, $matcher->match('Martin Sophie'));
    }
}
