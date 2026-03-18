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
    <div class="section-title"><div><h2>Dashboard overview</h2><p>Latest KPIs and visual summaries from the imported smart parking dataset.</p></div><span class="tag">Last refresh: <?= h(display_datetime($summary['last_refresh'] ?? null)) ?></span></div>
    <section class="kpi-grid">
        <article class="card"><h3>Facilities</h3><div class="metric"><?= h(format_number($summary['facilities_count'] ?? 0)) ?></div><p class="muted">Locations included in the monitoring website.</p></article>
        <article class="card"><h3>Occupied Now</h3><div class="metric"><?= h(format_number($summary['occupied_now'] ?? 0)) ?></div><p class="muted">Total occupied spaces from the latest reading of each facility.</p></article>
        <article class="card"><h3>Available Now</h3><div class="metric"><?= h(format_number($summary['available_now'] ?? 0)) ?></div><p class="muted">Estimated spaces still available in the latest readings.</p></article>
        <article class="card"><h3>Average Latest Occupancy</h3><div class="metric"><?= h(format_percentage($summary['avg_occupancy'] ?? 0)) ?></div><p class="muted">Average occupancy percentage across facilities.</p></article>
    </section>
    <section class="chart-grid">
        <article class="chart-card"><h3>Average occupancy by hour</h3><p class="muted">This chart highlights how the dataset behaves during the hours captured in the collection window.</p><canvas id="hourlyChart" height="140"></canvas></article>
        <article class="chart-card"><h3>Availability classes in latest snapshot</h3><p class="muted">Distribution of the latest availability classes currently stored in the database.</p><canvas id="availabilityChart" height="140"></canvas></article>
    </section>
    <section class="chart-card" style="margin-bottom:24px;"><h3>Top busiest facilities right now</h3><p class="muted">Facilities ordered by their latest occupancy percentage.</p><canvas id="busiestChart" height="120"></canvas></section>
    <section class="table-card">
        <div class="section-title"><div><h2>Latest facility table</h2><p>Sorted from highest occupancy to lowest occupancy.</p></div></div>
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
                            <td><a href="facilities.php?facility_id=<?= urlencode($row['facility_id']) ?>">View history</a></td>
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
new Chart(document.getElementById('hourlyChart'), { type: 'line', data: { labels: hourlyLabels, datasets: [{ label: 'Average occupancy %', data: hourlyValues, borderWidth: 3, tension: 0.25, fill: false }] }, options: { responsive: true, plugins: { legend: { display: true } }, scales: { y: { beginAtZero: true, max: 100 } } } });
new Chart(document.getElementById('availabilityChart'), { type: 'doughnut', data: { labels: distLabels, datasets: [{ data: distValues, borderWidth: 1 }] }, options: { responsive: true } });
new Chart(document.getElementById('busiestChart'), { type: 'bar', data: { labels: topLabels, datasets: [{ label: 'Latest occupancy %', data: topValues, borderWidth: 1 }] }, options: { indexAxis: 'y', responsive: true, scales: { x: { beginAtZero: true, max: 100 } } } });
</script>
<script>setTimeout(() => window.location.reload(), 60000);</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
