<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/live_collector.php';

$collectorIntervalMs = 10000;
try {
    $collectorIntervalMs = max(10000, ((int) (live_collector_config()['interval_seconds'] ?? 10)) * 1000);
} catch (Throwable) {
    $collectorIntervalMs = 10000;
}
$collectorIntervalSeconds = max(1, (int) round($collectorIntervalMs / 1000));

$summary = summary_metrics();
$latest = latest_snapshots();
$hourly = hourly_average_occupancy();
$topLatest = array_slice($latest, 0, 8);
$distribution = availability_distribution();
$predictionBundle = facility_hourly_predictions($latest);
$predictionWindows = $predictionBundle['windows'] ?? [];
$predictionSummary = $predictionBundle['summary'] ?? [];
$hourLabels = array_map(fn($row) => sprintf('%02d:00', (int) $row['hour']), $hourly);
$hourValues = array_map(fn($row) => (float) $row['average_occupancy'], $hourly);
$topLabels = array_map(fn($row) => $row['facility_name'], $topLatest);
$topValues = array_map(fn($row) => round(((float) $row['occupancy_rate']) * 100, 2), $topLatest);
$distLabels = array_map(fn($row) => $row['availability_class'], $distribution);
$distValues = array_map(fn($row) => (int) $row['total'], $distribution);
$dashboardPayload = [
    'summary' => $summary,
    'top_latest' => $topLatest,
    'hourly' => $hourly,
    'distribution' => $distribution,
    'prediction_windows' => $predictionWindows,
    'hourly_predictions' => $predictionBundle['predictions'] ?? [],
    'prediction_summary' => $predictionSummary,
];
?>
<div class="container" data-live-collector-url="api/collect_live.php" data-live-collector-interval="<?= h((string) $collectorIntervalMs) ?>" data-live-summary-url="api/live_summary.php">
    <div class="section-title">
        <div>
            <h2>Network operations dashboard</h2>
            <p>Live occupancy health, utilization patterns, and facility-level status across monitored NSW locations.</p>
        </div>
        <div class="tag-row section-tags">
            <span class="tag" data-summary-last-refresh>Last data refresh: <?= h(display_datetime($summary['last_refresh'] ?? null)) ?></span>
            <span class="tag" data-live-collector-status>Auto sync every <?= h((string) $collectorIntervalSeconds) ?> seconds while this dashboard is open</span>
        </div>
    </div>
    <section class="kpi-grid">
        <article class="card"><h3>Facilities Online</h3><div class="metric" data-summary-facilities><?= h(format_number($summary['facilities_count'] ?? 0)) ?></div><p class="muted">Locations currently contributing records to the latest network snapshot.</p></article>
        <article class="card"><h3>Occupied Spaces</h3><div class="metric" data-summary-occupied><?= h(format_number($summary['occupied_now'] ?? 0)) ?></div><p class="muted">Total spaces currently in use based on the newest facility readings.</p></article>
        <article class="card"><h3>Available Spaces</h3><div class="metric" data-summary-available><?= h(format_number($summary['available_now'] ?? 0)) ?></div><p class="muted">Estimated free capacity available right now across all monitored facilities.</p></article>
        <article class="card"><h3>Average Utilization</h3><div class="metric" data-summary-avg><?= h(format_percentage($summary['avg_occupancy'] ?? 0)) ?></div><p class="muted">Current mean occupancy percentage for the latest record of each site.</p></article>
        <article class="card"><h3>Predicted Free (<?= h((string) ($predictionWindows['current_label'] ?? 'Current hour')) ?>)</h3><div class="metric" data-prediction-current-available><?= h(format_number($predictionSummary['current_window_available_total'] ?? 0)) ?></div><p class="muted">Forecast available spaces for the ongoing hour window in <?= h((string) ($predictionWindows['timezone'] ?? 'Australia/Sydney')) ?>.</p></article>
        <article class="card"><h3>Predicted Free (<?= h((string) ($predictionWindows['next_label'] ?? 'Next hour')) ?>)</h3><div class="metric" data-prediction-next-available><?= h(format_number($predictionSummary['next_window_available_total'] ?? 0)) ?></div><p class="muted">Forecast available spaces for the upcoming hour window across all tracked sites.</p></article>
        <article class="card"><h3>24/7 Facilities</h3><div class="metric" data-prediction-open247><?= h(format_number($predictionSummary['open_24_7_count'] ?? 0)) ?></div><p class="muted">Sites reported as open all day by the NSW API operating-hours metadata.</p></article>
        <article class="card"><h3>Limited-Hours Facilities</h3><div class="metric" data-prediction-limited-hours><?= h(format_number($predictionSummary['limited_hours_count'] ?? 0)) ?></div><p class="muted">Sites that are not marked as 24/7 and may have closing periods (including nighttime closures).</p></article>
    </section>

    <section class="chart-grid">
        <article class="chart-card"><h3>Average occupancy by hour</h3><p class="muted">Hourly utilization profile based on all stored observations.</p><canvas id="hourlyChart" height="140"></canvas></article>
        <article class="chart-card"><h3>Latest availability distribution</h3><p class="muted">How current facility statuses are split across availability classes.</p><canvas id="availabilityChart" height="140"></canvas></article>
    </section>

    <section class="chart-card" style="margin-bottom:24px;"><h3>Most utilized facilities right now</h3><p class="muted">Facilities ranked by current occupancy percentage.</p><canvas id="busiestChart" height="120"></canvas></section>

    <section class="table-card dashboard-cta">
        <div class="dashboard-cta-copy">
            <h2>Need full facility details?</h2>
            <p>Open the Facilities page to search every monitored site, filter by status, and inspect a selected facility's occupancy timeline.</p>
        </div>
        <a class="btn btn-primary" href="facilities.php">View all facilities</a>
    </section>
</div>
<script>
window.dashboardState = <?= json_encode($dashboardPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const hourlyLabels = <?= json_encode($hourLabels) ?>;
const hourlyValues = <?= json_encode($hourValues) ?>;
const topLabels = <?= json_encode($topLabels) ?>;
const topValues = <?= json_encode($topValues) ?>;
const distLabels = <?= json_encode($distLabels) ?>;
const distValues = <?= json_encode($distValues) ?>;

const rootStyles = getComputedStyle(document.documentElement);
const chartPrimary = rootStyles.getPropertyValue('--chart-primary').trim() || '#0e5eb5';
const chartSecondary = rootStyles.getPropertyValue('--chart-secondary').trim() || '#0ea5a8';
const chartAccent = rootStyles.getPropertyValue('--chart-accent').trim() || '#f59e0b';
const chartDanger = rootStyles.getPropertyValue('--chart-danger').trim() || '#b0302f';
const availabilityColorForLabel = (label) => {
    const normalized = String(label || '').trim().toLowerCase();
    if (normalized === 'full') {
        return chartDanger;
    }
    if (normalized === 'limited') {
        return chartAccent;
    }
    return chartSecondary;
};

window.dashboardCharts = {};
window.dashboardCharts.hourly = new Chart(document.getElementById('hourlyChart'), {
    type: 'line',
    data: {
        labels: hourlyLabels,
        datasets: [{
            label: 'Average occupancy %',
            data: hourlyValues,
            borderColor: chartPrimary,
            backgroundColor: 'rgba(14, 94, 181, 0.12)',
            borderWidth: 3,
            tension: 0.25,
            fill: true,
            pointRadius: 2
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: true } },
        scales: { y: { beginAtZero: true, max: 100 } }
    }
});

window.dashboardCharts.availability = new Chart(document.getElementById('availabilityChart'), {
    type: 'doughnut',
    data: {
        labels: distLabels,
        datasets: [{
            data: distValues,
            backgroundColor: distLabels.map((label) => availabilityColorForLabel(label)),
            borderWidth: 1
        }]
    },
    options: { responsive: true }
});

window.dashboardCharts.busiest = new Chart(document.getElementById('busiestChart'), {
    type: 'bar',
    data: {
        labels: topLabels,
        datasets: [{
            label: 'Current occupancy %',
            data: topValues,
            backgroundColor: 'rgba(14, 94, 181, 0.82)',
            borderRadius: 8,
            borderWidth: 0
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        scales: { y: { ticks: { font: { size: 11 } } }, x: { beginAtZero: true, max: 100 } }
    }
});
window.dashboardColors = { chartPrimary, chartSecondary, chartAccent, chartDanger };
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
