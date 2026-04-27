<?php

// XGBoost integration helpers: find latest runs, read predictions, and retrain when due.
const ML_MODEL_NAME = 'xgboost';
const ML_MODEL_REFRESH_SECONDS = 300;
const ML_MODEL_LOCK_FILE = __DIR__ . '/../logs/xgboost_training.lock';
const ML_MODEL_LOG_FILE = __DIR__ . '/../logs/xgboost_training.log';

function ml_model_snapshot_source(?string $snapshotSource = null): string
{
    $snapshotSource = strtolower(trim((string) ($snapshotSource ?? '')));
    if ($snapshotSource === 'live' || $snapshotSource === 'seed') {
        return $snapshotSource;
    }

    if (function_exists('snapshot_data_source')) {
        // Mirror the PHP page data source so model metrics match the visible dashboard data.
        $resolved = strtolower(trim((string) snapshot_data_source()));
        if ($resolved === 'live' || $resolved === 'seed') {
            return $resolved;
        }
    }

    return 'seed';
}

function ml_model_latest_run(?string $snapshotSource = null): ?array
{
    $snapshotSource = ml_model_snapshot_source($snapshotSource);
    // Predictions are stored by facility and horizon, e.g. [facility_id]["1"] for +1h.
    $connection = db();
    $stmt = $connection->prepare(
        "SELECT run_id, model_name, snapshot_source, run_status, trained_at, training_rows, validation_rows, feature_count, notes
         FROM model_runs
         WHERE model_name = ?
           AND snapshot_source = ?
           AND run_status = 'completed'
         ORDER BY trained_at DESC, run_id DESC
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $modelName = ML_MODEL_NAME;
    $stmt->bind_param('ss', $modelName, $snapshotSource);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? ($result->fetch_assoc() ?: null) : null;
    $stmt->close();

    return $row;
}

function ml_model_predictions_lookup(?string $snapshotSource = null): array
{
    static $cache = [];

    $snapshotSource = ml_model_snapshot_source($snapshotSource);
    if (isset($cache[$snapshotSource])) {
        return $cache[$snapshotSource];
    }

    $run = ml_model_latest_run($snapshotSource);
    if ($run === null) {
        $cache[$snapshotSource] = [];
        return $cache[$snapshotSource];
    }

    $connection = db();
    $stmt = $connection->prepare(
        "SELECT facility_id, hours_ahead, target_time, predicted_occupancy_rate, predicted_occupied, predicted_available, predicted_class
         FROM predictions
         WHERE run_id = ?
         ORDER BY facility_id ASC, hours_ahead ASC"
    );

    if (!$stmt) {
        $cache[$snapshotSource] = [];
        return $cache[$snapshotSource];
    }

    $runId = (int) $run['run_id'];
    $stmt->bind_param('i', $runId);
    $stmt->execute();
    $result = $stmt->get_result();
    $lookup = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $facilityId = (string) ($row['facility_id'] ?? '');
            $hoursAhead = (string) ((int) ($row['hours_ahead'] ?? 0));
            if ($facilityId === '' || $hoursAhead === '0') {
                continue;
            }

            $lookup[$facilityId][$hoursAhead] = [
                'hours_ahead' => (int) $hoursAhead,
                'target_time' => (string) ($row['target_time'] ?? ''),
                'predicted_occupancy_rate' => (float) ($row['predicted_occupancy_rate'] ?? 0),
                'predicted_occupied' => (int) ($row['predicted_occupied'] ?? 0),
                'predicted_available' => (int) ($row['predicted_available'] ?? 0),
                'predicted_class' => (string) ($row['predicted_class'] ?? 'Available'),
            ];
        }
    }

    $stmt->close();
    $cache[$snapshotSource] = $lookup;
    return $cache[$snapshotSource];
}

function ml_model_facility_metrics(?string $snapshotSource = null): array
{
    static $cache = [];

    $snapshotSource = ml_model_snapshot_source($snapshotSource);
    if (isset($cache[$snapshotSource])) {
        return $cache[$snapshotSource];
    }

    $run = ml_model_latest_run($snapshotSource);
    if ($run === null) {
        $cache[$snapshotSource] = [];
        return $cache[$snapshotSource];
    }

    $connection = db();
    $stmt = $connection->prepare(
        "SELECT f.facility_name, m.sample_size, m.mae, m.rmse, m.r2, m.accuracy
         FROM facility_metrics m
         INNER JOIN parking_facilities f ON f.facility_id = m.facility_id
         WHERE m.run_id = ?
         ORDER BY f.facility_name ASC"
    );

    if (!$stmt) {
        $cache[$snapshotSource] = [];
        return $cache[$snapshotSource];
    }

    $runId = (int) $run['run_id'];
    $stmt->bind_param('i', $runId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    $rows = array_values(array_filter(
        $rows,
        static fn(array $row): bool => !function_exists('facility_is_hidden') || !facility_is_hidden($row['facility_name'] ?? '')
    ));

    $cache[$snapshotSource] = $rows;
    return $cache[$snapshotSource];
}

function ml_model_is_available(?string $snapshotSource = null): bool
{
    return ml_model_latest_run($snapshotSource) !== null;
}

function ml_model_refresh_interval_seconds(): int
{
    $value = getenv('XGBOOST_TRAIN_INTERVAL_SECONDS');
    if (is_string($value) && trim($value) !== '' && ctype_digit(trim($value))) {
        return max(60, (int) trim($value));
    }

    return ML_MODEL_REFRESH_SECONDS;
}

function ml_model_refresh_if_due(?string $snapshotSource = null, bool $force = false): array
{
    $snapshotSource = ml_model_snapshot_source($snapshotSource);
    $latestRun = ml_model_latest_run($snapshotSource);
    $ageSeconds = null;

    if ($latestRun !== null && !empty($latestRun['trained_at'])) {
        $trainedAtTs = strtotime((string) $latestRun['trained_at']);
        if ($trainedAtTs !== false) {
            $ageSeconds = max(0, time() - $trainedAtTs);
        }
    }

    if (!$force && $ageSeconds !== null && $ageSeconds < ml_model_refresh_interval_seconds()) {
        return [
            'status' => 'fresh',
            'snapshot_source' => $snapshotSource,
            'age_seconds' => $ageSeconds,
        ];
    }

    // A lock file prevents overlapping training jobs from multiple browser syncs.
    if (is_file(ML_MODEL_LOCK_FILE) && (time() - (int) @filemtime(ML_MODEL_LOCK_FILE)) < 1800) {
        return [
            'status' => 'busy',
            'snapshot_source' => $snapshotSource,
        ];
    }

    @file_put_contents(ML_MODEL_LOCK_FILE, (string) getmypid());

    try {
        $python = ml_model_python_path();
        $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'train_xgboost.py';
        if (!is_file($script)) {
            return [
                'status' => 'missing_script',
                'snapshot_source' => $snapshotSource,
            ];
        }

        $command = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' --source ' . escapeshellarg($snapshotSource) . ' --json';
        $output = [];
        $exitCode = 1;
        exec($command . ' 2>&1', $output, $exitCode);
        $outputText = trim(implode(PHP_EOL, $output));
        ml_model_log('Train command [' . $snapshotSource . '] exit=' . $exitCode . ' output=' . $outputText);

        $decoded = json_decode($outputText, true);
        if (!is_array($decoded)) {
            // Python may print warnings before JSON, so recover the last valid JSON line.
            $lines = preg_split('/\r\n|\r|\n/', $outputText) ?: [];
            for ($index = count($lines) - 1; $index >= 0; $index--) {
                $candidate = trim((string) $lines[$index]);
                if ($candidate === '') {
                    continue;
                }
                $decoded = json_decode($candidate, true);
                if (is_array($decoded)) {
                    break;
                }
            }
        }
        if ($exitCode === 0 && is_array($decoded)) {
            return $decoded;
        }

        return [
            'status' => 'error',
            'snapshot_source' => $snapshotSource,
            'exit_code' => $exitCode,
            'output' => $outputText,
        ];
    } finally {
        @unlink(ML_MODEL_LOCK_FILE);
    }
}

function ml_model_python_path(): string
{
    $venvPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
    if (is_file($venvPath)) {
        return $venvPath;
    }

    return 'python';
}

function ml_model_log(string $message): void
{
    $timestamp = gmdate('Y-m-d H:i:s');
    @file_put_contents(ML_MODEL_LOG_FILE, '[' . $timestamp . '] ' . $message . PHP_EOL, FILE_APPEND);
}
