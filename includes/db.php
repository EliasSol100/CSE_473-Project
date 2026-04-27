<?php
// Database bootstrap: loads configuration, opens MySQL, and applies small runtime migrations.
function app_config(): array
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/config.php';
        $localConfig = __DIR__ . '/config.local.php';
        if (file_exists($localConfig)) {
            $config = array_merge($config, require $localConfig);
        }
    }

    return $config;
}

function db(): mysqli
{
    static $connection = null;

    if ($connection instanceof mysqli) {
        return $connection;
    }

    $config = app_config();
    mysqli_report(MYSQLI_REPORT_OFF);

    // The app keeps one shared mysqli connection per request.
    $connection = @new mysqli(
        $config['db_host'],
        $config['db_user'],
        $config['db_pass'],
        $config['db_name'],
        (int) $config['db_port']
    );

    if ($connection->connect_error) {
        http_response_code(500);
        die('<h2>Database connection failed</h2><p>Please import <code>database/smart_parking_web.sql</code> into phpMyAdmin and check <code>includes/config.php</code>.</p><p>Error: ' . htmlspecialchars($connection->connect_error) . '</p>');
    }

    $connection->set_charset('utf8mb4');
    db_ensure_runtime_schema($connection);
    return $connection;
}

function db_ensure_runtime_schema(mysqli $connection): void
{
    static $bootstrapped = false;

    if ($bootstrapped) {
        return;
    }

    if (!db_table_exists($connection, 'occupancy_snapshots')) {
        $bootstrapped = true;
        return;
    }

    // Model tables are created at runtime so existing imports can support XGBoost runs.
    db_ensure_model_tables($connection);

    if (db_table_exists($connection, 'parking_facilities')) {
        if (!db_table_has_column($connection, 'parking_facilities', 'is_open_24_7')) {
            $connection->query(
                "ALTER TABLE parking_facilities
                 ADD COLUMN is_open_24_7 TINYINT(1) NULL DEFAULT NULL AFTER capacity"
            );
        }

        if (!db_table_has_column($connection, 'parking_facilities', 'operating_hours_json')) {
            $connection->query(
                "ALTER TABLE parking_facilities
                 ADD COLUMN operating_hours_json TEXT NULL AFTER is_open_24_7"
            );
        }
    }

    $addedSourceColumn = false;

    if (!db_table_has_column($connection, 'occupancy_snapshots', 'snapshot_source')) {
        $connection->query(
            "ALTER TABLE occupancy_snapshots
             ADD COLUMN snapshot_source VARCHAR(16) NOT NULL DEFAULT 'seed' AFTER month"
        );
        $addedSourceColumn = true;
    }

    if (!db_table_has_index($connection, 'occupancy_snapshots', 'idx_snapshots_source_time')) {
        $connection->query(
            "ALTER TABLE occupancy_snapshots
             ADD INDEX idx_snapshots_source_time (snapshot_source, recorded_at)"
        );
    }

    if (!db_table_has_index($connection, 'occupancy_snapshots', 'idx_snapshots_source_facility_time')) {
        $connection->query(
            "ALTER TABLE occupancy_snapshots
             ADD INDEX idx_snapshots_source_facility_time (snapshot_source, facility_id, recorded_at)"
        );
    }

    db_classify_snapshot_sources($connection, $addedSourceColumn);
    $bootstrapped = true;
}

function db_ensure_model_tables(mysqli $connection): void
{
    $connection->query(
        "CREATE TABLE IF NOT EXISTS model_runs (
            run_id INT AUTO_INCREMENT PRIMARY KEY,
            model_name VARCHAR(64) NOT NULL,
            snapshot_source VARCHAR(16) NOT NULL,
            run_status VARCHAR(16) NOT NULL DEFAULT 'completed',
            trained_at DATETIME NOT NULL,
            training_rows INT NOT NULL DEFAULT 0,
            validation_rows INT NOT NULL DEFAULT 0,
            feature_count INT NOT NULL DEFAULT 0,
            notes TEXT NULL,
            KEY idx_model_runs_lookup (model_name, snapshot_source, run_status, trained_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $connection->query(
        "CREATE TABLE IF NOT EXISTS facility_metrics (
            metric_id INT AUTO_INCREMENT PRIMARY KEY,
            run_id INT NOT NULL,
            facility_id VARCHAR(20) NOT NULL,
            sample_size INT NOT NULL,
            mae DECIMAL(10,6) NULL,
            rmse DECIMAL(10,6) NULL,
            r2 DECIMAL(10,6) NULL,
            accuracy DECIMAL(10,6) NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_facility_metrics_run (run_id, facility_id),
            KEY idx_facility_metrics_run (run_id),
            KEY idx_facility_metrics_facility (facility_id),
            CONSTRAINT fk_facility_metrics_run FOREIGN KEY (run_id) REFERENCES model_runs(run_id)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_facility_metrics_facility FOREIGN KEY (facility_id) REFERENCES parking_facilities(facility_id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $connection->query(
        "CREATE TABLE IF NOT EXISTS predictions (
            pred_id INT AUTO_INCREMENT PRIMARY KEY,
            run_id INT NOT NULL,
            facility_id VARCHAR(20) NOT NULL,
            hours_ahead TINYINT NOT NULL,
            target_time DATETIME NULL,
            predicted_occupancy_rate DECIMAL(7,4) NOT NULL,
            predicted_occupied INT NOT NULL,
            predicted_available INT NOT NULL,
            predicted_class VARCHAR(30) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_predictions_run (run_id, facility_id, hours_ahead),
            KEY idx_predictions_lookup (run_id, facility_id, hours_ahead),
            CONSTRAINT fk_predictions_run FOREIGN KEY (run_id) REFERENCES model_runs(run_id)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_predictions_facility FOREIGN KEY (facility_id) REFERENCES parking_facilities(facility_id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $connection->query(
        "CREATE TABLE IF NOT EXISTS model_artifacts (
            artifact_id INT AUTO_INCREMENT PRIMARY KEY,
            run_id INT NOT NULL,
            artifact_type VARCHAR(32) NOT NULL,
            horizon_hours TINYINT NULL,
            file_path VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_model_artifacts_run (run_id),
            CONSTRAINT fk_model_artifacts_run FOREIGN KEY (run_id) REFERENCES model_runs(run_id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function db_table_exists(mysqli $connection, string $table): bool
{
    $table = $connection->real_escape_string($table);
    $result = $connection->query("SHOW TABLES LIKE '{$table}'");
    if (!$result) {
        return false;
    }

    return $result->num_rows > 0;
}

function db_table_has_column(mysqli $connection, string $table, string $column): bool
{
    $table = $connection->real_escape_string($table);
    $column = $connection->real_escape_string($column);
    $result = $connection->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    if (!$result) {
        return false;
    }

    return $result->num_rows > 0;
}

function db_table_has_index(mysqli $connection, string $table, string $indexName): bool
{
    $table = $connection->real_escape_string($table);
    $indexName = $connection->real_escape_string($indexName);
    $result = $connection->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$indexName}'");
    if (!$result) {
        return false;
    }

    return $result->num_rows > 0;
}

function db_classify_snapshot_sources(mysqli $connection, bool $columnWasJustAdded): void
{
    $sourceCounts = db_snapshot_source_counts($connection);
    $totalRows = (int) ($sourceCounts['total_rows'] ?? 0);
    $liveRows = (int) ($sourceCounts['live_rows'] ?? 0);
    $seedRows = (int) ($sourceCounts['seed_rows'] ?? 0);

    if ($totalRows <= 0 || $liveRows > 0) {
        return;
    }

    $seedSnapshotPairs = db_seed_snapshot_pairs();
    $seedPairCount = count($seedSnapshotPairs);

    // When older databases lacked snapshot_source, infer seed rows from the SQL import file.
    if (!$columnWasJustAdded && !($seedRows === $totalRows && $totalRows > $seedPairCount && $seedPairCount > 0)) {
        return;
    }

    if ($seedPairCount <= 0 || $totalRows <= $seedPairCount) {
        return;
    }

    $connection->begin_transaction();

    try {
        $connection->query("UPDATE occupancy_snapshots SET snapshot_source = 'live'");

        foreach (array_chunk($seedSnapshotPairs, 400) as $chunk) {
            $pairsSql = [];
            foreach ($chunk as $pair) {
                $facilityId = $connection->real_escape_string($pair['facility_id']);
                $recordedAt = $connection->real_escape_string($pair['recorded_at']);
                $pairsSql[] = "('{$facilityId}', '{$recordedAt}')";
            }

            if ($pairsSql === []) {
                continue;
            }

            $connection->query(
                "UPDATE occupancy_snapshots
                 SET snapshot_source = 'seed'
                 WHERE (facility_id, recorded_at) IN (" . implode(', ', $pairsSql) . ')'
            );
        }

        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

function db_snapshot_source_counts(mysqli $connection): array
{
    $result = $connection->query(
        "SELECT
            COUNT(*) AS total_rows,
            SUM(CASE WHEN snapshot_source = 'live' THEN 1 ELSE 0 END) AS live_rows,
            SUM(CASE WHEN snapshot_source = 'seed' THEN 1 ELSE 0 END) AS seed_rows
         FROM occupancy_snapshots"
    );

    if (!$result) {
        return ['total_rows' => 0, 'live_rows' => 0, 'seed_rows' => 0];
    }

    return $result->fetch_assoc() ?: ['total_rows' => 0, 'live_rows' => 0, 'seed_rows' => 0];
}

function db_seed_snapshot_pairs(): array
{
    static $pairs = null;

    if (is_array($pairs)) {
        return $pairs;
    }

    $pairs = [];
    $sqlPath = dirname(__DIR__) . '/database/smart_parking_web.sql';
    if (!is_file($sqlPath)) {
        return $pairs;
    }

    $contents = @file_get_contents($sqlPath);
    if (!is_string($contents) || $contents === '') {
        return $pairs;
    }

    if (!preg_match_all("/\\('([^']+)'\\s*,\\s*'(\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2})'/", $contents, $matches, PREG_SET_ORDER)) {
        return $pairs;
    }

    $seen = [];
    foreach ($matches as $match) {
        $facilityId = (string) ($match[1] ?? '');
        $recordedAt = (string) ($match[2] ?? '');
        if ($facilityId === '' || $recordedAt === '') {
            continue;
        }

        $key = $facilityId . '|' . $recordedAt;
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $pairs[] = [
            'facility_id' => $facilityId,
            'recorded_at' => $recordedAt,
        ];
    }

    return $pairs;
}
