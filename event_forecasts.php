<?php
$pageTitle = 'Event Forecasts';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/event_forecast_engine.php';
require_once __DIR__ . '/includes/live_collector.php';

$collectorIntervalMs = 10000;
try {
    $collectorIntervalMs = max(10000, ((int) (live_collector_config()['interval_seconds'] ?? 10)) * 1000);
} catch (Throwable) {
    $collectorIntervalMs = 10000;
}
$collectorIntervalSeconds = max(1, (int) round($collectorIntervalMs / 1000));

$requestedEventId = isset($_GET['event']) ? trim((string) $_GET['event']) : null;
$requestedCategory = 'all';
$eventsPayload = events_view_payload($requestedEventId, $requestedCategory);
$events = $eventsPayload['events'];
$selectedEvent = $eventsPayload['selected_event'];
$selectedEventId = (string) ($eventsPayload['selected_event_id'] ?? $requestedEventId ?? '');
$selectedCategory = (string) ($eventsPayload['selected_category'] ?? 'all');
$featuredForecast = $selectedEvent['featured_forecast'] ?? null;
$featuredPercent = $featuredForecast ? ((float) $featuredForecast['predicted_rate']) * 100 : 0;
$topImpactLabels = $eventsPayload['top_impact_labels'];
$topImpactValues = $eventsPayload['top_impact_values'];
$topImpactMetricLabel = (string) ($eventsPayload['top_impact_metric_label'] ?? 'Projected occupancy %');
$hasEvents = $events !== [];
$eventsSyncUrl = 'api/events_summary.php';
$eventsOverviewUrl = $selectedEventId !== ''
    ? 'events.php?event=' . urlencode($selectedEventId)
    : 'events.php';
$nearbyRadiusLabel = $selectedEvent
    ? rtrim(rtrim(number_format((float) ($selectedEvent['nearby_radius_km'] ?? 0), 1), '0'), '.')
    : '0';
?>
<div class="container" data-live-collector-url="api/collect_live.php" data-live-events-url="<?= h($eventsSyncUrl) ?>" data-live-events-selected="<?= h($selectedEventId) ?>" data-live-events-category="<?= h($selectedCategory) ?>" data-live-events-page-type="forecast" data-live-events-page-label="Event Forecast page" data-live-collector-interval="<?= h((string) $collectorIntervalMs) ?>">
    <div class="section-title">
        <div>
            <h2>Event forecast</h2>
            <p>Show nearby parking within 10 km and the closest facility now. Event-based prediction appears only on the event day.</p>
        </div>
        <div class="tag-row section-tags">
            <span class="tag" data-events-window>Forecast window: <?= h($eventsPayload['window_label']) ?></span>
            <span class="tag" data-live-collector-status>Auto sync every <?= h((string) $collectorIntervalSeconds) ?> seconds while this Event Forecast page is open</span>
        </div>
    </div>

    <section class="notice" style="margin-bottom:24px;">
        <h3>Selection</h3>
        <p class="muted" style="margin-bottom:14px;">You are viewing the detailed forecast for the selected Sydney event. Return to the Events page any time to browse all current and upcoming event forecasts.</p>
        <div class="selection-actions">
            <a class="btn btn-secondary" href="<?= h($eventsOverviewUrl) ?>">&larr; Back to current and upcoming events</a>
        </div>
        <div class="tag-row" style="margin-top:0;">
            <span class="tag">Selected event: <span data-events-selected-title><?= h($selectedEvent['title'] ?? 'None') ?></span></span>
            <span class="tag tag-category">Selected type: <span data-events-selected-category><?= h($selectedEvent['active_category_label'] ?? $selectedEvent['category_label'] ?? 'Event') ?></span></span>
            <span class="tag">Timing: <span data-events-selected-timing><?= h($selectedEvent['timing_label'] ?? 'Upcoming event') ?></span></span>
            <span class="tag">Tracked events: <span data-events-tracked-count><?= h(format_number(count($events))) ?></span></span>
        </div>
    </section>

    <section class="notice" style="margin-bottom:24px;" data-events-note<?= empty($eventsPayload['note']) ? ' hidden' : '' ?>>
        <h3>Forecast coverage note</h3>
        <p class="muted" data-events-note-copy><?= h($eventsPayload['note'] ?? '') ?></p>
    </section>

    <section class="empty-state card" data-events-empty<?= $hasEvents ? ' hidden' : '' ?>>No current or upcoming researched Sydney events are available in this seven-day forecast window.</section>

    <div data-events-content<?= $hasEvents ? '' : ' hidden' ?>>
        <section class="info-grid">
            <article class="notice">
                <h3>Selected event spillover</h3>
                <div class="metric" data-events-spillover><?= h(format_number($selectedEvent['peak_vehicle_demand'] ?? 0)) ?></div>
                <p class="muted">Estimated extra vehicles most likely to spill into the monitored park-and-ride network during the selected event peak.</p>
            </article>
            <article class="notice">
                <h3>Pressure snapshot</h3>
                <div class="metric" data-events-pressure-count><?= h(format_number(($selectedEvent['status_counts']['full'] ?? 0) + ($selectedEvent['status_counts']['limited'] ?? 0))) ?></div>
                <p class="muted" data-events-pressure-copy><?= ($selectedEvent['is_prediction_day'] ?? false)
                        ? h(format_number($selectedEvent['status_counts']['full'] ?? 0)) . ' full and ' . h(format_number($selectedEvent['status_counts']['limited'] ?? 0)) . ' limited sites are projected under the selected event.'
                        : h(format_number($selectedEvent['status_counts']['full'] ?? 0)) . ' full and ' . h(format_number($selectedEvent['status_counts']['limited'] ?? 0)) . ' limited nearby sites are currently observed.' ?></p>
            </article>
            <article class="notice">
                <h3>Featured facility spaces left</h3>
                <div class="metric" data-events-featured-available><?= h(format_number($featuredForecast['current_available'] ?? 0)) ?></div>
                <p class="muted" data-events-featured-copy><?= h(($selectedEvent['is_prediction_day'] ?? false)
                        ? 'Closest highlighted facility current availability. Event-day prediction is active.'
                        : 'Closest highlighted facility current availability. Prediction becomes available on the event day.') ?></p>
            </article>
        </section>

        <section class="grid-two" style="margin-bottom:24px;">
            <article class="panel" data-events-selected-panel>
                <?php if ($selectedEvent): ?>
                    <h3><?= h($selectedEvent['title']) ?></h3>
                    <p class="muted" style="margin-top:8px;"><strong><?= h($selectedEvent['timing_label'] ?? 'Upcoming event') ?>:</strong> <?= h($selectedEvent['timing_note'] ?? 'Forecasts are based on live data and historical occupancy patterns.') ?></p>
                    <p class="muted" style="margin-top:6px;"><strong>Prediction:</strong> <?= h($selectedEvent['prediction_note'] ?? 'Prediction will be available on the current day of the event.') ?></p>
                    <div class="stat-list" style="margin-top:18px;">
                        <div class="stat-item"><span>Starts</span><strong><?= h($selectedEvent['starts_at_display']) ?></strong></div>
                        <div class="stat-item"><span>Ends</span><strong><?= h($selectedEvent['ends_at_display']) ?></strong></div>
                        <div class="stat-item"><span>Event type</span><strong><?= h($selectedEvent['active_category_label'] ?? $selectedEvent['category_label'] ?? 'Event') ?></strong></div>
                        <div class="stat-item"><span>Venue</span><strong><?= h($selectedEvent['venue_name']) ?>, <?= h($selectedEvent['venue_area']) ?></strong></div>
                        <div class="stat-item"><span>Address</span><strong><?= h($selectedEvent['venue_address']) ?></strong></div>
                        <div class="stat-item"><span><?= h($selectedEvent['attendance_label']) ?></span><strong><?= h(format_number($selectedEvent['attendance_estimate'])) ?></strong></div>
                        <div class="stat-item"><span>Attendance basis</span><strong><?= h($selectedEvent['attendance_note']) ?></strong></div>
                        <div class="stat-item"><span>Monitored spillover vehicles</span><strong><?= h(format_number($selectedEvent['peak_vehicle_demand'])) ?></strong></div>
                        <div class="stat-item"><span>Source</span><strong><a href="<?= h($selectedEvent['source_url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($selectedEvent['source_label']) ?></a></strong></div>
                    </div>
                <?php else: ?>
                    <h3>No selected event</h3>
                    <p class="muted">Event details will appear here when a forecastable Sydney event is available.</p>
                <?php endif; ?>
            </article>

            <article class="chart-card forecast-feature" data-events-featured-panel>
                <p class="forecast-kicker"><?= h(($selectedEvent['closest_forecast']['facility_name'] ?? '') !== '' ? 'Closest facility to the venue' : $eventsPayload['featured_title']) ?></p>
                <?php if ($featuredForecast): ?>
                    <h3><?= h($featuredForecast['facility_name']) ?></h3>
                    <p class="muted"><?= h(($selectedEvent['is_prediction_day'] ?? false)
                        ? 'Closest facility within 10 km. Showing current availability, with event-day prediction available below.'
                        : 'Closest facility within 10 km. Showing current availability only until the event day.') ?></p>
                    <div class="metric"><?= h(format_number($featuredForecast['current_available'] ?? 0)) ?> spaces now</div>
                    <div class="progress"><span style="width: <?= max(0, min(100, ((float) ($featuredForecast['current_rate'] ?? 0) * 100))) ?>%"></span></div>
                    <div class="stat-list" style="margin-top:18px;">
                        <div class="stat-item"><span>Distance from venue</span><strong><?= h(number_format((float) ($featuredForecast['distance_km'] ?? 0), 1)) ?> km</strong></div>
                    <div class="stat-item"><span>Current occupied</span><strong><?= h(format_number($featuredForecast['current_occupied'] ?? 0)) ?></strong></div>
                    <div class="stat-item"><span>Current status</span><strong><span class="status-pill <?= h(availability_badge_class((string) ($featuredForecast['current_status'] ?? 'Available'))) ?>"><?= h($featuredForecast['current_status'] ?? 'Available') ?></span></strong></div>
                    <?php if (!($selectedEvent['is_prediction_day'] ?? false)): ?>
                        <div class="stat-item"><span>Event-day prediction</span><strong>Available on event day</strong></div>
                    <?php endif; ?>
                    </div>
                <?php else: ?>
                    <h3>No highlighted facility</h3>
                    <p class="muted">Forecast details will appear here as soon as event and facility data are available.</p>
                <?php endif; ?>
            </article>
        </section>

        <section class="chart-card" style="margin-bottom:24px;" data-impact-chart-card<?= ($selectedEvent['is_prediction_day'] ?? false) ? '' : ' hidden' ?>>
            <h3>Most impacted facilities for the selected event</h3>
            <p class="muted" data-impact-chart-copy>The chart below ranks the eight sites receiving the biggest event-driven parking lift, then shows their projected occupancy percentage.</p>
            <canvas id="eventImpactChart" height="160"></canvas>
        </section>
        <section class="notice" style="margin-bottom:24px;" data-impact-chart-unavailable<?= ($selectedEvent['is_prediction_day'] ?? false) ? ' hidden' : '' ?>>
            <h3>Most impacted facilities</h3>
            <p class="muted">This prediction chart is available on the event day only.</p>
        </section>

        <section class="table-card" data-facility-search-scope>
            <div class="section-title">
                <div>
                    <h2>Nearby facility forecasts for the selected event</h2>
                    <p data-events-table-description>Every row below shows a tracked facility within roughly <?= h($nearbyRadiusLabel) ?> km of the venue. Event-based prediction appears only on the event day.</p>
                </div>
                <p class="muted" style="margin:0;" data-events-table-count>Showing <?= h(format_number(count($selectedEvent['nearby_ranked'] ?? []))) ?> nearby facilities</p>
            </div>

            <div class="filters">
                <input class="search-bar" type="text" placeholder="Search facility name or ID..." data-events-forecast-search>
                <select class="select-field" data-events-forecast-status>
                    <option value="all">All statuses</option>
                    <option value="pressured">High pressure only</option>
                    <option value="full">Full only</option>
                    <option value="limited">Limited only</option>
                    <option value="available">Available only</option>
                </select>
                <select class="select-field" data-events-forecast-sort>
                    <option value="distance_asc">Closest first</option>
                    <option value="impact_desc">Biggest event lift</option>
                    <option value="occupancy_desc">Highest occupancy</option>
                    <option value="available_asc">Fewest spaces left</option>
                    <option value="name_asc">Facility name</option>
                </select>
            </div>

            <div class="table-wrap">
                <table>
                    <thead><tr><th>Facility</th><th>Distance</th><th>Capacity</th><th>Current Available</th><th>+1h</th><th>+3h</th><th>+6h</th><th>+12h</th><th>Event Lift (event day only)</th><th data-occ-header><?= ($selectedEvent['is_prediction_day'] ?? false) ? 'Current &amp; Predicted Occupancy' : 'Current Occupancy' ?></th><th>Status</th><th>Details</th></tr></thead>
                    <tbody data-events-table-body>
                        <?php if (($selectedEvent['nearby_ranked'] ?? []) === []): ?>
                            <tr><td colspan="12" class="empty-state">No tracked parking facilities are within <?= h($nearbyRadiusLabel) ?> km of this event venue.</td></tr>
                        <?php else: ?>
                            <?php foreach (($selectedEvent['nearby_ranked'] ?? []) as $row): ?>
                                <?php
                                    $currentPercent = ((float) ($row['current_rate'] ?? 0)) * 100;
                                    if ($selectedEvent['is_prediction_day'] ?? false) {
                                        $_cap = max(1, (int) ($row['capacity'] ?? 1));
                                        $h1Occ = round(($_cap - (int) ($row['horizon_1h_available'] ?? 0)) / $_cap * 100, 1);
                                        $h3Occ = round(($_cap - (int) ($row['horizon_3h_available'] ?? 0)) / $_cap * 100, 1);
                                        $h6Occ = round(($_cap - (int) ($row['horizon_6h_available'] ?? 0)) / $_cap * 100, 1);
                                        $h12Occ = round(($_cap - (int) ($row['horizon_12h_available'] ?? 0)) / $_cap * 100, 1);
                                    }
                                ?>
                                <tr data-facility-row data-search="<?= h(strtolower($row['facility_id'] . ' ' . $row['facility_name'] . ' ' . $row['predicted_status'])) ?>">
                                    <td>
                                        <div class="facility-cell">
                                            <span class="facility-name"><?= h($row['facility_name']) ?></span>
                                            <?php if (!empty($row['is_closest'])): ?>
                                                <span class="tag closest-badge">Closest</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?= h(number_format((float) ($row['distance_km'] ?? 0), 1)) ?> km</td>
                                    <td><?= h(format_number($row['capacity'])) ?></td>
                                    <td><?= h(format_number($row['current_available'] ?? 0)) ?></td>
                                    <td><?= ($selectedEvent['is_prediction_day'] ?? false) ? h(format_number($row['horizon_1h_available'] ?? 0)) : 'Event day' ?></td>
                                    <td><?= ($selectedEvent['is_prediction_day'] ?? false) ? h(format_number($row['horizon_3h_available'] ?? 0)) : 'Event day' ?></td>
                                    <td><?= ($selectedEvent['is_prediction_day'] ?? false) ? h(format_number($row['horizon_6h_available'] ?? 0)) : 'Event day' ?></td>
                                    <td><?= ($selectedEvent['is_prediction_day'] ?? false) ? h(format_number($row['horizon_12h_available'] ?? 0)) : 'Event day' ?></td>
                                    <td><?= ($selectedEvent['is_prediction_day'] ?? false) ? '<strong>+' . h(format_number($row['event_lift'])) . '</strong>' : 'Event day' ?></td>
                                    <td>
                                        <?php if ($selectedEvent['is_prediction_day'] ?? false): ?>
                                            <small style="display:block;font-size:10px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">Current</small>
                                            <strong><?= h(format_percentage($currentPercent)) ?></strong>
                                            <div class="progress" style="margin-top:4px;margin-bottom:8px;"><span style="width:<?= max(0, min(100, $currentPercent)) ?>%"></span></div>
                                            <small style="display:block;font-size:10px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Predicted</small>
                                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:2px 8px;font-size:12px;">
                                                <span>+1H: <strong><?= h(number_format($h1Occ, 1)) ?>%</strong></span>
                                                <span>+3H: <strong><?= h(number_format($h3Occ, 1)) ?>%</strong></span>
                                                <span>+6H: <strong><?= h(number_format($h6Occ, 1)) ?>%</strong></span>
                                                <span>+12H: <strong><?= h(number_format($h12Occ, 1)) ?>%</strong></span>
                                            </div>
                                        <?php else: ?>
                                            <strong><?= h(format_percentage($currentPercent)) ?></strong>
                                            <div class="progress" style="margin-top:8px;"><span style="width:<?= max(0, min(100, $currentPercent)) ?>%"></span></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="status-pill <?= h(availability_badge_class((string) ((($selectedEvent['is_prediction_day'] ?? false) ? ($row['predicted_status'] ?? 'Available') : ($row['current_status'] ?? 'Available'))))) ?>"><?= h(($selectedEvent['is_prediction_day'] ?? false) ? ($row['predicted_status'] ?? 'Available') : ($row['current_status'] ?? 'Available')) ?></span></td>
                                    <td><a href="facilities.php?facility_id=<?= urlencode($row['facility_id']) ?>">View facility</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<script>
window.eventsState = <?= json_encode($eventsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.eventsCharts = {};
window.eventsCharts.impact = new Chart(document.getElementById('eventImpactChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($topImpactLabels) ?>,
        datasets: [{
            label: <?= json_encode($topImpactMetricLabel) ?>,
            data: <?= json_encode($topImpactValues) ?>,
            borderWidth: 0,
            borderRadius: 8,
            backgroundColor: 'rgba(14, 94, 181, 0.82)'
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        layout: { padding: { left: 14 } },
        scales: {
            y: { ticks: { font: { size: 9 }, padding: 8, autoSkip: false } },
            x: { beginAtZero: true, max: 100 }
        }
    }
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
