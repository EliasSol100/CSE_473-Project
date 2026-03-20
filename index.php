<?php
$pageTitle = 'Home';
require_once __DIR__ . '/includes/page_payloads.php';
require_once __DIR__ . '/includes/live_collector.php';

$collectorIntervalMs = 10000;
try {
    $collectorIntervalMs = max(10000, ((int) (live_collector_config()['interval_seconds'] ?? 10)) * 1000);
} catch (Throwable) {
    $collectorIntervalMs = 10000;
}
$collectorIntervalSeconds = max(1, (int) round($collectorIntervalMs / 1000));
$homePayload = home_page_payload();
$summary = $homePayload['summary'];
$dataset = $homePayload['dataset'];
$topThree = $homePayload['top_facilities'];

require_once __DIR__ . '/includes/header.php';
?>
<div class="container" data-live-collector-url="api/collect_live.php" data-live-home-url="api/home_summary.php" data-live-collector-interval="<?= h((string) $collectorIntervalMs) ?>">
    <section class="hero">
        <div>
            <p class="eyebrow">NSW Live Parking Network</p>
            <h1>Plan parking operations with clear, real-time occupancy visibility.</h1>
            <p>Smart Parking NSW combines live facility snapshots, occupancy trends, and operational metrics in one interface so teams can respond quickly to demand changes.</p>
            <div class="hero-actions">
                <a class="btn btn-primary" href="dashboard.php">Open live dashboard</a>
                <a class="btn btn-secondary" href="facilities.php">Browse facilities</a>
            </div>
            <div class="tag-row">
                <span class="tag" data-home-monitored>Monitored facilities: <?= h(format_number($summary['facilities_count'] ?? 0)) ?></span>
                <span class="tag" data-home-observations>Total observations: <?= h(format_number($dataset['observations'] ?? 0)) ?></span>
                <span class="tag" data-home-latest-update>Latest update: <?= h(display_datetime($dataset['max_time'] ?? null)) ?></span>
                <span class="tag" data-live-collector-status>Auto sync every <?= h((string) $collectorIntervalSeconds) ?> seconds while this Home page is open</span>
            </div>
        </div>
        <div class="panel capability-panel">
            <h3>Platform capabilities</h3>
            <div class="stat-list">
                <div class="stat-item"><span>Live ingestion</span><strong>Continuous NSW snapshot updates</strong></div>
                <div class="stat-item"><span>Operational awareness</span><strong>Current occupancy and availability</strong></div>
                <div class="stat-item"><span>Facility intelligence</span><strong>Searchable site-level monitoring</strong></div>
                <div class="stat-item"><span>Analytical context</span><strong>Trend and model performance insights</strong></div>
            </div>
        </div>
    </section>

    <section class="kpi-grid">
        <article class="card"><h3>Total Facilities</h3><div class="metric" data-home-total-facilities><?= h(format_number($summary['facilities_count'] ?? 0)) ?></div><p class="muted">Active parking facilities currently represented in the monitoring network.</p></article>
        <article class="card"><h3>Total Capacity</h3><div class="metric" data-home-total-capacity><?= h(format_number($summary['total_capacity'] ?? 0)) ?></div><p class="muted">Combined number of parking spaces across all tracked locations.</p></article>
        <article class="card"><h3>Average Occupancy</h3><div class="metric" data-home-average-occupancy><?= h(format_percentage($summary['avg_occupancy'] ?? 0)) ?></div><p class="muted">Mean occupancy across the latest snapshot for each facility.</p></article>
        <article class="card"><h3>Highest Utilization</h3><div class="metric" style="font-size:1.25rem;" data-home-busiest-name><?= h($summary['busiest_name'] ?? 'N/A') ?></div><p class="muted">Current peak occupancy: <span data-home-busiest-rate><?= h(format_percentage($summary['busiest_rate'] ?? 0)) ?></span></p></article>
    </section>

    <section class="info-grid">
        <article class="notice"><h3>Data pipeline</h3><p class="muted">Historical records can be retained for context while the dashboard auto-sync or optional Python collector keeps fresh NSW parking snapshots flowing into MySQL.</p></article>
        <article class="notice"><h3>Technology foundation</h3><p class="muted">The platform runs on PHP, MySQL, and XAMPP, with automated live data ingestion handled by the dashboard sync layer or optional Python collector.</p></article>
        <article class="notice"><h3>Operational use</h3><p class="muted">Each page is built for rapid status checks, from whole-network KPIs to facility-level details and performance patterns.</p></article>
    </section>

    <section class="table-card">
        <div class="section-title"><div><h2>Facility activity highlights</h2><p>Top facilities from the most recent snapshot.</p></div><a class="btn btn-secondary" href="facilities.php">See all facilities</a></div>
        <div data-home-highlights>
            <?php if ($topThree): ?>
                <div class="grid-three">
                    <?php foreach ($topThree as $row): ?>
                        <?php $percent = (float) $row['occupancy_rate'] * 100; ?>
                        <article class="panel">
                            <span class="status-pill <?= h(percent_badge_class($percent)) ?>"><?= h($row['availability_class']) ?></span>
                            <h3 style="margin-top:14px;"><?= h($row['facility_name']) ?></h3>
                            <p class="muted">Facility ID: <?= h($row['facility_id']) ?></p>
                            <div class="metric"><?= h(format_percentage($percent)) ?></div>
                            <div class="progress"><span style="width: <?= max(0, min(100, $percent)) ?>%"></span></div>
                            <p class="muted">Occupied: <?= h(format_number($row['occupied'])) ?> / <?= h(format_number($row['capacity'])) ?></p>
                            <a class="btn btn-secondary" href="facilities.php?facility_id=<?= urlencode($row['facility_id']) ?>">View facility profile</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">No facility snapshots are available yet. Start live collection or import data to populate this section.</div>
            <?php endif; ?>
        </div>
    </section>
</div>
<script>
window.homeState = <?= json_encode($homePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
