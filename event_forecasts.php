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
$eventsPayload = events_view_payload($requestedEventId);
$events = $eventsPayload['events'];
$selectedEvent = $eventsPayload['selected_event'];
$selectedEventId = (string) ($eventsPayload['selected_event_id'] ?? $requestedEventId ?? '');
$featuredForecast = $selectedEvent['featured_forecast'] ?? null;
$featuredPercent = $featuredForecast ? ((float) $featuredForecast['predicted_rate']) * 100 : 0;
$topImpactLabels = $eventsPayload['top_impact_labels'];
$topImpactValues = $eventsPayload['top_impact_values'];
$hasEvents = $events !== [];
$eventsSyncUrl = 'api/events_summary.php';
$nearbyRadiusLabel = $selectedEvent
    ? rtrim(rtrim(number_format((float) ($selectedEvent['nearby_radius_km'] ?? 0), 1), '0'), '.')
    : '0';
?>
<div class="container" data-live-collector-url="api/collect_live.php" data-live-events-url="<?= h($eventsSyncUrl) ?>" data-live-events-selected="<?= h($selectedEventId) ?>" data-live-events-page-type="forecast" data-live-events-page-label="Event Forecast page" data-live-collector-interval="<?= h((string) $collectorIntervalMs) ?>">
    <div class="section-title">
        <div>
            <h2>Event forecast</h2>
            <p>Detailed event spillover, featured-facility pressure, and full parking forecasts for the selected Sydney event.</p>
        </div>
        <div class="tag-row section-tags">
            <span class="tag" data-events-window>Forecast window: <?= h($eventsPayload['window_label']) ?></span>
            <span class="tag" data-live-collector-status>Auto sync every <?= h((string) $collectorIntervalSeconds) ?> seconds while this Event Forecast page is open</span>
        </div>
    </div>

    <section class="notice" style="margin-bottom:24px;">
        <h3>Selection</h3>
        <p class="muted" style="margin-bottom:14px;">Open another researched event forecast below, or return to <a href="events.php">Events</a> for the broader outlook.</p>
        <div class="tag-row" style="margin-top:0;">
            <span class="tag">Selected event: <span data-events-selected-title><?= h($selectedEvent['title'] ?? 'None') ?></span></span>
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
                <p class="muted" data-events-pressure-copy><?= h(format_number($selectedEvent['status_counts']['full'] ?? 0)) ?> full and <?= h(format_number($selectedEvent['status_counts']['limited'] ?? 0)) ?> limited sites are projected under the selected event.</p>
            </article>
            <article class="notice">
                <h3>Featured facility spaces left</h3>
                <div class="metric" data-events-featured-available><?= h(format_number($featuredForecast['predicted_available'] ?? 0)) ?></div>
                <p class="muted">Live forecast for the highlighted facility most relevant to the selected event.</p>
            </article>
        </section>

        <section class="grid-two" style="margin-bottom:24px;" data-events-cards>
            <?php foreach ($events as $event): ?>
                <?php $active = $selectedEvent && $selectedEvent['id'] === $event['id']; ?>
                <article class="panel event-selector<?= $active ? ' active' : '' ?>" data-event-id="<?= h($event['id']) ?>">
                    <div class="tag-row" style="margin-top:0;">
                        <span class="tag"><?= h(events_parse_datetime($event['starts_at'])->format('D, d M Y')) ?></span>
                        <span class="tag"><?= h($event['attendance_label']) ?>: <?= h(format_number($event['attendance_estimate'])) ?></span>
                    </div>
                    <h3><?= h($event['title']) ?></h3>
                    <p class="muted"><?= h($event['starts_at_display']) ?> to <?= h($event['ends_at_display']) ?> | <?= h($event['venue_name']) ?>, <?= h($event['venue_area']) ?></p>
                    <p class="muted"><?= h($event['network_headline']) ?></p>
                    <div class="event-actions">
                        <?php if ($active): ?>
                            <span class="btn btn-disabled" aria-disabled="true">Current forecast</span>
                        <?php else: ?>
                            <a class="btn btn-primary" href="event_forecasts.php?event=<?= urlencode($event['id']) ?>">Open forecast</a>
                        <?php endif; ?>
                        <a class="btn btn-secondary" href="<?= h($event['source_url']) ?>" target="_blank" rel="noopener noreferrer">Official source</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="grid-two" style="margin-bottom:24px;">
            <article class="panel" data-events-selected-panel>
                <?php if ($selectedEvent): ?>
                    <h3><?= h($selectedEvent['title']) ?></h3>
                    <p class="muted"><?= h($selectedEvent['starts_at_display']) ?> to <?= h($selectedEvent['ends_at_display']) ?></p>
                    <div class="stat-list" style="margin-top:18px;">
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
                <p class="forecast-kicker"><?= h($eventsPayload['featured_title']) ?></p>
                <?php if ($featuredForecast): ?>
                    <h3><?= h($featuredForecast['facility_name']) ?></h3>
                    <p class="muted"><?= h($selectedEvent['featured_reason'] ?? 'This site is forecast as one of the highest-pressure locations for the selected event.') ?></p>
                    <div class="metric"><?= h(format_number($featuredForecast['predicted_available'])) ?> spaces left</div>
                    <div class="progress"><span style="width: <?= max(0, min(100, $featuredPercent)) ?>%"></span></div>
                    <div class="stat-list" style="margin-top:18px;">
                        <div class="stat-item"><span>Baseline occupied</span><strong><?= h(format_number($featuredForecast['baseline_occupied'])) ?></strong></div>
                        <div class="stat-item"><span>Event lift</span><strong>+<?= h(format_number($featuredForecast['event_lift'])) ?></strong></div>
                        <div class="stat-item"><span>Predicted occupied</span><strong><?= h(format_number($featuredForecast['predicted_occupied'])) ?></strong></div>
                        <div class="stat-item"><span>Predicted status</span><strong><span class="status-pill <?= h(availability_badge_class($featuredForecast['predicted_status'])) ?>"><?= h($featuredForecast['predicted_status']) ?></span></strong></div>
                    </div>
                <?php else: ?>
                    <h3>No highlighted facility</h3>
                    <p class="muted">Forecast details will appear here as soon as event and facility data are available.</p>
                <?php endif; ?>
            </article>
        </section>

        <section class="chart-card" style="margin-bottom:24px;">
            <h3>Most impacted facilities for the selected event</h3>
            <p class="muted">The chart below ranks the eight sites receiving the biggest event-driven parking lift, then shows their projected occupancy percentage.</p>
            <canvas id="eventImpactChart" height="160"></canvas>
        </section>

        <section class="table-card" data-facility-search-scope>
            <div class="section-title">
                <div>
                    <h2>Nearby facility forecasts for the selected event</h2>
                    <p data-events-table-description>Every row below shows a tracked facility within roughly <?= h($nearbyRadiusLabel) ?> km of the venue.</p>
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
                    <option value="impact_desc">Biggest event lift</option>
                    <option value="occupancy_desc">Highest occupancy</option>
                    <option value="available_asc">Fewest spaces left</option>
                    <option value="name_asc">Facility name</option>
                </select>
            </div>

            <div class="table-wrap">
                <table>
                    <thead><tr><th>Facility</th><th>Capacity</th><th>Baseline Occupied</th><th>Event Lift</th><th>Predicted Available</th><th>Predicted Occupancy</th><th>Status</th><th>Details</th></tr></thead>
                    <tbody data-events-table-body>
                        <?php if (($selectedEvent['nearby_ranked'] ?? []) === []): ?>
                            <tr><td colspan="8" class="empty-state">No tracked parking facilities are within <?= h($nearbyRadiusLabel) ?> km of this event venue.</td></tr>
                        <?php else: ?>
                            <?php foreach (($selectedEvent['nearby_ranked'] ?? []) as $row): ?>
                                <?php $predictedPercent = ((float) $row['predicted_rate']) * 100; ?>
                                <tr data-facility-row data-search="<?= h(strtolower($row['facility_id'] . ' ' . $row['facility_name'] . ' ' . $row['predicted_status'])) ?>">
                                    <td><?= h($row['facility_name']) ?></td>
                                    <td><?= h(format_number($row['capacity'])) ?></td>
                                    <td><?= h(format_number($row['baseline_occupied'])) ?></td>
                                    <td><strong>+<?= h(format_number($row['event_lift'])) ?></strong></td>
                                    <td><?= h(format_number($row['predicted_available'])) ?></td>
                                    <td><strong><?= h(format_percentage($predictedPercent)) ?></strong><div class="progress" style="margin-top:8px;"><span style="width: <?= max(0, min(100, $predictedPercent)) ?>%"></span></div></td>
                                    <td><span class="status-pill <?= h(availability_badge_class($row['predicted_status'])) ?>"><?= h($row['predicted_status']) ?></span></td>
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
            label: 'Projected occupancy %',
            data: <?= json_encode($topImpactValues) ?>,
            borderWidth: 0,
            borderRadius: 8,
            backgroundColor: 'rgba(14, 94, 181, 0.82)'
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        scales: { x: { beginAtZero: true, max: 100 } }
    }
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
