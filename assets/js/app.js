document.addEventListener('DOMContentLoaded', () => {
    const rootStyles = getComputedStyle(document.documentElement);
    const chartPrimary = rootStyles.getPropertyValue('--chart-primary').trim() || '#0e5eb5';
    const chartSecondary = rootStyles.getPropertyValue('--chart-secondary').trim() || '#0ea5a8';
    const chartAccent = rootStyles.getPropertyValue('--chart-accent').trim() || '#f59e0b';
    const chartDanger = rootStyles.getPropertyValue('--chart-danger').trim() || '#b0302f';

    if (window.Chart) {
        Chart.defaults.font.family = '"Public Sans", "Segoe UI", sans-serif';
        Chart.defaults.color = rootStyles.getPropertyValue('--muted').trim() || '#53627a';
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
    }

    const numberFormatter = new Intl.NumberFormat('en-US');

    const formatNumber = (value) => numberFormatter.format(Number(value) || 0);
    const formatPercentage = (value, decimals = 1) => `${(Number(value) || 0).toFixed(decimals)}%`;
    const formatDecimal = (value, decimals = 4) => (Number(value) || 0).toFixed(decimals);
    const formatDistanceKm = (value) => {
        const numeric = Number(value);
        if (!Number.isFinite(numeric) || numeric <= 0) {
            return '0';
        }

        return Number.isInteger(numeric) ? String(numeric) : numeric.toFixed(1).replace(/\.0$/, '');
    };
    const renderEventsDistanceEmptyState = (radiusKm) => {
        const radiusLabel = formatDistanceKm(radiusKm);
        return `<tr><td colspan="8" class="empty-state">No tracked parking facilities are within ${escapeHtml(radiusLabel)} km of this event venue.</td></tr>`;
    };
    const escapeHtml = (value) => String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const availabilityBadgeClass = (label) => {
        const normalized = String(label || '').trim().toLowerCase();
        if (normalized === 'full') {
            return 'status-full';
        }
        if (normalized === 'limited') {
            return 'status-limited';
        }
        return 'status-available';
    };
    const percentBadgeClass = (value) => {
        const numeric = Number(value) || 0;
        if (numeric >= 90) {
            return 'status-full';
        }
        if (numeric >= 70) {
            return 'status-limited';
        }
        return 'status-available';
    };
    const formatUtcDateTime = (value) => {
        if (!value) {
            return 'N/A';
        }

        const normalizedValue = String(value).replace(' ', 'T');
        const iso = normalizedValue.includes('T') && /(?:[+\-]\d{2}:\d{2}|Z)$/.test(normalizedValue)
            ? normalizedValue
            : `${normalizedValue}Z`;
        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) {
            return 'N/A';
        }

        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const day = String(date.getUTCDate()).padStart(2, '0');
        const month = months[date.getUTCMonth()] || '';
        const year = date.getUTCFullYear();
        const hours = String(date.getUTCHours()).padStart(2, '0');
        const minutes = String(date.getUTCMinutes()).padStart(2, '0');
        return `${day} ${month} ${year}, ${hours}:${minutes} UTC`;
    };
    const formatHourLabel = (value) => {
        if (value === null || value === undefined || value === '') {
            return 'N/A';
        }

        return `${String(Number(value) || 0).padStart(2, '0')}:00`;
    };
    const updateChartData = (chart, labels, values) => {
        if (!chart) {
            return;
        }

        chart.data.labels = labels;
        if (chart.data.datasets[0]) {
            chart.data.datasets[0].data = values;
        }
        chart.update();
    };
    const destroyChart = (chartRef, key) => {
        if (chartRef && chartRef[key]) {
            chartRef[key].destroy();
            chartRef[key] = null;
        }
    };
    const applyTableSearch = (searchInput) => {
        if (!searchInput) {
            return;
        }

        const scope = searchInput.closest('[data-facility-search-scope]') || document;
        const rows = scope.querySelectorAll('[data-facility-row]');
        const term = searchInput.value.trim().toLowerCase();

        rows.forEach((row) => {
            const haystack = row.getAttribute('data-search') || '';
            row.style.display = haystack.includes(term) ? '' : 'none';
        });
    };

    document.querySelectorAll('[data-facility-search]').forEach((searchInput) => {
        searchInput.addEventListener('input', () => {
            applyTableSearch(searchInput);
        });
        applyTableSearch(searchInput);
    });

    const renderLatestTable = (tableBody, latest) => {
        if (!tableBody) {
            return;
        }

        if (!Array.isArray(latest) || latest.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="7" class="empty-state">No live facility data is available yet.</td></tr>';
            return;
        }

        tableBody.innerHTML = latest.map((row) => {
            const occupancyRate = Number(row.occupancy_rate) || 0;
            const occupancyPercent = Math.max(0, Math.min(100, occupancyRate * 100));
            const facilityId = encodeURIComponent(String(row.facility_id ?? ''));

            return `
                <tr>
                    <td>${escapeHtml(row.facility_name ?? '')}</td>
                    <td>${escapeHtml(formatNumber(row.capacity))}</td>
                    <td>${escapeHtml(formatNumber(row.occupied))}</td>
                    <td>${escapeHtml(formatNumber(row.available))}</td>
                    <td><strong>${escapeHtml(formatPercentage(occupancyPercent))}</strong><div class="progress" style="margin-top:8px;"><span style="width: ${occupancyPercent}%"></span></div></td>
                    <td><span class="status-pill ${escapeHtml(availabilityBadgeClass(row.availability_class))}">${escapeHtml(row.availability_class ?? 'Available')}</span></td>
                    <td><a href="facilities.php?facility_id=${facilityId}">View facility</a></td>
                </tr>
            `;
        }).join('');
    };
    const topLatestFacilities = (payload) => {
        if (payload && Array.isArray(payload.top_latest) && payload.top_latest.length) {
            return payload.top_latest;
        }
        if (payload && Array.isArray(payload.latest)) {
            return payload.latest.slice(0, 8);
        }
        return [];
    };

    const renderHomeHighlights = (facilities) => {
        if (!Array.isArray(facilities) || facilities.length === 0) {
            return '<div class="empty-state">No facility snapshots are available yet. Start live collection or import data to populate this section.</div>';
        }

        return `
            <div class="grid-three">
                ${facilities.map((row) => {
                    const percent = Math.max(0, Math.min(100, (Number(row.occupancy_rate) || 0) * 100));
                    const facilityId = encodeURIComponent(String(row.facility_id ?? ''));

                    return `
                        <article class="panel">
                            <span class="status-pill ${escapeHtml(percentBadgeClass(percent))}">${escapeHtml(row.availability_class ?? 'Available')}</span>
                            <h3 style="margin-top:14px;">${escapeHtml(row.facility_name ?? '')}</h3>
                            <p class="muted">Facility ID: ${escapeHtml(row.facility_id ?? '')}</p>
                            <div class="metric">${escapeHtml(formatPercentage(percent))}</div>
                            <div class="progress"><span style="width: ${percent}%"></span></div>
                            <p class="muted">Occupied: ${escapeHtml(formatNumber(row.occupied))} / ${escapeHtml(formatNumber(row.capacity))}</p>
                            <a class="btn btn-secondary" href="facilities.php?facility_id=${facilityId}">View facility profile</a>
                        </article>
                    `;
                }).join('')}
            </div>
        `;
    };
    const renderHomeData = (host, payload) => {
        if (!host || !payload || typeof payload !== 'object') {
            return;
        }

        const summary = payload.summary || {};
        const dataset = payload.dataset || {};
        const topFacilities = Array.isArray(payload.top_facilities) ? payload.top_facilities : [];
        const fields = {
            monitored: host.querySelector('[data-home-monitored]'),
            observations: host.querySelector('[data-home-observations]'),
            latestUpdate: host.querySelector('[data-home-latest-update]'),
            totalFacilities: host.querySelector('[data-home-total-facilities]'),
            totalCapacity: host.querySelector('[data-home-total-capacity]'),
            averageOccupancy: host.querySelector('[data-home-average-occupancy]'),
            busiestName: host.querySelector('[data-home-busiest-name]'),
            busiestRate: host.querySelector('[data-home-busiest-rate]'),
            highlights: host.querySelector('[data-home-highlights]')
        };

        if (fields.monitored) {
            fields.monitored.textContent = `Monitored facilities: ${formatNumber(summary.facilities_count)}`;
        }
        if (fields.observations) {
            fields.observations.textContent = `Total observations: ${formatNumber(dataset.observations)}`;
        }
        if (fields.latestUpdate) {
            fields.latestUpdate.textContent = `Latest update: ${formatUtcDateTime(dataset.max_time)}`;
        }
        if (fields.totalFacilities) {
            fields.totalFacilities.textContent = formatNumber(summary.facilities_count);
        }
        if (fields.totalCapacity) {
            fields.totalCapacity.textContent = formatNumber(summary.total_capacity);
        }
        if (fields.averageOccupancy) {
            fields.averageOccupancy.textContent = formatPercentage(summary.avg_occupancy);
        }
        if (fields.busiestName) {
            fields.busiestName.textContent = summary.busiest_name || 'N/A';
        }
        if (fields.busiestRate) {
            fields.busiestRate.textContent = formatPercentage(summary.busiest_rate);
        }
        if (fields.highlights) {
            fields.highlights.innerHTML = renderHomeHighlights(topFacilities);
        }
    };

    const renderDashboardData = (host, payload) => {
        if (!host || !payload || typeof payload !== 'object') {
            return;
        }

        const summary = payload.summary || {};
        const hourly = Array.isArray(payload.hourly) ? payload.hourly : [];
        const distribution = Array.isArray(payload.distribution) ? payload.distribution : [];
        const latest = Array.isArray(payload.latest) ? payload.latest : [];
        const topLatest = topLatestFacilities(payload);
        const summaryFields = {
            lastRefresh: host.querySelector('[data-summary-last-refresh]'),
            facilities: host.querySelector('[data-summary-facilities]'),
            occupied: host.querySelector('[data-summary-occupied]'),
            available: host.querySelector('[data-summary-available]'),
            average: host.querySelector('[data-summary-avg]'),
            latestTableBody: host.querySelector('[data-latest-table-body]')
        };

        if (summaryFields.lastRefresh) {
            summaryFields.lastRefresh.textContent = `Last data refresh: ${formatUtcDateTime(summary.last_refresh)}`;
        }
        if (summaryFields.facilities) {
            summaryFields.facilities.textContent = formatNumber(summary.facilities_count);
        }
        if (summaryFields.occupied) {
            summaryFields.occupied.textContent = formatNumber(summary.occupied_now);
        }
        if (summaryFields.available) {
            summaryFields.available.textContent = formatNumber(summary.available_now);
        }
        if (summaryFields.average) {
            summaryFields.average.textContent = formatPercentage(summary.avg_occupancy);
        }

        renderLatestTable(summaryFields.latestTableBody, latest);

        updateChartData(
            window.dashboardCharts && window.dashboardCharts.hourly,
            hourly.map((row) => `${String(Number(row.hour) || 0).padStart(2, '0')}:00`),
            hourly.map((row) => Number(row.average_occupancy) || 0)
        );
        updateChartData(
            window.dashboardCharts && window.dashboardCharts.availability,
            distribution.map((row) => row.availability_class || ''),
            distribution.map((row) => Number(row.total) || 0)
        );
        updateChartData(
            window.dashboardCharts && window.dashboardCharts.busiest,
            topLatest.map((row) => row.facility_name || ''),
            topLatest.map((row) => Math.round((Number(row.occupancy_rate) || 0) * 10000) / 100)
        );
    };

    const renderFacilitiesTableRows = (facilities) => {
        if (!Array.isArray(facilities) || facilities.length === 0) {
            return '<tr><td colspan="7" class="empty-state">No facilities match the current filters.</td></tr>';
        }

        return facilities.map((row) => {
            const percent = Math.max(0, Math.min(100, (Number(row.occupancy_rate) || 0) * 100));
            const facilityId = encodeURIComponent(String(row.facility_id ?? ''));

            return `
                <tr>
                    <td>${escapeHtml(row.facility_id ?? '')}</td>
                    <td><a href="facilities.php?facility_id=${facilityId}">${escapeHtml(row.facility_name ?? '')}</a></td>
                    <td>${escapeHtml(formatNumber(row.capacity))}</td>
                    <td>${escapeHtml(formatNumber(row.occupied))}</td>
                    <td>${escapeHtml(formatNumber(row.available))}</td>
                    <td><strong>${escapeHtml(formatPercentage(percent))}</strong><div class="progress" style="margin-top:8px;"><span style="width: ${percent}%"></span></div></td>
                    <td><span class="status-pill ${escapeHtml(availabilityBadgeClass(row.availability_class))}">${escapeHtml(row.availability_class ?? 'Available')}</span></td>
                </tr>
            `;
        }).join('');
    };
    const getSelectedFacilityFilterValue = (host) => String(
        host?.querySelector('[data-facilities-facility-filter]')?.value
        || host?.getAttribute('data-live-facilities-selected')
        || ''
    ).trim();
    const buildFacilitiesPageUrl = (selectedFacilityId = '') => {
        const nextUrl = new URL(window.location.href);
        const facilityId = String(selectedFacilityId || '').trim();

        if (facilityId) {
            nextUrl.searchParams.set('facility_id', facilityId);
        } else {
            nextUrl.searchParams.delete('facility_id');
        }

        return nextUrl;
    };
    const buildFacilitiesSummaryUrl = (host, selectedFacilityId = '') => {
        const baseUrl = host && host.getAttribute('data-live-facilities-url')
            ? host.getAttribute('data-live-facilities-url')
            : 'api/facilities_summary.php';
        const nextUrl = new URL(baseUrl, window.location.href);
        const facilityId = String(selectedFacilityId || getSelectedFacilityFilterValue(host) || '').trim();

        if (facilityId) {
            nextUrl.searchParams.set('facility_id', facilityId);
        } else {
            nextUrl.searchParams.delete('facility_id');
        }

        return nextUrl.toString();
    };
    const syncFacilitiesSelectionState = (host, payload) => {
        const selectedFacilityId = String(payload && payload.selected_facility_id ? payload.selected_facility_id : '').trim();
        const select = host?.querySelector('[data-facilities-facility-filter]');
        const nextState = payload && typeof payload === 'object'
            ? { ...payload, selected_facility_id: selectedFacilityId }
            : { selected_facility_id: selectedFacilityId };

        if (host) {
            host.setAttribute('data-live-facilities-selected', selectedFacilityId);
        }
        if (select && select.value !== selectedFacilityId) {
            select.value = selectedFacilityId;
        }

        window.facilitiesState = nextState;

        if (window.history && typeof window.history.replaceState === 'function') {
            window.history.replaceState(
                window.history.state,
                document.title,
                buildFacilitiesPageUrl(selectedFacilityId).toString()
            );
        }
    };
    const getFilteredFacilities = (host, facilitiesPayload) => {
        const facilities = Array.isArray(facilitiesPayload && facilitiesPayload.facilities)
            ? facilitiesPayload.facilities.slice()
            : [];
        const selectedFacilityId = getSelectedFacilityFilterValue(host);
        const searchTerm = (host.querySelector('[data-facilities-search]')?.value || '').trim().toLowerCase();
        const statusFilter = host.querySelector('[data-facilities-status-filter]')?.value || 'all';
        const sortFilter = host.querySelector('[data-facilities-sort-filter]')?.value || 'occupancy_desc';

        const filtered = facilities.filter((row) => {
            const matchesFacility = selectedFacilityId === ''
                || String(row.facility_id ?? '').trim() === selectedFacilityId;
            const haystack = `${String(row.facility_id ?? '').toLowerCase()} ${String(row.facility_name ?? '').toLowerCase()} ${String(row.availability_class ?? '').toLowerCase()}`;
            const matchesSearch = searchTerm === '' || haystack.includes(searchTerm);
            const matchesStatus = statusFilter === 'all'
                || String(row.availability_class ?? '').trim().toLowerCase() === statusFilter;

            return matchesFacility && matchesSearch && matchesStatus;
        });

        filtered.sort((left, right) => {
            const leftRate = Number(left.occupancy_rate) || 0;
            const rightRate = Number(right.occupancy_rate) || 0;

            if (sortFilter === 'occupancy_asc') {
                if (leftRate === rightRate) {
                    return String(left.facility_name ?? '').localeCompare(String(right.facility_name ?? ''));
                }
                return leftRate - rightRate;
            }

            if (leftRate === rightRate) {
                return String(left.facility_name ?? '').localeCompare(String(right.facility_name ?? ''));
            }
            return rightRate - leftRate;
        });

        return filtered;
    };
    const renderFacilitiesSelectedShell = (host, payload) => {
        const shell = host.querySelector('[data-facilities-selected-shell]');
        if (!shell) {
            return;
        }

        if (!window.facilitiesCharts) {
            window.facilitiesCharts = {};
        }
        destroyChart(window.facilitiesCharts, 'history');

        const selectedFacilityId = String(payload.selected_facility_id || '');
        const selectedSummary = payload.selected_summary && typeof payload.selected_summary === 'object'
            ? payload.selected_summary
            : null;
        const historyLabels = Array.isArray(payload.history_labels) ? payload.history_labels : [];
        const historyValues = Array.isArray(payload.history_values) ? payload.history_values : [];

        if (selectedSummary) {
            const selectedPercent = Math.max(0, Math.min(100, (Number(selectedSummary.occupancy_rate) || 0) * 100));

            shell.innerHTML = `
                <section class="grid-two">
                    <article class="panel">
                        <h3>${escapeHtml(selectedSummary.facility_name ?? '')}</h3>
                        <p class="muted">Facility ID: ${escapeHtml(selectedSummary.facility_id ?? '')} | Latest reading: ${escapeHtml(formatUtcDateTime(selectedSummary.recorded_at))}</p>
                        <div class="metric">${escapeHtml(formatPercentage(selectedPercent))}</div>
                        <div class="progress"><span style="width: ${selectedPercent}%"></span></div>
                        <div class="stat-list" style="margin-top:18px;">
                            <div class="stat-item"><span>Capacity</span><strong>${escapeHtml(formatNumber(selectedSummary.capacity))}</strong></div>
                            <div class="stat-item"><span>Occupied</span><strong>${escapeHtml(formatNumber(selectedSummary.occupied))}</strong></div>
                            <div class="stat-item"><span>Available</span><strong>${escapeHtml(formatNumber(selectedSummary.available))}</strong></div>
                            <div class="stat-item"><span>Status</span><strong><span class="status-pill ${escapeHtml(availabilityBadgeClass(selectedSummary.availability_class))}">${escapeHtml(selectedSummary.availability_class ?? 'Available')}</span></strong></div>
                        </div>
                    </article>
                    <article class="chart-card">
                        <h3>Occupancy timeline for selected facility</h3>
                        <p class="muted">Recent occupancy percentage trend for this specific site.</p>
                        <canvas data-facilities-history-chart height="180"></canvas>
                    </article>
                </section>
            `;

            const historyCanvas = shell.querySelector('[data-facilities-history-chart]');
            if (historyCanvas && window.Chart) {
                window.facilitiesCharts.history = new Chart(historyCanvas, {
                    type: 'line',
                    data: {
                        labels: historyLabels,
                        datasets: [{
                            label: 'Occupancy %',
                            data: historyValues,
                            borderColor: chartPrimary,
                            backgroundColor: 'rgba(14, 94, 181, 0.12)',
                            borderWidth: 3,
                            tension: 0.22,
                            fill: true,
                            pointRadius: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: { y: { beginAtZero: true, max: 100 } }
                    }
                });
            }

            return;
        }

        shell.innerHTML = selectedFacilityId !== ''
            ? '<section class="empty-state card">No timeline data was found for the selected facility.</section>'
            : '';
    };
    const renderFacilitiesData = (host, payload) => {
        if (!host || !payload || typeof payload !== 'object') {
            return;
        }

        const selectedFacilityId = String(payload.selected_facility_id || getSelectedFacilityFilterValue(host) || '').trim();
        const nextPayload = {
            ...payload,
            selected_facility_id: selectedFacilityId
        };

        const latestRefresh = host.querySelector('[data-facilities-latest-refresh]');
        const resultCount = host.querySelector('[data-facilities-result-count]');
        const tableBody = host.querySelector('[data-facilities-table-body]');
        const filteredFacilities = getFilteredFacilities(host, nextPayload);

        if (latestRefresh) {
            latestRefresh.textContent = `Latest network refresh: ${formatUtcDateTime(nextPayload.summary && nextPayload.summary.last_refresh)}`;
        }
        if (resultCount) {
            resultCount.textContent = `Showing ${formatNumber(filteredFacilities.length)} facilities`;
        }
        if (tableBody) {
            tableBody.innerHTML = renderFacilitiesTableRows(filteredFacilities);
        }

        renderFacilitiesSelectedShell(host, nextPayload);
        syncFacilitiesSelectionState(host, nextPayload);
    };
    const bindFacilitiesControls = (host) => {
        if (!host || host.dataset.facilitiesControlsBound === 'true') {
            return;
        }

        const controls = [
            host.querySelector('[data-facilities-search]'),
            host.querySelector('[data-facilities-status-filter]'),
            host.querySelector('[data-facilities-sort-filter]')
        ].filter(Boolean);
        const facilitySelect = host.querySelector('[data-facilities-facility-filter]');
        const facilityForm = host.querySelector('[data-facilities-selection-form]');
        let selectionRequestToken = 0;

        controls.forEach((control) => {
            const eventName = control.matches('input') ? 'input' : 'change';
            control.addEventListener(eventName, () => {
                renderFacilitiesData(host, window.facilitiesState || {});
            });
        });

        if (facilityForm) {
            facilityForm.addEventListener('submit', (event) => {
                event.preventDefault();
            });
        }

        if (facilitySelect) {
            facilitySelect.addEventListener('change', async () => {
                const selectedFacilityId = String(facilitySelect.value || '').trim();
                const requestToken = selectionRequestToken + 1;
                selectionRequestToken = requestToken;

                renderFacilitiesData(host, {
                    ...(window.facilitiesState || {}),
                    selected_facility_id: selectedFacilityId,
                    selected_summary: selectedFacilityId === '' ? null : (
                        window.facilitiesState?.selected_facility_id === selectedFacilityId
                            ? window.facilitiesState?.selected_summary ?? null
                            : null
                    ),
                    history_labels: selectedFacilityId === '' ? [] : (
                        window.facilitiesState?.selected_facility_id === selectedFacilityId
                            ? window.facilitiesState?.history_labels ?? []
                            : []
                    ),
                    history_values: selectedFacilityId === '' ? [] : (
                        window.facilitiesState?.selected_facility_id === selectedFacilityId
                            ? window.facilitiesState?.history_values ?? []
                            : []
                    )
                });

                try {
                    const response = await fetch(buildFacilitiesSummaryUrl(host, selectedFacilityId), {
                        method: 'GET',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        cache: 'no-store',
                        credentials: 'same-origin'
                    });
                    const payload = await response.json().catch(() => null);

                    if (!response.ok || !payload || requestToken !== selectionRequestToken) {
                        return;
                    }

                    renderFacilitiesData(host, payload);
                } catch (error) {
                    console.error('Facilities selection refresh failed:', error);
                }
            });
        }

        host.dataset.facilitiesControlsBound = 'true';
    };

    const renderRegressionRows = (rows) => {
        if (!Array.isArray(rows) || rows.length === 0) {
            return '<tr><td colspan="5" class="empty-state">No regression metrics are available yet.</td></tr>';
        }

        return rows.map((row) => `
            <tr>
                <td>${escapeHtml(row.facility_name ?? '')}</td>
                <td>${escapeHtml(formatNumber(row.sample_size))}</td>
                <td>${escapeHtml(formatDecimal(row.mae, 4))}</td>
                <td>${escapeHtml(formatDecimal(row.rmse, 4))}</td>
                <td>${row.r2 == null ? 'N/A' : escapeHtml((Number(row.r2) || 0).toFixed(3))}</td>
            </tr>
        `).join('');
    };
    const renderClassificationRows = (rows) => {
        if (!Array.isArray(rows) || rows.length === 0) {
            return '<tr><td colspan="3" class="empty-state">No classification metrics are available yet.</td></tr>';
        }

        return rows.map((row) => `
            <tr>
                <td>${escapeHtml(row.facility_name ?? '')}</td>
                <td>${escapeHtml(formatNumber(row.sample_size))}</td>
                <td>${escapeHtml(formatPercentage((Number(row.accuracy) || 0) * 100))}</td>
            </tr>
        `).join('');
    };
    const renderInsightsData = (host, payload) => {
        if (!host || !payload || typeof payload !== 'object') {
            return;
        }

        const peak = payload.peak || {};
        const dataset = payload.dataset || {};
        const regressionMetrics = Array.isArray(payload.regression_metrics) ? payload.regression_metrics : [];
        const classificationMetrics = Array.isArray(payload.classification_metrics) ? payload.classification_metrics : [];
        const fields = {
            lastRefresh: host.querySelector('[data-insights-last-refresh]'),
            peakHour: host.querySelector('[data-insights-peak-hour]'),
            peakRate: host.querySelector('[data-insights-peak-rate]'),
            observations: host.querySelector('[data-insights-observations]'),
            minTime: host.querySelector('[data-insights-min-time]'),
            maxTime: host.querySelector('[data-insights-max-time]'),
            avgAccuracy: host.querySelector('[data-insights-avg-accuracy]'),
            regressionBody: host.querySelector('[data-insights-regression-body]'),
            classificationBody: host.querySelector('[data-insights-classification-body]')
        };

        if (fields.lastRefresh) {
            fields.lastRefresh.textContent = `Latest network refresh: ${formatUtcDateTime(payload.summary && payload.summary.last_refresh)}`;
        }
        if (fields.peakHour) {
            fields.peakHour.textContent = formatHourLabel(peak.hour);
        }
        if (fields.peakRate) {
            fields.peakRate.textContent = formatPercentage(peak.average_occupancy);
        }
        if (fields.observations) {
            fields.observations.textContent = formatNumber(dataset.observations);
        }
        if (fields.minTime) {
            fields.minTime.textContent = formatUtcDateTime(dataset.min_time);
        }
        if (fields.maxTime) {
            fields.maxTime.textContent = formatUtcDateTime(dataset.max_time);
        }
        if (fields.avgAccuracy) {
            fields.avgAccuracy.textContent = formatPercentage(payload.avg_accuracy, 1);
        }
        if (fields.regressionBody) {
            fields.regressionBody.innerHTML = renderRegressionRows(regressionMetrics);
        }
        if (fields.classificationBody) {
            fields.classificationBody.innerHTML = renderClassificationRows(classificationMetrics);
        }

        updateChartData(
            window.insightsCharts && window.insightsCharts.topAverage,
            Array.isArray(payload.top_average_labels) ? payload.top_average_labels : [],
            Array.isArray(payload.top_average_values) ? payload.top_average_values : []
        );
        updateChartData(
            window.insightsCharts && window.insightsCharts.capacity,
            Array.isArray(payload.capacity_labels) ? payload.capacity_labels : [],
            Array.isArray(payload.capacity_values) ? payload.capacity_values : []
        );
    };

    const renderAboutData = (host, payload) => {
        if (!host || !payload || typeof payload !== 'object') {
            return;
        }

        const summary = payload.summary || {};
        const dataset = payload.dataset || {};
        const fields = {
            facilities: host.querySelector('[data-about-facilities]'),
            minTime: host.querySelector('[data-about-min-time]'),
            maxTime: host.querySelector('[data-about-max-time]'),
            observations: host.querySelector('[data-about-observations]')
        };

        if (fields.facilities) {
            fields.facilities.textContent = `Monitored facilities: ${formatNumber(summary.facilities_count)}`;
        }
        if (fields.minTime) {
            fields.minTime.textContent = formatUtcDateTime(dataset.min_time);
        }
        if (fields.maxTime) {
            fields.maxTime.textContent = formatUtcDateTime(dataset.max_time);
        }
        if (fields.observations) {
            fields.observations.textContent = formatNumber(dataset.observations);
        }
    };

    const eventsFeaturedTitle = (selectedEvent) => {
        const featuredForecast = selectedEvent && selectedEvent.featured_forecast ? selectedEvent.featured_forecast : null;
        return featuredForecast && String(featuredForecast.facility_id ?? '') === '3'
            ? 'Bella Vista Example'
            : 'Featured Facility';
    };
    const buildEventsPageUrl = (selectedEventId, selectedCategory = '') => {
        const nextUrl = new URL(window.location.href);

        if (selectedEventId) {
            nextUrl.searchParams.set('event', selectedEventId);
        } else {
            nextUrl.searchParams.delete('event');
        }

        if (selectedCategory && selectedCategory !== 'all') {
            nextUrl.searchParams.set('category', selectedCategory);
        } else {
            nextUrl.searchParams.delete('category');
        }

        return nextUrl;
    };
    const buildEventsSummaryUrl = (host, selectedEventId) => {
        const baseUrl = host && host.getAttribute('data-live-events-url')
            ? host.getAttribute('data-live-events-url')
            : 'api/events_summary.php';
        const nextUrl = new URL(baseUrl, window.location.href);

        if (selectedEventId) {
            nextUrl.searchParams.set('event', selectedEventId);
        } else {
            nextUrl.searchParams.delete('event');
        }

        return nextUrl.toString();
    };
    const isEventForecastPage = () => {
        const host = document.querySelector('[data-live-events-url]');
        return String(host && host.getAttribute('data-live-events-page-type') ? host.getAttribute('data-live-events-page-type') : '')
            .trim()
            .toLowerCase() === 'forecast';
    };
    const normalizeEventsPayload = (payload, selectedEventId = '') => {
        const source = payload && typeof payload === 'object' ? payload : {};
        const events = Array.isArray(source.events) ? source.events : [];
        const categoryOptions = Array.isArray(source.category_options) && source.category_options.length
            ? source.category_options
            : Array.from(events.reduce((map, event) => {
                const slugs = Array.isArray(event.category_slugs) && event.category_slugs.length
                    ? event.category_slugs
                    : [String(event.category_slug || '')];
                const labels = Array.isArray(event.category_labels) && event.category_labels.length
                    ? event.category_labels
                    : [String(event.category_label || 'Event')];

                slugs.forEach((slug, index) => {
                    const normalizedSlug = String(slug || '').trim();
                    if (!normalizedSlug || map.has(normalizedSlug)) {
                        return;
                    }

                    map.set(normalizedSlug, {
                        slug: normalizedSlug,
                        label: String(labels[index] || event.category_label || normalizedSlug).trim() || normalizedSlug
                    });
                });

                return map;
            }, new Map()).values()).sort((left, right) => String(left.label || '').localeCompare(String(right.label || '')));
        const preferredEventId = String(selectedEventId || source.selected_event_id || '');
        const selectedEvent = events.find((event) => String(event.id ?? '') === preferredEventId) || events[0] || null;
        const topImpact = selectedEvent && Array.isArray(selectedEvent.top_impact)
            ? selectedEvent.top_impact
            : [];

        return {
            ...source,
            events,
            category_options: categoryOptions,
            selected_category: String(source.selected_category || 'all').trim().toLowerCase() || 'all',
            selected_event_id: selectedEvent ? String(selectedEvent.id ?? '') : '',
            selected_event: selectedEvent,
            featured_title: eventsFeaturedTitle(selectedEvent),
            top_impact_labels: topImpact.map((row) => row.facility_name ?? ''),
            top_impact_values: topImpact.map((row) => Math.round((Number(row.predicted_rate) || 0) * 1000) / 10)
        };
    };
    const getEventCategoryFilterValue = (host) => String(host?.querySelector('[data-events-category-filter]')?.value || 'all').trim().toLowerCase();
    const eventMatchesCategory = (event, categorySlug) => {
        if (!event || categorySlug === 'all') {
            return true;
        }

        const availableSlugs = Array.isArray(event.category_slugs) && event.category_slugs.length
            ? event.category_slugs
            : [event.category_slug];

        return availableSlugs
            .map((slug) => String(slug || '').trim().toLowerCase())
            .includes(String(categorySlug || '').trim().toLowerCase());
    };
    const resolveEventActiveCategory = (event, categorySlug) => {
        const primarySlug = String(event?.category_slug || 'event').trim() || 'event';
        const primaryLabel = String(event?.category_label || 'Event').trim() || 'Event';

        if (!event || categorySlug === 'all') {
            return {
                active_category_slug: primarySlug,
                active_category_label: primaryLabel
            };
        }

        const availableSlugs = Array.isArray(event.category_slugs) && event.category_slugs.length
            ? event.category_slugs
            : [primarySlug];
        const availableLabels = Array.isArray(event.category_labels) && event.category_labels.length
            ? event.category_labels
            : [primaryLabel];
        const normalizedTarget = String(categorySlug || '').trim().toLowerCase();

        for (let index = 0; index < availableSlugs.length; index += 1) {
            const slug = String(availableSlugs[index] || '').trim();
            if (slug.toLowerCase() === normalizedTarget) {
                return {
                    active_category_slug: slug || primarySlug,
                    active_category_label: String(availableLabels[index] || primaryLabel).trim() || primaryLabel
                };
            }
        }

        return {
            active_category_slug: primarySlug,
            active_category_label: primaryLabel
        };
    };
    const renderEventsCategoryOptions = (host, options) => {
        const select = host?.querySelector('[data-events-category-filter]');
        if (!select) {
            return;
        }

        const fallbackValue = String(host?.getAttribute('data-live-events-category') || 'all');
        const previousValue = String(select.value || fallbackValue || 'all');
        const normalizedOptions = Array.isArray(options) ? options : [];

        select.innerHTML = `
            <option value="all">All event types</option>
            ${normalizedOptions.map((option) => `
                <option value="${escapeHtml(option.slug ?? '')}">${escapeHtml(option.label ?? option.slug ?? '')}</option>
            `).join('')}
        `;

        select.value = normalizedOptions.some((option) => String(option.slug ?? '') === previousValue)
            ? previousValue
            : 'all';
    };
    const buildEventsDisplayState = (host, payload) => {
        const categoryFilter = getEventCategoryFilterValue(host);
        const visibleEvents = Array.isArray(payload.events)
            ? payload.events
                .filter((event) => eventMatchesCategory(event, categoryFilter))
                .map((event) => ({
                    ...event,
                    ...resolveEventActiveCategory(event, categoryFilter)
                }))
            : [];
        const selectedEventId = String(payload.selected_event_id || '');
        const selectedEvent = visibleEvents.find((event) => String(event.id ?? '') === selectedEventId) || visibleEvents[0] || null;
        const topImpact = selectedEvent && Array.isArray(selectedEvent.top_impact)
            ? selectedEvent.top_impact
            : [];

        return {
            ...payload,
            selected_category: categoryFilter,
            visible_events: visibleEvents,
            selected_event_id: selectedEvent ? String(selectedEvent.id ?? '') : '',
            selected_event: selectedEvent,
            featured_title: eventsFeaturedTitle(selectedEvent),
            top_impact_labels: topImpact.map((row) => row.facility_name ?? ''),
            top_impact_values: topImpact.map((row) => Math.round((Number(row.predicted_rate) || 0) * 1000) / 10)
        };
    };
    const syncEventsSelectionState = (host, payload) => {
        const selectedEventId = String(payload && payload.selected_event_id ? payload.selected_event_id : '');

        if (host) {
            host.setAttribute('data-live-events-selected', selectedEventId);
            host.setAttribute('data-live-events-category', String(payload && payload.selected_category ? payload.selected_category : 'all'));
        }

        window.eventsState = payload;

        if (window.history && typeof window.history.replaceState === 'function') {
            window.history.replaceState(
                window.history.state,
                document.title,
                buildEventsPageUrl(selectedEventId, String(payload && payload.selected_category ? payload.selected_category : 'all')).toString()
            );
        }
    };
    const renderEventCards = (events, selectedEventId) => {
        if (!Array.isArray(events) || events.length === 0) {
            return '<div class="empty-state card" style="grid-column: 1 / -1;">No events match the selected event type right now.</div>';
        }

        return events.map((event) => {
            const eventId = String(event.id ?? '');
            const isActive = eventId === String(selectedEventId || '');
            const activeClass = isActive ? ' active' : '';
            const title = event.title ?? '';
            const categoryLabel = event.active_category_label || event.category_label || 'Event';
            const attendanceLabel = event.attendance_label ?? 'Estimated attendance';
            const attendanceEstimate = formatNumber(event.attendance_estimate);
            const startsAtTag = escapeHtml(String(event.starts_at_display ?? '').split(' ').slice(0, 4).join(' '));
            const forecastAction = isEventForecastPage() && isActive
                ? '<span class="btn btn-disabled" aria-disabled="true">Current forecast</span>'
                : `<a class="btn btn-primary" href="event_forecasts.php?event=${encodeURIComponent(eventId)}">Open forecast</a>`;

            return `
                <article class="panel event-selector${activeClass}" data-event-id="${escapeHtml(eventId)}">
                    <div class="tag-row" style="margin-top:0;">
                        <span class="tag">${startsAtTag}</span>
                        <span class="tag tag-category">${escapeHtml(categoryLabel)}</span>
                        <span class="tag">${escapeHtml(attendanceLabel)}: ${escapeHtml(attendanceEstimate)}</span>
                    </div>
                    <h3>${escapeHtml(title)}</h3>
                    <p class="muted">${escapeHtml(event.starts_at_display ?? '')} to ${escapeHtml(event.ends_at_display ?? '')} | ${escapeHtml(event.venue_name ?? '')}, ${escapeHtml(event.venue_area ?? '')}</p>
                    <p class="muted">${escapeHtml(event.network_headline ?? '')}</p>
                    <div class="event-actions">
                        ${forecastAction}
                        <a class="btn btn-secondary" href="${escapeHtml(event.source_url ?? '#')}" target="_blank" rel="noopener noreferrer">Official source</a>
                    </div>
                </article>
            `;
        }).join('');
    };
    const renderSelectedEventPanel = (selectedEvent) => {
        if (!selectedEvent) {
            return `
                <h3>No selected event</h3>
                <p class="muted">Event details will appear here when a forecastable Sydney event is available.</p>
            `;
        }

        return `
            <h3>${escapeHtml(selectedEvent.title ?? '')}</h3>
            <p class="muted">${escapeHtml(selectedEvent.starts_at_display ?? '')} to ${escapeHtml(selectedEvent.ends_at_display ?? '')}</p>
            <div class="stat-list" style="margin-top:18px;">
                <div class="stat-item"><span>Event type</span><strong>${escapeHtml(selectedEvent.active_category_label || selectedEvent.category_label || 'Event')}</strong></div>
                <div class="stat-item"><span>Venue</span><strong>${escapeHtml(selectedEvent.venue_name ?? '')}, ${escapeHtml(selectedEvent.venue_area ?? '')}</strong></div>
                <div class="stat-item"><span>Address</span><strong>${escapeHtml(selectedEvent.venue_address ?? '')}</strong></div>
                <div class="stat-item"><span>${escapeHtml(selectedEvent.attendance_label ?? 'Estimated attendance')}</span><strong>${escapeHtml(formatNumber(selectedEvent.attendance_estimate))}</strong></div>
                <div class="stat-item"><span>Attendance basis</span><strong>${escapeHtml(selectedEvent.attendance_note ?? '')}</strong></div>
                <div class="stat-item"><span>Monitored spillover vehicles</span><strong>${escapeHtml(formatNumber(selectedEvent.peak_vehicle_demand))}</strong></div>
                <div class="stat-item"><span>Source</span><strong><a href="${escapeHtml(selectedEvent.source_url ?? '#')}" target="_blank" rel="noopener noreferrer">${escapeHtml(selectedEvent.source_label ?? 'Official source')}</a></strong></div>
            </div>
        `;
    };
    const renderFeaturedForecastPanel = (selectedEvent, featuredTitle) => {
        const featuredForecast = selectedEvent && selectedEvent.featured_forecast ? selectedEvent.featured_forecast : null;

        if (!featuredForecast) {
            return `
                <p class="forecast-kicker">${escapeHtml(featuredTitle || 'Featured Facility')}</p>
                <h3>No highlighted facility</h3>
                <p class="muted">Forecast details will appear here as soon as event and facility data are available.</p>
            `;
        }

        const featuredPercent = Math.max(0, Math.min(100, (Number(featuredForecast.predicted_rate) || 0) * 100));
        const featuredReason = selectedEvent && selectedEvent.featured_reason
            ? selectedEvent.featured_reason
            : 'This site is forecast as one of the highest-pressure locations for the selected event.';

        return `
            <p class="forecast-kicker">${escapeHtml(featuredTitle || 'Featured Facility')}</p>
            <h3>${escapeHtml(featuredForecast.facility_name ?? '')}</h3>
            <p class="muted">${escapeHtml(featuredReason)}</p>
            <div class="metric">${escapeHtml(formatNumber(featuredForecast.predicted_available))} spaces left</div>
            <div class="progress"><span style="width: ${featuredPercent}%"></span></div>
            <div class="stat-list" style="margin-top:18px;">
                <div class="stat-item"><span>Baseline occupied</span><strong>${escapeHtml(formatNumber(featuredForecast.baseline_occupied))}</strong></div>
                <div class="stat-item"><span>Event lift</span><strong>+${escapeHtml(formatNumber(featuredForecast.event_lift))}</strong></div>
                <div class="stat-item"><span>Predicted occupied</span><strong>${escapeHtml(formatNumber(featuredForecast.predicted_occupied))}</strong></div>
                <div class="stat-item"><span>Predicted status</span><strong><span class="status-pill ${escapeHtml(availabilityBadgeClass(featuredForecast.predicted_status))}">${escapeHtml(featuredForecast.predicted_status ?? 'Available')}</span></strong></div>
            </div>
        `;
    };
    const getFilteredEventForecasts = (host, selectedEvent) => {
        const impactRanked = Array.isArray(selectedEvent && selectedEvent.nearby_ranked)
            ? selectedEvent.nearby_ranked.slice()
            : [];
        const searchTerm = (host.querySelector('[data-events-forecast-search]')?.value || '').trim().toLowerCase();
        const statusFilter = host.querySelector('[data-events-forecast-status]')?.value || 'all';
        const sortFilter = host.querySelector('[data-events-forecast-sort]')?.value || 'impact_desc';

        const filtered = impactRanked.filter((row) => {
            const searchValue = `${String(row.facility_id ?? '').toLowerCase()} ${String(row.facility_name ?? '').toLowerCase()} ${String(row.predicted_status ?? '').toLowerCase()}`;
            const status = String(row.predicted_status ?? '').trim().toLowerCase();
            const matchesSearch = searchTerm === '' || searchValue.includes(searchTerm);
            const matchesStatus = statusFilter === 'all'
                || (statusFilter === 'pressured' && (status === 'full' || status === 'limited'))
                || status === statusFilter;

            return matchesSearch && matchesStatus;
        });

        filtered.sort((left, right) => {
            if (sortFilter === 'occupancy_desc') {
                const leftRate = Number(left.predicted_rate) || 0;
                const rightRate = Number(right.predicted_rate) || 0;
                if (leftRate === rightRate) {
                    return String(left.facility_name ?? '').localeCompare(String(right.facility_name ?? ''));
                }
                return rightRate - leftRate;
            }

            if (sortFilter === 'available_asc') {
                const leftAvailable = Number(left.predicted_available) || 0;
                const rightAvailable = Number(right.predicted_available) || 0;
                if (leftAvailable === rightAvailable) {
                    return String(left.facility_name ?? '').localeCompare(String(right.facility_name ?? ''));
                }
                return leftAvailable - rightAvailable;
            }

            if (sortFilter === 'name_asc') {
                return String(left.facility_name ?? '').localeCompare(String(right.facility_name ?? ''));
            }

            const leftLift = Number(left.event_lift) || 0;
            const rightLift = Number(right.event_lift) || 0;
            if (leftLift === rightLift) {
                const leftRate = Number(left.predicted_rate) || 0;
                const rightRate = Number(right.predicted_rate) || 0;
                if (leftRate === rightRate) {
                    return String(left.facility_name ?? '').localeCompare(String(right.facility_name ?? ''));
                }
                return rightRate - leftRate;
            }
            return rightLift - leftLift;
        });

        return filtered;
    };
    const renderEventsTableRows = (rows) => {
        if (!Array.isArray(rows) || rows.length === 0) {
            return '<tr><td colspan="8" class="empty-state">No nearby facilities match the current filters.</td></tr>';
        }

        return rows.map((row) => {
            const predictedPercent = Math.max(0, Math.min(100, (Number(row.predicted_rate) || 0) * 100));
            const facilityId = encodeURIComponent(String(row.facility_id ?? ''));
            const searchValue = `${String(row.facility_id ?? '').toLowerCase()} ${String(row.facility_name ?? '').toLowerCase()} ${String(row.predicted_status ?? '').toLowerCase()}`;

            return `
                <tr data-facility-row data-search="${escapeHtml(searchValue)}">
                    <td>${escapeHtml(row.facility_name ?? '')}</td>
                    <td>${escapeHtml(formatNumber(row.capacity))}</td>
                    <td>${escapeHtml(formatNumber(row.baseline_occupied))}</td>
                    <td><strong>+${escapeHtml(formatNumber(row.event_lift))}</strong></td>
                    <td>${escapeHtml(formatNumber(row.predicted_available))}</td>
                    <td><strong>${escapeHtml(formatPercentage(predictedPercent))}</strong><div class="progress" style="margin-top:8px;"><span style="width: ${predictedPercent}%"></span></div></td>
                    <td><span class="status-pill ${escapeHtml(availabilityBadgeClass(row.predicted_status))}">${escapeHtml(row.predicted_status ?? 'Available')}</span></td>
                    <td><a href="facilities.php?facility_id=${facilityId}">View facility</a></td>
                </tr>
            `;
        }).join('');
    };
    const renderEventsForecastTable = (host, selectedEvent) => {
        if (!host) {
            return;
        }

        const tableBody = host.querySelector('[data-events-table-body]');
        const tableCount = host.querySelector('[data-events-table-count]');
        const tableDescription = host.querySelector('[data-events-table-description]');
        const nearbyRows = Array.isArray(selectedEvent && selectedEvent.nearby_ranked)
            ? selectedEvent.nearby_ranked
            : [];
        const filteredRows = getFilteredEventForecasts(host, selectedEvent);

        if (tableCount) {
            tableCount.textContent = `Showing ${formatNumber(filteredRows.length)} nearby facilities`;
        }
        if (tableDescription) {
            const radiusLabel = formatDistanceKm(selectedEvent && selectedEvent.nearby_radius_km);
            tableDescription.textContent = `Every row below shows a tracked facility within roughly ${radiusLabel} km of the venue.`;
        }
        if (tableBody) {
            if (nearbyRows.length === 0) {
                tableBody.innerHTML = renderEventsDistanceEmptyState(selectedEvent && selectedEvent.nearby_radius_km);
                return;
            }

            tableBody.innerHTML = filteredRows.length === 0
                ? '<tr><td colspan="8" class="empty-state">No nearby facilities match the current filters.</td></tr>'
                : renderEventsTableRows(filteredRows);
        }
    };
    const bindEventsForecastControls = (host) => {
        if (!host || host.dataset.eventsForecastControlsBound === 'true') {
            return;
        }

        const controls = [
            host.querySelector('[data-events-forecast-search]'),
            host.querySelector('[data-events-forecast-status]'),
            host.querySelector('[data-events-forecast-sort]')
        ].filter(Boolean);

        if (controls.length === 0) {
            return;
        }

        controls.forEach((control) => {
            const eventName = control.matches('input') ? 'input' : 'change';
            control.addEventListener(eventName, () => {
                const selectedEvent = window.eventsState && window.eventsState.selected_event
                    ? window.eventsState.selected_event
                    : null;
                renderEventsForecastTable(host, selectedEvent);
            });
        });

        host.dataset.eventsForecastControlsBound = 'true';
    };
    const bindEventsCategoryControl = (host) => {
        if (!host || host.dataset.eventsCategoryControlBound === 'true') {
            return;
        }

        const select = host.querySelector('[data-events-category-filter]');
        const form = host.querySelector('[data-events-category-form]');
        if (!select) {
            return;
        }

        if (form) {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                host.setAttribute('data-live-events-category', String(select.value || 'all'));
                renderEventsData(host, window.eventsState || {});
            });
        }

        select.addEventListener('change', () => {
            host.setAttribute('data-live-events-category', String(select.value || 'all'));
            renderEventsData(host, window.eventsState || {});
        });

        host.dataset.eventsCategoryControlBound = 'true';
    };
    const renderEventsData = (host, payload) => {
        if (!host || !payload || typeof payload !== 'object') {
            return;
        }

        const normalizedPayload = normalizeEventsPayload(
            payload,
            host.getAttribute('data-live-events-selected') || ''
        );
        renderEventsCategoryOptions(host, normalizedPayload.category_options);

        const displayState = buildEventsDisplayState(host, normalizedPayload);
        const events = displayState.visible_events;
        const selectedEvent = displayState.selected_event && typeof displayState.selected_event === 'object'
            ? displayState.selected_event
            : null;
        const hasEvents = normalizedPayload.events.length > 0;
        const fields = {
            window: host.querySelector('[data-events-window]'),
            note: host.querySelector('[data-events-note]'),
            noteCopy: host.querySelector('[data-events-note-copy]'),
            empty: host.querySelector('[data-events-empty]'),
            content: host.querySelector('[data-events-content]'),
            selectedTitle: host.querySelector('[data-events-selected-title]'),
            selectedCategory: host.querySelector('[data-events-selected-category]'),
            trackedCount: host.querySelector('[data-events-tracked-count]'),
            spillover: host.querySelector('[data-events-spillover]'),
            pressureCount: host.querySelector('[data-events-pressure-count]'),
            pressureCopy: host.querySelector('[data-events-pressure-copy]'),
            featuredAvailable: host.querySelector('[data-events-featured-available]'),
            cardCount: host.querySelector('[data-events-card-count]'),
            cards: host.querySelector('[data-events-cards]'),
            selectedPanel: host.querySelector('[data-events-selected-panel]'),
            featuredPanel: host.querySelector('[data-events-featured-panel]'),
            tableBody: host.querySelector('[data-events-table-body]')
        };
        const fullCount = Number(selectedEvent && selectedEvent.status_counts ? selectedEvent.status_counts.full : 0) || 0;
        const limitedCount = Number(selectedEvent && selectedEvent.status_counts ? selectedEvent.status_counts.limited : 0) || 0;

        if (fields.window) {
            fields.window.textContent = `Forecast window: ${normalizedPayload.window_label || 'N/A'}`;
        }
        if (fields.note) {
            fields.note.hidden = !normalizedPayload.note;
        }
        if (fields.noteCopy) {
            fields.noteCopy.textContent = normalizedPayload.note || '';
        }
        if (fields.empty) {
            fields.empty.hidden = hasEvents;
        }
        if (fields.content) {
            fields.content.hidden = !hasEvents;
        }
        if (fields.selectedTitle) {
            fields.selectedTitle.textContent = selectedEvent ? (selectedEvent.title || 'None') : 'None';
        }
        if (fields.selectedCategory) {
            fields.selectedCategory.textContent = selectedEvent ? (selectedEvent.active_category_label || selectedEvent.category_label || 'Event') : 'None';
        }
        if (fields.trackedCount) {
            fields.trackedCount.textContent = formatNumber(events.length);
        }
        if (fields.spillover) {
            fields.spillover.textContent = formatNumber(selectedEvent ? selectedEvent.peak_vehicle_demand : 0);
        }
        if (fields.pressureCount) {
            fields.pressureCount.textContent = formatNumber(fullCount + limitedCount);
        }
        if (fields.pressureCopy) {
            fields.pressureCopy.textContent = `${formatNumber(fullCount)} full and ${formatNumber(limitedCount)} limited sites are projected under the selected event.`;
        }
        if (fields.featuredAvailable) {
            const available = selectedEvent && selectedEvent.featured_forecast
                ? selectedEvent.featured_forecast.predicted_available
                : 0;
            fields.featuredAvailable.textContent = formatNumber(available);
        }
        if (fields.cardCount) {
            fields.cardCount.textContent = `Showing ${formatNumber(events.length)} event${events.length === 1 ? '' : 's'}`;
        }
        if (fields.cards) {
            fields.cards.innerHTML = renderEventCards(events, displayState.selected_event_id || '');
        }
        if (fields.selectedPanel) {
            fields.selectedPanel.innerHTML = renderSelectedEventPanel(selectedEvent);
        }
        if (fields.featuredPanel) {
            fields.featuredPanel.innerHTML = renderFeaturedForecastPanel(selectedEvent, displayState.featured_title || 'Featured Facility');
        }
        if (fields.tableBody) {
            renderEventsForecastTable(host, selectedEvent);
        }
        updateChartData(
            window.eventsCharts && window.eventsCharts.impact,
            Array.isArray(displayState.top_impact_labels) ? displayState.top_impact_labels : [],
            Array.isArray(displayState.top_impact_values) ? displayState.top_impact_values : []
        );
        bindEventsCategoryControl(host);
        syncEventsSelectionState(
            host,
            normalizedPayload.events.length > 0
                ? {
                    ...normalizedPayload,
                    selected_category: displayState.selected_category || getEventCategoryFilterValue(host),
                    selected_event_id: displayState.selected_event_id,
                    selected_event: displayState.selected_event,
                    featured_title: displayState.featured_title,
                    top_impact_labels: Array.isArray(displayState.top_impact_labels) ? displayState.top_impact_labels : [],
                    top_impact_values: Array.isArray(displayState.top_impact_values) ? displayState.top_impact_values : []
                }
                : {
                    ...normalizedPayload,
                    selected_category: getEventCategoryFilterValue(host)
                }
        );
    };

    const initCollectorSync = ({
        host,
        viewUrl,
        initialState,
        renderPayload,
        pageLabel,
        checkingMessage,
        updatedMessage,
        checkedMessage,
        busyMessage,
        refreshFailureMessage,
        logPrefix
    }) => {
        const liveCollectorStatusNodes = host.querySelectorAll('[data-live-collector-status]');
        if (!host || !viewUrl || !liveCollectorStatusNodes.length) {
            return;
        }

        const collectorUrl = host.getAttribute('data-live-collector-url') || '';
        const intervalMs = Number.parseInt(host.getAttribute('data-live-collector-interval') || '10000', 10) || 10000;
        const intervalSeconds = Math.max(1, Math.round(intervalMs / 1000));
        const idleCollectorMessage = `Auto sync every ${intervalSeconds} second${intervalSeconds === 1 ? '' : 's'} while this ${pageLabel} is open`;
        let collectorInFlight = false;

        const setCollectorStatus = (message) => {
            liveCollectorStatusNodes.forEach((node) => {
                node.textContent = message;
            });
        };

        const fetchViewData = async () => {
            try {
                const targetViewUrl = typeof viewUrl === 'function'
                    ? viewUrl()
                    : viewUrl;
                if (!targetViewUrl) {
                    return false;
                }

                const response = await fetch(targetViewUrl, {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    cache: 'no-store',
                    credentials: 'same-origin'
                });

                const payload = await response.json().catch(() => null);
                if (!response.ok || !payload) {
                    return false;
                }

                renderPayload(host, payload);
                return true;
            } catch (error) {
                console.error(`${logPrefix} summary refresh failed:`, error);
                return false;
            }
        };

        const runCollector = async () => {
            if (!collectorUrl || collectorInFlight || document.hidden) {
                return;
            }

            collectorInFlight = true;
            setCollectorStatus(checkingMessage || 'Checking live data...');

            try {
                const response = await fetch(collectorUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    cache: 'no-store',
                    credentials: 'same-origin'
                });

                const payload = await response.json().catch(() => null);
                if (!response.ok || !payload) {
                    setCollectorStatus('Live sync failed. Retrying automatically.');
                    return;
                }

                const viewUpdated = await fetchViewData();

                if (payload.status === 'updated') {
                    setCollectorStatus(viewUpdated ? updatedMessage : refreshFailureMessage);
                    return;
                }

                if (payload.status === 'checked') {
                    setCollectorStatus(checkedMessage);
                    return;
                }

                if (payload.status === 'busy') {
                    setCollectorStatus(viewUpdated ? busyMessage : 'Live sync is already running in another request.');
                    return;
                }

                if (payload.status === 'skipped') {
                    setCollectorStatus(viewUpdated ? idleCollectorMessage : refreshFailureMessage);
                    return;
                }

                setCollectorStatus('Live sync failed. Retrying automatically.');
            } catch (error) {
                console.error(`${logPrefix} live sync failed:`, error);
                setCollectorStatus('Live sync failed. Retrying automatically.');
            } finally {
                collectorInFlight = false;
            }
        };

        if (initialState) {
            renderPayload(host, initialState);
        }

        runCollector();
        window.setInterval(runCollector, intervalMs);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                runCollector();
            }
        });
    };

    const liveCollectorHost = document.querySelector('[data-live-collector-url]');
    if (!liveCollectorHost) {
        return;
    }

    if (liveCollectorHost.hasAttribute('data-live-home-url')) {
        initCollectorSync({
            host: liveCollectorHost,
            viewUrl: liveCollectorHost.getAttribute('data-live-home-url') || '',
            initialState: window.homeState,
            renderPayload: renderHomeData,
            pageLabel: 'Home page',
            checkingMessage: 'Checking live network summary...',
            updatedMessage: 'Live sync completed. Home highlights updated in place.',
            checkedMessage: 'Live sync is current. Home highlights stay updated without a page refresh.',
            busyMessage: 'Live sync is already running elsewhere. Showing the newest home summary.',
            refreshFailureMessage: 'Live data is available, but the Home page could not refresh.',
            logPrefix: 'Home'
        });
    }

    if (liveCollectorHost.hasAttribute('data-live-summary-url')) {
        initCollectorSync({
            host: liveCollectorHost,
            viewUrl: liveCollectorHost.getAttribute('data-live-summary-url') || '',
            initialState: window.dashboardState,
            renderPayload: renderDashboardData,
            pageLabel: 'dashboard',
            checkingMessage: 'Checking live occupancy summary...',
            updatedMessage: 'Live sync completed. Dashboard updated in place.',
            checkedMessage: 'Live sync is current. Dashboard stays updated without a page refresh.',
            busyMessage: 'Live sync is already running elsewhere. Showing the newest stored data.',
            refreshFailureMessage: 'Live data is available, but the dashboard view could not refresh.',
            logPrefix: 'Dashboard'
        });
    }

    if (liveCollectorHost.hasAttribute('data-live-facilities-url')) {
        bindFacilitiesControls(liveCollectorHost);
        initCollectorSync({
            host: liveCollectorHost,
            viewUrl: () => buildFacilitiesSummaryUrl(liveCollectorHost),
            initialState: window.facilitiesState,
            renderPayload: renderFacilitiesData,
            pageLabel: 'Facilities page',
            checkingMessage: 'Checking live facility statuses...',
            updatedMessage: 'Live sync completed. Facilities updated in place.',
            checkedMessage: 'Live sync is current. Facilities stay updated without a page refresh.',
            busyMessage: 'Live sync is already running elsewhere. Showing the newest facility status.',
            refreshFailureMessage: 'Live data is available, but the Facilities page could not refresh.',
            logPrefix: 'Facilities'
        });
    }

    if (liveCollectorHost.hasAttribute('data-live-insights-url')) {
        initCollectorSync({
            host: liveCollectorHost,
            viewUrl: liveCollectorHost.getAttribute('data-live-insights-url') || '',
            initialState: window.insightsState,
            renderPayload: renderInsightsData,
            pageLabel: 'Insights page',
            checkingMessage: 'Checking live analytics snapshot...',
            updatedMessage: 'Live sync completed. Insights updated in place.',
            checkedMessage: 'Live sync is current. Insights stay updated without a page refresh.',
            busyMessage: 'Live sync is already running elsewhere. Showing the newest insight summary.',
            refreshFailureMessage: 'Live data is available, but the Insights page could not refresh.',
            logPrefix: 'Insights'
        });
    }

    if (liveCollectorHost.hasAttribute('data-live-about-url')) {
        initCollectorSync({
            host: liveCollectorHost,
            viewUrl: liveCollectorHost.getAttribute('data-live-about-url') || '',
            initialState: window.aboutState,
            renderPayload: renderAboutData,
            pageLabel: 'About page',
            checkingMessage: 'Checking live platform coverage...',
            updatedMessage: 'Live sync completed. About coverage stats updated in place.',
            checkedMessage: 'Live sync is current. About coverage stays updated without a page refresh.',
            busyMessage: 'Live sync is already running elsewhere. Showing the newest platform coverage.',
            refreshFailureMessage: 'Live data is available, but the About page could not refresh.',
            logPrefix: 'About'
        });
    }

    if (liveCollectorHost.hasAttribute('data-live-events-url')) {
        bindEventsForecastControls(liveCollectorHost);
        initCollectorSync({
            host: liveCollectorHost,
            viewUrl: () => buildEventsSummaryUrl(
                liveCollectorHost,
                String((window.eventsState && window.eventsState.selected_event_id) || liveCollectorHost.getAttribute('data-live-events-selected') || '')
            ),
            initialState: window.eventsState,
            renderPayload: renderEventsData,
            pageLabel: liveCollectorHost.getAttribute('data-live-events-page-label') || 'Events page',
            checkingMessage: String(liveCollectorHost.getAttribute('data-live-events-page-type') || '').trim().toLowerCase() === 'forecast'
                ? 'Checking live event forecasts...'
                : 'Checking live Sydney event feed...',
            updatedMessage: 'Live sync completed. Events forecasts updated in place.',
            checkedMessage: 'Live sync is current. Events forecasts stay updated without a page refresh.',
            busyMessage: 'Live sync is already running elsewhere. Showing the newest event forecasts.',
            refreshFailureMessage: 'Live data is available, but the Events forecast could not refresh.',
            logPrefix: 'Events'
        });
    }
});
