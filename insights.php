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
if ($clsMetrics) {
    $avgAccuracy = array_sum(array_map(fn($row) => (float) $row['accuracy'], $clsMetrics)) / count($clsMetrics) * 100;
}
?>
<div class="container">
    <div class="section-title"><div><h2>Insights and model performance</h2><p>Analytical highlights that explain utilization behavior and baseline model quality.</p></div></div>

    <section class="info-grid">
        <article class="notice"><h3>Peak observed hour</h3><p class="muted">The highest average occupancy appears at <strong><?= h(sprintf('%02d:00', (int) ($peak['hour'] ?? 0))) ?></strong>, reaching <strong><?= h(format_percentage($peak['average_occupancy'] ?? 0)) ?></strong>.</p></article>
        <article class="notice"><h3>Coverage window</h3><p class="muted">Current records include <strong><?= h(format_number($dataset['observations'] ?? 0)) ?></strong> observations from <strong><?= h(display_datetime($dataset['min_time'] ?? null)) ?></strong> to <strong><?= h(display_datetime($dataset['max_time'] ?? null)) ?></strong>.</p></article>
        <article class="notice"><h3>Classification context</h3><p class="muted">Average classification accuracy is <strong><?= h(format_percentage($avgAccuracy, 1)) ?></strong>. Strong results can reflect class imbalance, so this metric should be interpreted with class distribution in mind.</p></article>
    </section>

    <section class="chart-grid">
        <article class="chart-card"><h3>Facilities with highest average occupancy</h3><canvas id="topAverageChart" height="160"></canvas></article>
        <article class="chart-card"><h3>Largest facilities by capacity</h3><canvas id="capacityChart" height="160"></canvas></article>
    </section>

    <section class="grid-two" style="margin-bottom:24px;">
        <article class="table-card">
            <h3>Top regression performance (lowest RMSE)</h3>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Facility</th><th>Samples</th><th>MAE</th><th>RMSE</th><th>R2</th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($regMetrics, 0, 10) as $row): ?>
                            <tr>
                                <td><?= h($row['facility_name']) ?></td>
                                <td><?= h(format_number($row['sample_size'])) ?></td>
                                <td><?= h(number_format((float) $row['mae'], 4)) ?></td>
                                <td><?= h(number_format((float) $row['rmse'], 4)) ?></td>
                                <td><?= $row['r2'] === null ? 'N/A' : h(number_format((float) $row['r2'], 3)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="table-card">
            <h3>Classification accuracy by facility</h3>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Facility</th><th>Samples</th><th>Accuracy</th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($clsMetrics, 0, 10) as $row): ?>
                            <tr>
                                <td><?= h($row['facility_name']) ?></td>
                                <td><?= h(format_number($row['sample_size'])) ?></td>
                                <td><?= h(format_percentage(((float) $row['accuracy']) * 100)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <section class="notice"><h3>How to read this page</h3><p class="muted">Use these metrics as decision support: utilization trends identify demand pressure, while model scores indicate how reliably the current feature set predicts occupancy behavior.</p></section>
</div>
<script>
const topAvgLabels = <?= json_encode($topAvgLabels) ?>;
const topAvgValues = <?= json_encode($topAvgValues) ?>;
const capLabels = <?= json_encode($capLabels) ?>;
const capValues = <?= json_encode($capValues) ?>;
new Chart(document.getElementById('topAverageChart'), {
    type: 'bar',
    data: {
        labels: topAvgLabels,
        datasets: [{
            label: 'Average occupancy %',
            data: topAvgValues,
            borderWidth: 0,
            borderRadius: 8,
            backgroundColor: 'rgba(14, 94, 181, 0.82)'
        }]
    },
    options: { indexAxis: 'y', responsive: true, scales: { x: { beginAtZero: true, max: 100 } } }
});
new Chart(document.getElementById('capacityChart'), {
    type: 'bar',
    data: {
        labels: capLabels,
        datasets: [{
            label: 'Capacity',
            data: capValues,
            borderWidth: 0,
            borderRadius: 8,
            backgroundColor: 'rgba(14, 165, 168, 0.82)'
        }]
    },
    options: { indexAxis: 'y', responsive: true, scales: { x: { beginAtZero: true } } }
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
