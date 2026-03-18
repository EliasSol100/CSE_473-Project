<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
$summary = summary_metrics();
$hourly = hourly_average_occupancy();
$topLatest = top_latest_facilities(8);
$distribution = availability_distribution();
$latest = latest_snapshots();
$hourLabels = array_map(fn($row) => sprintf('%02d:00', (int) $row['hour']), $hourly);
$hourValues = array_map(fn($row) => (float) $row['average_occupancy'], $hourly);
$topLabels = array_map(fn($row) => $row['facility_name'], $topLatest);
$topValues = array_map(fn($row) => round(((float) $row['occupancy_rate']) * 100, 2), $topLatest);
$distLabels = array_map(fn($row) => $row['availability_class'], $distribution);
$distValues = array_map(fn($row) => (int) $row['total'], $distribution);
?>
<div class="container">
    <div class="section-title"><div><h2>Network operations dashboard</h2><p>Live occupancy health, utilization patterns, and facility-level status across monitored NSW locations.</p></div><span class="tag">Last data refresh: <?= h(display_datetime($summary['last_refresh'] ?? null)) ?></span></div>
    <section class="kpi-grid">
        <article class="card"><h3>Facilities Online</h3><div class="metric"><?= h(format_number($summary['facilities_count'] ?? 0)) ?></div><p class="muted">Locations currently contributing records to the latest network snapshot.</p></article>
        <article class="card"><h3>Occupied Spaces</h3><div class="metric"><?= h(format_number($summary['occupied_now'] ?? 0)) ?></div><p class="muted">Total spaces currently in use based on the newest facility readings.</p></article>
        <article class="card"><h3>Available Spaces</h3><div class="metric"><?= h(format_number($summary['available_now'] ?? 0)) ?></div><p class="muted">Estimated free capacity available right now across all monitored facilities.</p></article>
        <article class="card"><h3>Average Utilization</h3><div class="metric"><?= h(format_percentage($summary['avg_occupancy'] ?? 0)) ?></div><p class="muted">Current mean occupancy percentage for the latest record of each site.</p></article>
    </section>

    <section class="chart-grid">
        <article class="chart-card"><h3>Average occupancy by hour</h3><p class="muted">Hourly utilization profile based on all stored observations.</p><canvas id="hourlyChart" height="140"></canvas></article>
        <article class="chart-card"><h3>Latest availability distribution</h3><p class="muted">How current facility statuses are split across availability classes.</p><canvas id="availabilityChart" height="140"></canvas></article>
    </section>

    <section class="chart-card" style="margin-bottom:24px;"><h3>Most utilized facilities right now</h3><p class="muted">Facilities ranked by current occupancy percentage.</p><canvas id="busiestChart" height="120"></canvas></section>

    <section class="table-card">
        <div class="section-title"><div><h2>Latest facility status table</h2><p>Sorted by highest occupancy to surface high-demand locations first.</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Facility</th><th>Capacity</th><th>Occupied</th><th>Available</th><th>Occupancy</th><th>Status</th><th>Details</th></tr></thead>
                <tbody>
                    <?php foreach ($latest as $row): ?>
                        <?php $percent = (float) $row['occupancy_rate'] * 100; ?>
                        <tr>
                            <td><?= h($row['facility_name']) ?></td>
                            <td><?= h(format_number($row['capacity'])) ?></td>
                            <td><?= h(format_number($row['occupied'])) ?></td>
                            <td><?= h(format_number($row['available'])) ?></td>
                            <td><strong><?= h(format_percentage($percent)) ?></strong><div class="progress" style="margin-top:8px;"><span style="width: <?= max(0, min(100, $percent)) ?>%"></span></div></td>
                            <td><span class="status-pill <?= h(availability_badge_class($row['availability_class'])) ?>"><?= h($row['availability_class']) ?></span></td>
                            <td><a href="facilities.php?facility_id=<?= urlencode($row['facility_id']) ?>">View facility</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<script>
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

new Chart(document.getElementById('hourlyChart'), {
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

new Chart(document.getElementById('availabilityChart'), {
    type: 'doughnut',
    data: {
        labels: distLabels,
        datasets: [{
            data: distValues,
            backgroundColor: [chartSecondary, chartAccent, chartDanger, chartPrimary],
            borderWidth: 1
        }]
    },
    options: { responsive: true }
});

new Chart(document.getElementById('busiestChart'), {
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
        scales: { x: { beginAtZero: true, max: 100 } }
    }
});
</script>
<script>setTimeout(() => window.location.reload(), 60000);</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
