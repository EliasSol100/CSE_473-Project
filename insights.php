<?php
$pageTitle = 'Insights';
require_once __DIR__ . '/includes/page_payloads.php';
require_once __DIR__ . '/includes/live_collector.php';

$collectorIntervalMs = 10000;
try {
    $collectorIntervalMs = max(10000, ((int) (live_collector_config()['interval_seconds'] ?? 10)) * 1000);
} catch (Throwable) {
    $collectorIntervalMs = 10000;
}
$collectorIntervalSeconds = max(1, (int) round($collectorIntervalMs / 1000));
$insightsPayload = insights_page_payload();
$peak = $insightsPayload['peak'];
$dataset = $insightsPayload['dataset'];
$regMetrics = $insightsPayload['regression_metrics'];
$clsMetrics = $insightsPayload['classification_metrics'];

require_once __DIR__ . '/includes/header.php';
?>
<div class="container" data-live-collector-url="api/collect_live.php" data-live-insights-url="api/insights_summary.php" data-live-collector-interval="<?= h((string) $collectorIntervalMs) ?>">
    <div class="section-title">
        <div>
            <h2>Insights and model performance</h2>
            <p>Analytical highlights that explain utilization behavior and the current best available model performance summaries.</p>
        </div>
        <div class="tag-row section-tags">
            <span class="tag" data-insights-last-refresh>Latest network refresh: <?= h(display_datetime($insightsPayload['summary']['last_refresh'] ?? null)) ?></span>
            <span class="tag" data-live-collector-status>Auto sync every <?= h((string) $collectorIntervalSeconds) ?> seconds while this Insights page is open</span>
        </div>
    </div>

    <section class="info-grid">
        <article class="notice"><h3>Peak observed hour</h3><p class="muted">The highest average occupancy appears at <strong data-insights-peak-hour><?= h(sprintf('%02d:00', (int) ($peak['hour'] ?? 0))) ?></strong>, reaching <strong data-insights-peak-rate><?= h(format_percentage($peak['average_occupancy'] ?? 0)) ?></strong>.</p></article>
        <article class="notice"><h3>Coverage window</h3><p class="muted">Current records include <strong data-insights-observations><?= h(format_number($dataset['observations'] ?? 0)) ?></strong> observations from <strong data-insights-min-time><?= h(display_datetime($dataset['min_time'] ?? null)) ?></strong> to <strong data-insights-max-time><?= h(display_datetime($dataset['max_time'] ?? null)) ?></strong>.</p></article>
        <article class="notice"><h3>Classification context</h3><p class="muted">Average classification accuracy is <strong data-insights-avg-accuracy><?= h(format_percentage($insightsPayload['avg_accuracy'] ?? 0, 1)) ?></strong>. <span data-insights-classification-context><?= h($insightsPayload['classification_context_note'] ?? '') ?></span></p></article>
    </section>

    <section class="chart-grid">
        <article class="chart-card"><h3>Facilities with highest average occupancy</h3><canvas id="topAverageChart" height="160"></canvas></article>
        <article class="chart-card"><h3>Largest facilities by capacity</h3><canvas id="capacityChart" height="160"></canvas></article>
    </section>

    <section class="grid-two" style="margin-bottom:24px;">
        <article class="table-card">
            <h3>XGBoost regression performance (lowest RMSE)</h3>
            <p class="muted" data-insights-regression-note><?= h($insightsPayload['regression_note'] ?? '') ?></p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Facility</th><th>Samples</th><th>MAE</th><th>RMSE</th><th>R2</th></tr></thead>
                    <tbody data-insights-regression-body>
                        <?php if ($regMetrics === []): ?>
                            <tr><td colspan="5" class="empty-state">No live regression baseline metrics are available yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($regMetrics as $row): ?>
                                <tr>
                                    <td><?= h($row['facility_name']) ?></td>
                                    <td><?= h(format_number($row['sample_size'])) ?></td>
                                    <td><?= h(number_format((float) $row['mae'], 4)) ?></td>
                                    <td><?= h(number_format((float) $row['rmse'], 4)) ?></td>
                                    <td><?= $row['r2'] === null ? 'N/A' : h(number_format((float) $row['r2'], 3)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="table-card">
            <h3>XGBoost classification accuracy by facility</h3>
            <p class="muted" data-insights-classification-note><?= h($insightsPayload['classification_note'] ?? '') ?></p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Facility</th><th>Samples</th><th>Accuracy</th></tr></thead>
                    <tbody data-insights-classification-body>
                        <?php if ($clsMetrics === []): ?>
                            <tr><td colspan="3" class="empty-state">No live classification baseline metrics are available yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($clsMetrics as $row): ?>
                                <tr>
                                    <td><?= h($row['facility_name']) ?></td>
                                    <td><?= h(format_number($row['sample_size'])) ?></td>
                                    <td><?= h(format_percentage(((float) $row['accuracy']) * 100)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

</div>
<script>
window.insightsState = <?= json_encode($insightsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.insightsCharts = {};
window.insightsCharts.topAverage = new Chart(document.getElementById('topAverageChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($insightsPayload['top_average_labels']) ?>,
        datasets: [{
            label: 'Average occupancy %',
            data: <?= json_encode($insightsPayload['top_average_values']) ?>,
            borderWidth: 0,
            borderRadius: 8,
            backgroundColor: 'rgba(14, 94, 181, 0.82)'
        }]
    },
    options: { indexAxis: 'y', responsive: true, layout: { padding: { left: 14 } }, scales: { y: { ticks: { font: { size: 9 }, padding: 8, autoSkip: false } }, x: { beginAtZero: true, max: 100 } } }
});
window.insightsCharts.capacity = new Chart(document.getElementById('capacityChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($insightsPayload['capacity_labels']) ?>,
        datasets: [{
            label: 'Capacity',
            data: <?= json_encode($insightsPayload['capacity_values']) ?>,
            borderWidth: 0,
            borderRadius: 8,
            backgroundColor: 'rgba(14, 165, 168, 0.82)'
        }]
    },
    options: { indexAxis: 'y', responsive: true, layout: { padding: { left: 14 } }, scales: { y: { ticks: { font: { size: 9 }, padding: 8, autoSkip: false } }, x: { beginAtZero: true } } }
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
