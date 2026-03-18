<?php
require_once __DIR__ . '/db.php';

function site_title(string $pageTitle = ''): string
{
    $site = app_config()['site_name'] ?? 'Smart Parking Web';
    return $pageTitle ? $pageTitle . ' | ' . $site : $site;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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

function dataset_overview(): array
{
    return fetch_one_assoc("SELECT MIN(recorded_at) AS min_time, MAX(recorded_at) AS max_time, COUNT(*) AS observations FROM occupancy_snapshots") ?? ['min_time' => null, 'max_time' => null, 'observations' => 0];
}

function summary_metrics(): array
{
    $sql = "
        SELECT 
            COUNT(*) AS facilities_count,
            SUM(f.capacity) AS total_capacity,
            SUM(latest.occupied) AS occupied_now,
            SUM(COALESCE(latest.available, 0)) AS available_now,
            AVG(latest.occupancy_rate) * 100 AS avg_occupancy,
            MAX(latest.recorded_at) AS last_refresh,
            busiest.facility_name AS busiest_name,
            busiest.occupancy_rate * 100 AS busiest_rate
        FROM (
            SELECT s.*
            FROM occupancy_snapshots s
            INNER JOIN (
                SELECT facility_id, MAX(recorded_at) AS max_recorded_at
                FROM occupancy_snapshots
                GROUP BY facility_id
            ) latest_map
                ON s.facility_id = latest_map.facility_id
               AND s.recorded_at = latest_map.max_recorded_at
        ) latest
        INNER JOIN parking_facilities f ON f.facility_id = latest.facility_id
        CROSS JOIN (
            SELECT f2.facility_name, s2.occupancy_rate
            FROM occupancy_snapshots s2
            INNER JOIN (
                SELECT facility_id, MAX(recorded_at) AS max_recorded_at
                FROM occupancy_snapshots
                GROUP BY facility_id
            ) latest_map2
                ON s2.facility_id = latest_map2.facility_id
               AND s2.recorded_at = latest_map2.max_recorded_at
            INNER JOIN parking_facilities f2 ON f2.facility_id = s2.facility_id
            ORDER BY s2.occupancy_rate DESC, f2.facility_name ASC
            LIMIT 1
        ) busiest
    ";

    return fetch_one_assoc($sql) ?? [];
}

function latest_snapshots(): array
{
    $sql = "
        SELECT s.facility_id, f.facility_name, f.latitude, f.longitude, f.capacity,
               s.recorded_at, s.occupied, s.available, s.occupancy_rate, s.availability_class
        FROM occupancy_snapshots s
        INNER JOIN (
            SELECT facility_id, MAX(recorded_at) AS max_recorded_at
            FROM occupancy_snapshots
            GROUP BY facility_id
        ) latest_map
            ON s.facility_id = latest_map.facility_id
           AND s.recorded_at = latest_map.max_recorded_at
        INNER JOIN parking_facilities f ON f.facility_id = s.facility_id
        ORDER BY s.occupancy_rate DESC, f.facility_name ASC
    ";

    return fetch_all_assoc($sql);
}

function top_latest_facilities(int $limit = 8): array
{
    $limit = max(1, min($limit, 20));
    $sql = "
        SELECT * FROM (
            SELECT s.facility_id, f.facility_name, f.capacity, s.occupied, s.available,
                   s.occupancy_rate, s.availability_class
            FROM occupancy_snapshots s
            INNER JOIN (
                SELECT facility_id, MAX(recorded_at) AS max_recorded_at
                FROM occupancy_snapshots
                GROUP BY facility_id
            ) latest_map
                ON s.facility_id = latest_map.facility_id
               AND s.recorded_at = latest_map.max_recorded_at
            INNER JOIN parking_facilities f ON f.facility_id = s.facility_id
            ORDER BY s.occupancy_rate DESC, f.facility_name ASC
        ) ranked
        LIMIT {$limit}
    ";
    return fetch_all_assoc($sql);
}

function hourly_average_occupancy(): array
{
    return fetch_all_assoc("SELECT hour, ROUND(AVG(occupancy_rate) * 100, 2) AS average_occupancy FROM occupancy_snapshots GROUP BY hour ORDER BY hour ASC");
}

function availability_distribution(): array
{
    $sql = "
        SELECT availability_class, COUNT(*) AS total
        FROM (
            SELECT s.availability_class
            FROM occupancy_snapshots s
            INNER JOIN (
                SELECT facility_id, MAX(recorded_at) AS max_recorded_at
                FROM occupancy_snapshots
                GROUP BY facility_id
            ) latest_map
                ON s.facility_id = latest_map.facility_id
               AND s.recorded_at = latest_map.max_recorded_at
        ) latest
        GROUP BY availability_class
        ORDER BY total DESC
    ";
    return fetch_all_assoc($sql);
}

function facility_options(): array
{
    return fetch_all_assoc("SELECT facility_id, facility_name FROM parking_facilities ORDER BY facility_name ASC");
}

function facility_summary(string $facilityId): ?array
{
    $sql = "
        SELECT f.facility_id, f.facility_name, f.latitude, f.longitude, f.capacity,
               s.recorded_at, s.occupied, s.available, s.occupancy_rate, s.availability_class
        FROM parking_facilities f
        INNER JOIN occupancy_snapshots s ON s.facility_id = f.facility_id
        INNER JOIN (
            SELECT facility_id, MAX(recorded_at) AS max_recorded_at
            FROM occupancy_snapshots
            WHERE facility_id = ?
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
    return $result->fetch_assoc() ?: null;
}

function facility_history(string $facilityId): array
{
    $sql = "SELECT recorded_at, occupied, available, ROUND(occupancy_rate * 100, 2) AS occupancy_percent FROM occupancy_snapshots WHERE facility_id = ? ORDER BY recorded_at ASC";
    $stmt = db()->prepare($sql);
    $stmt->bind_param('s', $facilityId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

function peak_hour(): ?array
{
    return fetch_one_assoc("SELECT hour, ROUND(AVG(occupancy_rate) * 100, 2) AS average_occupancy FROM occupancy_snapshots GROUP BY hour ORDER BY AVG(occupancy_rate) DESC, hour ASC LIMIT 1");
}

function top_average_occupancy(int $limit = 10): array
{
    $limit = max(1, min($limit, 20));
    return fetch_all_assoc("SELECT f.facility_name, ROUND(AVG(s.occupancy_rate) * 100, 2) AS average_occupancy FROM occupancy_snapshots s INNER JOIN parking_facilities f ON f.facility_id = s.facility_id GROUP BY f.facility_id, f.facility_name ORDER BY AVG(s.occupancy_rate) DESC, f.facility_name ASC LIMIT {$limit}");
}

function capacity_leaders(int $limit = 10): array
{
    $limit = max(1, min($limit, 20));
    return fetch_all_assoc("SELECT facility_name, capacity FROM parking_facilities ORDER BY capacity DESC, facility_name ASC LIMIT {$limit}");
}

function regression_metrics(): array
{
    return fetch_all_assoc("SELECT f.facility_name, m.sample_size, m.mae, m.rmse, m.r2 FROM model_regression_metrics m INNER JOIN parking_facilities f ON f.facility_id = m.facility_id ORDER BY m.rmse ASC, f.facility_name ASC");
}

function classification_metrics(): array
{
    return fetch_all_assoc("SELECT f.facility_name, m.sample_size, m.accuracy FROM model_classification_metrics m INNER JOIN parking_facilities f ON f.facility_id = m.facility_id ORDER BY m.accuracy DESC, f.facility_name ASC");
}

function percent_badge_class(float $percentage): string
{
    if ($percentage >= 90) return 'status-full';
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
