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

use Config;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Fleetview\Controller\ConfigController;
use GlpiPlugin\Fleetview\PluginConfig;
use GlpiPlugin\Fleetview\VehicleMapping;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Functional tests against the GLPI test database. Every test runs inside a
 * rolled-back transaction; all values are fictional.
 */
final class ConfigControllerTest extends DbTestCase
{
    public function testSaveConfigStoresKnownKeysOnly(): void
    {
        $this->login('glpi');

        $request = Request::create('', 'POST', [
            '_tab'          => '1',
            'api_username'  => 'fake_api_user',
            'search_radius' => '125',
            'unknown_key'   => 'must-not-be-stored',
            'update'        => '1',
        ]);

        $response = (new ConfigController())->saveConfig($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);

        $stored = Config::getConfigurationValues(PluginConfig::CONTEXT);
        $this->assertSame('fake_api_user', $stored['api_username']);
        $this->assertSame('125', $stored['search_radius']);
        $this->assertArrayNotHasKey('unknown_key', $stored);
        $this->assertArrayNotHasKey('update', $stored);
    }

    public function testSaveConfigEncryptsTheSecretAtRest(): void
    {
        $this->login('glpi');

        (new ConfigController())->saveConfig(Request::create('', 'POST', [
            '_tab'       => '1',
            'api_secret' => 'fake-secret-value',
        ]));

        // Stored encrypted, decrypted transparently by the plugin accessor
        $stored = Config::getConfigurationValues(PluginConfig::CONTEXT);
        $this->assertNotSame('fake-secret-value', $stored['api_secret']);
        $this->assertSame('fake-secret-value', PluginConfig::getConfig()['api_secret']);
    }

    public function testSaveConfigKeepsTheSecretWhenSubmittedEmpty(): void
    {
        $this->login('glpi');
        $controller = new ConfigController();

        $controller->saveConfig(Request::create('', 'POST', [
            '_tab'       => '1',
            'api_secret' => 'fake-secret-value',
        ]));
        $controller->saveConfig(Request::create('', 'POST', [
            '_tab'         => '1',
            'api_secret'   => '',
            'api_username' => 'fake_api_user',
        ]));

        $this->assertSame('fake-secret-value', PluginConfig::getConfig()['api_secret']);
        $this->assertSame('fake_api_user', PluginConfig::getConfig()['api_username']);
    }

    public function testSaveConfigNormalizesMapFilters(): void
    {
        $this->login('glpi');

        (new ConfigController())->saveConfig(Request::create('', 'POST', [
            '_tab'         => '2',
            'modal_group'  => ['Zone Alpha', '0', 'Zone Bravo'],
            'modal_status' => [],
        ]));

        $config = PluginConfig::getConfig();
        $this->assertSame(['Zone Alpha', 'Zone Bravo'], PluginConfig::decodeListValue($config['modal_group']));
        $this->assertSame([], PluginConfig::decodeListValue($config['modal_status']));
    }

    public function testSaveMappingsCreatesAndClearsAssociations(): void
    {
        $this->login('glpi');
        $controller = new ConfigController();
        $asset_id   = 'test_asset_' . uniqid();

        $response = $controller->saveMappings(Request::create('', 'POST', [
            'mappings' => [$asset_id => '42'],
            'labels'   => [$asset_id => 'Dupont Jean'],
        ]));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(42, VehicleMapping::getMap()[$asset_id] ?? null);

        $controller->saveMappings(Request::create('', 'POST', [
            'mappings' => [$asset_id => '0'],
            'labels'   => [$asset_id => 'Dupont Jean'],
        ]));

        $this->assertArrayNotHasKey($asset_id, VehicleMapping::getMap());
    }

    public function testSaveMappingsIgnoresNonNumericValues(): void
    {
        $this->login('glpi');
        $asset_id = 'test_asset_' . uniqid();

        (new ConfigController())->saveMappings(Request::create('', 'POST', [
            'mappings' => [$asset_id => 'not-a-number'],
            'labels'   => [$asset_id => 'Martin Sophie'],
        ]));

        $this->assertArrayNotHasKey($asset_id, VehicleMapping::getMap());
    }

    public function testSaveConfigRequiresTheConfigRight(): void
    {
        $this->login('post-only');

        $this->expectException(\Glpi\Exception\Http\AccessDeniedHttpException::class);

        (new ConfigController())->saveConfig(Request::create('', 'POST', [
            '_tab'         => '1',
            'api_username' => 'fake_api_user',
        ]));
    }

    public function testSaveMappingsRequiresTheConfigRight(): void
    {
        $this->login('post-only');

        $this->expectException(\Glpi\Exception\Http\AccessDeniedHttpException::class);

        (new ConfigController())->saveMappings(Request::create('', 'POST', [
            'mappings' => ['test_asset' => '42'],
        ]));
    }
}
