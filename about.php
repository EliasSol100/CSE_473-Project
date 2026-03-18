<?php
$pageTitle = 'About';
require_once __DIR__ . '/includes/header.php';
$dataset = dataset_overview();
?>
<div class="container">
    <section class="hero">
        <div>
            <h1>About the project</h1>
            <p>The original repository is a complete data science system for NSW smart parking data. This website keeps the final result simple and presentation-friendly, while still allowing the original Python collector to push live data into the MySQL database used by the PHP frontend.</p>
            <div class="tag-row"><span class="tag">PHP + MySQL + XAMPP</span><span class="tag">Python live collector</span><span class="tag">Historical + live snapshots</span></div>
        </div>
        <div class="panel">
            <h3>Imported sample window</h3>
            <div class="stat-list">
                <div class="stat-item"><span>First record</span><strong><?= h(display_datetime($dataset['min_time'] ?? null)) ?></strong></div>
                <div class="stat-item"><span>Last record</span><strong><?= h(display_datetime($dataset['max_time'] ?? null)) ?></strong></div>
                <div class="stat-item"><span>Processed observations</span><strong><?= h(format_number($dataset['observations'] ?? 0)) ?></strong></div>
            </div>
        </div>
    </section>
    <section class="grid-two" style="margin-bottom:24px;">
        <article class="panel"><h3>Website pages</h3><div class="stat-list"><div class="stat-item"><span>Home</span><strong>Project summary and fast introduction</strong></div><div class="stat-item"><span>Dashboard</span><strong>KPIs, charts and current facility table</strong></div><div class="stat-item"><span>Facilities</span><strong>Facility search and one-facility history chart</strong></div><div class="stat-item"><span>Insights</span><strong>Peak hours, capacity leaders and model metrics</strong></div><div class="stat-item"><span>About</span><strong>Architecture, stack and academic explanation</strong></div></div></article>
        <article class="panel"><h3>Architecture in simple words</h3><div class="stat-list"><div class="stat-item"><span>1. Live collection</span><strong>Python fetches NSW parking data into MySQL</strong></div><div class="stat-item"><span>2. Historical layer</span><strong>Processed CSV data can remain for demo/training context</strong></div><div class="stat-item"><span>3. Modeling</span><strong>Regression and classification metrics are generated</strong></div><div class="stat-item"><span>4. Website layer</span><strong>PHP reads MySQL tables and shows the newest rows</strong></div></div></article>
    </section>
    <section class="grid-two" style="margin-bottom:24px;">
        <article class="table-card"><h3>Main database tables in this website</h3><div class="table-wrap"><table><thead><tr><th>Table</th><th>Purpose</th></tr></thead><tbody><tr><td>parking_facilities</td><td>Facility name, coordinates and total capacity.</td></tr><tr><td>occupancy_snapshots</td><td>Time-series observations for occupied spaces, available spaces and occupancy rate.</td></tr><tr><td>model_regression_metrics</td><td>Regression performance values such as MAE, RMSE and R².</td></tr><tr><td>model_classification_metrics</td><td>Classification accuracy by facility.</td></tr></tbody></table></div></article>
        <article class="table-card"><h3>Why this simpler website is a good choice</h3><div class="table-wrap"><table><thead><tr><th>Reason</th><th>Benefit</th></tr></thead><tbody><tr><td>Easy to explain</td><td>The teacher can understand the idea in a few minutes.</td></tr><tr><td>Professional enough</td><td>It still looks like a complete system, not just raw code.</td></tr><tr><td>Good for GitHub</td><td>The repository will be organised and presentation-ready.</td></tr><tr><td>Compatible with XAMPP</td><td>It runs locally with familiar PHP/MySQL tools and a Python collector.</td></tr></tbody></table></div></article>
    </section>
    <?php if (file_exists(__DIR__ . '/docs/erd-smart-parking.jpg')): ?>
        <section class="chart-card"><h3>Existing ERD image from the original project</h3><p class="muted">You can keep this in the repository as supporting material for the report or presentation.</p><img src="docs/erd-smart-parking.jpg" alt="Smart Parking ERD"></section>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
