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

    const showAlert = (message, level = 'warning') => {
        const alert = document.getElementById('fleetview-alert');
        alert.textContent = message;
        alert.className = `alert alert-${level} m-3`;
    };

    const hideAlert = () => {
        document.getElementById('fleetview-alert').className = 'alert m-3 d-none';
    };

    const formatDuration = (minutes) => {
        if (minutes < 60) {
            return `${minutes} min`;
        }
        const remainder = minutes % 60;
        return `${Math.floor(minutes / 60)} h ${String(remainder).padStart(2, '0')}`;
    };

    const formatDate = (utcDate) => {
        if (!utcDate) {
            return '';
        }
        // API dates are UTC without timezone marker (YYYY-MM-DDThh:mm:ss.ms)
        const date = new Date(`${utcDate}Z`);
        return Number.isNaN(date.getTime()) ? utcDate : date.toLocaleString();
    };

    // Native Leaflet marker for the ticket location, recolored to red with a
    // CSS hue-rotate filter (see fleetview.css) so it stays visually
    // consistent with the default blue vehicle markers.
    const ticketIcon = () => {
        const icon = new L.Icon.Default();
        icon.options.className = 'fleetview-ticket-marker';
        return icon;
    };

    let map = null;
    let vehiclesLayer = null;

    // Leaflet cannot compute sizes/bounds while the modal is still animating:
    // resolve once the modal is fully shown.
    const waitModalShown = (modalEl) => new Promise((resolve) => {
        if (modalEl.classList.contains('show')) {
            resolve();
            return;
        }
        modalEl.addEventListener('shown.bs.modal', () => resolve(), { once: true });
    });

    const openMap = async (ticketId, context) => {
        const modalEl = document.getElementById('fleetview-modal') ?? buildModal();
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
        hideAlert();

        try {
            await loadLeaflet();
        } catch {
            showAlert(__('Unable to load the map library.', 'fleetview'), 'danger');
            return;
        }

        const { latitude, longitude, name } = context.location;

        if (!map) {
            map = L.map('fleetview-map');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            }).addTo(map);
            vehiclesLayer = L.layerGroup().addTo(map);

            L.marker([latitude, longitude], { icon: ticketIcon(), zIndexOffset: 1000 })
                .addTo(map)
                .bindPopup(`<strong>${_.escape(name)}</strong>`);
        }

        await waitModalShown(modalEl);
        map.invalidateSize();
        map.setView([latitude, longitude], 11);

        if (!context.configured) {
            showAlert(__('The Masternaut API is not configured yet.', 'fleetview'));
            return;
        }

        vehiclesLayer.clearLayers();

        try {
            const response = await fetch(`${PLUGIN_URL}/ticket/${ticketId}/vehicles`);
            const data = await response.json();

            if (data.error) {
                showAlert(data.error, 'danger');
                return;
            }

            const located = data.vehicles.filter((v) => v.latitude !== null && v.longitude !== null);

            if (located.length === 0) {
                showAlert(
                    __('No vehicle found within a radius of %s km.', 'fleetview')
                        .replace('%s', data.radius_km),
                    'info'
                );
                return;
            }

            const bounds = [[latitude, longitude]];
            located.forEach((vehicle) => {
                const travel = vehicle.travel_time_min !== null
                    ? __(
                        'approx. %1 drive (%2 km by road)',
                        'fleetview',
                        formatDuration(vehicle.travel_time_min),
                        vehicle.road_distance_km
                    )
                    : null;

                const details = [
                    `<strong>${_.escape(vehicle.label)}</strong>`,
                    vehicle.driver_name ? `<i class="ti ti-user"></i> ${_.escape(vehicle.driver_name)}` : null,
                    `<i class="ti ti-route"></i> ${_.escape(String(vehicle.distance_km))} km`,
                    travel ? `<i class="ti ti-car"></i> ${travel}` : null,
                    `<i class="ti ti-clock"></i> ${_.escape(formatDate(vehicle.updated_at))}`,
                ].filter(Boolean).join('<br>');

                L.marker([vehicle.latitude, vehicle.longitude])
                    .addTo(vehiclesLayer)
                    .bindPopup(details);
                bounds.push([vehicle.latitude, vehicle.longitude]);
            });
            map.fitBounds(bounds, { padding: [40, 40], maxZoom: 13 });
        } catch {
            showAlert(__('Unable to fetch vehicle positions.', 'fleetview'), 'danger');
        }
    };

    // Parts of the ticket form are rendered after DOMContentLoaded: wait for
    // the element to show up instead of assuming it is already there.
    const waitForElement = (selector, timeout = 15000) => new Promise((resolve) => {
        const existing = document.querySelector(selector);
        if (existing) {
            resolve(existing);
            return;
        }
        const observer = new MutationObserver(() => {
            const element = document.querySelector(selector);
            if (element) {
                observer.disconnect();
                resolve(element);
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
        setTimeout(() => {
            observer.disconnect();
            resolve(null);
        }, timeout);
    });

    const insertButton = async (ticketId, context) => {
        const assignSelect = await waitForElement('select[data-actor-type="assign"]');
        if (!assignSelect || document.getElementById('fleetview-open')) {
            return;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.id = 'fleetview-open';
        button.className = 'btn btn-sm btn-outline-secondary mt-2';
        button.innerHTML = `<i class="ti ti-map-pin me-1"></i>${__('Nearby technicians', 'fleetview')}`;
        button.addEventListener('click', () => openMap(ticketId, context));

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
                insertButton(ticketId, context);
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
