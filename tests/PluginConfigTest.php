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

use GlpiPlugin\Fleetview\PluginConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * All values in these tests are fictional; no real credential or person is
 * referenced.
 */
final class PluginConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $defaults = PluginConfig::getDefaults();

        // The out-of-the-box radius must be 50 km, widenable up to 150 km
        $this->assertSame('50', $defaults['search_radius']);
        $this->assertSame('150', $defaults['search_radius_max']);

        // Routing is opt-in: location data must not reach a third party
        // unless the administrator decided so
        $this->assertSame('', $defaults['routing_base_url']);

        // No credential is ever shipped as a default value
        $this->assertSame('', $defaults['customer_id']);
        $this->assertSame('', $defaults['api_username']);
        $this->assertSame('', $defaults['api_secret']);

        // Name matching is opt-in: it only fits fleets naming vehicles
        // after their technician
        $this->assertSame('0', $defaults['name_matching_fallback']);
        $this->assertSame('6', $defaults['popup_max_tasks']);
        $this->assertSame('1', $defaults['popup_external_events']);
        $this->assertSame('technician', $defaults['popup_title_source']);
        $this->assertSame('1', $defaults['popup_show_registration']);
        $this->assertSame('1', $defaults['map_show_routes']);

        // Map filters are disabled by default
        $this->assertSame('', $defaults['modal_group']);
        $this->assertSame('', $defaults['modal_status']);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function decodeListValueProvider(): array
    {
        return [
            'empty string'          => ['', []],
            'blank string'          => ['   ', []],
            'json list'             => ['["Alpha","Bravo"]', ['Alpha', 'Bravo']],
            'json list with number' => ['["Alpha",42]', ['Alpha', '42']],
            'json unicode escapes'  => ['["Zone N°1"]', ['Zone N°1']],
            'json object values'    => ['{"a":"Alpha","b":"Bravo"}', ['Alpha', 'Bravo']],
            'json non-scalar entry' => ['["Alpha",["nested"]]', ['Alpha']],
            'legacy single value'   => ['Alpha', ['Alpha']],
            'invalid json'          => ['[broken', ['[broken']],
            'json scalar'           => ['"Alpha"', ['"Alpha"']],
        ];
    }

    #[DataProvider('decodeListValueProvider')]
    public function testDecodeListValue(string $stored, array $expected): void
    {
        $this->assertSame($expected, PluginConfig::decodeListValue($stored));
    }

    public function testConfigUpdateKeepsCurrentSecretWhenEmpty(): void
    {
        $input = PluginConfig::configUpdate([
            'api_username' => 'fake_api_user',
            'api_secret'   => '',
        ]);

        $this->assertArrayNotHasKey('api_secret', $input);
        $this->assertSame('fake_api_user', $input['api_username']);
    }

    public function testConfigUpdateKeepsProvidedSecret(): void
    {
        $input = PluginConfig::configUpdate(['api_secret' => 'fake-secret-value']);

        $this->assertSame('fake-secret-value', $input['api_secret']);
    }

    public function testConfigUpdateNormalizesMapFiltersOnCustomizationForm(): void
    {
        $input = PluginConfig::configUpdate([
            '_tab'         => '2',
            'modal_group'  => ['Zone Alpha', '0', '', 'Zone Bravo'],
            'modal_status' => ['IN_CIRCULATION'],
        ]);

        $this->assertSame(['Zone Alpha', 'Zone Bravo'], PluginConfig::decodeListValue((string) $input['modal_group']));
        $this->assertSame(['IN_CIRCULATION'], PluginConfig::decodeListValue((string) $input['modal_status']));
    }

    public function testConfigUpdateRejectsUnknownPopupTitleSource(): void
    {
        $input = PluginConfig::configUpdate(['_tab' => '2', 'popup_title_source' => 'hacked']);
        $this->assertSame('technician', $input['popup_title_source']);

        $input = PluginConfig::configUpdate(['_tab' => '2', 'popup_title_source' => 'vehicle']);
        $this->assertSame('vehicle', $input['popup_title_source']);
    }

    public function testConfigUpdateClearsMapFiltersWhenAbsentFromCustomizationForm(): void
    {
        // A multiple select with nothing selected is absent from the POST:
        // submitting the customization form must clear the filters
        $input = PluginConfig::configUpdate(['_tab' => '2']);

        $this->assertSame('', $input['modal_group']);
        $this->assertSame('', $input['modal_status']);
    }

    public function testConfigUpdateLeavesMapFiltersAloneOnOtherForms(): void
    {
        // The API form does not carry the map filter fields: submitting it
        // must not wipe them
        $input = PluginConfig::configUpdate([
            '_tab'         => '1',
            'api_username' => 'fake_api_user',
        ]);

        $this->assertArrayNotHasKey('modal_group', $input);
        $this->assertArrayNotHasKey('modal_status', $input);
    }

    public function testGetStatusLabelFallsBackToRawValue(): void
    {
        $this->assertNotSame('', PluginConfig::getStatusLabel('IN_CIRCULATION'));
        $this->assertNotSame('', PluginConfig::getStatusLabel('IN_MAINTENANCE'));
        $this->assertNotSame('', PluginConfig::getStatusLabel('SOLD'));
        $this->assertSame('UNKNOWN_STATUS', PluginConfig::getStatusLabel('UNKNOWN_STATUS'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function externalHostProvider(): iterable
    {
        yield 'empty'            => ['', false];
        yield 'blank'            => ['   ', false];
        yield 'localhost'        => ['http://localhost:5000', false];
        yield 'localhost domain' => ['http://osrm.localhost', false];
        yield '.local'           => ['http://osrm.local:5000', false];
        yield 'bare hostname'    => ['http://osrm-server:5000', false];
        yield 'loopback'         => ['http://127.0.0.1:5000', false];
        yield 'private 10/8'     => ['http://10.1.2.3:5000/', false];
        yield 'private 172.16'   => ['http://172.20.0.5', false];
        yield 'private 192.168'  => ['http://192.168.1.10:5000', false];
        yield 'ipv6 loopback'    => ['http://[::1]:5000', false];
        yield 'public demo'      => ['https://router.project-osrm.org', true];
        yield 'public domain'    => ['http://osrm.example.com:5000/', true];
        yield 'public ip'        => ['http://8.8.8.8:5000', true];
        yield 'not an url'       => ['osrm', false];
    }

    #[DataProvider('externalHostProvider')]
    public function testIsExternalHost(string $url, bool $expected): void
    {
        $this->assertSame($expected, PluginConfig::isExternalHost($url));
    }
}
