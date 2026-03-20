<?php
$pageTitle = 'About';
require_once __DIR__ . '/includes/page_payloads.php';
require_once __DIR__ . '/includes/live_collector.php';

$collectorIntervalMs = 10000;
try {
    $collectorIntervalMs = max(10000, ((int) (live_collector_config()['interval_seconds'] ?? 10)) * 1000);
} catch (Throwable) {
    $collectorIntervalMs = 10000;
}
$collectorIntervalSeconds = max(1, (int) round($collectorIntervalMs / 1000));
$aboutPayload = about_page_payload();
$dataset = $aboutPayload['dataset'];
$summary = $aboutPayload['summary'];

require_once __DIR__ . '/includes/header.php';
?>
<div class="container" data-live-collector-url="api/collect_live.php" data-live-about-url="api/about_summary.php" data-live-collector-interval="<?= h((string) $collectorIntervalMs) ?>">
    <section class="hero">
        <div>
            <p class="eyebrow">Platform Overview</p>
            <h1>Built to turn parking data into clear operational insight.</h1>
            <p>Smart Parking NSW delivers a practical web layer on top of live and historical occupancy data, making it easier for teams to understand demand, monitor utilization, and communicate performance.</p>
            <div class="tag-row">
                <span class="tag">PHP + MySQL + XAMPP</span>
                <span class="tag">Dashboard sync or optional Python collector</span>
                <span class="tag" data-about-facilities>Monitored facilities: <?= h(format_number($summary['facilities_count'] ?? 0)) ?></span>
                <span class="tag" data-live-collector-status>Auto sync every <?= h((string) $collectorIntervalSeconds) ?> seconds while this About page is open</span>
            </div>
        </div>
        <div class="panel">
            <h3>Current data coverage</h3>
            <div class="stat-list">
                <div class="stat-item"><span>First record</span><strong data-about-min-time><?= h(display_datetime($dataset['min_time'] ?? null)) ?></strong></div>
                <div class="stat-item"><span>Latest record</span><strong data-about-max-time><?= h(display_datetime($dataset['max_time'] ?? null)) ?></strong></div>
                <div class="stat-item"><span>Total observations</span><strong data-about-observations><?= h(format_number($dataset['observations'] ?? 0)) ?></strong></div>
            </div>
        </div>
    </section>

    <section class="grid-two" style="margin-bottom:24px;">
        <article class="panel"><h3>Application pages</h3><div class="stat-list"><div class="stat-item"><span>Home</span><strong>Executive overview and current highlights</strong></div><div class="stat-item"><span>Dashboard</span><strong>Network KPIs, trend charts, and live table view</strong></div><div class="stat-item"><span>Facilities</span><strong>Searchable facility records and occupancy timeline</strong></div><div class="stat-item"><span>Insights</span><strong>Trend interpretation and model diagnostics</strong></div><div class="stat-item"><span>About</span><strong>Architecture, scope, and implementation notes</strong></div></div></article>
        <article class="panel"><h3>System architecture</h3><div class="stat-list"><div class="stat-item"><span>1. Data ingestion</span><strong>Dashboard PHP sync or optional Python collector pulls NSW parking snapshots</strong></div><div class="stat-item"><span>2. Storage layer</span><strong>MySQL stores facilities, snapshots, and model outputs</strong></div><div class="stat-item"><span>3. Processing layer</span><strong>Occupancy and model metrics are prepared for reporting</strong></div><div class="stat-item"><span>4. Web layer</span><strong>PHP serves live KPIs, charts, and searchable views</strong></div></div></article>
    </section>

    <section class="grid-two" style="margin-bottom:24px;">
        <article class="table-card"><h3>Primary database tables</h3><div class="table-wrap"><table><thead><tr><th>Table</th><th>Purpose</th></tr></thead><tbody><tr><td>parking_facilities</td><td>Facility profile information such as name, coordinates, and capacity.</td></tr><tr><td>occupancy_snapshots</td><td>Time-stamped occupancy, availability, and utilization records.</td></tr><tr><td>model_regression_metrics</td><td>Regression quality scores including MAE, RMSE, and R2.</td></tr><tr><td>model_classification_metrics</td><td>Classification accuracy outputs per facility.</td></tr></tbody></table></div></article>
        <article class="table-card"><h3>Why this implementation works well</h3><div class="table-wrap"><table><thead><tr><th>Focus</th><th>Operational benefit</th></tr></thead><tbody><tr><td>Clear interface</td><td>Stakeholders can understand current network status quickly.</td></tr><tr><td>Live data readiness</td><td>Supports continuous updates without redesigning the frontend.</td></tr><tr><td>Structured reporting</td><td>KPIs and model summaries are easy to present and compare.</td></tr><tr><td>Local deployment</td><td>Runs reliably in a familiar XAMPP environment.</td></tr></tbody></table></div></article>
    </section>

    <?php if (file_exists(__DIR__ . '/docs/erd-smart-parking.jpg')): ?>
        <section class="chart-card"><h3>Entity relationship diagram</h3><p class="muted">The ERD below summarizes how facilities, snapshots, and model metrics connect inside the database schema.</p><img src="docs/erd-smart-parking.jpg" alt="Smart Parking ERD"></section>
    <?php endif; ?>
</div>
<script>
window.aboutState = <?= json_encode($aboutPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
