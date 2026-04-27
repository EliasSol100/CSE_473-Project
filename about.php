<?php
// About page: presents project purpose plus live coverage statistics.
$pageTitle = 'About';
require_once __DIR__ . '/includes/page_payloads.php';
require_once __DIR__ . '/includes/live_collector.php';

// Coverage cards are refreshed with the same collector interval as the live dashboard.
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
            <p class="eyebrow">About Smart Parking NSW</p>
            <h1>A clearer way to understand parking availability across the network.</h1>
            <p>Smart Parking NSW brings live facility status, occupancy trends, and event-aware parking outlooks into one place so people can understand current conditions quickly and make more confident travel or operations decisions.</p>
            <div class="hero-actions">
                <a class="btn btn-primary" href="dashboard.php">Open dashboard</a>
                <a class="btn btn-secondary" href="facilities.php">Browse facilities</a>
                <a class="btn btn-secondary" href="events.php">View events outlook</a>
            </div>
            <div class="tag-row">
                <span class="tag" data-about-facilities>Monitored facilities: <?= h(format_number($summary['facilities_count'] ?? 0)) ?></span>
                <span class="tag">Live and historical occupancy visibility</span>
                <span class="tag">Facility search, comparison, and event context</span>
                <span class="tag" data-live-collector-status>Auto sync every <?= h((string) $collectorIntervalSeconds) ?> seconds while this About page is open</span>
            </div>
        </div>
        <div class="panel capability-panel">
            <h3>What can you do here</h3>
            <div class="stat-list">
                <div class="stat-item"><span>Check current availability</span><strong>See which facilities are full, limited, or available right now.</strong></div>
                <div class="stat-item"><span>Compare locations</span><strong>Review facility capacity, occupied spaces, and occupancy percentage side by side.</strong></div>
                <div class="stat-item"><span>Track trends</span><strong>Open a facility timeline to understand how usage changes through the day.</strong></div>
                <div class="stat-item"><span>Plan around events</span><strong>Review event forecasts and nearby parking pressure before busy Sydney activities.</strong></div>
            </div>
        </div>
    </section>

    <section class="kpi-grid">
        <article class="card">
            <h3>Monitored facilities</h3>
            <div class="metric" data-about-facilities-count><?= h(format_number($summary['facilities_count'] ?? 0)) ?></div>
            <p class="muted">Parking locations currently represented in the monitored network.</p>
        </article>
        <article class="card">
            <h3>Total observations</h3>
            <div class="metric" data-about-observations><?= h(format_number($dataset['observations'] ?? 0)) ?></div>
            <p class="muted">Recorded occupancy snapshots supporting the live views and trend analysis.</p>
        </article>
        <article class="card">
            <h3>First record</h3>
            <div class="metric" style="font-size:1.3rem;" data-about-min-time><?= h(display_datetime($dataset['min_time'] ?? null)) ?></div>
            <p class="muted">Start of the currently available monitoring history in this environment.</p>
        </article>
        <article class="card">
            <h3>Latest record</h3>
            <div class="metric" style="font-size:1.3rem;" data-about-max-time><?= h(display_datetime($dataset['max_time'] ?? null)) ?></div>
            <p class="muted">Newest parking snapshot currently available to the platform.</p>
        </article>
    </section>

    <section class="grid-two" style="margin-bottom:24px;">
        <article class="panel">
            <h3>Explore the platform</h3>
            <div class="stat-list">
                <div class="stat-item"><span>Home</span><strong>Quick overview of the network, key counts, and highlighted facilities.</strong></div>
                <div class="stat-item"><span>Dashboard</span><strong>Live KPIs and charts that summarise network conditions at a glance.</strong></div>
                <div class="stat-item"><span>Facilities</span><strong>Search and filter every monitored site, then inspect one facility in detail.</strong></div>
                <div class="stat-item"><span>Insights</span><strong>See occupancy behaviour patterns and performance-oriented analytical summaries.</strong></div>
                <div class="stat-item"><span>Events</span><strong>Review current and upcoming Sydney events with parking pressure forecasts.</strong></div>
            </div>
        </article>
        <article class="panel">
            <h3>Who this platform helps</h3>
            <div class="stat-list">
                <div class="stat-item"><span>Operations teams</span><strong>Spot busy facilities early and respond to demand changes faster.</strong></div>
                <div class="stat-item"><span>Planners and analysts</span><strong>Understand network utilisation patterns across facilities and time periods.</strong></div>
                <div class="stat-item"><span>Event coordinators</span><strong>Check likely spillover demand and nearby parking pressure for major activities.</strong></div>
                <div class="stat-item"><span>General users</span><strong>Get a clearer view of where parking may be available before travelling.</strong></div>
            </div>
        </article>
    </section>

    <section class="grid-three">
        <article class="notice">
            <h3>Live occupancy visibility</h3>
            <p class="muted">The platform keeps the latest parking conditions front and centre so people can see current capacity and occupancy without searching through raw records.</p>
        </article>
        <article class="notice">
            <h3>Facility-level detail</h3>
            <p class="muted">Each monitored site can be explored individually, making it easier to compare locations and understand local usage patterns.</p>
        </article>
        <article class="notice">
            <h3>Event-aware planning</h3>
            <p class="muted">Event forecast views help connect real Sydney activities with expected parking pressure around relevant facilities.</p>
        </article>
    </section>
</div>
<script>
// app.js uses this initial state to update About metrics after a live sync.
window.aboutState = <?= json_encode($aboutPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
