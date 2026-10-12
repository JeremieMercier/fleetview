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

namespace GlpiPlugin\Fleetview\Http;

use GLPIKey;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Symfony HTTP client honouring the GLPI proxy settings.
 *
 * GLPI 12 ships its own wrapper (`Glpi\Toolbox\HttpClient`), not used here
 * on purpose: it is final and builds its transport itself (no way to inject
 * a mock in tests), and it refuses private network addresses, which would
 * break the self-hosted OSRM instance on a LAN the README recommends.
 */
final class HttpClientFactory
{
    /**
     * @param array<string, mixed> $options Default request options
     *        (https://symfony.com/doc/current/http_client.html)
     */
    public static function create(array $options = []): HttpClientInterface
    {
        /** @var array<string, mixed> $CFG_GLPI */
        global $CFG_GLPI;

        $options += ['max_connect_duration' => 5];

        $proxy_name = $CFG_GLPI['proxy_name'] ?? '';
        if (is_string($proxy_name) && $proxy_name !== '') {
            $credentials = '';
            $proxy_user  = $CFG_GLPI['proxy_user'] ?? '';
            if (is_string($proxy_user) && $proxy_user !== '') {
                $proxy_passwd = $CFG_GLPI['proxy_passwd'] ?? '';
                $credentials  = sprintf(
                    '%s:%s@',
                    rawurlencode($proxy_user),
                    rawurlencode((new GLPIKey())->decrypt(is_string($proxy_passwd) ? $proxy_passwd : null) ?? ''),
                );
            }

            $proxy_port       = $CFG_GLPI['proxy_port'] ?? 0;
            $options['proxy'] = sprintf(
                'http://%s%s:%d',
                $credentials,
                $proxy_name,
                is_numeric($proxy_port) ? (int) $proxy_port : 0,
            );
        }

        return HttpClient::create($options);
    }
}
