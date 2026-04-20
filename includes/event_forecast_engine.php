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

    $latestSnapshots = latest_snapshots();
    $facilities = events_prepare_facilities($latestSnapshots);
    $modelPredictions = ml_model_predictions_lookup(snapshot_data_source());
    $forecastedEvents = array_map(
        fn(array $event) => events_build_forecast($event, $facilities, $modelPredictions),
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
        'featured_title' => $selectedEvent && isset($selectedEvent['closest_forecast']) && is_array($selectedEvent['closest_forecast'])
            ? 'Closest Facility'
            : 'Featured Facility',
        'top_impact_labels' => ($selectedEvent && ($selectedEvent['is_prediction_day'] ?? false))
            ? array_map(fn(array $row) => $row['facility_name'], $selectedEvent['top_impact'])
            : [],
        'top_impact_metric_label' => 'Projected occupancy %',
        'top_impact_values' => ($selectedEvent && ($selectedEvent['is_prediction_day'] ?? false))
            ? array_map(
                fn(array $row) => round(((float) ($row['predicted_rate'] ?? 0)) * 100, 1),
                $selectedEvent['top_impact']
            )
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

function events_build_forecast(array $event, array $facilities, array $modelPredictions = []): array
{
    $now = new DateTimeImmutable('now', events_timezone());
    $start = events_parse_datetime($event['starts_at']);
    $end = events_parse_datetime($event['ends_at']);
    $baselineProfiles = events_baseline_profiles((int) $start->format('G'), ((int) $start->format('N')) >= 6 ? 1 : 0);
    $fallbackProfiles = events_baseline_profiles((int) $start->format('G'), null);
    $currentProfiles = events_baseline_profiles((int) $now->format('G'), ((int) $now->format('N')) >= 6 ? 1 : 0);
    $currentFallbackProfiles = events_baseline_profiles((int) $now->format('G'), null);
    $weights = [];
    $totalWeight = 0.0;
    $eventTiming = events_event_timing_meta($start, $end, $now);
    $isPredictionDay = (bool) ($eventTiming['is_prediction_day'] ?? false);
    $horizonHours = [1, 2, 3];
    $horizonLabels = [];

    foreach ($horizonHours as $hoursAhead) {
        $target = $now->modify('+' . $hoursAhead . ' hours');
        $horizonLabels[(string) $hoursAhead] = $target->format('D H:i');
    }

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
        $facilityModelPredictions = is_array($modelPredictions[$facility['facility_id']] ?? null)
            ? $modelPredictions[$facility['facility_id']]
            : [];
        $profile = $baselineProfiles[$facility['facility_id']] ?? $fallbackProfiles[$facility['facility_id']] ?? null;
        $currentProfile = $currentProfiles[$facility['facility_id']] ?? $currentFallbackProfiles[$facility['facility_id']] ?? null;
        $baselineOccupied = $profile !== null
            ? (int) round((((float) $profile['avg_occupied']) * 0.75) + ($facility['occupied'] * 0.25))
            : (int) $facility['occupied'];
        $baselineOccupied = max(0, min((int) $facility['capacity'], $baselineOccupied));

        $baselineCurrentOccupied = $currentProfile !== null
            ? (int) round((((float) $currentProfile['avg_occupied']) * 0.45) + ($facility['occupied'] * 0.55))
            : (int) $facility['occupied'];
        $baselineCurrentOccupied = max(0, min((int) $facility['capacity'], $baselineCurrentOccupied));

        $additionalVehicles = (int) round($event['peak_vehicle_demand'] * ($weights[$facility['facility_id']] / $totalWeight));
        $currentEventFactor = $isPredictionDay ? events_event_activity_factor($start, $end, $now) : 0.0;
        $currentEventLift = (int) round($additionalVehicles * $currentEventFactor);

        $currentOccupied = max(0, min((int) $facility['capacity'], (int) $facility['occupied'] + $currentEventLift));
        $currentAvailable = max(0, (int) $facility['capacity'] - $currentOccupied);
        $currentRate = $facility['capacity'] > 0 ? $currentOccupied / (int) $facility['capacity'] : 0.0;
        $predictedOccupied = $isPredictionDay
            ? max(0, min((int) $facility['capacity'], $baselineOccupied + $additionalVehicles))
            : $currentOccupied;
        $predictedAvailable = max(0, (int) $facility['capacity'] - $predictedOccupied);
        $predictedRate = $facility['capacity'] > 0 ? $predictedOccupied / (int) $facility['capacity'] : 0.0;
        $effectiveLift = $isPredictionDay ? $additionalVehicles : 0;
        $impactScoreOccupied = max(0, min((int) $facility['capacity'], $baselineOccupied + $additionalVehicles));
        $impactScoreRate = $facility['capacity'] > 0 ? $impactScoreOccupied / (int) $facility['capacity'] : 0.0;
        $distanceKm = null;
        $horizonForecasts = [];

        if ($facility['effective_latitude'] !== null && $facility['effective_longitude'] !== null) {
            $distanceKm = events_haversine_km(
                (float) $event['venue_lat'],
                (float) $event['venue_lng'],
                (float) $facility['effective_latitude'],
                (float) $facility['effective_longitude']
            );
        }

        foreach ($horizonHours as $hoursAhead) {
            if (!$isPredictionDay) {
                $horizonForecasts[(string) $hoursAhead] = [
                    'hours_ahead' => $hoursAhead,
                    'target_time' => null,
                    'target_label' => null,
                    'baseline_occupied' => null,
                    'event_lift' => null,
                    'predicted_occupied' => null,
                    'predicted_available' => null,
                    'predicted_rate' => null,
                    'predicted_status' => null,
                    'note' => 'Prediction available on the event day',
                ];
                continue;
            }

            $targetTime = $now->modify('+' . $hoursAhead . ' hours');
            $hourProfiles = events_baseline_profiles((int) $targetTime->format('G'), ((int) $targetTime->format('N')) >= 6 ? 1 : 0);
            $hourFallbackProfiles = events_baseline_profiles((int) $targetTime->format('G'), null);
            $hourProfile = $hourProfiles[$facility['facility_id']] ?? $hourFallbackProfiles[$facility['facility_id']] ?? null;
            $currentAvgOccupied = $currentProfile !== null
                ? (float) ($currentProfile['avg_occupied'] ?? $facility['occupied'])
                : (float) $facility['occupied'];
            $modelPrediction = is_array($facilityModelPredictions[(string) $hoursAhead] ?? null)
                ? $facilityModelPredictions[(string) $hoursAhead]
                : null;

            if ($modelPrediction !== null) {
                $targetBaselineOccupied = (int) ($modelPrediction['predicted_occupied'] ?? 0);
            } else {
                $targetBaselineOccupied = $hourProfile !== null
                    ? (int) round((((float) $hourProfile['avg_occupied']) * 0.70) + ($facility['occupied'] * 0.30))
                    : (int) round(($currentAvgOccupied * 0.55) + ($facility['occupied'] * 0.45));
            }
            $targetBaselineOccupied = max(0, min((int) $facility['capacity'], $targetBaselineOccupied));

            $targetEventFactor = events_event_activity_factor($start, $end, $targetTime);
            $targetLift = (int) round($additionalVehicles * $targetEventFactor);
            $targetOccupied = max(0, min((int) $facility['capacity'], $targetBaselineOccupied + $targetLift));
            $targetAvailable = max(0, (int) $facility['capacity'] - $targetOccupied);
            $targetRate = $facility['capacity'] > 0 ? $targetOccupied / (int) $facility['capacity'] : 0.0;

            $horizonForecasts[(string) $hoursAhead] = [
                'hours_ahead' => $hoursAhead,
                'target_time' => $targetTime->format('Y-m-d H:i:s'),
                'target_label' => $targetTime->format('D H:i'),
                'baseline_occupied' => $targetBaselineOccupied,
                'event_lift' => $targetLift,
                'predicted_occupied' => $targetOccupied,
                'predicted_available' => $targetAvailable,
                'predicted_rate' => $targetRate,
                'predicted_status' => events_availability_class($targetRate),
            ];
        }

        $forecasts[] = [
            'facility_id' => $facility['facility_id'],
            'facility_name' => $facility['facility_name'],
            'capacity' => (int) $facility['capacity'],
            'is_current_event' => $eventTiming['is_current_event'],
            'current_available' => $currentAvailable,
            'current_occupied' => $currentOccupied,
            'current_rate' => $currentRate,
            'current_status' => events_availability_class($currentRate),
            'baseline_occupied' => $baselineOccupied,
            'event_lift' => $effectiveLift,
            'predicted_occupied' => $predictedOccupied,
            'predicted_available' => $predictedAvailable,
            'predicted_rate' => $predictedRate,
            'predicted_status' => events_availability_class($predictedRate),
            'potential_event_lift' => $additionalVehicles,
            'impact_score_rate' => $impactScoreRate,
            'distance_km' => $distanceKm,
            'horizon_forecasts' => $horizonForecasts,
            'horizon_1h_available' => $isPredictionDay ? (int) ($horizonForecasts['1']['predicted_available'] ?? 0) : null,
            'horizon_2h_available' => $isPredictionDay ? (int) ($horizonForecasts['2']['predicted_available'] ?? 0) : null,
            'horizon_3h_available' => $isPredictionDay ? (int) ($horizonForecasts['3']['predicted_available'] ?? 0) : null,
        ];
    }

    $impactRanked = $forecasts;
    usort(
        $impactRanked,
        function (array $a, array $b): int {
            $leftLift = (int) ($a['potential_event_lift'] ?? $a['event_lift'] ?? 0);
            $rightLift = (int) ($b['potential_event_lift'] ?? $b['event_lift'] ?? 0);

            if ($leftLift === $rightLift) {
                $leftScore = (float) ($a['impact_score_rate'] ?? $a['predicted_rate'] ?? 0);
                $rightScore = (float) ($b['impact_score_rate'] ?? $b['predicted_rate'] ?? 0);
                if (abs($leftScore - $rightScore) < 0.00001) {
                    return strcmp($a['facility_name'], $b['facility_name']);
                }

                return $rightScore <=> $leftScore;
            }

            return $rightLift <=> $leftLift;
        }
    );

    $nearbyRadiusKm = events_display_radius_km($event);
    $nearbyRanked = array_values(array_filter(
        $impactRanked,
        static fn(array $row): bool => $row['distance_km'] !== null && $row['distance_km'] <= $nearbyRadiusKm
    ));

    usort(
        $nearbyRanked,
        static function (array $a, array $b): int {
            $distanceComparison = ((float) ($a['distance_km'] ?? 9999)) <=> ((float) ($b['distance_km'] ?? 9999));
            if ($distanceComparison !== 0) {
                return $distanceComparison;
            }

            return strcmp((string) ($a['facility_name'] ?? ''), (string) ($b['facility_name'] ?? ''));
        }
    );

    $closestForecast = $nearbyRanked[0] ?? null;
    $closestFacilityId = (string) ($closestForecast['facility_id'] ?? '');
    foreach ($nearbyRanked as $index => $row) {
        $nearbyRanked[$index]['is_closest'] = $closestFacilityId !== '' && (string) ($row['facility_id'] ?? '') === $closestFacilityId;
    }

    $statusCounts = ['full' => 0, 'limited' => 0, 'available' => 0];
    foreach ($forecasts as $forecast) {
        $normalized = strtolower($isPredictionDay ? $forecast['predicted_status'] : $forecast['current_status']);
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

    if ($closestForecast !== null) {
        $featuredForecast = $closestForecast;
    }

    $event['starts_at_display'] = $start->format('D, d M Y g:ia T');
    $event['ends_at_display'] = $end->format('D, d M Y g:ia T');
    $event['is_current_event'] = $eventTiming['is_current_event'];
    $event['is_upcoming_event'] = $eventTiming['is_upcoming_event'];
    $event['is_prediction_day'] = $isPredictionDay;
    $event['prediction_note'] = $eventTiming['prediction_note'];
    $event['timing_label'] = $eventTiming['timing_label'];
    $event['timing_note'] = $eventTiming['timing_note'];
    $event['horizon_labels'] = $horizonLabels;
    $event['status_counts'] = $statusCounts;
    $event['forecasts'] = $forecasts;
    $event['impact_ranked'] = $impactRanked;
    $event['nearby_radius_km'] = $nearbyRadiusKm;
    $event['nearby_ranked'] = $nearbyRanked;
    $event['closest_forecast'] = $closestForecast;
    $event['top_impact'] = array_slice($impactRanked, 0, 8);
    $event['featured_forecast'] = $featuredForecast;
    $event['network_headline'] = $featuredForecast !== null
        ? sprintf(
            '%s is the closest tracked site for this event, with %d spaces currently available.',
            $featuredForecast['facility_name'],
            (int) ($featuredForecast['current_available'] ?? 0)
        )
        : 'No forecast data is available for this event yet.';

    return $event;
}

function events_event_timing_meta(DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $now): array
{
    $isPredictionDay = $start->format('Y-m-d') === $now->format('Y-m-d');

    if ($now >= $start && $now <= $end) {
        return [
            'is_current_event' => true,
            'is_upcoming_event' => false,
            'is_prediction_day' => true,
            'timing_label' => 'Current event',
            'timing_note' => 'This event is live now. Event-day prediction is enabled and refreshed from live conditions.',
            'prediction_note' => 'Predictions are active because this is the same day as the event.',
        ];
    }

    return [
        'is_current_event' => false,
        'is_upcoming_event' => $now < $start,
        'is_prediction_day' => $isPredictionDay,
        'timing_label' => $now < $start ? 'Upcoming event' : 'Event ended',
        'timing_note' => $isPredictionDay
            ? 'This event is scheduled for today. Event-day prediction will activate as today progresses.'
            : 'Prediction is shown only on the event day. Until then, this page shows current nearby availability and closest-facility status.',
        'prediction_note' => $isPredictionDay
            ? 'Predictions are available today for this event.'
            : 'Prediction will be available on the current day of the event.',
    ];
}

function events_event_activity_factor(DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $target): float
{
    if ($target < $start) {
        $secondsToStart = $start->getTimestamp() - $target->getTimestamp();
        $rampWindow = 2 * 3600;
        if ($secondsToStart >= $rampWindow) {
            return 0.0;
        }

        return max(0.0, min(1.0, 1 - ($secondsToStart / $rampWindow)));
    }

    if ($target <= $end) {
        return 1.0;
    }

    $secondsAfterEnd = $target->getTimestamp() - $end->getTimestamp();
    $tailWindow = 2 * 3600;
    if ($secondsAfterEnd >= $tailWindow) {
        return 0.0;
    }

    return max(0.0, min(1.0, 1 - ($secondsAfterEnd / $tailWindow)));
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
    $source = snapshot_data_source();
    $cacheKey = $source . '|' . $hour . '|' . ($isWeekend === null ? 'any' : (string) $isWeekend);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $hourStart = max(0, $hour - 1);
    $hourEnd = min(23, $hour + 1);
    $sourceCondition = snapshot_source_condition();

    if ($isWeekend === null) {
        $stmt = db()->prepare(
            "
            SELECT facility_id, AVG(occupied) AS avg_occupied, AVG(available) AS avg_available,
                   AVG(occupancy_rate) AS avg_rate, COUNT(*) AS sample_count
            FROM occupancy_snapshots
            WHERE {$sourceCondition}
              AND hour BETWEEN ? AND ?
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
            WHERE {$sourceCondition}
              AND is_weekend = ? AND hour BETWEEN ? AND ?
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
