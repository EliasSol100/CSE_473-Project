<?php
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
