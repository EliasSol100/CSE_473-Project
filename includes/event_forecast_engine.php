<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/event_live_sources.php';

function events_timezone(): DateTimeZone
{
    static $timezone = null;

    if (!$timezone instanceof DateTimeZone) {
        $timezone = new DateTimeZone('Australia/Sydney');
    }

    return $timezone;
}

function events_upcoming_bundle(?DateTimeImmutable $referenceNow = null): array
{
    $now = $referenceNow instanceof DateTimeImmutable
        ? $referenceNow->setTimezone(events_timezone())
        : new DateTimeImmutable('now', events_timezone());
    $catalogBundle = events_live_catalog_bundle($now);
    $allEvents = is_array($catalogBundle['events'] ?? null) ? $catalogBundle['events'] : [];
    $today = $now->setTime(0, 0, 0);
    $windowEnd = $today->modify('+6 days')->setTime(23, 59, 59);
    $upcoming = [];

    foreach ($allEvents as $event) {
        $start = events_parse_datetime($event['starts_at']);
        $end = events_parse_datetime($event['ends_at']);

        if ($end >= $now && $start <= $windowEnd) {
            $upcoming[] = $event;
        }
    }

    usort($upcoming, fn(array $a, array $b) => strcmp($a['starts_at'], $b['starts_at']));

    $facilities = events_prepare_facilities(latest_snapshots());
    $forecastedEvents = array_map(
        fn(array $event) => events_build_forecast($event, $facilities),
        $upcoming
    );

    return [
        'generated_at' => (string) ($catalogBundle['generated_at'] ?? $now->format('Y-m-d H:i:s')),
        'window_label' => $today->format('d M') . ' to ' . $windowEnd->format('d M Y'),
        'events' => $forecastedEvents,
        'note' => $catalogBundle['note'] ?? null,
    ];
}

function events_select_event(array $events, ?string $selectedEventId = null): ?array
{
    $selectedEventId = trim((string) ($selectedEventId ?? ''));

    if ($selectedEventId !== '') {
        foreach ($events as $event) {
            if (($event['id'] ?? null) === $selectedEventId) {
                return $event;
            }
        }
    }

    return $events[0] ?? null;
}

function events_view_payload(?string $selectedEventId = null, ?string $selectedCategory = null): array
{
    $bundle = events_upcoming_bundle();
    $allEvents = $bundle['events'];
    $categorySlug = events_normalize_category_slug($selectedCategory);
    $events = events_filter_by_category($allEvents, $categorySlug);
    $selectedEvent = events_select_event($events, $selectedEventId);
    $featuredForecast = $selectedEvent['featured_forecast'] ?? null;

    return [
        'generated_at' => $bundle['generated_at'],
        'window_label' => $bundle['window_label'],
        'note' => $bundle['note'],
        'events' => $events,
        'category_options' => events_category_options($allEvents),
        'selected_category' => $categorySlug,
        'selected_event_id' => $selectedEvent['id'] ?? null,
        'selected_event' => $selectedEvent,
        'featured_title' => $featuredForecast && (string) ($featuredForecast['facility_id'] ?? '') === '3'
            ? 'Bella Vista Example'
            : 'Featured Facility',
        'top_impact_labels' => $selectedEvent
            ? array_map(fn(array $row) => $row['facility_name'], $selectedEvent['top_impact'])
            : [],
        'top_impact_values' => $selectedEvent
            ? array_map(fn(array $row) => round(((float) $row['predicted_rate']) * 100, 1), $selectedEvent['top_impact'])
            : [],
    ];
}

function events_normalize_category_slug(?string $selectedCategory): string
{
    $normalized = strtolower(trim((string) ($selectedCategory ?? 'all')));
    return $normalized !== '' ? $normalized : 'all';
}

function events_filter_by_category(array $events, string $categorySlug): array
{
    if ($categorySlug === 'all') {
        return array_map(
            static fn(array $event): array => array_merge($event, [
                'active_category_slug' => (string) ($event['category_slug'] ?? 'event'),
                'active_category_label' => (string) ($event['category_label'] ?? 'Event'),
            ]),
            $events
        );
    }

    $filtered = array_values(array_filter(
        $events,
        static function (array $event) use ($categorySlug): bool {
            $slugs = is_array($event['category_slugs'] ?? null)
                ? $event['category_slugs']
                : [trim((string) ($event['category_slug'] ?? ''))];

            foreach ($slugs as $slug) {
                if (strtolower(trim((string) $slug)) === $categorySlug) {
                    return true;
                }
            }

            return false;
        }
    ));

    return array_map(
        static function (array $event) use ($categorySlug): array {
            $labels = is_array($event['category_labels'] ?? null) ? $event['category_labels'] : [];
            $slugs = is_array($event['category_slugs'] ?? null) ? $event['category_slugs'] : [];
            $activeLabel = (string) ($event['category_label'] ?? 'Event');

            foreach ($slugs as $index => $slug) {
                if (strtolower(trim((string) $slug)) === $categorySlug) {
                    $activeLabel = trim((string) ($labels[$index] ?? $activeLabel)) ?: $activeLabel;
                    break;
                }
            }

            return array_merge($event, [
                'active_category_slug' => $categorySlug,
                'active_category_label' => $activeLabel,
            ]);
        },
        $filtered
    );
}

function events_category_options(array $events): array
{
    $options = [];

    foreach ($events as $event) {
        $slugs = is_array($event['category_slugs'] ?? null)
            ? $event['category_slugs']
            : [trim((string) ($event['category_slug'] ?? ''))];
        $labels = is_array($event['category_labels'] ?? null)
            ? $event['category_labels']
            : [trim((string) ($event['category_label'] ?? ''))];

        foreach ($slugs as $index => $slug) {
            $normalizedSlug = trim((string) $slug);
            if ($normalizedSlug === '') {
                continue;
            }

            $label = trim((string) ($labels[$index] ?? $event['category_label'] ?? $normalizedSlug));
            $options[$normalizedSlug] = [
                'slug' => $normalizedSlug,
                'label' => $label !== '' ? $label : ucfirst(str_replace('-', ' ', $normalizedSlug)),
            ];
        }
    }

    uasort(
        $options,
        static fn(array $left, array $right): int => strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''))
    );

    return array_values($options);
}

function events_parse_datetime(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value, events_timezone());
}

function events_prepare_facilities(array $latest): array
{
    $byName = [];
    foreach ($latest as $row) {
        $byName[strtolower(trim((string) $row['facility_name']))] = $row;
    }

    $aliases = [
        'bella vista station car park (historical only)' => 'park&ride - bella vista',
        'hills showground station car park (historical only)' => 'park&ride - hills showground',
        'cherrybrook station car park (historical only)' => 'park&ride - cherrybrook',
    ];

    $facilities = [];
    foreach ($latest as $row) {
        $normalizedName = strtolower(trim((string) $row['facility_name']));
        $aliasSource = isset($aliases[$normalizedName], $byName[$aliases[$normalizedName]])
            ? $byName[$aliases[$normalizedName]]
            : null;

        $effectiveLat = $row['latitude'] !== null
            ? (float) $row['latitude']
            : ($aliasSource['latitude'] !== null ? (float) $aliasSource['latitude'] : null);
        $effectiveLng = $row['longitude'] !== null
            ? (float) $row['longitude']
            : ($aliasSource['longitude'] !== null ? (float) $aliasSource['longitude'] : null);

        $facilities[] = [
            'facility_id' => (string) $row['facility_id'],
            'facility_name' => (string) $row['facility_name'],
            'capacity' => (int) $row['capacity'],
            'occupied' => (int) $row['occupied'],
            'available' => (int) $row['available'],
            'occupancy_rate' => (float) $row['occupancy_rate'],
            'availability_class' => (string) $row['availability_class'],
            'latitude' => $row['latitude'] !== null ? (float) $row['latitude'] : null,
            'longitude' => $row['longitude'] !== null ? (float) $row['longitude'] : null,
            'effective_latitude' => $effectiveLat,
            'effective_longitude' => $effectiveLng,
        ];
    }

    return $facilities;
}

function events_build_forecast(array $event, array $facilities): array
{
    $start = events_parse_datetime($event['starts_at']);
    $baselineProfiles = events_baseline_profiles((int) $start->format('G'), ((int) $start->format('N')) >= 6 ? 1 : 0);
    $fallbackProfiles = events_baseline_profiles((int) $start->format('G'), null);
    $weights = [];
    $totalWeight = 0.0;

    foreach ($facilities as $facility) {
        $weight = events_facility_weight($event, $facility);
        $weights[$facility['facility_id']] = $weight;
        $totalWeight += $weight;
    }

    if ($totalWeight <= 0) {
        $totalWeight = 1.0;
    }

    $forecasts = [];
    foreach ($facilities as $facility) {
        $profile = $baselineProfiles[$facility['facility_id']] ?? $fallbackProfiles[$facility['facility_id']] ?? null;
        $baselineOccupied = $profile !== null
            ? (int) round((((float) $profile['avg_occupied']) * 0.75) + ($facility['occupied'] * 0.25))
            : (int) $facility['occupied'];
        $baselineOccupied = max(0, min((int) $facility['capacity'], $baselineOccupied));

        $additionalVehicles = (int) round($event['peak_vehicle_demand'] * ($weights[$facility['facility_id']] / $totalWeight));
        $predictedOccupied = max(0, min((int) $facility['capacity'], $baselineOccupied + $additionalVehicles));
        $predictedAvailable = max(0, (int) $facility['capacity'] - $predictedOccupied);
        $predictedRate = $facility['capacity'] > 0 ? $predictedOccupied / (int) $facility['capacity'] : 0.0;
        $distanceKm = null;

        if ($facility['effective_latitude'] !== null && $facility['effective_longitude'] !== null) {
            $distanceKm = events_haversine_km(
                (float) $event['venue_lat'],
                (float) $event['venue_lng'],
                (float) $facility['effective_latitude'],
                (float) $facility['effective_longitude']
            );
        }

        $forecasts[] = [
            'facility_id' => $facility['facility_id'],
            'facility_name' => $facility['facility_name'],
            'capacity' => (int) $facility['capacity'],
            'baseline_occupied' => $baselineOccupied,
            'event_lift' => $additionalVehicles,
            'predicted_occupied' => $predictedOccupied,
            'predicted_available' => $predictedAvailable,
            'predicted_rate' => $predictedRate,
            'predicted_status' => events_availability_class($predictedRate),
            'distance_km' => $distanceKm,
        ];
    }

    $impactRanked = $forecasts;
    usort(
        $impactRanked,
        function (array $a, array $b): int {
            if ($a['event_lift'] === $b['event_lift']) {
                if (abs($a['predicted_rate'] - $b['predicted_rate']) < 0.00001) {
                    return strcmp($a['facility_name'], $b['facility_name']);
                }

                return $b['predicted_rate'] <=> $a['predicted_rate'];
            }

            return $b['event_lift'] <=> $a['event_lift'];
        }
    );

    $nearbyRadiusKm = events_display_radius_km($event);
    $nearbyRanked = array_values(array_filter(
        $impactRanked,
        static fn(array $row): bool => $row['distance_km'] !== null && $row['distance_km'] <= $nearbyRadiusKm
    ));

    $statusCounts = ['full' => 0, 'limited' => 0, 'available' => 0];
    foreach ($forecasts as $forecast) {
        $normalized = strtolower($forecast['predicted_status']);
        if (isset($statusCounts[$normalized])) {
            $statusCounts[$normalized]++;
        }
    }

    $featuredFacilityId = (string) ($event['featured_facility_id'] ?? '');
    $featuredForecast = null;
    foreach ($impactRanked as $forecast) {
        if ($featuredFacilityId !== '' && $forecast['facility_id'] === $featuredFacilityId) {
            $featuredForecast = $forecast;
            break;
        }
    }
    if ($featuredForecast === null && isset($impactRanked[0])) {
        $featuredForecast = $impactRanked[0];
    }

    $event['starts_at_display'] = $start->format('D, d M Y g:ia T');
    $event['ends_at_display'] = events_parse_datetime($event['ends_at'])->format('g:ia T');
    $event['status_counts'] = $statusCounts;
    $event['forecasts'] = $forecasts;
    $event['impact_ranked'] = $impactRanked;
    $event['nearby_radius_km'] = $nearbyRadiusKm;
    $event['nearby_ranked'] = $nearbyRanked;
    $event['top_impact'] = array_slice($impactRanked, 0, 8);
    $event['featured_forecast'] = $featuredForecast;
    $event['network_headline'] = $featuredForecast !== null
        ? sprintf(
            '%s is the most sensitive highlighted site for this event, with %d spaces predicted to remain.',
            $featuredForecast['facility_name'],
            $featuredForecast['predicted_available']
        )
        : 'No forecast data is available for this event yet.';

    return $event;
}

function events_display_radius_km(array $event): float
{
    $configured = (float) ($event['display_max_distance_km'] ?? 0);
    if ($configured > 0) {
        return $configured;
    }

    $maxDistance = max(1.0, (float) ($event['max_distance_km'] ?? 20));
    $decayDistance = max(1.0, (float) ($event['distance_decay_km'] ?? 10));

    return min($maxDistance, max(10.0, round($decayDistance * 1.5)));
}

function events_baseline_profiles(int $hour, ?int $isWeekend): array
{
    static $cache = [];
    $cacheKey = $hour . '|' . ($isWeekend === null ? 'any' : (string) $isWeekend);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $hourStart = max(0, $hour - 1);
    $hourEnd = min(23, $hour + 1);

    if ($isWeekend === null) {
        $stmt = db()->prepare(
            "
            SELECT facility_id, AVG(occupied) AS avg_occupied, AVG(available) AS avg_available,
                   AVG(occupancy_rate) AS avg_rate, COUNT(*) AS sample_count
            FROM occupancy_snapshots
            WHERE hour BETWEEN ? AND ?
            GROUP BY facility_id
            "
        );
        $stmt->bind_param('ii', $hourStart, $hourEnd);
    } else {
        $stmt = db()->prepare(
            "
            SELECT facility_id, AVG(occupied) AS avg_occupied, AVG(available) AS avg_available,
                   AVG(occupancy_rate) AS avg_rate, COUNT(*) AS sample_count
            FROM occupancy_snapshots
            WHERE is_weekend = ? AND hour BETWEEN ? AND ?
            GROUP BY facility_id
            "
        );
        $stmt->bind_param('iii', $isWeekend, $hourStart, $hourEnd);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $profiles = [];
    while ($row = $result->fetch_assoc()) {
        $profiles[(string) $row['facility_id']] = $row;
    }
    $stmt->close();

    $cache[$cacheKey] = $profiles;
    return $profiles;
}

function events_facility_weight(array $event, array $facility): float
{
    $facilityName = strtolower($facility['facility_name']);
    $distanceWeight = (float) ($event['network_floor'] ?? 0.01);
    $distanceKm = null;

    if ($facility['effective_latitude'] !== null && $facility['effective_longitude'] !== null) {
        $distanceKm = events_haversine_km(
            (float) $event['venue_lat'],
            (float) $event['venue_lng'],
            (float) $facility['effective_latitude'],
            (float) $facility['effective_longitude']
        );

        if ($distanceKm <= (float) $event['max_distance_km']) {
            $distanceWeight = max(
                $distanceWeight,
                1 / (1 + (($distanceKm / max(1.0, (float) $event['distance_decay_km'])) ** 2))
            );
        }
    }

    $keywordBonus = 1.0;
    foreach ($event['focus_keywords'] as $keyword) {
        if (str_contains($facilityName, $keyword)) {
            $keywordBonus += 1.15;
        }
    }
    foreach ($event['secondary_keywords'] as $keyword) {
        if (str_contains($facilityName, $keyword)) {
            $keywordBonus += 0.45;
        }
    }

    if (str_contains($facilityName, 'historical only')) {
        $keywordBonus += 0.15;
    }

    $capacityWeight = max(0.75, sqrt(max(1, $facility['capacity']) / 220));
    $headroomWeight = 0.65 + max(0.15, (1 - (float) $facility['occupancy_rate']));
    $distancePenalty = $distanceKm !== null && $distanceKm > (float) $event['max_distance_km']
        ? 0.4
        : 1.0;

    return max(
        (float) ($event['network_floor'] ?? 0.01),
        $distanceWeight * $keywordBonus * $capacityWeight * $headroomWeight * $distancePenalty
    );
}

function events_haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadiusKm = 6371.0;

    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $lat1 = deg2rad($lat1);
    $lat2 = deg2rad($lat2);

    $a = sin($dLat / 2) ** 2
        + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

    return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function events_availability_class(float $rate): string
{
    if ($rate < 0.70) {
        return 'Available';
    }

    if ($rate < 0.90) {
        return 'Limited';
    }

    return 'Full';
}
