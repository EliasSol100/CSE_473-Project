<?php
require_once __DIR__ . '/db.php';

const LIVE_COLLECTOR_BASE_URL = 'https://api.transport.nsw.gov.au/v1';
const LIVE_COLLECTOR_LIST_URL = LIVE_COLLECTOR_BASE_URL . '/carpark';
const LIVE_COLLECTOR_DETAIL_URL_TEMPLATE = LIVE_COLLECTOR_BASE_URL . '/carpark?facility=%s';
const LIVE_COLLECTOR_DEFAULT_INTERVAL_SECONDS = 10;
const LIVE_COLLECTOR_DEFAULT_TIMEOUT_SECONDS = 20;
const LIVE_COLLECTOR_DEFAULT_MAX_PARALLEL = 10;
const LIVE_COLLECTOR_OVERPASS_URL = 'https://overpass-api.de/api/interpreter';
const LIVE_COLLECTOR_HOURS_ENRICH_BUDGET = 0;
const LIVE_COLLECTOR_HOURS_RETRY_SECONDS = 21600;
const LIVE_COLLECTOR_STATE_FILE = __DIR__ . '/../logs/live_collector_state.json';
const LIVE_COLLECTOR_LOCK_FILE = __DIR__ . '/../logs/live_collector.lock';
const LIVE_COLLECTOR_LOG_FILE = __DIR__ . '/../logs/live_collector_php.log';
const LIVE_COLLECTOR_HOURS_CACHE_FILE = __DIR__ . '/../logs/live_collector_hours_cache.json';

function live_collector_run(bool $force = false): array
{
    try {
        $config = live_collector_config();
        $state = live_collector_read_state();

        if (!$force && !live_collector_is_due($state, $config)) {
            return live_collector_skip_result($state, $config);
        }

        $lockHandle = live_collector_acquire_lock();
        if ($lockHandle === null) {
            return [
                'status' => 'busy',
                'page_should_reload' => false,
                'message' => 'A live sync is already running.',
                'checked_at' => live_collector_now_iso(),
            ];
        }

        try {
            $state = live_collector_read_state();
            if (!$force && !live_collector_is_due($state, $config)) {
                return live_collector_skip_result($state, $config);
            }

            $startedAt = live_collector_now_iso();
            live_collector_log('Starting dashboard-triggered live sync.');

            $facilityMap = live_collector_fetch_facility_map($config);
            $detailResults = live_collector_fetch_facility_details(array_keys($facilityMap), $config);

            $items = [];
            $failedFacilities = 0;
            $skippedFacilities = 0;
            $hoursCache = live_collector_read_hours_cache();
            $hoursCacheChanged = false;
            $hoursEnrichBudget = (int) ($config['hours_enrich_budget'] ?? LIVE_COLLECTOR_HOURS_ENRICH_BUDGET);

            foreach ($detailResults as $detailResult) {
                if (($detailResult['status_code'] ?? 0) === 404) {
                    $skippedFacilities++;
                    continue;
                }

                if (!empty($detailResult['error']) || !is_array($detailResult['payload'] ?? null)) {
                    $failedFacilities++;
                    continue;
                }

                $facilityId = (string) ($detailResult['facility_id'] ?? '');
                $fallbackName = $facilityMap[$facilityId] ?? null;
                $item = live_collector_extract_fields($detailResult['payload'], $fallbackName);

                if ($item === null) {
                    $skippedFacilities++;
                    continue;
                }

                $item = live_collector_apply_cached_hours($item, $hoursCache);
                if (($item['is_open_24_7'] ?? null) === null && $hoursEnrichBudget > 0 && live_collector_should_attempt_hours_enrichment($item, $hoursCache, $config)) {
                    $enriched = live_collector_fetch_overpass_operating_hours($item, $config);
                    if (is_array($enriched)) {
                        $item['is_open_24_7'] = $enriched['is_open_24_7'] ?? null;
                        $item['operating_hours_json'] = $enriched['operating_hours_json'] ?? null;
                        $hoursCache[(string) ($item['facility_id'] ?? '')] = [
                            'is_open_24_7' => $item['is_open_24_7'],
                            'operating_hours_json' => $item['operating_hours_json'],
                            'checked_at' => gmdate('c'),
                        ];
                        $hoursCacheChanged = true;
                    } else {
                        $facilityIdForCache = (string) ($item['facility_id'] ?? '');
                        if ($facilityIdForCache !== '') {
                            $previousMisses = (int) ($hoursCache[$facilityIdForCache]['miss_count'] ?? 0);
                            $hoursCache[$facilityIdForCache] = [
                                'is_open_24_7' => null,
                                'operating_hours_json' => null,
                                'checked_at' => gmdate('c'),
                                'miss_count' => $previousMisses + 1,
                            ];
                            $hoursCacheChanged = true;
                        }
                    }
                    $hoursEnrichBudget--;
                }

                $items[] = $item;
            }

            if ($hoursCacheChanged) {
                live_collector_write_hours_cache($hoursCache);
            }

            $persistResult = live_collector_persist_items($items);
            $completedAt = live_collector_now_iso();
            $pageShouldReload = (($persistResult['new_snapshots'] ?? 0) + ($persistResult['changed_snapshots'] ?? 0)) > 0;
            $status = $pageShouldReload ? 'updated' : 'checked';

            $result = [
                'status' => $status,
                'page_should_reload' => $pageShouldReload,
                'message' => $pageShouldReload
                    ? 'Fresh parking data was written to MySQL.'
                    : 'Live feed checked. No new parking changes were written.',
                'started_at' => $startedAt,
                'checked_at' => $completedAt,
                'last_completed_at' => $completedAt,
                'facility_count' => count($facilityMap),
                'saved_facilities' => count($items),
                'failed_facilities' => $failedFacilities,
                'skipped_facilities' => $skippedFacilities,
                'new_snapshots' => $persistResult['new_snapshots'] ?? 0,
                'changed_snapshots' => $persistResult['changed_snapshots'] ?? 0,
                'unchanged_snapshots' => $persistResult['unchanged_snapshots'] ?? 0,
                'last_snapshot_at' => $persistResult['last_snapshot_at'] ?? null,
                'interval_seconds' => $config['interval_seconds'],
            ];

            $stateUpdate = [
                'last_status' => $status,
                'last_message' => $result['message'],
                'last_started_at' => $startedAt,
                'last_completed_at' => $completedAt,
                'last_completed_at_ts' => time(),
                'last_data_change_at' => $pageShouldReload ? $completedAt : ($state['last_data_change_at'] ?? null),
                'last_snapshot_at' => $result['last_snapshot_at'],
                'facility_count' => $result['facility_count'],
                'saved_facilities' => $result['saved_facilities'],
                'failed_facilities' => $result['failed_facilities'],
                'skipped_facilities' => $result['skipped_facilities'],
                'new_snapshots' => $result['new_snapshots'],
                'changed_snapshots' => $result['changed_snapshots'],
                'unchanged_snapshots' => $result['unchanged_snapshots'],
                'interval_seconds' => $result['interval_seconds'],
            ];
            live_collector_write_state($stateUpdate);

            live_collector_log(
                sprintf(
                    'Live sync finished with status=%s, facilities=%d, new=%d, changed=%d, unchanged=%d.',
                    $status,
                    $result['saved_facilities'],
                    $result['new_snapshots'],
                    $result['changed_snapshots'],
                    $result['unchanged_snapshots']
                )
            );

            return $result;
        } finally {
            live_collector_release_lock($lockHandle);
        }
    } catch (Throwable $exception) {
        $errorResult = [
            'status' => 'error',
            'page_should_reload' => false,
            'message' => $exception->getMessage(),
            'checked_at' => live_collector_now_iso(),
        ];

        $state = live_collector_read_state();
        $state['last_status'] = 'error';
        $state['last_message'] = $exception->getMessage();
        $state['last_completed_at'] = $errorResult['checked_at'];
        $state['last_completed_at_ts'] = time();
        live_collector_write_state($state);

        live_collector_log('Live sync failed: ' . $exception->getMessage());
        return $errorResult;
    }
}

function live_collector_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    live_collector_boot_env();

    $apiKey = live_collector_env('NSW_API_KEY');
    if ($apiKey === '') {
        throw new RuntimeException('Missing NSW_API_KEY. Add it to .env or python/.env first.');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for dashboard live sync.');
    }

    $config = [
        'api_key' => $apiKey,
        'interval_seconds' => max(10, live_collector_env_int('DASHBOARD_COLLECT_INTERVAL_SECONDS', LIVE_COLLECTOR_DEFAULT_INTERVAL_SECONDS)),
        'timeout_seconds' => max(5, live_collector_env_int('DASHBOARD_REQUEST_TIMEOUT_SECONDS', LIVE_COLLECTOR_DEFAULT_TIMEOUT_SECONDS)),
        'max_parallel' => max(1, live_collector_env_int('DASHBOARD_MAX_PARALLEL_REQUESTS', LIVE_COLLECTOR_DEFAULT_MAX_PARALLEL)),
        'hours_enrich_budget' => max(0, live_collector_env_int('DASHBOARD_HOURS_ENRICH_BUDGET', LIVE_COLLECTOR_HOURS_ENRICH_BUDGET)),
        'hours_retry_seconds' => max(300, live_collector_env_int('DASHBOARD_HOURS_RETRY_SECONDS', LIVE_COLLECTOR_HOURS_RETRY_SECONDS)),
        'overpass_url' => LIVE_COLLECTOR_OVERPASS_URL,
    ];

    return $config;
}

function live_collector_boot_env(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $root = dirname(__DIR__);
    $paths = [
        $root . '/.env',
        $root . '/python/.env',
    ];

    foreach ($paths as $path) {
        live_collector_load_env_file($path);
    }

    $loaded = true;
}

function live_collector_load_env_file(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if ($key === '' || live_collector_env($key) !== '') {
            continue;
        }

        $value = trim($value);
        $value = trim($value, "\"'");

        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

function live_collector_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value !== false) {
        return trim((string) $value);
    }

    if (array_key_exists($key, $_ENV)) {
        return trim((string) $_ENV[$key]);
    }

    return $default;
}

function live_collector_env_int(string $key, int $default): int
{
    $value = live_collector_env($key);
    if ($value === '' || !preg_match('/^-?\d+$/', $value)) {
        return $default;
    }

    return (int) $value;
}

function live_collector_acquire_lock()
{
    live_collector_ensure_logs_dir();

    $handle = fopen(LIVE_COLLECTOR_LOCK_FILE, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to create the dashboard live-sync lock file.');
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }

    return $handle;
}

function live_collector_release_lock($handle): void
{
    if (!is_resource($handle)) {
        return;
    }

    flock($handle, LOCK_UN);
    fclose($handle);
}

function live_collector_read_state(): array
{
    if (!is_file(LIVE_COLLECTOR_STATE_FILE)) {
        return [];
    }

    $contents = file_get_contents(LIVE_COLLECTOR_STATE_FILE);
    if ($contents === false || $contents === '') {
        return [];
    }

    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : [];
}

function live_collector_write_state(array $state): void
{
    live_collector_ensure_logs_dir();

    file_put_contents(
        LIVE_COLLECTOR_STATE_FILE,
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

function live_collector_ensure_logs_dir(): void
{
    $dir = dirname(LIVE_COLLECTOR_STATE_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

function live_collector_log(string $message): void
{
    live_collector_ensure_logs_dir();
    $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL;
    file_put_contents(LIVE_COLLECTOR_LOG_FILE, $line, FILE_APPEND);
}

function live_collector_is_due(array $state, array $config): bool
{
    $lastCompletedAt = isset($state['last_completed_at_ts']) ? (int) $state['last_completed_at_ts'] : 0;
    if ($lastCompletedAt <= 0) {
        return true;
    }

    return (time() - $lastCompletedAt) >= (int) $config['interval_seconds'];
}

function live_collector_skip_result(array $state, array $config): array
{
    $lastCompletedAt = isset($state['last_completed_at_ts']) ? (int) $state['last_completed_at_ts'] : 0;
    $remaining = 0;

    if ($lastCompletedAt > 0) {
        $remaining = max(0, (int) $config['interval_seconds'] - (time() - $lastCompletedAt));
    }

    return [
        'status' => 'skipped',
        'page_should_reload' => false,
        'message' => sprintf(
            'Dashboard live sync is waiting for the %d-second cooldown.',
            (int) $config['interval_seconds']
        ),
        'checked_at' => live_collector_now_iso(),
        'last_completed_at' => $state['last_completed_at'] ?? null,
        'cooldown_remaining_seconds' => $remaining,
        'interval_seconds' => $config['interval_seconds'],
    ];
}

function live_collector_now_iso(): string
{
    return gmdate('c');
}

function live_collector_fetch_facility_map(array $config): array
{
    $response = live_collector_fetch_json(LIVE_COLLECTOR_LIST_URL, $config);
    if (!is_array($response)) {
        throw new RuntimeException('Failed to fetch the NSW facility list for dashboard sync.');
    }

    return $response;
}

function live_collector_fetch_json(string $url, array $config): ?array
{
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize cURL for dashboard sync.');
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => (int) $config['timeout_seconds'],
        CURLOPT_CONNECTTIMEOUT => min(10, (int) $config['timeout_seconds']),
        CURLOPT_HTTPHEADER => live_collector_headers($config),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($handle);
    $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($body === false || $error !== '') {
        throw new RuntimeException('The NSW facility list request failed: ' . $error);
    }

    if ($statusCode !== 200) {
        throw new RuntimeException('The NSW facility list request returned HTTP ' . $statusCode . '.');
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('The NSW facility list response was not valid JSON.');
    }

    return $decoded;
}

function live_collector_headers(array $config): array
{
    return [
        'Accept: application/json',
        'Authorization: apikey ' . $config['api_key'],
    ];
}

function live_collector_fetch_facility_details(array $facilityIds, array $config): array
{
    $results = [];
    $chunks = array_chunk($facilityIds, max(1, (int) $config['max_parallel']));

    foreach ($chunks as $chunk) {
        $results = array_merge($results, live_collector_fetch_facility_detail_chunk($chunk, $config));
    }

    return $results;
}

function live_collector_fetch_facility_detail_chunk(array $facilityIds, array $config): array
{
    $multiHandle = curl_multi_init();
    if ($multiHandle === false) {
        throw new RuntimeException('Unable to initialize parallel dashboard sync requests.');
    }

    $handles = [];
    foreach ($facilityIds as $facilityId) {
        $handle = curl_init(sprintf(LIVE_COLLECTOR_DETAIL_URL_TEMPLATE, rawurlencode((string) $facilityId)));
        if ($handle === false) {
            continue;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => (int) $config['timeout_seconds'],
            CURLOPT_CONNECTTIMEOUT => min(10, (int) $config['timeout_seconds']),
            CURLOPT_HTTPHEADER => live_collector_headers($config),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        curl_multi_add_handle($multiHandle, $handle);
        $handles[(int) $handle] = [
            'handle' => $handle,
            'facility_id' => (string) $facilityId,
        ];
    }

    $running = null;
    do {
        $status = curl_multi_exec($multiHandle, $running);
        if ($status > CURLM_OK) {
            break;
        }

        if ($running > 0) {
            $selected = curl_multi_select($multiHandle, 1.0);
            if ($selected === -1) {
                usleep(100000);
            }
        }
    } while ($running > 0);

    $results = [];
    foreach ($handles as $meta) {
        $handle = $meta['handle'];
        $body = curl_multi_getcontent($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        $payload = null;
        if ($error === '' && $statusCode === 200 && is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            } else {
                $error = 'Invalid JSON response.';
            }
        }

        $results[] = [
            'facility_id' => $meta['facility_id'],
            'status_code' => $statusCode,
            'payload' => $payload,
            'error' => $statusCode === 404 ? null : ($error !== '' ? $error : null),
        ];

        curl_multi_remove_handle($multiHandle, $handle);
        curl_close($handle);
    }

    curl_multi_close($multiHandle);
    return $results;
}

function live_collector_extract_fields(array $payload, ?string $fallbackName = null): ?array
{
    $facilityId = trim((string) ($payload['facility_id'] ?? ''));
    $facilityName = trim((string) ($payload['facility_name'] ?? $fallbackName ?? $facilityId));
    $capacity = live_collector_parse_int($payload['spots'] ?? null);

    $occupancy = isset($payload['occupancy']) && is_array($payload['occupancy'])
        ? $payload['occupancy']
        : [];
    $occupied = live_collector_parse_int($occupancy['total'] ?? null);

    $location = isset($payload['location']) && is_array($payload['location'])
        ? $payload['location']
        : [];
    $latitude = live_collector_parse_float($location['latitude'] ?? null);
    $longitude = live_collector_parse_float($location['longitude'] ?? null);
    $operatingHoursMeta = live_collector_extract_operating_hours($payload);

    if ($facilityId === '' || $capacity === null || $occupied === null) {
        return null;
    }

    $available = max($capacity - $occupied, 0);
    $occupancyRate = $capacity > 0
        ? min(max($occupied / $capacity, 0.0), 1.0)
        : 0.0;
    $observedAt = live_collector_parse_timestamp($payload);

    return [
        'facility_id' => $facilityId,
        'facility_name' => $facilityName !== '' ? $facilityName : $facilityId,
        'capacity' => $capacity,
        'occupied' => $occupied,
        'available' => $available,
        'occupancy_rate' => $occupancyRate,
        'availability_class' => live_collector_availability_class($occupancyRate, $available),
        'recorded_at' => $observedAt,
        'hour' => (int) $observedAt->format('G'),
        'day_of_week' => max(0, ((int) $observedAt->format('N')) - 1),
        'is_weekend' => ((int) $observedAt->format('N')) >= 6 ? 1 : 0,
        'month' => (int) $observedAt->format('n'),
        'latitude' => $latitude,
        'longitude' => $longitude,
        'is_open_24_7' => $operatingHoursMeta['is_open_24_7'],
        'operating_hours_json' => $operatingHoursMeta['operating_hours_json'],
    ];
}

function live_collector_extract_operating_hours(array $payload): array
{
    $candidate = null;
    $candidateKey = null;

    foreach (['opening_hours', 'openingHours', 'operating_hours', 'operatingHours', 'hours', 'opening'] as $key) {
        if (!array_key_exists($key, $payload)) {
            continue;
        }

        $value = $payload[$key];
        if ($value === null || $value === '') {
            continue;
        }

        $candidate = $value;
        $candidateKey = $key;
        break;
    }

    if ($candidate === null) {
        return [
            'is_open_24_7' => null,
            'operating_hours_json' => null,
        ];
    }

    $isOpen247 = live_collector_detect_24_7($candidate) ? 1 : 0;
    $normalized = [
        'source_key' => $candidateKey,
        'source_value' => $candidate,
    ];

    $encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || $encoded === '') {
        $encoded = null;
    }

    return [
        'is_open_24_7' => $isOpen247,
        'operating_hours_json' => $encoded,
    ];
}

function live_collector_detect_24_7($value): bool
{
    if (is_array($value)) {
        foreach ($value as $item) {
            if (live_collector_detect_24_7($item)) {
                return true;
            }
        }

        return false;
    }

    if (!is_scalar($value)) {
        return false;
    }

    $text = strtolower((string) $value);
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;

    return str_contains($text, '24/7')
        || str_contains($text, '24 7')
        || str_contains($text, '24 hours')
        || str_contains($text, 'open all day')
        || str_contains($text, 'always open');
}

function live_collector_read_hours_cache(): array
{
    if (!is_file(LIVE_COLLECTOR_HOURS_CACHE_FILE)) {
        return [];
    }

    $contents = @file_get_contents(LIVE_COLLECTOR_HOURS_CACHE_FILE);
    if (!is_string($contents) || $contents === '') {
        return [];
    }

    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : [];
}

function live_collector_write_hours_cache(array $cache): void
{
    live_collector_ensure_logs_dir();
    @file_put_contents(
        LIVE_COLLECTOR_HOURS_CACHE_FILE,
        json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

function live_collector_apply_cached_hours(array $item, array $hoursCache): array
{
    $facilityId = (string) ($item['facility_id'] ?? '');
    if ($facilityId === '' || !isset($hoursCache[$facilityId]) || !is_array($hoursCache[$facilityId])) {
        return $item;
    }

    $cached = $hoursCache[$facilityId];
    if (($item['is_open_24_7'] ?? null) === null && array_key_exists('is_open_24_7', $cached)) {
        $item['is_open_24_7'] = $cached['is_open_24_7'];
    }
    if (($item['operating_hours_json'] ?? null) === null && isset($cached['operating_hours_json']) && is_string($cached['operating_hours_json'])) {
        $item['operating_hours_json'] = $cached['operating_hours_json'];
    }

    return $item;
}

function live_collector_should_attempt_hours_enrichment(array $item, array $hoursCache, array $config): bool
{
    $facilityId = (string) ($item['facility_id'] ?? '');
    if ($facilityId === '' || !isset($hoursCache[$facilityId]) || !is_array($hoursCache[$facilityId])) {
        return true;
    }

    $cached = $hoursCache[$facilityId];
    $cachedHours = trim((string) ($cached['operating_hours_json'] ?? ''));
    if ($cachedHours !== '') {
        return false;
    }

    $checkedAt = trim((string) ($cached['checked_at'] ?? ''));
    if ($checkedAt === '') {
        return true;
    }

    try {
        $checkedTs = (new DateTimeImmutable($checkedAt))->getTimestamp();
    } catch (Throwable) {
        return true;
    }

    $retrySeconds = max(300, (int) ($config['hours_retry_seconds'] ?? LIVE_COLLECTOR_HOURS_RETRY_SECONDS));
    return (time() - $checkedTs) >= $retrySeconds;
}

function live_collector_fetch_overpass_operating_hours(array $item, array $config): ?array
{
    $latitude = live_collector_parse_float($item['latitude'] ?? null);
    $longitude = live_collector_parse_float($item['longitude'] ?? null);
    if ($latitude === null || $longitude === null) {
        return null;
    }

    $query = sprintf(
        '[out:json][timeout:20];(node(around:650,%.6F,%.6F)["amenity"="parking"];way(around:650,%.6F,%.6F)["amenity"="parking"];relation(around:650,%.6F,%.6F)["amenity"="parking"];node(around:650,%.6F,%.6F)["parking"="park_ride"];way(around:650,%.6F,%.6F)["parking"="park_ride"];relation(around:650,%.6F,%.6F)["parking"="park_ride"];);out tags center;',
        $latitude,
        $longitude,
        $latitude,
        $longitude,
        $latitude,
        $longitude,
        $latitude,
        $longitude,
        $latitude,
        $longitude,
        $latitude,
        $longitude
    );

    $url = (string) ($config['overpass_url'] ?? LIVE_COLLECTOR_OVERPASS_URL);
    $handle = curl_init($url);
    if ($handle === false) {
        return null;
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'data=' . rawurlencode($query),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($handle);
    $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($body === false || $error !== '' || $statusCode !== 200) {
        return null;
    }

    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        return null;
    }

    $elements = is_array($decoded['elements'] ?? null) ? $decoded['elements'] : [];
    $facilityName = strtolower(trim((string) ($item['facility_name'] ?? '')));
    $facilityName = str_replace(['park&ride', 'park and ride', '-', '(', ')'], ' ', $facilityName);
    $facilityTokens = array_values(array_filter(explode(' ', preg_replace('/\s+/', ' ', $facilityName) ?? '')));

    $best = null;
    $bestScore = -1000000.0;

    foreach ($elements as $element) {
        if (!is_array($element)) {
            continue;
        }
        $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
        $openingHours = live_collector_extract_opening_hours_from_tags($tags);
        if ($openingHours === '') {
            continue;
        }

        $candidateName = strtolower(trim((string) ($tags['name'] ?? '')));
        $candidateName = str_replace(['park&ride', 'park and ride', '-', '(', ')'], ' ', $candidateName);
        $candidateTokens = array_values(array_filter(explode(' ', preg_replace('/\s+/', ' ', $candidateName) ?? '')));

        $tokenMatches = 0;
        foreach ($facilityTokens as $token) {
            if ($token !== '' && in_array($token, $candidateTokens, true)) {
                $tokenMatches++;
            }
        }

        $pointLat = null;
        $pointLon = null;
        if (isset($element['lat'], $element['lon']) && is_numeric($element['lat']) && is_numeric($element['lon'])) {
            $pointLat = (float) $element['lat'];
            $pointLon = (float) $element['lon'];
        } elseif (isset($element['center']) && is_array($element['center']) && is_numeric($element['center']['lat'] ?? null) && is_numeric($element['center']['lon'] ?? null)) {
            $pointLat = (float) $element['center']['lat'];
            $pointLon = (float) $element['center']['lon'];
        }

        $distancePenalty = 0.0;
        if ($pointLat !== null && $pointLon !== null) {
            $distancePenalty = live_collector_distance_meters($latitude, $longitude, $pointLat, $pointLon) / 20.0;
        }

        $score = ($tokenMatches * 20.0) - $distancePenalty;
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = [
                'opening_hours' => $openingHours,
                'name' => (string) ($tags['name'] ?? ''),
            ];
        }
    }

    if ($best !== null) {
        $isOpen247 = live_collector_detect_24_7($best['opening_hours']) ? 1 : 0;
        $encoded = json_encode([
            'source_key' => 'opening_hours',
            'source_value' => $best['opening_hours'],
            'source' => 'osm_overpass',
            'facility_match_name' => $best['name'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'is_open_24_7' => $isOpen247,
            'operating_hours_json' => is_string($encoded) ? $encoded : null,
        ];
    }

    return null;
}

function live_collector_extract_opening_hours_from_tags(array $tags): string
{
    foreach (['opening_hours', 'service_times', 'collection_times', 'opening_hours:covid19'] as $key) {
        $value = trim((string) ($tags[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function live_collector_distance_meters(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

function live_collector_parse_int($value): ?int
{
    if ($value === null) {
        return null;
    }

    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if (is_int($value)) {
        return $value;
    }

    if (is_float($value)) {
        return (int) $value;
    }

    if (is_string($value)) {
        $cleaned = str_replace(',', '', trim($value));
        if ($cleaned !== '' && preg_match('/^-?\d+$/', $cleaned)) {
            return (int) $cleaned;
        }
    }

    return null;
}

function live_collector_parse_float($value): ?float
{
    if ($value === null) {
        return null;
    }

    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }

    if (is_string($value)) {
        $cleaned = str_replace(',', '', trim($value));
        if ($cleaned === '') {
            return null;
        }

        if (is_numeric($cleaned)) {
            return (float) $cleaned;
        }
    }

    return null;
}

function live_collector_parse_timestamp(array $payload): DateTimeImmutable
{
    $candidates = [
        $payload['MessageDate'] ?? null,
        $payload['messageDate'] ?? null,
        $payload['last_updated'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (!is_scalar($candidate) || trim((string) $candidate) === '') {
            continue;
        }

        $text = trim((string) $candidate);
        $text = str_replace('Z', '+00:00', $text);
        if (!preg_match('/(?:[+\-]\d{2}:\d{2})$/', $text)) {
            $text .= '+00:00';
        }

        try {
            $date = new DateTimeImmutable($text);
            return $date->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            continue;
        }
    }

    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function live_collector_availability_class(float $occupancyRate, int $available): string
{
    if ($available <= 0) {
        return 'Full';
    }

    if ($occupancyRate >= 0.70) {
        return 'Limited';
    }

    return 'Available';
}

function live_collector_persist_items(array $items): array
{
    if ($items === []) {
        return [
            'new_snapshots' => 0,
            'changed_snapshots' => 0,
            'unchanged_snapshots' => 0,
            'last_snapshot_at' => null,
        ];
    }

    $connection = db();
    $facilityStatement = $connection->prepare(
        "
        INSERT INTO parking_facilities (facility_id, facility_name, latitude, longitude, capacity, is_open_24_7, operating_hours_json)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            facility_name = VALUES(facility_name),
            latitude = COALESCE(VALUES(latitude), latitude),
            longitude = COALESCE(VALUES(longitude), longitude),
            capacity = COALESCE(VALUES(capacity), capacity),
            is_open_24_7 = COALESCE(VALUES(is_open_24_7), is_open_24_7),
            operating_hours_json = COALESCE(VALUES(operating_hours_json), operating_hours_json)
        "
    );

    $snapshotStatement = $connection->prepare(
        "
        INSERT INTO occupancy_snapshots
            (facility_id, recorded_at, occupied, available, occupancy_rate, availability_class, hour, day_of_week, is_weekend, month, snapshot_source)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            occupied = VALUES(occupied),
            available = VALUES(available),
            occupancy_rate = VALUES(occupancy_rate),
            availability_class = VALUES(availability_class),
            hour = VALUES(hour),
            day_of_week = VALUES(day_of_week),
            is_weekend = VALUES(is_weekend),
            month = VALUES(month),
            snapshot_source = VALUES(snapshot_source)
        "
    );

    if (!$facilityStatement || !$snapshotStatement) {
        throw new RuntimeException('Unable to prepare the dashboard live-sync database statements.');
    }

    $metrics = [
        'new_snapshots' => 0,
        'changed_snapshots' => 0,
        'unchanged_snapshots' => 0,
        'last_snapshot_at' => null,
    ];

    $connection->begin_transaction();
    try {
        foreach ($items as $item) {
            live_collector_upsert_facility($facilityStatement, $item);
            $resultCode = live_collector_upsert_snapshot($snapshotStatement, $item);

            if ($resultCode === 1) {
                $metrics['new_snapshots']++;
            } elseif ($resultCode === 2) {
                $metrics['changed_snapshots']++;
            } else {
                $metrics['unchanged_snapshots']++;
            }

            $recordedAt = $item['recorded_at']->format('Y-m-d H:i:s');
            if ($metrics['last_snapshot_at'] === null || strcmp($recordedAt, (string) $metrics['last_snapshot_at']) > 0) {
                $metrics['last_snapshot_at'] = $recordedAt;
            }
        }

        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    } finally {
        $facilityStatement->close();
        $snapshotStatement->close();
    }

    return $metrics;
}

function live_collector_upsert_facility(mysqli_stmt $statement, array $item): void
{
    $facilityId = (string) $item['facility_id'];
    $facilityName = (string) $item['facility_name'];
    $latitude = $item['latitude'] === null ? null : sprintf('%.6F', (float) $item['latitude']);
    $longitude = $item['longitude'] === null ? null : sprintf('%.6F', (float) $item['longitude']);
    $capacity = (int) $item['capacity'];
    $isOpen247 = array_key_exists('is_open_24_7', $item) && $item['is_open_24_7'] !== null
        ? (int) $item['is_open_24_7']
        : null;
    $operatingHoursJson = isset($item['operating_hours_json']) && is_string($item['operating_hours_json'])
        ? $item['operating_hours_json']
        : null;

    $statement->bind_param('ssssiss', $facilityId, $facilityName, $latitude, $longitude, $capacity, $isOpen247, $operatingHoursJson);
    if (!$statement->execute()) {
        throw new RuntimeException('Failed to upsert parking_facilities data: ' . $statement->error);
    }
}

function live_collector_upsert_snapshot(mysqli_stmt $statement, array $item): int
{
    $facilityId = (string) $item['facility_id'];
    $recordedAt = $item['recorded_at']->format('Y-m-d H:i:s');
    $occupied = (int) $item['occupied'];
    $available = (int) $item['available'];
    $occupancyRate = sprintf('%.4F', (float) $item['occupancy_rate']);
    $availabilityClass = (string) $item['availability_class'];
    $hour = (int) $item['hour'];
    $dayOfWeek = (int) $item['day_of_week'];
    $isWeekend = (int) $item['is_weekend'];
    $month = (int) $item['month'];
    $snapshotSource = 'live';

    $statement->bind_param(
        'ssiissiiiis',
        $facilityId,
        $recordedAt,
        $occupied,
        $available,
        $occupancyRate,
        $availabilityClass,
        $hour,
        $dayOfWeek,
        $isWeekend,
        $month,
        $snapshotSource
    );

    if (!$statement->execute()) {
        throw new RuntimeException('Failed to upsert occupancy_snapshots data: ' . $statement->error);
    }

    return (int) $statement->affected_rows;
}
