<?php
$pageTitle = 'Facilities';
require_once __DIR__ . '/includes/page_payloads.php';
require_once __DIR__ . '/includes/live_collector.php';

$collectorIntervalMs = 10000;
try {
    $collectorIntervalMs = max(10000, ((int) (live_collector_config()['interval_seconds'] ?? 10)) * 1000);
} catch (Throwable) {
    $collectorIntervalMs = 10000;
}
$collectorIntervalSeconds = max(1, (int) round($collectorIntervalMs / 1000));
$selectedFacilityId = isset($_GET['facility_id']) ? trim((string) $_GET['facility_id']) : '';
$facilitiesPayload = facilities_page_payload($selectedFacilityId);
$facilities = $facilitiesPayload['facilities'];
$visibleFacilities = $selectedFacilityId !== ''
    ? array_values(array_filter(
        $facilities,
        static fn(array $row): bool => (string) ($row['facility_id'] ?? '') === $selectedFacilityId
    ))
    : $facilities;
$summary = $facilitiesPayload['summary'];
$selectedSummary = $facilitiesPayload['selected_summary'];
$selectedPrediction = is_array($facilitiesPayload['selected_prediction'] ?? null) ? $facilitiesPayload['selected_prediction'] : null;
$predictionWindows = is_array($facilitiesPayload['prediction_windows'] ?? null) ? $facilitiesPayload['prediction_windows'] : [];
$hourlyPredictions = is_array($facilitiesPayload['hourly_predictions'] ?? null) ? $facilitiesPayload['hourly_predictions'] : [];
$options = facility_options();
$facilitiesSyncUrl = 'api/facilities_summary.php';

require_once __DIR__ . '/includes/header.php';
?>
<div class="container" data-live-collector-url="api/collect_live.php" data-live-facilities-url="<?= h($facilitiesSyncUrl) ?>" data-live-facilities-selected="<?= h($selectedFacilityId) ?>" data-live-collector-interval="<?= h((string) $collectorIntervalMs) ?>">
    <div class="section-title">
        <div>
            <h2>Facility monitoring center</h2>
            <p>Search, filter, and compare the latest status of each location, then drill into a selected facility timeline.</p>
        </div>
        <div class="tag-row section-tags">
            <span class="tag" data-facilities-latest-refresh>Latest network refresh: <?= h(display_datetime($summary['last_refresh'] ?? null)) ?></span>
            <span class="tag" data-live-collector-status>Auto sync every <?= h((string) $collectorIntervalSeconds) ?> seconds while this Facilities page is open</span>
        </div>
    </div>

    <section class="table-card" style="margin-bottom:24px;">
        <div class="filters">
            <input class="search-bar" type="text" placeholder="Search by facility name or ID..." data-facilities-search>
            <select class="select-field" data-facilities-status-filter>
                <option value="all">All statuses</option>
                <option value="available">Available</option>
                <option value="limited">Limited</option>
                <option value="full">Full</option>
            </select>
            <select class="select-field" data-facilities-sort-filter>
                <option value="occupancy_desc">Full to lowest occupancy</option>
                <option value="occupancy_asc">Lowest occupancy to full</option>
            </select>
            <form method="get" data-facilities-selection-form>
                <select class="select-field" name="facility_id" data-facilities-facility-filter>
                    <option value="">Show all facilities</option>
                    <?php foreach ($options as $option): ?>
                        <option value="<?= h($option['facility_id']) ?>" <?= $selectedFacilityId === $option['facility_id'] ? 'selected' : '' ?>><?= h($option['facility_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="tag-row" style="margin-bottom:18px;">
            <span class="tag" data-facilities-result-count>Showing <?= h(format_number(count($visibleFacilities))) ?> facilities</span>
        </div>

        <div class="table-wrap">
            <table>
                <thead><tr><th>Facility ID</th><th>Facility Name</th><th>Capacity</th><th>Occupied</th><th>Available</th><th>Occupancy</th><th>Status</th><th><?= h((string) ($predictionWindows['current_until_label'] ?? 'Forecast later this hour (to end of current hour)')) ?></th><th>Predicted <?= h((string) ($predictionWindows['next_label'] ?? 'Next')) ?></th><th>Operating Hours</th></tr></thead>
                <tbody data-facilities-table-body>
                    <?php foreach ($visibleFacilities as $row): ?>
                        <?php $percent = (float) $row['occupancy_rate'] * 100; ?>
                        <?php $prediction = is_array($hourlyPredictions[$row['facility_id']] ?? null) ? $hourlyPredictions[$row['facility_id']] : null; ?>
                        <?php $currentPred = is_array($prediction['current_window'] ?? null) ? $prediction['current_window'] : null; ?>
                        <?php $nextPred = is_array($prediction['next_window'] ?? null) ? $prediction['next_window'] : null; ?>
                        <tr>
                            <td><?= h($row['facility_id']) ?></td>
                            <td><a href="facilities.php?facility_id=<?= urlencode($row['facility_id']) ?>"><?= h($row['facility_name']) ?></a></td>
                            <td><?= h(format_number($row['capacity'])) ?></td>
                            <td><?= h(format_number($row['occupied'])) ?></td>
                            <td><?= h(format_number($row['available'])) ?></td>
                            <td><strong><?= h(format_percentage($percent)) ?></strong><div class="progress" style="margin-top:8px;"><span style="width: <?= max(0, min(100, $percent)) ?>%"></span></div></td>
                            <td><span class="status-pill <?= h(availability_badge_class($row['availability_class'])) ?>"><?= h($row['availability_class']) ?></span></td>
                            <td>
                                <?php if ($currentPred): ?>
                                    <strong><?= h(format_number($currentPred['predicted_available'] ?? 0)) ?> free</strong><br>
                                    <span class="status-pill <?= h(availability_badge_class($currentPred['predicted_class'] ?? 'Available')) ?>"><?= h($currentPred['predicted_class'] ?? 'Available') ?></span>
                                <?php else: ?>
                                    <span class="muted">No forecast</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($nextPred): ?>
                                    <strong><?= h(format_number($nextPred['predicted_available'] ?? 0)) ?> free</strong><br>
                                    <span class="status-pill <?= h(availability_badge_class($nextPred['predicted_class'] ?? 'Available')) ?>"><?= h($nextPred['predicted_class'] ?? 'Available') ?></span>
                                <?php else: ?>
                                    <span class="muted">No forecast</span>
                                <?php endif; ?>
                            </td>
                            <td><?= h($prediction['operating_hours_note'] ?? 'Operating hours not provided') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div data-facilities-selected-shell>
        <?php if ($selectedSummary): ?>
            <?php $selectedPercent = (float) $selectedSummary['occupancy_rate'] * 100; ?>
            <section class="grid-two">
                <article class="panel">
                    <h3><?= h($selectedSummary['facility_name']) ?></h3>
                    <p class="muted">Facility ID: <?= h($selectedSummary['facility_id']) ?> | Latest reading: <?= h(display_datetime($selectedSummary['recorded_at'])) ?></p>
                    <div class="metric"><?= h(format_percentage($selectedPercent)) ?></div>
                    <div class="progress"><span style="width: <?= max(0, min(100, $selectedPercent)) ?>%"></span></div>
                    <div class="stat-list" style="margin-top:18px;">
                        <div class="stat-item"><span>Capacity</span><strong><?= h(format_number($selectedSummary['capacity'])) ?></strong></div>
                        <div class="stat-item"><span>Occupied</span><strong><?= h(format_number($selectedSummary['occupied'])) ?></strong></div>
                        <div class="stat-item"><span>Available</span><strong><?= h(format_number($selectedSummary['available'])) ?></strong></div>
                        <div class="stat-item"><span>Status</span><strong><span class="status-pill <?= h(availability_badge_class($selectedSummary['availability_class'])) ?>"><?= h($selectedSummary['availability_class']) ?></span></strong></div>
                            <?php if ($selectedPrediction): ?>
                                <div class="stat-item"><span><?= h((string) ($predictionWindows['current_until_label'] ?? 'Forecast later this hour (to end of current hour)')) ?></span><strong><?= h(format_number($selectedPrediction['current_window']['predicted_available'] ?? 0)) ?> free (<?= h($selectedPrediction['current_window']['predicted_class'] ?? 'Available') ?>)</strong></div>
                                <div class="stat-item"><span>Predicted <?= h((string) ($predictionWindows['next_label'] ?? 'Next')) ?></span><strong><?= h(format_number($selectedPrediction['next_window']['predicted_available'] ?? 0)) ?> free (<?= h($selectedPrediction['next_window']['predicted_class'] ?? 'Available') ?>)</strong></div>
                                <div class="stat-item"><span>Operating hours</span><strong><?= h($selectedPrediction['operating_hours_note'] ?? 'Operating hours not provided') ?></strong></div>
                            <?php endif; ?>
                    </div>
                </article>
                <article class="chart-card">
                    <h3>Occupancy timeline for selected facility</h3>
                    <p class="muted">Recent occupancy percentage trend for this specific site.</p>
                    <canvas data-facilities-history-chart height="180"></canvas>
                </article>
            </section>
        <?php elseif ($selectedFacilityId !== ''): ?>
            <section class="empty-state card">No timeline data was found for the selected facility.</section>
        <?php endif; ?>
    </div>
</div>
<script>
window.facilitiesState = <?= json_encode($facilitiesPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.facilitiesCharts = {};
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
