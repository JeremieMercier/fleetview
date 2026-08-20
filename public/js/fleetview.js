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

/* global CFG_GLPI, bootstrap, L, getAjaxCsrfToken */

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
                            <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom">
                                <label class="form-label mb-0" for="fleetview-radius">
                                    ${__('Search radius (km)', 'fleetview')}
                                </label>
                                <select id="fleetview-radius" class="form-select form-select-sm w-auto"></select>
                                <span id="fleetview-legend" class="ms-2"></span>
                                <span id="fleetview-count" class="text-secondary ms-auto"></span>
                            </div>
                            <div id="fleetview-alert" class="alert alert-warning m-3 d-none" role="alert"></div>
                            <div id="fleetview-map"></div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(wrapper.firstElementChild);

        document.getElementById('fleetview-radius').addEventListener('change', (event) => {
            if (currentTicketId !== null) {
                loadVehicles(currentTicketId, currentContext, Number(event.target.value));
            }
        });

        // Assign buttons live inside Leaflet popups (dynamic DOM): delegate
        document.getElementById('fleetview-modal').addEventListener('click', (event) => {
            const button = event.target.closest('.fleetview-assign');
            if (button && currentTicketId !== null) {
                assignTechnician(button);
            }
        });

        return document.getElementById('fleetview-modal');
    };

    const assignTechnician = async (button) => {
        button.disabled = true;

        try {
            const response = await fetch(`${PLUGIN_URL}/ticket/${currentTicketId}/assign`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Glpi-Csrf-Token': getAjaxCsrfToken(),
                },
                body: JSON.stringify({ users_id: Number(button.dataset.usersId) }),
            });
            const data = await response.json();

            if (!data.success) {
                button.disabled = false;
                showAlert(data.error ?? __('Unable to assign the technician.', 'fleetview'), 'danger');
                return;
            }

            button.innerHTML = `<i class="ti ti-check me-1"></i>${__('Assigned', 'fleetview')}`;
            showAlert(__('%1 has been assigned to the ticket. Reloading…', 'fleetview', data.user_name), 'success');
            setTimeout(() => window.location.reload(), 1500);
        } catch {
            button.disabled = false;
            showAlert(__('Unable to assign the technician.', 'fleetview'), 'danger');
        }
    };

    // Radius choices offered in the modal (km); the configured radius is
    // inserted on first load if missing. Keep in sync with PluginConfig.
    const RADIUS_CHOICES = [25, 50, 75, 100, 125, 150, 175, 200, 250, 300, 400, 500];

    const syncRadiusSelect = (radiusKm) => {
        const select = document.getElementById('fleetview-radius');
        if (select.options.length === 0) {
            const choices = [...new Set([...RADIUS_CHOICES, Math.round(radiusKm)])].sort((a, b) => a - b);
            for (const km of choices) {
                select.add(new Option(`${km} km`, km));
            }
        }
        select.value = String(Math.round(radiusKm));
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

    // Native Leaflet marker recolored for the ticket location (configured
    // color, red by default) so it stays visually consistent with the
    // vehicle markers.
    const ticketIcon = () => {
        const icon = new L.Icon.Default();
        icon.options.className = 'fleetview-ticket-marker';
        return icon;
    };

    // Recolor the native blue marker (hsl(207, 66%, 48%)) to an arbitrary
    // configured color using CSS filters, keeping the native shape.
    const hexToHsl = (hex) => {
        const value = hex.replace('#', '');
        const r = parseInt(value.slice(0, 2), 16) / 255;
        const g = parseInt(value.slice(2, 4), 16) / 255;
        const b = parseInt(value.slice(4, 6), 16) / 255;
        const max = Math.max(r, g, b);
        const min = Math.min(r, g, b);
        const l = (max + min) / 2;
        if (max === min) {
            return { h: 0, s: 0, l: l * 100 };
        }
        const d = max - min;
        const s = d / (l > 0.5 ? 2 - max - min : max + min);
        let h;
        if (max === r) {
            h = ((g - b) / d + (g < b ? 6 : 0)) * 60;
        } else if (max === g) {
            h = ((b - r) / d + 2) * 60;
        } else {
            h = ((r - g) / d + 4) * 60;
        }
        return { h, s: s * 100, l: l * 100 };
    };

    const markerFilter = (hex) => {
        const base = { h: 207, s: 66, l: 48 };
        const { h, s, l } = hexToHsl(hex);
        return `hue-rotate(${Math.round(h - base.h)}deg)`
            + ` saturate(${(s / base.s).toFixed(2)})`
            + ` brightness(${(l / base.l).toFixed(2)})`;
    };

    // Native Leaflet blue, used for every vehicle beyond the podium
    const DEFAULT_MARKER_COLOR = '#2a81cb';

    const rankColor = (index, colors) => [colors.top1, colors.top2, colors.top3][index] ?? null;

    const updateLegend = (colors) => {
        const entries = [
            [colors.top1, __('1st', 'fleetview')],
            [colors.top2, __('2nd', 'fleetview')],
            [colors.top3, __('3rd', 'fleetview')],
            [DEFAULT_MARKER_COLOR, __('others', 'fleetview')],
        ];
        document.getElementById('fleetview-legend').innerHTML = entries
            .map(([color, label]) => `<span class="ms-2 text-nowrap">`
                + `<span class="fleetview-dot" style="background:${_.escape(color)}"></span> ${label}</span>`)
            .join('');
    };

    let map = null;
    let vehiclesLayer = null;
    let currentTicketId = null;
    let currentContext = null;

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

        // Configured ticket marker color, applied through a dynamic CSS rule
        // so it works whenever the marker icon is (re)rendered.
        {
            document.getElementById('fleetview-ticket-marker-style')?.remove();
            const style = document.createElement('style');
            style.id = 'fleetview-ticket-marker-style';
            style.textContent = `img.fleetview-ticket-marker { filter: ${markerFilter(context.marker_color)}; }`;
            document.head.appendChild(style);
        }

        await waitModalShown(modalEl);
        map.invalidateSize();
        map.setView([latitude, longitude], 11);

        if (!context.configured) {
            showAlert(__('The Masternaut API is not configured yet.', 'fleetview'));
            return;
        }

        currentTicketId = ticketId;
        currentContext = context;
        await loadVehicles(ticketId, context);
    };

    const loadVehicles = async (ticketId, context, radius = null) => {
        const { latitude, longitude } = context.location;
        const count = document.getElementById('fleetview-count');

        hideAlert();
        vehiclesLayer.clearLayers();
        count.textContent = '…';

        try {
            const url = new URL(`${PLUGIN_URL}/ticket/${ticketId}/vehicles`, window.location.origin);
            if (radius !== null) {
                url.searchParams.set('radius', radius);
            }
            const response = await fetch(url);
            const data = await response.json();

            if (data.error) {
                count.textContent = '';
                showAlert(data.error, 'danger');
                return;
            }

            syncRadiusSelect(data.radius_km);
            updateLegend(data.marker_colors);

            const located = data.vehicles.filter((v) => v.latitude !== null && v.longitude !== null);
            count.textContent = _n('%1 vehicle', '%1 vehicles', located.length, 'fleetview', located.length);

            if (located.length === 0) {
                showAlert(
                    __('No vehicle found within a radius of %s km.', 'fleetview')
                        .replace('%s', data.radius_km),
                    'info'
                );
                return;
            }

            const bounds = [[latitude, longitude]];
            located.forEach((vehicle, index) => {
                const travel = vehicle.travel_time_min !== null
                    ? __(
                        'approx. %1 drive (%2 km by road)',
                        'fleetview',
                        formatDuration(vehicle.travel_time_min),
                        vehicle.road_distance_km
                    )
                    : null;

                const assign = data.can_assign && vehicle.user_id !== null
                    ? `<button type="button" class="btn btn-sm btn-primary mt-2 fleetview-assign" data-users-id="${vehicle.user_id}">`
                        + `<i class="ti ti-user-plus me-1"></i>${__('Assign this technician', 'fleetview')}</button>`
                    : null;

                const status = vehicle.status_label
                    ? `<i class="ti ${vehicle.status === 'IN_MAINTENANCE' ? 'ti-tool' : 'ti-circle-check'}"></i> `
                        + `${_.escape(vehicle.status_label)}`
                    : null;

                const details = [
                    `<strong>${_.escape(vehicle.label)}</strong>`,
                    status,
                    vehicle.driver_name ? `<i class="ti ti-user"></i> ${_.escape(vehicle.driver_name)}` : null,
                    `<i class="ti ti-route"></i> ${__('%1 km as the crow flies', 'fleetview', vehicle.distance_km)}`,
                    travel ? `<i class="ti ti-car"></i> ${travel}` : null,
                    `<i class="ti ti-clock"></i> ${_.escape(formatDate(vehicle.updated_at))}`,
                    assign,
                ].filter(Boolean).join('<br>');

                const marker = L.marker([vehicle.latitude, vehicle.longitude])
                    .addTo(vehiclesLayer)
                    .bindPopup(details);
                const color = rankColor(index, data.marker_colors);
                if (color !== null) {
                    marker.getElement().style.filter = markerFilter(color);
                }
                bounds.push([vehicle.latitude, vehicle.longitude]);
            });
            map.fitBounds(bounds, { padding: [40, 40], maxZoom: 13 });
        } catch {
            count.textContent = '';
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
