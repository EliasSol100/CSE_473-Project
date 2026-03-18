<?php
$pageTitle = 'Home';
require_once __DIR__ . '/includes/header.php';
$summary = summary_metrics();
$dataset = dataset_overview();
$latest = latest_snapshots();
$topThree = array_slice($latest, 0, 3);
?>
<div class="container">
    <section class="hero">
        <div>
            <p class="tag">Simple academic web application built from the Smart Parking data science project</p>
            <h1>Monitor live NSW parking occupancy through a clean PHP website.</h1>
            <p>This version keeps the simple PHP/MySQL website, but it can now be connected to the original Python collector so the dashboard shows live NSW car park snapshots instead of only imported history.</p>
            <div class="hero-actions">
                <a class="btn btn-primary" href="dashboard.php">Open dashboard</a>
                <a class="btn btn-secondary" href="about.php">View project structure</a>
            </div>
            <div class="tag-row">
                <span class="tag">42 monitored facilities</span>
                <span class="tag"><?= h(format_number($dataset['observations'] ?? 0)) ?> processed observations</span>
                <span class="tag">Latest record: <?= h(display_datetime($dataset['max_time'] ?? null)) ?></span>
            </div>
        </div>
        <div class="panel">
            <h3>What the website demonstrates</h3>
            <div class="stat-list">
                <div class="stat-item"><span>Presentation layer</span><strong>PHP pages + reusable includes</strong></div>
                <div class="stat-item"><span>Data layer</span><strong>MySQL tables fed by Python live collector</strong></div>
                <div class="stat-item"><span>Dashboard layer</span><strong>KPIs, charts and facility tables</strong></div>
                <div class="stat-item"><span>Academic value</span><strong>Dataset explanation + ML metrics</strong></div>
            </div>
        </div>
    </section>

    <section class="kpi-grid">
        <article class="card"><h3>Total Facilities</h3><div class="metric"><?= h(format_number($summary['facilities_count'] ?? 0)) ?></div><p class="muted">Distinct parking locations currently stored in the website database.</p></article>
        <article class="card"><h3>Total Capacity</h3><div class="metric"><?= h(format_number($summary['total_capacity'] ?? 0)) ?></div><p class="muted">Combined number of parking spaces across all facilities.</p></article>
        <article class="card"><h3>Average Occupancy</h3><div class="metric"><?= h(format_percentage($summary['avg_occupancy'] ?? 0)) ?></div><p class="muted">Average of the latest occupancy percentages across facilities.</p></article>
        <article class="card"><h3>Busiest Facility</h3><div class="metric" style="font-size:1.25rem;"><?= h($summary['busiest_name'] ?? 'N/A') ?></div><p class="muted">Current highest occupancy: <?= h(format_percentage($summary['busiest_rate'] ?? 0)) ?></p></article>
    </section>

    <section class="info-grid">
        <article class="notice"><h3>Data source</h3><p class="muted">The website can keep the historical demo data, but when the live Python collector runs it continuously writes fresh NSW parking snapshots into MySQL for this PHP frontend.</p></article>
        <article class="notice"><h3>Technology stack</h3><p class="muted">PHP for the website, MySQL/phpMyAdmin for storage, XAMPP for localhost hosting, and Python in the background for scheduled live data collection.</p></article>
        <article class="notice"><h3>How live mode works</h3><p class="muted">The PHP pages read the newest rows from MySQL. The separate Python collector fetches live data from the NSW API every few minutes and inserts new snapshots.</p></article>
    </section>

    <section class="table-card">
        <div class="section-title"><div><h2>Current facility highlights</h2><p>Top facilities from the latest available snapshot.</p></div><a class="btn btn-secondary" href="facilities.php">Open full facilities table</a></div>
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
                    <a class="btn btn-secondary" href="facilities.php?facility_id=<?= urlencode($row['facility_id']) ?>">View history</a>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
