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
$summary = $facilitiesPayload['summary'];
$selectedSummary = $facilitiesPayload['selected_summary'];
$options = facility_options();
$facilitiesSyncUrl = 'api/facilities_summary.php?facility_id=' . rawurlencode($selectedFacilityId);

require_once __DIR__ . '/includes/header.php';
?>
<div class="container" data-live-collector-url="api/collect_live.php" data-live-facilities-url="<?= h($facilitiesSyncUrl) ?>" data-live-collector-interval="<?= h((string) $collectorIntervalMs) ?>">
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
            <form method="get">
                <select class="select-field" name="facility_id" onchange="this.form.submit()">
                    <option value="">Select a facility to view history</option>
                    <?php foreach ($options as $option): ?>
                        <option value="<?= h($option['facility_id']) ?>" <?= $selectedFacilityId === $option['facility_id'] ? 'selected' : '' ?>><?= h($option['facility_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="tag-row" style="margin-bottom:18px;">
            <span class="tag" data-facilities-result-count>Showing <?= h(format_number(count($facilities))) ?> facilities</span>
        </div>

        <div class="table-wrap">
            <table>
                <thead><tr><th>Facility ID</th><th>Facility Name</th><th>Capacity</th><th>Occupied</th><th>Available</th><th>Occupancy</th><th>Status</th></tr></thead>
                <tbody data-facilities-table-body>
                    <?php foreach ($facilities as $row): ?>
                        <?php $percent = (float) $row['occupancy_rate'] * 100; ?>
                        <tr>
                            <td><?= h($row['facility_id']) ?></td>
                            <td><a href="facilities.php?facility_id=<?= urlencode($row['facility_id']) ?>"><?= h($row['facility_name']) ?></a></td>
                            <td><?= h(format_number($row['capacity'])) ?></td>
                            <td><?= h(format_number($row['occupied'])) ?></td>
                            <td><?= h(format_number($row['available'])) ?></td>
                            <td><strong><?= h(format_percentage($percent)) ?></strong><div class="progress" style="margin-top:8px;"><span style="width: <?= max(0, min(100, $percent)) ?>%"></span></div></td>
                            <td><span class="status-pill <?= h(availability_badge_class($row['availability_class'])) ?>"><?= h($row['availability_class']) ?></span></td>
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
