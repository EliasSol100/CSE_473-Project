<?php
$pageTitle = 'Insights';
require_once __DIR__ . '/includes/header.php';
$peak = peak_hour();
$topAverage = top_average_occupancy(10);
$capacityLeaders = capacity_leaders(10);
$regMetrics = regression_metrics();
$clsMetrics = classification_metrics();
$dataset = dataset_overview();
$topAvgLabels = array_map(fn($row) => $row['facility_name'], array_slice($topAverage, 0, 8));
$topAvgValues = array_map(fn($row) => (float) $row['average_occupancy'], array_slice($topAverage, 0, 8));
$capLabels = array_map(fn($row) => $row['facility_name'], array_slice($capacityLeaders, 0, 8));
$capValues = array_map(fn($row) => (int) $row['capacity'], array_slice($capacityLeaders, 0, 8));
$avgAccuracy = 0;
if ($clsMetrics) $avgAccuracy = array_sum(array_map(fn($row) => (float) $row['accuracy'], $clsMetrics)) / count($clsMetrics) * 100;
?>
<div class="container">
    <div class="section-title"><div><h2>Insights and model observations</h2><p>Useful talking points for presenting the project to your teacher.</p></div></div>
    <section class="info-grid">
        <article class="notice"><h3>Peak observed hour</h3><p class="muted">The highest average occupancy in this sample appears at <strong><?= h(sprintf('%02d:00', (int) ($peak['hour'] ?? 0))) ?></strong>, with an average occupancy of <strong><?= h(format_percentage($peak['average_occupancy'] ?? 0)) ?></strong>.</p></article>
        <article class="notice"><h3>Dataset limitation</h3><p class="muted">This imported sample covers <strong><?= h(format_number($dataset['observations'] ?? 0)) ?></strong> rows from <strong><?= h(display_datetime($dataset['min_time'] ?? null)) ?></strong> to <strong><?= h(display_datetime($dataset['max_time'] ?? null)) ?></strong>. That means it is useful for a demo, but still limited in time span.</p></article>
        <article class="notice"><h3>Classification note</h3><p class="muted">Average classification accuracy is <strong><?= h(format_percentage($avgAccuracy, 1)) ?></strong>. This looks perfect, but it likely happens because the dataset is dominated by the “Available” class, so the classification task is easier than usual.</p></article>
    </section>
    <section class="chart-grid">
        <article class="chart-card"><h3>Facilities with highest average occupancy</h3><canvas id="topAverageChart" height="160"></canvas></article>
        <article class="chart-card"><h3>Largest facilities by capacity</h3><canvas id="capacityChart" height="160"></canvas></article>
    </section>
    <section class="grid-two" style="margin-bottom:24px;">
        <article class="table-card">
            <h3>Best regression metrics (lowest RMSE)</h3>
            <div class="table-wrap"><table><thead><tr><th>Facility</th><th>Samples</th><th>MAE</th><th>RMSE</th><th>R²</th></tr></thead><tbody><?php foreach (array_slice($regMetrics, 0, 10) as $row): ?><tr><td><?= h($row['facility_name']) ?></td><td><?= h(format_number($row['sample_size'])) ?></td><td><?= h(number_format((float) $row['mae'], 4)) ?></td><td><?= h(number_format((float) $row['rmse'], 4)) ?></td><td><?= $row['r2'] === null ? 'N/A' : h(number_format((float) $row['r2'], 3)) ?></td></tr><?php endforeach; ?></tbody></table></div>
        </article>
        <article class="table-card">
            <h3>Classification metrics by facility</h3>
            <div class="table-wrap"><table><thead><tr><th>Facility</th><th>Samples</th><th>Accuracy</th></tr></thead><tbody><?php foreach (array_slice($clsMetrics, 0, 10) as $row): ?><tr><td><?= h($row['facility_name']) ?></td><td><?= h(format_number($row['sample_size'])) ?></td><td><?= h(format_percentage(((float) $row['accuracy']) * 100)) ?></td></tr><?php endforeach; ?></tbody></table></div>
        </article>
    </section>
    <section class="notice"><h3>Suggested presentation explanation</h3><p class="muted">You can present this website as the final visualization layer of the Smart Parking project. First, data is collected and cleaned in Python. Then it is stored in a database. Finally, the PHP website makes the results easier to understand for non-technical viewers through charts, tables and short written insights.</p></section>
</div>
<script>
const topAvgLabels = <?= json_encode($topAvgLabels) ?>;
const topAvgValues = <?= json_encode($topAvgValues) ?>;
const capLabels = <?= json_encode($capLabels) ?>;
const capValues = <?= json_encode($capValues) ?>;
new Chart(document.getElementById('topAverageChart'), { type: 'bar', data: { labels: topAvgLabels, datasets: [{ label: 'Average occupancy %', data: topAvgValues, borderWidth: 1 }] }, options: { indexAxis: 'y', responsive: true, scales: { x: { beginAtZero: true, max: 100 } } } });
new Chart(document.getElementById('capacityChart'), { type: 'bar', data: { labels: capLabels, datasets: [{ label: 'Capacity', data: capValues, borderWidth: 1 }] }, options: { indexAxis: 'y', responsive: true, scales: { x: { beginAtZero: true } } } });
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
