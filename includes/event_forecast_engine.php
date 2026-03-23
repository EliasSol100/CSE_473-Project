<?php
require_once __DIR__ . '/functions.php';

function events_timezone(): DateTimeZone
{
    static $timezone = null;

    if (!$timezone instanceof DateTimeZone) {
        $timezone = new DateTimeZone('Australia/Sydney');
    }

    return $timezone;
}

function events_curated_catalog(): array
{
    return [
        [
            'id' => 'pulse-alive-2026-03-20',
            'title' => 'Pulse Alive',
            'starts_at' => '2026-03-20 19:30:00',
            'ends_at' => '2026-03-20 21:30:00',
            'venue_name' => 'Ken Rosewall Arena',
            'venue_area' => 'Sydney Olympic Park',
            'venue_address' => 'Ken Rosewall Arena, Tennis Centre, Sydney Olympic Park NSW',
            'venue_lat' => -33.8474,
            'venue_lng' => 151.0672,
            'source_label' => 'Sydney Olympic Park',
            'source_url' => 'https://www.sydneyolympicpark.com.au/things-to-see-and-do/pulse-alive',
            'source_basis' => 'Official venue listing',
            'attendance_estimate' => 5200,
            'attendance_label' => 'Estimated attendance',
            'attendance_note' => 'Estimated from the official description of a large-scale arena event showcasing thousands of NSW public school performers.',
            'peak_vehicle_demand' => 210,
            'demand_note' => 'Higher evening arrivals, but only part of the crowd is expected to spill into monitored park-and-ride facilities.',
            'distance_decay_km' => 12.5,
            'max_distance_km' => 58,
            'display_max_distance_km' => 10,
            'network_floor' => 0.03,
            'focus_keywords' => ['seven hills', 'st marys', 'penrith', 'warwick farm', 'west ryde', 'revesby', 'campbelltown', 'edmondson park'],
            'secondary_keywords' => ['bella vista', 'hills showground', 'cherrybrook', 'tallawong', 'kellyville', 'gosford', 'sutherland'],
            'featured_facility_id' => '488',
            'featured_reason' => 'Seven Hills is positioned to absorb westbound rail demand into Sydney Olympic Park for a Friday night arena event.',
        ],
        [
            'id' => 'nsw-opens-2026-03-21',
            'title' => 'NSW Opens - Hart Sports Track And Field Championships',
            'starts_at' => '2026-03-21 09:00:00',
            'ends_at' => '2026-03-21 18:00:00',
            'venue_name' => 'Sydney Olympic Park Athletic Centre',
            'venue_area' => 'Sydney Olympic Park',
            'venue_address' => 'Sydney Olympic Park Athletic Centre, Edwin Flack Ave, Sydney Olympic Park NSW',
            'venue_lat' => -33.8494,
            'venue_lng' => 151.0730,
            'source_label' => 'Sydney Olympic Park Athletic Centre',
            'source_url' => 'https://www.sydneyolympicpark.com.au/athletic-centre/events',
            'source_basis' => 'Official venue calendar with crowd forecast',
            'attendance_estimate' => 2000,
            'attendance_label' => 'Official crowd forecast',
            'attendance_note' => 'The venue calendar lists a crowd of 2,000 for the Saturday program.',
            'peak_vehicle_demand' => 155,
            'demand_note' => 'Daytime championships spread arrivals across the morning, so the peak spillover into monitored commuter parking is moderate.',
            'distance_decay_km' => 13,
            'max_distance_km' => 58,
            'display_max_distance_km' => 10,
            'network_floor' => 0.025,
            'focus_keywords' => ['seven hills', 'st marys', 'penrith', 'warwick farm', 'west ryde', 'revesby', 'campbelltown', 'edmondson park'],
            'secondary_keywords' => ['bella vista', 'hills showground', 'cherrybrook', 'tallawong', 'kellyville', 'gosford', 'sutherland'],
            'featured_facility_id' => '22',
            'featured_reason' => 'Penrith multi-level is one of the stronger western catchment sites for all-day athletic events at Olympic Park.',
        ],
        [
            'id' => 'norwest-quarter-grand-opening-2026-03-22',
            'title' => 'Norwest Quarter Grand Opening',
            'starts_at' => '2026-03-22 11:00:00',
            'ends_at' => '2026-03-22 15:00:00',
            'venue_name' => 'Norwest Quarter',
            'venue_area' => 'Norwest / Bella Vista',
            'venue_address' => '40-42 Solent Circuit, Norwest NSW 2153',
            'venue_lat' => -33.7324,
            'venue_lng' => 150.9615,
            'source_label' => 'Humanitix listing for Norwest Quarter by Mulpha',
            'source_url' => 'https://events.humanitix.com/nq-grand-opening',
            'source_basis' => 'Primary event listing',
            'attendance_estimate' => 1600,
            'attendance_label' => 'Estimated attendance',
            'attendance_note' => 'Estimated from a four-hour local launch with family entertainment, food tasting, live music, tours and prizes.',
            'peak_vehicle_demand' => 340,
            'demand_note' => 'The event is local, family-oriented and midday-focused, so the forecast assumes strong private-car usage and meaningful spillover into nearby station parking.',
            'distance_decay_km' => 5.8,
            'max_distance_km' => 26,
            'display_max_distance_km' => 10,
            'network_floor' => 0.01,
            'focus_keywords' => ['bella vista', 'hills showground', 'cherrybrook', 'kellyville', 'tallawong', 'schofields'],
            'secondary_keywords' => ['west ryde', 'north rocks', 'gordon', 'narrabeen', 'warriewood'],
            'featured_facility_id' => '3',
            'featured_reason' => 'Bella Vista Station Car Park (historical only) is used here as the headline example because it sits in the same broader Norwest / Bella Vista catchment.',
        ],
        [
            'id' => 'ssn-double-header-2026-03-22',
            'title' => 'SSN Double Header 2026',
            'starts_at' => '2026-03-22 15:00:00',
            'ends_at' => '2026-03-22 20:30:00',
            'venue_name' => 'Qudos Bank Arena',
            'venue_area' => 'Sydney Olympic Park',
            'venue_address' => '19 Edwin Flack Ave, Sydney Olympic Park NSW 2127',
            'venue_lat' => -33.8468,
            'venue_lng' => 151.0633,
            'source_label' => 'Qudos Bank Arena',
            'source_url' => 'https://qudosbankarena.com.au/',
            'source_basis' => 'Official venue schedule',
            'attendance_estimate' => 13500,
            'attendance_label' => 'Estimated attendance',
            'attendance_note' => 'Estimated from a Sunday Super Netball double-header hosted at the arena, which the venue describes as Australia\'s largest indoor entertainment and sporting arena.',
            'peak_vehicle_demand' => 290,
            'demand_note' => 'Most visitors will use major transport corridors, but the event is large enough to create a meaningful Sunday surge across the wider network.',
            'distance_decay_km' => 14,
            'max_distance_km' => 60,
            'display_max_distance_km' => 10,
            'network_floor' => 0.035,
            'focus_keywords' => ['seven hills', 'st marys', 'penrith', 'warwick farm', 'west ryde', 'revesby', 'campbelltown', 'edmondson park'],
            'secondary_keywords' => ['bella vista', 'hills showground', 'cherrybrook', 'tallawong', 'kellyville', 'gosford', 'sutherland'],
            'featured_facility_id' => '488',
            'featured_reason' => 'Seven Hills is again highlighted because a large Sunday arena crowd can ripple strongly through western rail-linked park-and-ride supply.',
        ],
    ];
}

function events_default_selected_event_id(array $events = []): string
{
    if ($events !== []) {
        return (string) ($events[0]['id'] ?? '');
    }

    $catalog = events_curated_catalog();
    return (string) ($catalog[0]['id'] ?? '');
}

function events_upcoming_bundle(?DateTimeImmutable $referenceNow = null): array
{
    $allEvents = events_curated_catalog();
    $now = $referenceNow instanceof DateTimeImmutable
        ? $referenceNow->setTimezone(events_timezone())
        : new DateTimeImmutable('now', events_timezone());
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
        'generated_at' => $now->format('Y-m-d H:i:s'),
        'window_label' => $today->format('d M') . ' to ' . $windowEnd->format('d M Y'),
        'events' => $forecastedEvents,
        'note' => null,
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

function events_view_payload(?string $selectedEventId = null): array
{
    $bundle = events_upcoming_bundle();
    $events = $bundle['events'];
    $selectedEvent = events_select_event($events, $selectedEventId);
    $featuredForecast = $selectedEvent['featured_forecast'] ?? null;

    return [
        'generated_at' => $bundle['generated_at'],
        'window_label' => $bundle['window_label'],
        'note' => $bundle['note'],
        'events' => $events,
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
