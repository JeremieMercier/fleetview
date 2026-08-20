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
 * @link      https://github.com/pluginsGLPI/fleetview
 * -------------------------------------------------------------------------
 */

/* global CFG_GLPI, bootstrap, L */

(() => {
    'use strict';

    const PLUGIN_URL = `${CFG_GLPI.root_doc}/plugins/fleetview`;

    const getTicketId = () => {
        if (!/\/front\/ticket\.form\.php/.test(window.location.pathname)) {
            return null;
        }
        const id = new URLSearchParams(window.location.search).get('id');
        return id && /^\d+$/.test(id) ? id : null;
    };

    // Leaflet is bundled with GLPI but not loaded on the ticket page: load it
    // on demand, only when the modal is first opened.
    let leafletLoading = null;
    const loadLeaflet = () => {
        if (window.L) {
            return Promise.resolve();
        }
        if (!leafletLoading) {
            const css = document.createElement('link');
            css.rel = 'stylesheet';
            css.href = `${CFG_GLPI.root_doc}/lib/leaflet.css`;
            document.head.appendChild(css);

            leafletLoading = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = `${CFG_GLPI.root_doc}/lib/leaflet.js`;
                script.onload = resolve;
                script.onerror = () => reject(new Error('Unable to load Leaflet'));
                document.head.appendChild(script);
            });
        }
        return leafletLoading;
    };

    const buildModal = () => {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = `
            <div class="modal fade" id="fleetview-modal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="ti ti-map-pin me-1"></i>
                                ${__('Nearby technicians', 'fleetview')}
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body p-0">
                            <div id="fleetview-alert" class="alert alert-warning m-3 d-none" role="alert"></div>
                            <div id="fleetview-map"></div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(wrapper.firstElementChild);
        return document.getElementById('fleetview-modal');
    };

    const showAlert = (message) => {
        const alert = document.getElementById('fleetview-alert');
        alert.textContent = message;
        alert.classList.remove('d-none');
    };

    let map = null;
    const openMap = async (context) => {
        const modalEl = document.getElementById('fleetview-modal') ?? buildModal();
        bootstrap.Modal.getOrCreateInstance(modalEl).show();

        try {
            await loadLeaflet();
        } catch {
            showAlert(__('Unable to load the map library.', 'fleetview'));
            return;
        }

        const { latitude, longitude, name } = context.location;

        if (!map) {
            map = L.map('fleetview-map');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            }).addTo(map);
        }
        map.setView([latitude, longitude], 11);

        // The modal animation breaks Leaflet's size computation
        setTimeout(() => map.invalidateSize(), 300);

        L.marker([latitude, longitude])
            .addTo(map)
            .bindPopup(`<strong>${_.escape(name)}</strong>`)
            .openPopup();

        try {
            const response = await fetch(`${PLUGIN_URL}/vehicles`);
            const data = await response.json();

            if (!data.configured) {
                showAlert(__('The Masternaut API is not configured yet.', 'fleetview'));
                return;
            }

            data.vehicles.forEach((vehicle) => {
                L.marker([vehicle.latitude, vehicle.longitude])
                    .addTo(map)
                    .bindPopup(
                        `<strong>${_.escape(vehicle.label)}</strong><br>${_.escape(vehicle.updated_at)}`
                    );
            });
        } catch {
            showAlert(__('Unable to fetch vehicle positions.', 'fleetview'));
        }
    };

    const insertButton = (context) => {
        const assignSelect = document.querySelector('select[data-actor-type="assign"]');
        if (!assignSelect || document.getElementById('fleetview-open')) {
            return;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.id = 'fleetview-open';
        button.className = 'btn btn-sm btn-outline-secondary mt-2';
        button.innerHTML = `<i class="ti ti-map-pin me-1"></i>${__('Nearby technicians', 'fleetview')}`;
        button.addEventListener('click', () => openMap(context));

        const fieldContainer = assignSelect.closest('.form-field') ?? assignSelect.parentElement;
        fieldContainer.insertAdjacentElement('afterend', button);
    };

    const init = async () => {
        const ticketId = getTicketId();
        if (!ticketId) {
            return;
        }

        try {
            const response = await fetch(`${PLUGIN_URL}/ticket/${ticketId}/context`);
            if (!response.ok) {
                return;
            }
            const context = await response.json();

            // Button only shown when the ticket location has coordinates
            if (context.available) {
                insertButton(context);
            }
        } catch {
            // Silently ignore: the plugin must never break the ticket form
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
