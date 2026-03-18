<?php
$pageTitle = 'Facilities';
require_once __DIR__ . '/includes/header.php';
$facilities = latest_snapshots();
$options = facility_options();
$selectedFacilityId = isset($_GET['facility_id']) ? trim((string) $_GET['facility_id']) : '';
$selectedSummary = $selectedFacilityId !== '' ? facility_summary($selectedFacilityId) : null;
$history = $selectedFacilityId !== '' ? facility_history($selectedFacilityId) : [];
$historyLabels = array_map(fn($row) => date('H:i', strtotime($row['recorded_at'])), $history);
$historyValues = array_map(fn($row) => (float) $row['occupancy_percent'], $history);
?>
<div class="container">
    <div class="section-title"><div><h2>Facility monitoring center</h2><p>Search and compare the latest status of each location, then drill into a selected facility timeline.</p></div></div>

    <section class="table-card" style="margin-bottom:24px;">
        <div class="filters">
            <input class="search-bar" type="text" placeholder="Search by facility name or ID..." data-facility-search>
            <form method="get">
                <select class="select-field" name="facility_id" onchange="this.form.submit()">
                    <option value="">Select a facility to view history</option>
                    <?php foreach ($options as $option): ?>
                        <option value="<?= h($option['facility_id']) ?>" <?= $selectedFacilityId === $option['facility_id'] ? 'selected' : '' ?>><?= h($option['facility_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead><tr><th>Facility ID</th><th>Facility Name</th><th>Capacity</th><th>Occupied</th><th>Available</th><th>Occupancy</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($facilities as $row): ?>
                        <?php $percent = (float) $row['occupancy_rate'] * 100; ?>
                        <tr data-facility-row data-search="<?= h(strtolower($row['facility_id'] . ' ' . $row['facility_name'])) ?>">
                            <td><?= h($row['facility_id']) ?></td>
                            <td><a href="facilities.php?facility_id=<?= urlencode($row['facility_id']) ?>"><?= h($row['facility_name']) ?></a></td>
                            <td><?= h(format_number($row['capacity'])) ?></td>
                            <td><?= h(format_number($row['occupied'])) ?></td>
                            <td><?= h(format_number($row['available'])) ?></td>
                            <td><?= h(format_percentage($percent)) ?></td>
                            <td><span class="status-pill <?= h(availability_badge_class($row['availability_class'])) ?>"><?= h($row['availability_class']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($selectedSummary): ?>
        <?php $selectedPercent = (float) $selectedSummary['occupancy_rate'] * 100; ?>
        <section class="grid-two">
            <article class="panel">
                <h3><?= h($selectedSummary['facility_name']) ?></h3>
                <p class="muted">Facility ID: <?= h($selectedSummary['facility_id']) ?> | Latest reading: <?= h(display_datetime($selectedSummary['recorded_at'])) ?></p>
                <div class="metric"><?= h(format_percentage($selectedPercent)) ?></div>
                <div class="progress"><span style="width: <?= max(0, min(100, $selectedPercent)) ?>%"></span></div>
                <div class="stat-list" style="margin-top:18px;">
                    <div class="stat-item"><span>Capacity</span><strong><?= h(format_number($selectedSummary['capacity'])) ?></strong></div>
                    <div class="stat-item"><span>Occupied</span><strong><?= h(format_number($selectedSummary['occupied'])) ?></strong></div>
                    <div class="stat-item"><span>Available</span><strong><?= h(format_number($selectedSummary['available'])) ?></strong></div>
                    <div class="stat-item"><span>Status</span><strong><?= h($selectedSummary['availability_class']) ?></strong></div>
                </div>
            </article>
            <article class="chart-card"><h3>Occupancy timeline for selected facility</h3><p class="muted">Recent occupancy percentage trend for this specific site.</p><canvas id="facilityHistoryChart" height="180"></canvas></article>
        </section>
    <?php elseif ($selectedFacilityId !== ''): ?>
        <section class="empty-state card">No timeline data was found for the selected facility.</section>
    <?php endif; ?>
</div>
<?php if ($selectedSummary): ?>
<script>
const facilityHistoryLabels = <?= json_encode($historyLabels) ?>;
const facilityHistoryValues = <?= json_encode($historyValues) ?>;
new Chart(document.getElementById('facilityHistoryChart'), {
    type: 'line',
    data: {
        labels: facilityHistoryLabels,
        datasets: [{
            label: 'Occupancy %',
            data: facilityHistoryValues,
            borderColor: '#0e5eb5',
            backgroundColor: 'rgba(14, 94, 181, 0.12)',
            borderWidth: 3,
            tension: 0.22,
            fill: true,
            pointRadius: 2
        }]
    },
    options: {
        responsive: true,
        scales: { y: { beginAtZero: true, max: 100 } }
    }
});
</script>
<?php endif; ?>
<script>setTimeout(() => window.location.reload(), 60000);</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
