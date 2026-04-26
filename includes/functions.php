<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ml_model.php';

function site_title(string $pageTitle = ''): string
{
    $site = app_config()['site_name'] ?? 'Smart Parking Web';
    return $pageTitle ? $pageTitle . ' | ' . $site : $site;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function asset_url(string $path): string
{
    $relativePath = ltrim($path, '/\\');
    $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);

    if (is_file($absolutePath)) {
        return $relativePath . '?v=' . filemtime($absolutePath);
    }

    return $relativePath;
}

function current_page(): string
{
    return basename($_SERVER['PHP_SELF'] ?? 'index.php');
}

function nav_active(string $page): string
{
    return current_page() === $page ? 'active' : '';
}

function fetch_all_assoc(string $sql): array
{
    $result = db()->query($sql);
    if (!$result) {
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

function fetch_one_assoc(string $sql): ?array
{
    $result = db()->query($sql);
    if (!$result) {
        return null;
    }
    $row = $result->fetch_assoc();
    return $row ?: null;
}

function live_collector_recently_active(int $maxAgeSeconds = 180): bool
{
    $stateFile = __DIR__ . '/../logs/live_collector_state.json';
    if (!is_file($stateFile)) {
        return false;
    }

    $contents = @file_get_contents($stateFile);
    if (!is_string($contents) || trim($contents) === '') {
        return false;
    }

    $state = json_decode($contents, true);
    if (!is_array($state)) {
        return false;
    }

    $lastCompletedAtTs = (int) ($state['last_completed_at_ts'] ?? 0);
    if ($lastCompletedAtTs <= 0) {
        return false;
    }

    return (time() - $lastCompletedAtTs) <= max(30, $maxAgeSeconds);
}

function insights_metrics_source(): string
{
    return snapshot_data_source() === 'live' ? 'live' : 'sql';
}

function snapshot_data_source(): string
{
    static $source = null;

    if ($source !== null) {
        return $source;
    }

    if (!live_collector_recently_active()) {
        $source = 'seed';
        return $source;
    }

    $hasLiveRows = fetch_one_assoc("SELECT 1 AS present FROM occupancy_snapshots WHERE snapshot_source = 'live' LIMIT 1");
    $source = $hasLiveRows ? 'live' : 'seed';
    return $source;
}

function snapshot_source_condition(string $alias = ''): string
{
    $qualified = $alias !== '' ? $alias . '.snapshot_source' : 'snapshot_source';
    $source = snapshot_data_source() === 'live' ? 'live' : 'seed';
    return $qualified . " = '" . $source . "'";
}

function snapshot_freshness_condition(string $alias = ''): string
{
    if (snapshot_data_source() !== 'live') {
        return '';
    }

    $qualified = $alias !== '' ? $alias . '.recorded_at' : 'recorded_at';
    return " AND {$qualified} >= (
        SELECT DATE_SUB(MAX(recorded_at), INTERVAL 30 MINUTE)
        FROM occupancy_snapshots
        WHERE snapshot_source = 'live'
    )";
}

function facility_is_hidden(?string $facilityName): bool
{
    $name = strtolower(trim((string) $facilityName));
    return $name !== '' && str_contains($name, 'historical only');
}

function occupancy_availability_class($available, $occupancyRate): string
{
    $availableCount = is_numeric($available) ? (int) $available : null;
    $rate = (float) $occupancyRate;

    if ($availableCount !== null && $availableCount <= 0) {
        return 'Full';
    }

    if ($rate >= 0.70) {
        return 'Limited';
    }

    return 'Available';
}

function operating_hours_text_is_24_7(string $value): bool
{
    $text = strtolower(trim($value));
    if ($text === '') {
        return false;
    }

    $text = preg_replace('/\s+/', ' ', $text) ?? $text;

    return str_contains($text, '24/7')
        || str_contains($text, '24 7')
        || str_contains($text, '24 hours')
        || str_contains($text, 'open all day')
        || str_contains($text, 'always open');
}

function facility_hours_overrides(): array
{
    static $overrides = null;

    if (is_array($overrides)) {
        return $overrides;
    }

    $path = __DIR__ . '/facility_hours_overrides.php';
    if (!is_file($path)) {
        $overrides = [];
        return $overrides;
    }

    $data = require $path;
    $overrides = is_array($data) ? $data : [];
    return $overrides;
}

function facility_override_hours(string $facilityId): ?array
{
    $facilityId = trim($facilityId);
    if ($facilityId === '') {
        return null;
    }

    $overrides = facility_hours_overrides();
    if (!isset($overrides[$facilityId]) || !is_array($overrides[$facilityId])) {
        return null;
    }

    $hours = trim((string) ($overrides[$facilityId]['hours'] ?? ''));
    if ($hours === '') {
        return null;
    }

    $isOpen247 = array_key_exists('is_open_24_7', $overrides[$facilityId])
        ? ((int) $overrides[$facilityId]['is_open_24_7'] === 1 ? 1 : 0)
        : (operating_hours_text_is_24_7($hours) ? 1 : 0);

    return [
        'is_open_24_7' => $isOpen247,
        'hours' => $hours,
    ];
}

function normalize_snapshot_row(array $row): array
{
    $row['availability_class'] = occupancy_availability_class(
        $row['available'] ?? null,
        $row['occupancy_rate'] ?? 0
    );

    return $row;
}

function dataset_overview(): array
{
    $condition = snapshot_source_condition('s');
    return fetch_one_assoc("
        SELECT MIN(recorded_at) AS min_time, MAX(recorded_at) AS max_time, COUNT(*) AS observations
        FROM occupancy_snapshots s
        INNER JOIN parking_facilities f ON f.facility_id = s.facility_id
        WHERE {$condition}
          AND LOWER(f.facility_name) NOT LIKE '%historical only%'
    ") ?? ['min_time' => null, 'max_time' => null, 'observations' => 0];
}

function summary_metrics(): array
{
    $latest = latest_snapshots();
    if ($latest === []) {
        return [
            'facilities_count' => 0,
            'total_capacity' => 0,
            'occupied_now' => 0,
            'available_now' => 0,
            'avg_occupancy' => 0,
            'last_refresh' => null,
            'busiest_name' => null,
            'busiest_rate' => 0,
        ];
    }

    $facilitiesCount = count($latest);
    $totalCapacity = 0;
    $occupiedNow = 0;
    $availableNow = 0;
    $occupancyTotal = 0.0;
    $lastRefresh = null;
    $busiestName = null;
    $busiestRate = 0.0;

    foreach ($latest as $row) {
        $totalCapacity += (int) ($row['capacity'] ?? 0);
        $occupiedNow += (int) ($row['occupied'] ?? 0);
        $availableNow += (int) ($row['available'] ?? 0);
        $occupancyRate = (float) ($row['occupancy_rate'] ?? 0);
        $occupancyTotal += $occupancyRate;

        $recordedAt = (string) ($row['recorded_at'] ?? '');
        if ($recordedAt !== '' && ($lastRefresh === null || $recordedAt > $lastRefresh)) {
            $lastRefresh = $recordedAt;
        }

        if ($busiestName === null || $occupancyRate > $busiestRate) {
            $busiestName = (string) ($row['facility_name'] ?? '');
            $busiestRate = $occupancyRate;
        }
    }

    return [
        'facilities_count' => $facilitiesCount,
        'total_capacity' => $totalCapacity,
        'occupied_now' => $occupiedNow,
        'available_now' => $availableNow,
        'avg_occupancy' => $facilitiesCount > 0 ? $occupancyTotal * 100 / $facilitiesCount : 0,
        'last_refresh' => $lastRefresh,
        'busiest_name' => $busiestName,
        'busiest_rate' => $busiestRate * 100,
    ];
}

function latest_snapshots(): array
{
    $outerCondition = snapshot_source_condition('s');
    $innerCondition = snapshot_source_condition();
    $innerFreshnessCondition = snapshot_freshness_condition();

    $sql = "
        SELECT s.facility_id, f.facility_name, f.latitude, f.longitude, f.capacity, f.is_open_24_7, f.operating_hours_json,
               s.recorded_at, s.occupied, s.available, s.occupancy_rate, s.availability_class
        FROM occupancy_snapshots s
        INNER JOIN (
            SELECT facility_id, MAX(recorded_at) AS max_recorded_at
            FROM occupancy_snapshots
            WHERE {$innerCondition}
              {$innerFreshnessCondition}
            GROUP BY facility_id
        ) latest_map
            ON s.facility_id = latest_map.facility_id
           AND s.recorded_at = latest_map.max_recorded_at
        INNER JOIN parking_facilities f ON f.facility_id = s.facility_id
        WHERE {$outerCondition}
        ORDER BY s.occupancy_rate DESC, f.facility_name ASC
    ";

    $rows = array_map('normalize_snapshot_row', fetch_all_assoc($sql));
    $rows = array_values(array_filter(
        $rows,
        fn(array $row) => !facility_is_hidden($row['facility_name'] ?? '')
    ));

    usort(
        $rows,
        fn(array $left, array $right) => ((float) ($right['occupancy_rate'] ?? 0) <=> (float) ($left['occupancy_rate'] ?? 0))
            ?: strcmp((string) ($left['facility_name'] ?? ''), (string) ($right['facility_name'] ?? ''))
    );

    return $rows;
}

function parking_timezone(): DateTimeZone
{
    static $timezone = null;

    if (!$timezone instanceof DateTimeZone) {
        $timezone = new DateTimeZone('Australia/Sydney');
    }

    return $timezone;
}

function parking_forecast_windows(): array
{
    $now = new DateTimeImmutable('now', parking_timezone());
    $horizons = [];

    foreach ([1, 2, 3] as $hoursAhead) {
        $targetPoint = $now->modify('+' . $hoursAhead . ' hours');
        $targetStart = $targetPoint->setTime((int) $targetPoint->format('G'), 0, 0);
        $targetEnd = $targetStart->modify('+1 hour');

        $horizons[(string) $hoursAhead] = [
            'hours_ahead' => $hoursAhead,
            'start' => $targetStart,
            'end' => $targetEnd,
            'hour' => (int) $targetStart->format('G'),
            'is_weekend' => ((int) $targetStart->format('N')) >= 6 ? 1 : 0,
            'label' => '+' . $hoursAhead . 'h',
            'window_label' => $targetStart->format('H:i') . ' - ' . $targetEnd->format('H:i'),
            'detail_label' => '+' . $hoursAhead . 'h forecast (' . $targetStart->format('H:i') . ' - ' . $targetEnd->format('H:i') . ')',
        ];
    }

    return [
        'timezone' => parking_timezone()->getName(),
        'now_label' => $now->format('d M Y, H:i'),
        'horizons' => $horizons,
    ];
}

function facility_hourly_predictions(array $latestRows = []): array
{
    $snapshotSource = snapshot_data_source();
    $modelPredictions = ml_model_predictions_lookup($snapshotSource);
    if ($modelPredictions !== []) {
        return facility_hourly_predictions_from_model($latestRows, $modelPredictions);
    }

    return facility_hourly_predictions_fallback($latestRows);
}

function facility_hourly_predictions_from_model(array $latestRows, array $modelPredictions): array
{
    $windows = parking_forecast_windows();
    $rows = $latestRows !== [] ? $latestRows : latest_snapshots();

    if ($rows === []) {
        return [
            'windows' => $windows,
            'predictions' => [],
            'summary' => [
                'horizon_1h_available_total' => 0,
                'horizon_2h_available_total' => 0,
                'horizon_3h_available_total' => 0,
                'open_24_7_count' => 0,
                'limited_hours_count' => 0,
                'unknown_hours_count' => 0,
            ],
        ];
    }

    $predictions = [];
    $horizonAvailableTotals = ['1' => 0, '2' => 0, '3' => 0];
    $open247Count = 0;
    $limitedHoursCount = 0;
    $unknownHoursCount = 0;

    foreach ($rows as $row) {
        $facilityId = (string) ($row['facility_id'] ?? '');
        $facilityName = (string) ($row['facility_name'] ?? '');
        $operatingMeta = facility_operating_meta($row);

        if ($operatingMeta['is_open_24_7'] === 1) {
            $open247Count++;
        } elseif ($operatingMeta['is_open_24_7'] === 0) {
            $limitedHoursCount++;
        } else {
            $unknownHoursCount++;
        }

        $facilityPredictionRows = [];
        foreach (['1', '2', '3'] as $horizonKey) {
            $pred = is_array($modelPredictions[$facilityId][$horizonKey] ?? null)
                ? $modelPredictions[$facilityId][$horizonKey]
                : null;

            if ($pred === null) {
                continue;
            }

            $facilityPredictionRows[$horizonKey] = [
                'hour' => (int) (($windows['horizons'][$horizonKey]['hour'] ?? 0)),
                'predicted_occupancy_rate' => (float) ($pred['predicted_occupancy_rate'] ?? 0),
                'predicted_occupied' => (int) ($pred['predicted_occupied'] ?? 0),
                'predicted_available' => (int) ($pred['predicted_available'] ?? 0),
                'predicted_class' => (string) ($pred['predicted_class'] ?? 'Available'),
                'is_open_24_7' => $operatingMeta['is_open_24_7'],
                'hours_note' => $operatingMeta['note'],
                'target_time' => (string) ($pred['target_time'] ?? ''),
            ];
            $horizonAvailableTotals[$horizonKey] += (int) ($pred['predicted_available'] ?? 0);
        }

        $predictions[$facilityId] = [
            'facility_id' => $facilityId,
            'facility_name' => $facilityName,
            'is_open_24_7' => $operatingMeta['is_open_24_7'],
            'operating_hours_note' => $operatingMeta['note'],
            'prediction_model' => 'xgboost',
            'horizons' => $facilityPredictionRows,
            'horizon_1h' => $facilityPredictionRows['1'] ?? null,
            'horizon_2h' => $facilityPredictionRows['2'] ?? null,
            'horizon_3h' => $facilityPredictionRows['3'] ?? null,
        ];
    }

    return [
        'windows' => [
            'timezone' => (string) ($windows['timezone'] ?? 'Australia/Sydney'),
            'now_label' => (string) ($windows['now_label'] ?? ''),
            'horizon_1h_label' => (string) (($windows['horizons']['1']['label'] ?? '+1h')),
            'horizon_2h_label' => (string) (($windows['horizons']['2']['label'] ?? '+2h')),
            'horizon_3h_label' => (string) (($windows['horizons']['3']['label'] ?? '+3h')),
            'horizon_1h_window_label' => (string) (($windows['horizons']['1']['window_label'] ?? '')),
            'horizon_2h_window_label' => (string) (($windows['horizons']['2']['window_label'] ?? '')),
            'horizon_3h_window_label' => (string) (($windows['horizons']['3']['window_label'] ?? '')),
            'horizon_1h_detail_label' => (string) (($windows['horizons']['1']['detail_label'] ?? '+1h forecast')),
            'horizon_2h_detail_label' => (string) (($windows['horizons']['2']['detail_label'] ?? '+2h forecast')),
            'horizon_3h_detail_label' => (string) (($windows['horizons']['3']['detail_label'] ?? '+3h forecast')),
        ],
        'predictions' => $predictions,
        'summary' => [
            'horizon_1h_available_total' => $horizonAvailableTotals['1'],
            'horizon_2h_available_total' => $horizonAvailableTotals['2'],
            'horizon_3h_available_total' => $horizonAvailableTotals['3'],
            'open_24_7_count' => $open247Count,
            'limited_hours_count' => $limitedHoursCount,
            'unknown_hours_count' => $unknownHoursCount,
        ],
    ];
}

function facility_hourly_predictions_fallback(array $latestRows = []): array
{
    $windows = parking_forecast_windows();
    $rows = $latestRows !== [] ? $latestRows : latest_snapshots();

    if ($rows === []) {
        return [
            'windows' => $windows,
            'predictions' => [],
            'summary' => [
                'horizon_1h_available_total' => 0,
                'horizon_2h_available_total' => 0,
                'horizon_3h_available_total' => 0,
                'open_24_7_count' => 0,
                'limited_hours_count' => 0,
                'unknown_hours_count' => 0,
            ],
        ];
    }

    $condition = snapshot_source_condition();
    $facilityHourRows = fetch_all_assoc(" 
        SELECT facility_id, hour, is_weekend, AVG(occupancy_rate) AS avg_rate
        FROM occupancy_snapshots
        WHERE {$condition}
        GROUP BY facility_id, hour, is_weekend
    ");
    $facilityHourAnyRows = fetch_all_assoc(" 
        SELECT facility_id, hour, AVG(occupancy_rate) AS avg_rate
        FROM occupancy_snapshots
        WHERE {$condition}
        GROUP BY facility_id, hour
    ");

    $globalHourly = [];
    foreach (hourly_average_occupancy() as $row) {
        $hour = (int) ($row['hour'] ?? 0);
        $globalHourly[$hour] = max(0.0, min(1.0, ((float) ($row['average_occupancy'] ?? 0)) / 100));
    }

    $facilityHourMap = [];
    foreach ($facilityHourRows as $row) {
        $facilityId = (string) ($row['facility_id'] ?? '');
        $hour = (int) ($row['hour'] ?? 0);
        $isWeekend = (int) ($row['is_weekend'] ?? 0);
        $key = $facilityId . '|' . $hour . '|' . $isWeekend;
        $facilityHourMap[$key] = (float) ($row['avg_rate'] ?? 0);
    }

    $facilityHourAnyMap = [];
    foreach ($facilityHourAnyRows as $row) {
        $facilityId = (string) ($row['facility_id'] ?? '');
        $hour = (int) ($row['hour'] ?? 0);
        $key = $facilityId . '|' . $hour;
        $facilityHourAnyMap[$key] = (float) ($row['avg_rate'] ?? 0);
    }

    $predictions = [];
    $horizonAvailableTotals = [
        '1' => 0,
        '2' => 0,
        '3' => 0,
    ];
    $open247Count = 0;
    $limitedHoursCount = 0;
    $unknownHoursCount = 0;

    foreach ($rows as $row) {
        $facilityId = (string) ($row['facility_id'] ?? '');
        $facilityName = (string) ($row['facility_name'] ?? '');
        $capacity = max(0, (int) ($row['capacity'] ?? 0));
        $currentRate = max(0.0, min(1.0, (float) ($row['occupancy_rate'] ?? 0)));

        $operatingMeta = facility_operating_meta($row);
        if ($operatingMeta['is_open_24_7'] === 1) {
            $open247Count++;
        } elseif ($operatingMeta['is_open_24_7'] === 0) {
            $limitedHoursCount++;
        } else {
            $unknownHoursCount++;
        }

        $facilityHorizonPredictions = [];
        foreach (($windows['horizons'] ?? []) as $horizonKey => $horizon) {
            $forecast = facility_predict_window(
                $facilityId,
                $capacity,
                $currentRate,
                (int) ($horizon['hour'] ?? 0),
                (int) ($horizon['is_weekend'] ?? 0),
                $facilityHourMap,
                $facilityHourAnyMap,
                $globalHourly,
                $operatingMeta
            );

            $facilityHorizonPredictions[$horizonKey] = $forecast;
            $horizonAvailableTotals[$horizonKey] += (int) ($forecast['predicted_available'] ?? 0);
        }

        $predictions[$facilityId] = [
            'facility_id' => $facilityId,
            'facility_name' => $facilityName,
            'is_open_24_7' => $operatingMeta['is_open_24_7'],
            'operating_hours_note' => $operatingMeta['note'],
            'horizons' => $facilityHorizonPredictions,
            'horizon_1h' => $facilityHorizonPredictions['1'] ?? null,
            'horizon_2h' => $facilityHorizonPredictions['2'] ?? null,
            'horizon_3h' => $facilityHorizonPredictions['3'] ?? null,
        ];
    }

    return [
        'windows' => [
            'timezone' => (string) ($windows['timezone'] ?? 'Australia/Sydney'),
            'now_label' => (string) ($windows['now_label'] ?? ''),
            'horizon_1h_label' => (string) (($windows['horizons']['1']['label'] ?? '+1h')),
            'horizon_2h_label' => (string) (($windows['horizons']['2']['label'] ?? '+2h')),
            'horizon_3h_label' => (string) (($windows['horizons']['3']['label'] ?? '+3h')),
            'horizon_1h_window_label' => (string) (($windows['horizons']['1']['window_label'] ?? '')),
            'horizon_2h_window_label' => (string) (($windows['horizons']['2']['window_label'] ?? '')),
            'horizon_3h_window_label' => (string) (($windows['horizons']['3']['window_label'] ?? '')),
            'horizon_1h_detail_label' => (string) (($windows['horizons']['1']['detail_label'] ?? '+1h forecast')),
            'horizon_2h_detail_label' => (string) (($windows['horizons']['2']['detail_label'] ?? '+2h forecast')),
            'horizon_3h_detail_label' => (string) (($windows['horizons']['3']['detail_label'] ?? '+3h forecast')),
        ],
        'predictions' => $predictions,
        'summary' => [
            'horizon_1h_available_total' => $horizonAvailableTotals['1'],
            'horizon_2h_available_total' => $horizonAvailableTotals['2'],
            'horizon_3h_available_total' => $horizonAvailableTotals['3'],
            'open_24_7_count' => $open247Count,
            'limited_hours_count' => $limitedHoursCount,
            'unknown_hours_count' => $unknownHoursCount,
        ],
    ];
}

function facility_operating_meta(array $row): array
{
    $facilityId = trim((string) ($row['facility_id'] ?? ''));
    $override = facility_override_hours($facilityId);
    if (is_array($override)) {
        $label = $override['is_open_24_7'] === 1
            ? 'Open 24/7'
            : ((string) $override['hours'] !== '' ? (string) $override['hours'] : 'Limited hours');

        return [
            'is_open_24_7' => $override['is_open_24_7'],
            'note' => $label,
        ];
    }

    $operatingHoursRaw = isset($row['operating_hours_json']) && is_string($row['operating_hours_json'])
        ? trim($row['operating_hours_json'])
        : '';
    $operatingHoursData = $operatingHoursRaw !== '' ? json_decode($operatingHoursRaw, true) : null;
    $hoursText = is_array($operatingHoursData) ? trim((string) ($operatingHoursData['source_value'] ?? '')) : '';
    $hoursSource = is_array($operatingHoursData) ? trim((string) ($operatingHoursData['source'] ?? 'nsw_api')) : 'nsw_api';

    $isOpen247 = null;
    if (array_key_exists('is_open_24_7', $row) && $row['is_open_24_7'] !== null) {
        $isOpen247 = (int) $row['is_open_24_7'] === 1 ? 1 : 0;
    }

    if ($isOpen247 === null && $hoursText !== '') {
        $isOpen247 = operating_hours_text_is_24_7($hoursText) ? 1 : 0;
    }

    $sourceLabel = $hoursSource === 'osm_overpass' ? 'OSM/Overpass' : 'NSW API';

    if ($isOpen247 === 1) {
        $note = $hoursText !== ''
            ? sprintf('Open 24/7 (%s: %s)', $sourceLabel, $hoursText)
            : sprintf('Open 24/7 (reported by %s)', $sourceLabel);
        return [
            'is_open_24_7' => 1,
            'note' => $note,
        ];
    }

    if ($isOpen247 === 0) {
        $note = $hoursText !== ''
            ? sprintf('Hours: %s (%s)', $hoursText, $sourceLabel)
            : sprintf('Not 24/7 (reported by %s)', $sourceLabel);
        return [
            'is_open_24_7' => 0,
            'note' => $note,
        ];
    }

    return [
        'is_open_24_7' => null,
        'note' => 'Open now (live telemetry active); official opening hours are not published',
    ];
}

function facility_predict_window(
    string $facilityId,
    int $capacity,
    float $currentRate,
    int $targetHour,
    int $isWeekend,
    array $facilityHourMap,
    array $facilityHourAnyMap,
    array $globalHourly,
    array $operatingMeta
): array {
    $specificKey = $facilityId . '|' . $targetHour . '|' . $isWeekend;
    $hourKey = $facilityId . '|' . $targetHour;

    $hourRate = $facilityHourMap[$specificKey] ?? $facilityHourAnyMap[$hourKey] ?? null;
    $globalRate = $globalHourly[$targetHour] ?? $currentRate;

    if ($hourRate === null) {
        $predictedRate = max(0.0, min(1.0, $currentRate));
    } else {
        $predictedRate = (0.55 * (float) $hourRate) + (0.35 * $currentRate) + (0.10 * $globalRate);
        $predictedRate = max(0.0, min(1.0, $predictedRate));
    }

    $predictedOccupied = $capacity > 0 ? (int) round($predictedRate * $capacity) : 0;
    $predictedOccupied = max(0, min($capacity, $predictedOccupied));
    $predictedAvailable = max(0, $capacity - $predictedOccupied);

    return [
        'hour' => $targetHour,
        'predicted_occupancy_rate' => $predictedRate,
        'predicted_occupied' => $predictedOccupied,
        'predicted_available' => $predictedAvailable,
        'predicted_class' => occupancy_availability_class($predictedAvailable, $predictedRate),
        'is_open_24_7' => $operatingMeta['is_open_24_7'],
        'hours_note' => $operatingMeta['note'],
    ];
}

function top_latest_facilities(int $limit = 8): array
{
    $limit = max(1, min($limit, 20));
    return array_slice(latest_snapshots(), 0, $limit);
}

function hourly_average_occupancy(): array
{
    $condition = snapshot_source_condition('s');
    return fetch_all_assoc("
        SELECT s.hour, ROUND(AVG(s.occupancy_rate) * 100, 2) AS average_occupancy
        FROM occupancy_snapshots s
        INNER JOIN parking_facilities f ON f.facility_id = s.facility_id
        WHERE {$condition}
          AND LOWER(f.facility_name) NOT LIKE '%historical only%'
        GROUP BY s.hour
        ORDER BY s.hour ASC
    ");
}

function availability_distribution(): array
{
    $distribution = [];
    foreach (latest_snapshots() as $row) {
        $label = (string) ($row['availability_class'] ?? 'Available');
        $distribution[$label] = ($distribution[$label] ?? 0) + 1;
    }

    arsort($distribution);

    $rows = [];
    foreach ($distribution as $label => $total) {
        $rows[] = [
            'availability_class' => $label,
            'total' => $total,
        ];
    }

    return $rows;
}

function facility_options(): array
{
    return array_values(array_filter(
        fetch_all_assoc("SELECT facility_id, facility_name FROM parking_facilities ORDER BY facility_name ASC"),
        fn(array $row) => !facility_is_hidden($row['facility_name'] ?? '')
    ));
}

function facility_summary(string $facilityId): ?array
{
    $outerCondition = snapshot_source_condition('s');
    $innerCondition = snapshot_source_condition();
    $innerFreshnessCondition = snapshot_freshness_condition();

    $sql = "
        SELECT f.facility_id, f.facility_name, f.latitude, f.longitude, f.capacity, f.is_open_24_7, f.operating_hours_json,
               s.recorded_at, s.occupied, s.available, s.occupancy_rate, s.availability_class
        FROM parking_facilities f
        INNER JOIN occupancy_snapshots s ON s.facility_id = f.facility_id AND {$outerCondition}
        INNER JOIN (
            SELECT facility_id, MAX(recorded_at) AS max_recorded_at
            FROM occupancy_snapshots
            WHERE facility_id = ?
              AND {$innerCondition}
              {$innerFreshnessCondition}
            GROUP BY facility_id
        ) latest_map
            ON latest_map.facility_id = s.facility_id
           AND latest_map.max_recorded_at = s.recorded_at
        WHERE f.facility_id = ?
        LIMIT 1
    ";

    $stmt = db()->prepare($sql);
    $stmt->bind_param('ss', $facilityId, $facilityId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc() ?: null;
    if ($row === null || facility_is_hidden($row['facility_name'] ?? '')) {
        return null;
    }

    return normalize_snapshot_row($row);
}

function facility_history(string $facilityId): array
{
    $sourceCondition = snapshot_source_condition();
    $sql = "
        SELECT recorded_at, occupied, available, ROUND(occupancy_rate * 100, 2) AS occupancy_percent
        FROM occupancy_snapshots
        WHERE facility_id = ?
          AND {$sourceCondition}
        ORDER BY recorded_at ASC
    ";
    $stmt = db()->prepare($sql);
    $stmt->bind_param('s', $facilityId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function peak_hour(): ?array
{
    $condition = snapshot_source_condition('s');
    return fetch_one_assoc("
        SELECT s.hour, ROUND(AVG(s.occupancy_rate) * 100, 2) AS average_occupancy
        FROM occupancy_snapshots s
        INNER JOIN parking_facilities f ON f.facility_id = s.facility_id
        WHERE {$condition}
          AND LOWER(f.facility_name) NOT LIKE '%historical only%'
        GROUP BY s.hour
        ORDER BY AVG(s.occupancy_rate) DESC, s.hour ASC
        LIMIT 1
    ");
}

function top_average_occupancy(int $limit = 10): array
{
    $limit = max(1, min($limit, 20));
    $condition = snapshot_source_condition('s');
    $rows = fetch_all_assoc("
        SELECT f.facility_name, ROUND(AVG(s.occupancy_rate) * 100, 2) AS average_occupancy
        FROM occupancy_snapshots s
        INNER JOIN parking_facilities f ON f.facility_id = s.facility_id
        WHERE {$condition}
        GROUP BY f.facility_id, f.facility_name
        ORDER BY AVG(s.occupancy_rate) DESC, f.facility_name ASC
    ");

    $rows = array_values(array_filter(
        $rows,
        fn(array $row) => !facility_is_hidden($row['facility_name'] ?? '')
    ));

    return array_slice($rows, 0, $limit);
}

function capacity_leaders(int $limit = 10): array
{
    $limit = max(1, min($limit, 20));
    $rows = fetch_all_assoc("SELECT facility_name, capacity FROM parking_facilities ORDER BY capacity DESC, facility_name ASC");
    $rows = array_values(array_filter(
        $rows,
        fn(array $row) => !facility_is_hidden($row['facility_name'] ?? '')
    ));

    return array_slice($rows, 0, $limit);
}

function live_baseline_performance_metrics(): array
{
    static $metrics = null;

    if ($metrics !== null) {
        return $metrics;
    }

    $condition = snapshot_source_condition('s');
    $rows = fetch_all_assoc("
        SELECT s.facility_id, f.facility_name, s.occupancy_rate, s.available
        FROM occupancy_snapshots s
        INNER JOIN parking_facilities f ON f.facility_id = s.facility_id
        WHERE {$condition}
        ORDER BY s.facility_id ASC, s.recorded_at ASC
    ");

    $byFacility = [];
    $previous = [];

    foreach ($rows as $row) {
        $facilityId = (string) ($row['facility_id'] ?? '');
        if ($facilityId === '') {
            continue;
        }
        if (facility_is_hidden($row['facility_name'] ?? '')) {
            continue;
        }

        if (!isset($byFacility[$facilityId])) {
            $byFacility[$facilityId] = [
                'facility_name' => (string) ($row['facility_name'] ?? $facilityId),
                'sample_size' => 0,
                'sum_abs_error' => 0.0,
                'sum_sq_error' => 0.0,
                'sum_actual' => 0.0,
                'sum_actual_sq' => 0.0,
                'class_hits' => 0,
                'distinct_rates' => [],
                'distinct_classes' => [],
            ];
        }

        $currentRate = (float) ($row['occupancy_rate'] ?? 0);
        $currentClass = occupancy_availability_class($row['available'] ?? null, $currentRate);
        $rateKey = number_format($currentRate, 4, '.', '');

        $byFacility[$facilityId]['distinct_rates'][$rateKey] = true;
        if ($currentClass !== '') {
            $byFacility[$facilityId]['distinct_classes'][$currentClass] = true;
        }

        if (isset($previous[$facilityId])) {
            $predictedRate = (float) $previous[$facilityId]['rate'];
            $error = $currentRate - $predictedRate;

            $byFacility[$facilityId]['sample_size']++;
            $byFacility[$facilityId]['sum_abs_error'] += abs($error);
            $byFacility[$facilityId]['sum_sq_error'] += $error * $error;
            $byFacility[$facilityId]['sum_actual'] += $currentRate;
            $byFacility[$facilityId]['sum_actual_sq'] += $currentRate * $currentRate;

            if ((string) $previous[$facilityId]['class'] === $currentClass) {
                $byFacility[$facilityId]['class_hits']++;
            }
        }

        $previous[$facilityId] = [
            'rate' => $currentRate,
            'class' => $currentClass,
        ];
    }

    $regression = [];
    $classification = [];

    foreach ($byFacility as $facilityMetrics) {
        $sampleSize = (int) $facilityMetrics['sample_size'];
        $distinctRateCount = count($facilityMetrics['distinct_rates']);
        $distinctClassCount = count($facilityMetrics['distinct_classes']);

        // Require enough real movement before surfacing "performance" rows.
        if ($sampleSize >= 24 && $distinctRateCount >= 3) {
            $mae = $facilityMetrics['sum_abs_error'] / $sampleSize;
            $rmse = sqrt($facilityMetrics['sum_sq_error'] / $sampleSize);
            $sumActual = $facilityMetrics['sum_actual'];
            $sst = $facilityMetrics['sum_actual_sq'] - (($sumActual * $sumActual) / $sampleSize);
            $r2 = $sst > 0.0000001 ? 1 - ($facilityMetrics['sum_sq_error'] / $sst) : null;

            $regression[] = [
                'facility_name' => $facilityMetrics['facility_name'],
                'sample_size' => $sampleSize,
                'mae' => $mae,
                'rmse' => $rmse,
                'r2' => $r2,
            ];
        }

        if ($sampleSize >= 24 && $distinctClassCount >= 2) {
            $classification[] = [
                'facility_name' => $facilityMetrics['facility_name'],
                'sample_size' => $sampleSize,
                'accuracy' => $facilityMetrics['class_hits'] / $sampleSize,
            ];
        }
    }

    usort(
        $regression,
        fn(array $left, array $right) => ((float) $left['rmse'] <=> (float) $right['rmse'])
            ?: strcmp((string) $left['facility_name'], (string) $right['facility_name'])
    );

    usort(
        $classification,
        fn(array $left, array $right) => ((float) $right['accuracy'] <=> (float) $left['accuracy'])
            ?: strcmp((string) $left['facility_name'], (string) $right['facility_name'])
    );

    $metrics = [
        'regression' => $regression,
        'classification' => $classification,
    ];

    return $metrics;
}

function stored_regression_metrics(): array
{
    return fetch_all_assoc("
        SELECT f.facility_name, m.sample_size, m.mae, m.rmse, m.r2
        FROM model_regression_metrics m
        INNER JOIN parking_facilities f ON f.facility_id = m.facility_id
        ORDER BY m.rmse ASC, f.facility_name ASC
    ");
}

function stored_classification_metrics(): array
{
    return fetch_all_assoc("
        SELECT f.facility_name, m.sample_size, m.accuracy
        FROM model_classification_metrics m
        INNER JOIN parking_facilities f ON f.facility_id = m.facility_id
        ORDER BY m.accuracy DESC, f.facility_name ASC
    ");
}

function xgboost_regression_metrics(string $source): array
{
    $rows = array_values(array_filter(
        ml_model_facility_metrics($source),
        static fn(array $row): bool => isset($row['rmse']) && $row['rmse'] !== null
    ));

    usort(
        $rows,
        static fn(array $left, array $right): int => ((float) ($left['rmse'] ?? 0) <=> (float) ($right['rmse'] ?? 0))
            ?: strcmp((string) ($left['facility_name'] ?? ''), (string) ($right['facility_name'] ?? ''))
    );

    return $rows;
}

function xgboost_classification_metrics(string $source): array
{
    $rows = array_values(array_filter(
        ml_model_facility_metrics($source),
        static fn(array $row): bool => isset($row['accuracy']) && $row['accuracy'] !== null
    ));

    usort(
        $rows,
        static fn(array $left, array $right): int => ((float) ($right['accuracy'] ?? 0) <=> (float) ($left['accuracy'] ?? 0))
            ?: strcmp((string) ($left['facility_name'] ?? ''), (string) ($right['facility_name'] ?? ''))
    );

    return $rows;
}

function parking_prediction_model(): string
{
    return ml_model_is_available(snapshot_data_source()) ? 'xgboost' : 'baseline';
}

function regression_metrics_for_source(string $source): array
{
    if (ml_model_is_available($source)) {
        return xgboost_regression_metrics($source);
    }

    return $source === 'live'
        ? live_baseline_performance_metrics()['regression']
        : stored_regression_metrics();
}

function classification_metrics_for_source(string $source): array
{
    if (ml_model_is_available($source)) {
        return xgboost_classification_metrics($source);
    }

    return $source === 'live'
        ? live_baseline_performance_metrics()['classification']
        : stored_classification_metrics();
}

function regression_metrics(): array
{
    return regression_metrics_for_source(insights_metrics_source());
}

function classification_metrics(): array
{
    return classification_metrics_for_source(insights_metrics_source());
}

function percent_badge_class(float $percentage): string
{
    if ($percentage >= 100) return 'status-full';
    if ($percentage >= 70) return 'status-limited';
    return 'status-available';
}

function availability_badge_class(string $label): string
{
    $normalized = strtolower(trim($label));
    return match ($normalized) {
        'full' => 'status-full',
        'limited' => 'status-limited',
        default => 'status-available',
    };
}

function format_percentage($value, int $decimals = 1): string
{
    return number_format((float) $value, $decimals) . '%';
}

function format_number($value): string
{
    return number_format((float) $value);
}

function display_datetime(?string $value): string
{
    if (!$value) return 'N/A';
    $dt = new DateTime($value, new DateTimeZone('UTC'));
    return $dt->format('d M Y, H:i') . ' UTC';
}
