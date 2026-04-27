<?php

// This file gathers official event feeds and reshapes them into one clean event catalog.
function events_live_cache_path(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'events_live_cache.json';
}

function events_live_fallback_cache_path(): string
{
    return rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'smart_parking_live_events_cache.json';
}

function events_live_cache_paths(): array
{
    return array_values(array_unique([
        events_live_cache_path(),
        events_live_fallback_cache_path(),
    ]));
}

function events_live_cache_ttl_seconds(): int
{
    return 1800;
}

function events_live_http_timeout_seconds(): int
{
    return 8;
}

function events_live_http_connect_timeout_seconds(): int
{
    return 4;
}

function events_live_fetch_budget_seconds(): int
{
    return 25;
}

function events_live_catalog_bundle(?DateTimeImmutable $referenceNow = null): array
{
    $liveNow = new DateTimeImmutable('now', events_timezone());
    $now = $referenceNow instanceof DateTimeImmutable
        ? $referenceNow->setTimezone(events_timezone())
        : $liveNow;

    // Use a fresh cache when possible so every page load does not hit official websites again.
    $cache = events_live_cache_read();
    if ($cache !== null && events_live_cache_is_fresh($cache, $liveNow)) {
        return [
            'generated_at' => (string) ($cache['generated_at'] ?? $liveNow->format('Y-m-d H:i:s')),
            'events' => is_array($cache['events'] ?? null) ? $cache['events'] : [],
            'note' => null,
        ];
    }

    $catalog = [];
    $errors = [];
    $refreshStartedAt = microtime(true);

    foreach (events_live_source_definitions() as $source) {
        // Stop if remote event pages are taking too long, so the website still responds.
        if ((microtime(true) - $refreshStartedAt) >= events_live_fetch_budget_seconds()) {
            $errors[] = 'Live event refresh exceeded the safe time budget, so some sources were skipped.';
            break;
        }

        try {
            $catalog = array_merge($catalog, events_live_fetch_source($source, $liveNow));
        } catch (Throwable $error) {
            $errors[] = sprintf('%s: %s', $source['source_label'], $error->getMessage());
        }
    }

    $catalog = events_live_normalize_catalog($catalog, $liveNow);

    if ($catalog !== []) {
        $bundle = [
            'generated_at' => $liveNow->format('Y-m-d H:i:s'),
            'events' => $catalog,
            'errors' => $errors,
        ];
        if ($referenceNow === null) {
            events_live_cache_write($bundle);
        }

        return [
            'generated_at' => $bundle['generated_at'],
            'events' => $bundle['events'],
            'note' => $errors !== []
                ? 'Some official venue pages could not be refreshed, but the latest maintained event feed still returned upcoming events.'
                : null,
        ];
    }

    if ($cache !== null && is_array($cache['events'] ?? null) && $cache['events'] !== []) {
        return [
            'generated_at' => (string) ($cache['generated_at'] ?? $liveNow->format('Y-m-d H:i:s')),
            'events' => $cache['events'],
            'note' => 'Live venue calendars could not be refreshed just now, so the page is using the most recent successful event sync.',
        ];
    }

    return [
        'generated_at' => $liveNow->format('Y-m-d H:i:s'),
        'events' => [],
        'note' => $errors !== []
            ? 'Official venue event calendars could not be refreshed at the moment.'
            : null,
    ];
}

function events_live_fetch_source(array $source, DateTimeImmutable $now): array
{
    return match ((string) ($source['kind'] ?? '')) {
        'city_of_sydney_weekly' => events_live_fetch_city_of_sydney_weekly_events($source, $now),
        'qudos_rest' => events_live_fetch_qudos_events($source, $now),
        'sopa_split' => events_live_fetch_sopa_split_events($source, $now),
        'sopa_combined' => events_live_fetch_sopa_combined_events($source, $now),
        default => [],
    };
}

function events_live_source_definitions(): array
{
    // Each source keeps its URL and the assumptions needed to estimate parking spillover.
    return [
        [
            'source_key' => 'city-of-sydney',
            'kind' => 'city_of_sydney_weekly',
            'source_label' => 'City of Sydney What\'s On',
            'source_page_url' => 'https://whatson.cityofsydney.nsw.gov.au/?time=week',
            'source_url_base' => 'https://whatson.cityofsydney.nsw.gov.au/events/',
            'source_basis' => 'Official City of Sydney weekly event guide',
            'attendance_label' => 'Estimated crowd',
            'distance_decay_km' => 12.0,
            'max_distance_km' => 60.0,
            'display_max_distance_km' => 10.0,
            'network_floor' => 0.012,
            'focus_keywords' => [],
            'secondary_keywords' => [],
            'featured_facility_id' => '',
            'featured_reason' => 'For broader city events, the highlighted facility reflects the strongest park-and-ride spillover signal from the event area.',
            'source_priority' => 60,
        ],
        array_merge(
            events_live_sydney_olympic_park_profile([
                'source_key' => 'qudos',
                'kind' => 'qudos_rest',
                'source_label' => 'Qudos Bank Arena',
                'source_page_url' => 'https://qudosbankarena.com.au/event-calendar',
                'source_api_url' => 'https://qudosbankarena.com.au/wp-json/wp/v2/event',
                'source_basis' => 'Official venue event feed',
                'venue_name' => 'Qudos Bank Arena',
                'venue_area' => 'Sydney Olympic Park',
                'venue_address' => '19 Edwin Flack Ave, Sydney Olympic Park NSW 2127',
                'venue_lat' => -33.8468,
                'venue_lng' => 151.0633,
                'distance_decay_km' => 14.0,
                'max_distance_km' => 60.0,
                'network_floor' => 0.035,
                'featured_facility_id' => '488',
                'featured_reason' => 'Seven Hills is highlighted because large arena events can spill strongly through western rail-linked park-and-ride demand.',
                'source_priority' => 100,
            ]),
            ['attendance_label' => 'Estimated attendance']
        ),
        array_merge(
            events_live_sydney_olympic_park_profile([
                'source_key' => 'athletic',
                'kind' => 'sopa_split',
                'source_label' => 'Sydney Olympic Park Athletic Centre',
                'source_page_url' => 'https://www.sydneyolympicpark.com.au/athletic-centre/events',
                'source_basis' => 'Official venue calendar with crowd forecast',
                'venue_name' => 'Sydney Olympic Park Athletic Centre',
                'venue_area' => 'Sydney Olympic Park',
                'venue_address' => 'Edwin Flack Avenue, Sydney Olympic Park NSW 2127',
                'venue_lat' => -33.8494,
                'venue_lng' => 151.0730,
                'distance_decay_km' => 13.0,
                'max_distance_km' => 58.0,
                'network_floor' => 0.025,
                'spillover_ratio' => 0.078,
                'featured_facility_id' => '22',
                'featured_reason' => 'Penrith multi-level remains a strong western catchment example for day-long athletic events at Olympic Park.',
                'source_priority' => 100,
            ]),
            ['attendance_label' => 'Official crowd forecast']
        ),
        array_merge(
            events_live_sydney_olympic_park_profile([
                'source_key' => 'aquatic',
                'kind' => 'sopa_combined',
                'source_label' => 'Sydney Olympic Park Aquatic Centre',
                'source_page_url' => 'https://www.sydneyolympicpark.com.au/aquatic-centre/events',
                'source_basis' => 'Official venue calendar with crowd forecast',
                'venue_name' => 'Sydney Olympic Park Aquatic Centre',
                'venue_area' => 'Sydney Olympic Park',
                'venue_address' => 'Olympic Boulevard, Sydney Olympic Park NSW 2127',
                'venue_lat' => -33.8483,
                'venue_lng' => 151.0702,
                'distance_decay_km' => 13.0,
                'max_distance_km' => 58.0,
                'network_floor' => 0.025,
                'spillover_ratio' => 0.06,
                'parking_affected_boost' => 0.035,
                'featured_facility_id' => '488',
                'featured_reason' => 'Seven Hills is highlighted because precinct-wide aquatic events can still ripple through the western commuter corridor.',
                'source_priority' => 100,
            ]),
            ['attendance_label' => 'Official crowd forecast']
        ),
        array_merge(
            events_live_sydney_olympic_park_profile([
                'source_key' => 'hockey',
                'kind' => 'sopa_split',
                'source_label' => 'Sydney Olympic Park Hockey Centre',
                'source_page_url' => 'https://www.sydneyolympicpark.com.au/hockey-centre/events',
                'source_basis' => 'Official venue calendar with crowd forecast',
                'venue_name' => 'Sydney Olympic Park Hockey Centre',
                'venue_area' => 'Sydney Olympic Park',
                'venue_address' => 'Shirley Strickland Avenue, Sydney Olympic Park NSW 2127',
                'venue_lat' => -33.8514,
                'venue_lng' => 151.0698,
                'distance_decay_km' => 12.5,
                'max_distance_km' => 58.0,
                'network_floor' => 0.02,
                'spillover_ratio' => 0.04,
                'featured_facility_id' => '488',
                'featured_reason' => 'Seven Hills is a good example of how even smaller precinct sports fixtures can nudge western park-and-ride demand upward.',
                'source_priority' => 100,
            ]),
            ['attendance_label' => 'Official crowd forecast']
        ),
        array_merge(
            events_live_sydney_olympic_park_profile([
                'source_key' => 'sports-halls',
                'kind' => 'sopa_split',
                'source_label' => 'Sydney Olympic Park Sports Halls',
                'source_page_url' => 'https://www.sydneyolympicpark.com.au/sports-halls/events',
                'source_basis' => 'Official venue calendar with crowd forecast',
                'venue_name' => 'Sydney Olympic Park Sports Halls',
                'venue_area' => 'Sydney Olympic Park',
                'venue_address' => 'Grand Parade, Sydney Olympic Park NSW 2127',
                'venue_lat' => -33.8502,
                'venue_lng' => 151.0722,
                'distance_decay_km' => 12.5,
                'max_distance_km' => 58.0,
                'network_floor' => 0.02,
                'spillover_ratio' => 0.04,
                'featured_facility_id' => '488',
                'featured_reason' => 'Seven Hills is again used as the western corridor example for indoor Olympic Park tournament days.',
                'source_priority' => 100,
            ]),
            ['attendance_label' => 'Official crowd forecast']
        ),
    ];
}

function events_live_sydney_olympic_park_profile(array $overrides = []): array
{
    return array_merge([
        'display_max_distance_km' => 10.0,
        'focus_keywords' => ['seven hills', 'st marys', 'penrith', 'warwick farm', 'west ryde', 'revesby', 'campbelltown', 'edmondson park'],
        'secondary_keywords' => ['bella vista', 'hills showground', 'cherrybrook', 'tallawong', 'kellyville', 'gosford', 'sutherland'],
    ], $overrides);
}

function events_live_fetch_city_of_sydney_weekly_events(array $source, DateTimeImmutable $now): array
{
    // City of Sydney keeps its weekly event list inside the page's Next.js data payload.
    $payload = events_live_extract_next_data_payload(events_live_http_get($source['source_page_url']));
    $hits = is_array($payload['props']['pageProps']['searchResults']['hits'] ?? null)
        ? $payload['props']['pageProps']['searchResults']['hits']
        : [];
    $events = [];

    foreach ($hits as $hit) {
        if (!is_array($hit)) {
            continue;
        }

        $event = events_live_build_city_of_sydney_event($source, $hit, $now);
        if ($event !== null) {
            $events[] = $event;
        }
    }

    return $events;
}

function events_live_build_city_of_sydney_event(array $source, array $hit, DateTimeImmutable $now): ?array
{
    $type = strtolower(trim((string) ($hit['type'] ?? '')));
    if ($type !== '' && $type !== 'event') {
        return null;
    }

    $title = events_live_clean_title((string) ($hit['name'] ?? ''));
    $slug = trim((string) ($hit['slug'] ?? ''));
    $upcomingDate = trim((string) ($hit['upcomingDate'] ?? ''));
    $suburbName = events_live_clean_title((string) ($hit['suburbName'] ?? ''));
    $regionSlugs = array_values(array_filter(array_map('trim', array_map('strval', (array) ($hit['regions'] ?? [])))));

    if ($title === '' || $slug === '' || $upcomingDate === '') {
        return null;
    }

    if (strtolower($suburbName) === 'online' || in_array('online', array_map('strtolower', $regionSlugs), true)) {
        return null;
    }

    $baseDate = DateTimeImmutable::createFromFormat('!Y-m-d', $upcomingDate, events_timezone());
    if (!$baseDate instanceof DateTimeImmutable) {
        return null;
    }

    $rawTags = array_values(array_filter(array_map('strval', (array) ($hit['tags'] ?? []))));
    $categoryMeta = events_live_category_metadata(
        (array) ($hit['categories'] ?? []),
        $title,
        $rawTags
    );
    $timeWindow = events_live_city_time_window(
        (string) ($hit['eventUpcomingTime'] ?? ''),
        $categoryMeta['category_slug'],
        $baseDate,
        is_array($hit['eventUpcomingDaytime'] ?? null)
            ? (string) (($hit['eventUpcomingDaytime'][0] ?? '') ?: '')
            : (string) ($hit['eventUpcomingDaytime'] ?? '')
    );
    $location = events_live_city_location_profile($hit);
    $attendance = events_live_city_attendance_profile(
        $categoryMeta['category_slug'],
        $title,
        $rawTags,
        (string) ($hit['freeEvent'] ?? ''),
        (array) ($hit['eventType'] ?? [])
    );

    return [
        'id' => sprintf(
            '%s-%s-%s',
            $source['source_key'],
            events_live_slugify($slug),
            $timeWindow['starts_at']->format('Y-m-d-H-i')
        ),
        'title' => $title,
        'starts_at' => $timeWindow['starts_at']->format('Y-m-d H:i:s'),
        'ends_at' => $timeWindow['ends_at']->format('Y-m-d H:i:s'),
        'venue_name' => $location['venue_name'],
        'venue_area' => $location['venue_area'],
        'venue_address' => $location['venue_address'],
        'venue_lat' => $location['venue_lat'],
        'venue_lng' => $location['venue_lng'],
        'source_label' => $source['source_label'],
        'source_url' => rtrim((string) $source['source_url_base'], '/') . '/' . rawurlencode($slug),
        'source_basis' => $source['source_basis'],
        'attendance_estimate' => $attendance['attendance_estimate'],
        'attendance_label' => $source['attendance_label'],
        'attendance_note' => $attendance['attendance_note'],
        'peak_vehicle_demand' => $attendance['peak_vehicle_demand'],
        'demand_note' => $attendance['demand_note'],
        'distance_decay_km' => $source['distance_decay_km'],
        'max_distance_km' => $source['max_distance_km'],
        'display_max_distance_km' => $source['display_max_distance_km'],
        'network_floor' => $source['network_floor'],
        'focus_keywords' => $source['focus_keywords'],
        'secondary_keywords' => $source['secondary_keywords'],
        'featured_facility_id' => $source['featured_facility_id'],
        'featured_reason' => $source['featured_reason'],
        'category_slug' => $categoryMeta['category_slug'],
        'category_label' => $categoryMeta['category_label'],
        'category_slugs' => $categoryMeta['category_slugs'],
        'category_labels' => $categoryMeta['category_labels'],
        'event_tags' => $categoryMeta['event_tags'],
        'source_priority' => (int) ($source['source_priority'] ?? 60),
        'dedupe_key' => events_live_build_dedupe_key($title, $timeWindow['starts_at'], $location['venue_name']),
    ];
}

function events_live_city_time_window(
    string $rawTimeText,
    string $categorySlug,
    DateTimeImmutable $baseDate,
    string $daytimeLabel = ''
): array {
    $times = events_live_extract_time_labels($rawTimeText);
    $defaultStart = events_live_category_default_start_label($categorySlug, $daytimeLabel);
    $defaultDurationMinutes = events_live_category_default_duration_minutes($categorySlug);

    if ($times === []) {
        $startsAt = events_live_build_datetime_from_label($baseDate, $defaultStart);
        return [
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->modify('+' . $defaultDurationMinutes . ' minutes'),
        ];
    }

    $startsAt = events_live_build_datetime_from_label($baseDate, $times[0]);
    if (count($times) === 1) {
        return [
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->modify('+' . $defaultDurationMinutes . ' minutes'),
        ];
    }

    $endsAt = events_live_build_datetime_from_label($baseDate, $times[count($times) - 1]);
    if ($endsAt <= $startsAt) {
        $endsAt = $endsAt->modify('+1 day');
    }

    return ['starts_at' => $startsAt, 'ends_at' => $endsAt];
}

function events_live_city_location_profile(array $hit): array
{
    // The city feed usually gives venue/suburb text, so known places are mapped to coordinates.
    $venueName = events_live_clean_title((string) ($hit['venueName'] ?? ''));
    $suburbName = events_live_clean_title((string) ($hit['suburbName'] ?? ''));
    $regionSlugs = array_values(array_filter(array_map('trim', array_map('strval', (array) ($hit['regions'] ?? [])))));
    $coordinates = events_live_city_coordinates($venueName, $suburbName, $regionSlugs);
    $venueArea = $suburbName !== ''
        ? $suburbName
        : events_live_city_region_label($regionSlugs[0] ?? '');

    if ($venueArea === '') {
        $venueArea = 'Sydney';
    }

    if ($venueName === '') {
        $venueName = $venueArea . ' event area';
    }

    $venueAddress = $venueName;
    if ($suburbName !== '' && stripos($venueAddress, $suburbName) === false) {
        $venueAddress .= ', ' . $suburbName;
    }
    if (stripos($venueAddress, 'NSW') === false) {
        $venueAddress .= ' NSW';
    }

    return [
        'venue_name' => $venueName,
        'venue_area' => $venueArea,
        'venue_address' => $venueAddress,
        'venue_lat' => $coordinates['lat'],
        'venue_lng' => $coordinates['lng'],
    ];
}

function events_live_city_coordinates(string $venueName, string $suburbName, array $regionSlugs): array
{
    $normalizedVenue = strtolower(events_live_plain_text($venueName));
    foreach (events_live_city_venue_keyword_map() as $keyword => $coordinates) {
        if ($normalizedVenue !== '' && str_contains($normalizedVenue, $keyword)) {
            return $coordinates;
        }
    }

    $normalizedSuburb = strtolower(events_live_plain_text($suburbName));
    $suburbMap = events_live_city_suburb_map();
    if ($normalizedSuburb !== '' && isset($suburbMap[$normalizedSuburb])) {
        return $suburbMap[$normalizedSuburb];
    }

    foreach ($regionSlugs as $regionSlug) {
        $normalizedRegion = strtolower(trim((string) $regionSlug));
        $regionMap = events_live_city_region_map();
        if ($normalizedRegion !== '' && isset($regionMap[$normalizedRegion])) {
            return $regionMap[$normalizedRegion];
        }
    }

    return ['lat' => -33.8688, 'lng' => 151.2093];
}

function events_live_city_venue_keyword_map(): array
{
    return [
        'australian national maritime museum' => ['lat' => -33.869881, 'lng' => 151.198125],
        'state theatre' => ['lat' => -33.8716, 'lng' => 151.2067],
        'queen victoria building' => ['lat' => -33.8710, 'lng' => 151.2065],
        'icc sydney' => ['lat' => -33.8748, 'lng' => 151.1989],
        'state library of nsw' => ['lat' => -33.8667, 'lng' => 151.2134],
        'carriageworks' => ['lat' => -33.8931, 'lng' => 151.1934],
        'martin place' => ['lat' => -33.8678, 'lng' => 151.2091],
        'darling harbour' => ['lat' => -33.8748, 'lng' => 151.1989],
        'barangaroo' => ['lat' => -33.8607, 'lng' => 151.2010],
        'hyde park' => ['lat' => -33.8731, 'lng' => 151.2110],
        'museum of contemporary art' => ['lat' => -33.8608, 'lng' => 151.2095],
        'overseas passenger terminal' => ['lat' => -33.8591, 'lng' => 151.2100],
        'powerhouse' => ['lat' => -33.8786, 'lng' => 151.2008],
        'town hall' => ['lat' => -33.8732, 'lng' => 151.2060],
    ];
}

function events_live_city_suburb_map(): array
{
    return [
        'sydney' => ['lat' => -33.8688, 'lng' => 151.2093],
        'the rocks' => ['lat' => -33.8599, 'lng' => 151.2090],
        'surry hills' => ['lat' => -33.8845, 'lng' => 151.2119],
        'glebe' => ['lat' => -33.8793, 'lng' => 151.1847],
        'darlinghurst' => ['lat' => -33.8794, 'lng' => 151.2207],
        'marrickville' => ['lat' => -33.9108, 'lng' => 151.1554],
        'alexandria' => ['lat' => -33.9106, 'lng' => 151.1985],
        'haymarket' => ['lat' => -33.8786, 'lng' => 151.2054],
        'pyrmont' => ['lat' => -33.8699, 'lng' => 151.1941],
        'ultimo' => ['lat' => -33.8791, 'lng' => 151.1980],
        'potts point' => ['lat' => -33.8705, 'lng' => 151.2241],
        'woolloomooloo' => ['lat' => -33.8691, 'lng' => 151.2219],
        'paddington' => ['lat' => -33.8844, 'lng' => 151.2292],
        'bondi beach' => ['lat' => -33.8915, 'lng' => 151.2767],
        'bondi junction' => ['lat' => -33.8927, 'lng' => 151.2497],
        'eveleigh' => ['lat' => -33.8942, 'lng' => 151.1936],
        'lilyfield' => ['lat' => -33.8749, 'lng' => 151.1649],
        'rosebery' => ['lat' => -33.9180, 'lng' => 151.2028],
        'rozelle' => ['lat' => -33.8627, 'lng' => 151.1707],
        'forest lodge' => ['lat' => -33.8829, 'lng' => 151.1841],
        'enmore' => ['lat' => -33.9009, 'lng' => 151.1735],
        'redfern' => ['lat' => -33.8921, 'lng' => 151.2042],
        'chatswood' => ['lat' => -33.7968, 'lng' => 151.1832],
        'darlington' => ['lat' => -33.8919, 'lng' => 151.1950],
        'waterloo' => ['lat' => -33.9008, 'lng' => 151.2067],
        'zetland' => ['lat' => -33.9076, 'lng' => 151.2089],
        'north sydney' => ['lat' => -33.8390, 'lng' => 151.2070],
        'chippendale' => ['lat' => -33.8860, 'lng' => 151.2000],
        'dawes point' => ['lat' => -33.8562, 'lng' => 151.2050],
        'newtown' => ['lat' => -33.8984, 'lng' => 151.1793],
        'erskineville' => ['lat' => -33.9022, 'lng' => 151.1852],
        'annandale' => ['lat' => -33.8807, 'lng' => 151.1701],
        'moore park' => ['lat' => -33.8945, 'lng' => 151.2245],
        'millers point' => ['lat' => -33.8582, 'lng' => 151.2038],
        'rushcutters bay' => ['lat' => -33.8754, 'lng' => 151.2271],
    ];
}

function events_live_city_region_map(): array
{
    return [
        'city-centre' => ['lat' => -33.8728, 'lng' => 151.2064],
        'sydney-inner-west' => ['lat' => -33.8850, 'lng' => 151.1700],
        'inner-west' => ['lat' => -33.8850, 'lng' => 151.1700],
        'south-sydney' => ['lat' => -33.9100, 'lng' => 151.2050],
        'sydney-inner-east' => ['lat' => -33.8880, 'lng' => 151.2300],
        'eastern-suburbs' => ['lat' => -33.8880, 'lng' => 151.2300],
        'lower-north-shore' => ['lat' => -33.8350, 'lng' => 151.2050],
        'upper-north-shore' => ['lat' => -33.7750, 'lng' => 151.1600],
        'western-sydney' => ['lat' => -33.8100, 'lng' => 150.9900],
        'online' => ['lat' => -33.8688, 'lng' => 151.2093],
    ];
}

function events_live_city_region_label(string $regionSlug): string
{
    $slug = strtolower(trim($regionSlug));
    return match ($slug) {
        'city-centre' => 'City centre',
        'sydney-inner-west', 'inner-west' => 'Inner West',
        'south-sydney' => 'South Sydney',
        'sydney-inner-east', 'eastern-suburbs' => 'Eastern Suburbs',
        'lower-north-shore' => 'Lower North Shore',
        'upper-north-shore' => 'Upper North Shore',
        'western-sydney' => 'Western Sydney',
        default => 'Sydney',
    };
}

function events_live_city_attendance_profile(
    string $categorySlug,
    string $title,
    array $rawTags,
    string $freeEvent,
    array $eventTypes
): array {
    // When a city event has no crowd number, estimate attendance from its category and tags.
    $tags = strtolower(implode(' ', array_map('events_live_plain_text', $rawTags)));
    $haystack = strtolower($title . ' ' . $tags);
    $isFree = strtolower(trim($freeEvent)) === 'true';
    $isOutdoor = in_array('Outdoor', $eventTypes, true);

    $attendanceEstimate = match ($categorySlug) {
        'festival' => 2800,
        'music' => 1800,
        'sport' => 1200,
        'comedy' => 900,
        'family' => 1000,
        'food-drink' => 1500,
        'nightlife' => 950,
        'markets-fairs' => 1700,
        'exhibition' => 700,
        'theatre-film' => 950,
        'community' => 850,
        'talks-workshops' => 280,
        'tours-experiences' => 350,
        'school-event' => 800,
        default => 950,
    };

    if (preg_match('/\bfestival\b/', $haystack) === 1) {
        $attendanceEstimate = max($attendanceEstimate, 3200);
    } elseif (preg_match('/\bmarket|fair\b/', $haystack) === 1) {
        $attendanceEstimate = max($attendanceEstimate, 1900);
    } elseif (preg_match('/\bparade|street party|streets\b/', $haystack) === 1) {
        $attendanceEstimate = max($attendanceEstimate, 2600);
    }

    if ($isOutdoor) {
        $attendanceEstimate = (int) round($attendanceEstimate * 1.12);
    }
    if ($isFree) {
        $attendanceEstimate = (int) round($attendanceEstimate * 1.1);
    }

    $spilloverRatio = match ($categorySlug) {
        'festival' => 0.025,
        'music' => 0.021,
        'sport' => 0.02,
        'comedy' => 0.017,
        'family' => 0.016,
        'food-drink' => 0.016,
        'nightlife' => 0.017,
        'markets-fairs' => 0.018,
        'exhibition' => 0.013,
        'theatre-film' => 0.015,
        'community' => 0.014,
        'talks-workshops' => 0.01,
        'tours-experiences' => 0.011,
        'school-event' => 0.018,
        default => 0.015,
    };

    return [
        'attendance_estimate' => $attendanceEstimate,
        'attendance_note' => 'Estimated from the official City of Sydney What\'s On listing, category, and event format because the citywide guide does not publish crowd counts.',
        'peak_vehicle_demand' => max(10, (int) round($attendanceEstimate * $spilloverRatio)),
        'demand_note' => 'This citywide event uses the official Sydney event guide location with a generalized spillover estimate into the monitored park-and-ride network.',
    ];
}

function events_live_extract_next_data_payload(string $html): array
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = new DOMXPath($document);
    $scriptNode = $xpath->query('//script[@id="__NEXT_DATA__"]')->item(0);
    if (!$scriptNode) {
        throw new RuntimeException('Unable to locate the official event data payload on the source page.');
    }

    $decoded = json_decode((string) $scriptNode->textContent, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Official event data payload could not be decoded.');
    }

    return $decoded;
}

function events_live_category_metadata(array $rawCategories, string $title = '', array $rawTags = []): array
{
    // Different feeds name categories differently, so normalize them for one filter menu.
    $slugs = [];
    foreach ($rawCategories as $rawCategory) {
        $slug = events_live_map_category_slug((string) $rawCategory);
        if ($slug !== '') {
            $slugs[] = $slug;
        }
    }

    $slugs = array_values(array_unique(events_live_refine_category_slugs($slugs, $title, $rawTags)));
    if ($slugs === []) {
        $fallback = events_live_infer_category_slug($title, $rawTags);
        if ($fallback !== '') {
            $slugs[] = $fallback;
        }
    }

    if ($slugs === []) {
        $slugs[] = 'community';
    }

    $labels = [];
    foreach ($slugs as $slug) {
        $labels[] = events_live_category_label($slug);
    }

    return [
        'category_slug' => $slugs[0],
        'category_label' => events_live_category_label($slugs[0]),
        'category_slugs' => $slugs,
        'category_labels' => array_values(array_unique($labels)),
        'event_tags' => events_live_normalize_event_tags($rawTags),
    ];
}

function events_live_map_category_slug(string $rawCategory): string
{
    $normalized = strtolower(trim($rawCategory));

    return match ($normalized) {
        'concert', 'music' => 'music',
        'sport', 'sport-and-fitness' => 'sport',
        'comedy' => 'comedy',
        'family', 'children-and-family' => 'family',
        'food-and-drink' => 'food-drink',
        'nightlife' => 'nightlife',
        'exhibitions', 'exhibition' => 'exhibition',
        'theatre-dance-and-film', 'theatre', 'film' => 'theatre-film',
        'shopping-markets-and-fairs', 'markets', 'market', 'fair' => 'markets-fairs',
        'community-and-causes', 'community' => 'community',
        'talks-courses-and-workshops', 'workshop' => 'talks-workshops',
        'tours-and-experiences', 'tour' => 'tours-experiences',
        'school-event' => 'school-event',
        'festival' => 'festival',
        default => '',
    };
}

function events_live_refine_category_slugs(array $slugs, string $title, array $rawTags): array
{
    $tagText = strtolower(implode(' ', array_map('events_live_plain_text', $rawTags)));
    $haystack = strtolower($title . ' ' . $tagText);
    $prioritized = [];

    if (str_contains($haystack, 'school carnival')) {
        $prioritized[] = 'school-event';
    }
    if (preg_match('/\bfestival\b/', $haystack) === 1) {
        $prioritized[] = 'festival';
    }
    if (preg_match('/\bmarket|fair\b/', $haystack) === 1) {
        $prioritized[] = 'markets-fairs';
    }

    if (preg_match('/\bmusic|concert|gig|dj|jazz|opera|classical|live-music|band\b/', $haystack) === 1) {
        if ($slugs === []) {
            $prioritized[] = 'music';
        } elseif (!in_array('music', $slugs, true)) {
            $slugs[] = 'music';
        }
    }

    return array_merge($prioritized, $slugs);
}

function events_live_infer_category_slug(string $title, array $rawTags = []): string
{
    $haystack = strtolower($title . ' ' . implode(' ', array_map('events_live_plain_text', $rawTags)));

    return match (true) {
        str_contains($haystack, 'school carnival') => 'school-event',
        preg_match('/\bfestival\b/', $haystack) === 1 => 'festival',
        preg_match('/\bmarket|fair\b/', $haystack) === 1 => 'markets-fairs',
        preg_match('/\bmusic|concert|gig|dj|jazz|opera|classical|band|live-music\b/', $haystack) === 1 => 'music',
        preg_match('/\bsport|league|championship|game|match|swimming|hockey|athletics\b/', $haystack) === 1 => 'sport',
        preg_match('/\bcomedy|comedian\b/', $haystack) === 1 => 'comedy',
        preg_match('/\bfamily|kids|children\b/', $haystack) === 1 => 'family',
        preg_match('/\bfood|drink|wine|dining\b/', $haystack) === 1 => 'food-drink',
        preg_match('/\bfilm|cinema|theatre|dance|play|screening\b/', $haystack) === 1 => 'theatre-film',
        preg_match('/\bworkshop|class|course|talk\b/', $haystack) === 1 => 'talks-workshops',
        preg_match('/\btour|walk|experience\b/', $haystack) === 1 => 'tours-experiences',
        default => 'community',
    };
}

function events_live_category_label(string $categorySlug): string
{
    return match ($categorySlug) {
        'festival' => 'Festival',
        'music' => 'Music',
        'sport' => 'Sport',
        'comedy' => 'Comedy',
        'family' => 'Family',
        'food-drink' => 'Food & Drink',
        'nightlife' => 'Nightlife',
        'markets-fairs' => 'Markets & Fairs',
        'exhibition' => 'Exhibition',
        'theatre-film' => 'Theatre & Film',
        'community' => 'Community',
        'talks-workshops' => 'Talks & Workshops',
        'tours-experiences' => 'Tours & Experiences',
        'school-event' => 'School Event',
        default => 'Event',
    };
}

function events_live_normalize_event_tags(array $rawTags): array
{
    $normalized = [];

    foreach ($rawTags as $rawTag) {
        $tag = trim((string) $rawTag);
        if ($tag === '') {
            continue;
        }

        $normalized[] = ucwords(str_replace(['-', '_'], ' ', strtolower($tag)));
    }

    return array_values(array_unique($normalized));
}

function events_live_category_default_start_label(string $categorySlug, string $daytimeLabel = ''): string
{
    $daytime = strtolower(trim($daytimeLabel));
    if ($daytime !== '') {
        return match ($daytime) {
            'morning' => '10:00am',
            'afternoon' => '1:00pm',
            'evening' => '7:00pm',
            default => '11:00am',
        };
    }

    return match ($categorySlug) {
        'nightlife', 'music', 'comedy' => '7:00pm',
        'family', 'school-event' => '10:00am',
        'sport' => '2:00pm',
        default => '11:00am',
    };
}

function events_live_category_default_duration_minutes(string $categorySlug): int
{
    return match ($categorySlug) {
        'festival' => 480,
        'nightlife' => 300,
        'music' => 210,
        'comedy' => 150,
        'family' => 240,
        'school-event' => 390,
        'sport' => 210,
        'exhibition' => 360,
        'markets-fairs' => 360,
        default => 180,
    };
}

function events_live_build_dedupe_key(string $title, DateTimeImmutable $startsAt, string $venueName): string
{
    return implode('|', [
        events_live_slugify($title),
        events_live_slugify($venueName),
        $startsAt->format('Y-m-d-H-i'),
    ]);
}

function events_live_fetch_qudos_events(array $source, DateTimeImmutable $now): array
{
    // Qudos provides events through WordPress JSON, which is easier to parse than HTML.
    $events = [];

    for ($page = 1; $page <= 3; $page++) {
        $url = sprintf('%s?per_page=100&page=%d', $source['source_api_url'], $page);
        $payload = events_live_http_get_json($url);
        if ($payload === []) {
            break;
        }

        foreach ($payload as $item) {
            if (!is_array($item)) {
                continue;
            }

            $normalized = events_live_normalize_qudos_event($item, $source, $now);
            if ($normalized !== null) {
                $events[] = $normalized;
            }
        }

        if (count($payload) < 100) {
            break;
        }
    }

    return $events;
}

function events_live_normalize_qudos_event(array $item, array $source, DateTimeImmutable $now): ?array
{
    $acf = is_array($item['acf'] ?? null) ? $item['acf'] : [];
    $title = events_live_clean_title((string) (($item['title']['rendered'] ?? '') ?: 'Qudos event'));
    $dateText = (string) ($acf['dates'] ?? ($acf['excerpt_date'] ?? ''));

    $startDate = events_live_parse_qudos_start_date($dateText, $now);
    if (!$startDate instanceof DateTimeImmutable) {
        return null;
    }

    $endDate = events_live_parse_qudos_end_date($dateText, (string) ($acf['end_date'] ?? ''), $startDate);
    $category = events_live_qudos_category($item, $title, (string) ($acf['about'] ?? ''));
    $categoryMeta = events_live_category_metadata([$category], $title);
    $timeWindow = events_live_qudos_time_window((string) ($acf['event-times'] ?? ''), $category, $startDate, $endDate);
    $attendance = events_live_qudos_attendance($title, $category);

    return [
        'id' => sprintf(
            '%s-%s-%s',
            $source['source_key'],
            events_live_slugify($title),
            $startDate->format('Y-m-d')
        ),
        'title' => $title,
        'starts_at' => $timeWindow['starts_at']->format('Y-m-d H:i:s'),
        'ends_at' => $timeWindow['ends_at']->format('Y-m-d H:i:s'),
        'venue_name' => $source['venue_name'],
        'venue_area' => $source['venue_area'],
        'venue_address' => $source['venue_address'],
        'venue_lat' => $source['venue_lat'],
        'venue_lng' => $source['venue_lng'],
        'source_label' => $source['source_label'],
        'source_url' => (string) ($item['link'] ?? $source['source_page_url']),
        'source_basis' => $source['source_basis'],
        'attendance_estimate' => $attendance['attendance_estimate'],
        'attendance_label' => $source['attendance_label'],
        'attendance_note' => $attendance['attendance_note'],
        'peak_vehicle_demand' => $attendance['peak_vehicle_demand'],
        'demand_note' => $attendance['demand_note'],
        'distance_decay_km' => $source['distance_decay_km'],
        'max_distance_km' => $source['max_distance_km'],
        'display_max_distance_km' => $source['display_max_distance_km'],
        'network_floor' => $source['network_floor'],
        'focus_keywords' => $source['focus_keywords'],
        'secondary_keywords' => $source['secondary_keywords'],
        'featured_facility_id' => $source['featured_facility_id'],
        'featured_reason' => $source['featured_reason'],
        'category_slug' => $categoryMeta['category_slug'],
        'category_label' => $categoryMeta['category_label'],
        'category_slugs' => $categoryMeta['category_slugs'],
        'category_labels' => $categoryMeta['category_labels'],
        'event_tags' => $categoryMeta['event_tags'],
        'source_priority' => (int) ($source['source_priority'] ?? 100),
        'dedupe_key' => events_live_build_dedupe_key($title, $timeWindow['starts_at'], (string) $source['venue_name']),
    ];
}

function events_live_qudos_category(array $item, string $title, string $about): string
{
    foreach ((array) ($item['class_list'] ?? []) as $className) {
        $normalized = strtolower((string) $className);
        if (str_contains($normalized, 'category-sport')) {
            return 'sport';
        }
        if (str_contains($normalized, 'category-concert')) {
            return 'concert';
        }
        if (str_contains($normalized, 'category-comedy')) {
            return 'comedy';
        }
        if (str_contains($normalized, 'category-family')) {
            return 'family';
        }
    }

    $haystack = strtolower($title . ' ' . $about);
    if (preg_match('/\b(game|championship|nbl|netball|basketball|sport)\b/', $haystack) === 1) {
        return 'sport';
    }
    if (preg_match('/\b(comedy|comedian)\b/', $haystack) === 1) {
        return 'comedy';
    }
    if (preg_match('/\b(disney|monster truck|family|kids|ice show)\b/', $haystack) === 1) {
        return 'family';
    }

    return 'concert';
}

function events_live_qudos_attendance(string $title, string $category): array
{
    $normalizedTitle = strtolower($title);

    $attendanceEstimate = match ($category) {
        'sport' => 12000,
        'comedy' => 8500,
        'family' => 9500,
        default => 15000,
    };

    if (preg_match('/monster truck|kids|disney|family/', $normalizedTitle) === 1) {
        $attendanceEstimate = 9500;
    } elseif (preg_match('/championship|final|game|playoff|kings|nbl/', $normalizedTitle) === 1) {
        $attendanceEstimate = 12500;
    }

    $spilloverRatio = match ($category) {
        'sport' => 0.024,
        'comedy' => 0.020,
        'family' => 0.021,
        default => 0.022,
    };

    $attendanceNote = match ($category) {
        'sport' => 'Estimated from the official Qudos Bank Arena event listing and a typical major indoor sport crowd for this venue.',
        'comedy' => 'Estimated from the official Qudos Bank Arena event listing and a moderate seated arena configuration.',
        'family' => 'Estimated from the official Qudos Bank Arena event listing and a family-show arena attendance heuristic.',
        default => 'Estimated from the official Qudos Bank Arena event listing and a major arena concert attendance heuristic.',
    };

    return [
        'attendance_estimate' => $attendanceEstimate,
        'attendance_note' => $attendanceNote,
        'peak_vehicle_demand' => max(35, (int) round($attendanceEstimate * $spilloverRatio)),
        'demand_note' => 'Most visitors will use major rail and road corridors, but a large arena event can still create meaningful spillover into the monitored park-and-ride network.',
    ];
}

function events_live_qudos_time_window(
    string $rawTimeText,
    string $category,
    DateTimeImmutable $startDate,
    DateTimeImmutable $endDate
): array {
    $times = events_live_extract_time_labels($rawTimeText);

    $defaultStart = match ($category) {
        'sport' => '2:30pm',
        'family' => '1:00pm',
        'comedy' => '7:30pm',
        default => '7:00pm',
    };
    $defaultDurationMinutes = match ($category) {
        'sport' => 210,
        'family' => 180,
        'comedy' => 150,
        default => 240,
    };

    if ($times === []) {
        $startsAt = events_live_build_datetime_from_label($startDate, $defaultStart);
        $endsAt = $startsAt->modify('+' . $defaultDurationMinutes . ' minutes');

        return ['starts_at' => $startsAt, 'ends_at' => $endsAt];
    }

    $startsAt = events_live_build_datetime_from_label($startDate, $times[0]);
    if (count($times) === 1) {
        $endsAt = $startsAt->modify('+' . $defaultDurationMinutes . ' minutes');

        return ['starts_at' => $startsAt, 'ends_at' => $endsAt];
    }

    $tailMinutes = match ($category) {
        'sport' => 120,
        'family' => 90,
        'comedy' => 90,
        default => 120,
    };

    $endBase = events_live_build_datetime_from_label($endDate, $times[count($times) - 1]);
    $endsAt = $endBase->modify('+' . $tailMinutes . ' minutes');

    if ($endsAt <= $startsAt) {
        $endsAt = $startsAt->modify('+' . $defaultDurationMinutes . ' minutes');
    }

    return ['starts_at' => $startsAt, 'ends_at' => $endsAt];
}

function events_live_parse_qudos_start_date(string $value, DateTimeImmutable $now): ?DateTimeImmutable
{
    $text = events_live_plain_text($value);
    $text = preg_replace('/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', '', $text);
    $text = trim(preg_replace('/\s+/', ' ', (string) $text));

    if (preg_match('/(\d{1,2})\s*(?:&|-|to)\s*(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/', $text, $matches) === 1) {
        return DateTimeImmutable::createFromFormat(
            '!j F Y',
            sprintf('%d %s %d', (int) $matches[1], $matches[3], (int) $matches[4]),
            events_timezone()
        ) ?: null;
    }

    if (preg_match('/(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/', $text, $matches) === 1) {
        return DateTimeImmutable::createFromFormat(
            '!j F Y',
            sprintf('%d %s %d', (int) $matches[1], $matches[2], (int) $matches[3]),
            events_timezone()
        ) ?: null;
    }

    if (preg_match('/([A-Za-z]+)\s+(\d{1,2}),?\s*(\d{4})/', $text, $matches) === 1) {
        return DateTimeImmutable::createFromFormat(
            '!F j Y',
            sprintf('%s %d %d', $matches[1], (int) $matches[2], (int) $matches[3]),
            events_timezone()
        ) ?: null;
    }

    return null;
}

function events_live_parse_qudos_end_date(string $dateText, string $value, DateTimeImmutable $startDate): DateTimeImmutable
{
    $text = events_live_plain_text($dateText);
    $text = preg_replace('/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', '', $text);
    $text = trim(preg_replace('/\s+/', ' ', (string) $text));

    if (
        preg_match('/(\d{1,2})\s*(?:&|-|to)\s*(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/', $text, $matches) === 1
    ) {
        $parsed = DateTimeImmutable::createFromFormat(
            '!j F Y',
            sprintf('%d %s %d', (int) $matches[2], $matches[3], (int) $matches[4]),
            events_timezone()
        );

        return $parsed instanceof DateTimeImmutable ? $parsed : $startDate;
    }

    if (
        preg_match(
            '/(\d{1,2})\s+([A-Za-z]+)\s*(?:&|-|to)\s*(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/',
            $text,
            $matches
        ) === 1
    ) {
        $parsed = DateTimeImmutable::createFromFormat(
            '!j F Y',
            sprintf('%d %s %d', (int) $matches[3], $matches[4], (int) $matches[5]),
            events_timezone()
        );

        return $parsed instanceof DateTimeImmutable ? $parsed : $startDate;
    }

    if (preg_match('/^\d{8}$/', trim($value)) !== 1) {
        return $startDate;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Ymd', trim($value), events_timezone());
    if (!$parsed instanceof DateTimeImmutable || $parsed <= $startDate) {
        return $startDate;
    }

    // Qudos routinely stores the next calendar day in `end_date` for single-night events,
    // so only trust the feed value when it clearly spans more than one day.
    return $parsed->diff($startDate)->days > 1 ? $parsed : $startDate;
}

function events_live_extract_time_labels(string $value): array
{
    $text = events_live_plain_text($value);
    if (preg_match_all('/\b\d{1,2}(?::\d{2})?\s*[ap]m\b/i', $text, $matches) !== 1) {
        return [];
    }

    $labels = [];
    foreach ($matches[0] as $match) {
        $normalized = events_live_normalize_time_label($match);
        if ($normalized !== '' && !in_array($normalized, $labels, true)) {
            $labels[] = $normalized;
        }
    }

    return $labels;
}

function events_live_fetch_sopa_split_events(array $source, DateTimeImmutable $now): array
{
    // Sydney Olympic Park venue pages publish crowd numbers in simple HTML tables.
    $events = [];

    foreach (events_live_extract_html_tables(events_live_http_get($source['source_page_url'])) as $table) {
        $headers = array_map('events_live_normalize_header', $table['headers']);
        if (count($headers) < 5) {
            continue;
        }

        if (!str_contains($headers[0], 'date') || $headers[1] !== 'event' || !str_starts_with($headers[2], 'start') || !str_starts_with($headers[3], 'finish') || $headers[4] !== 'crowd') {
            continue;
        }

        foreach ($table['rows'] as $row) {
            if (count($row) < 5) {
                continue;
            }

            $attendance = events_live_parse_crowd_value($row[4]);
            if ($attendance === null || $attendance <= 0) {
                continue;
            }

            $startDate = DateTimeImmutable::createFromFormat(
                '!j/n/Y g:i A',
                trim($row[0]) . ' ' . trim($row[2]),
                events_timezone()
            );
            $endDate = DateTimeImmutable::createFromFormat(
                '!j/n/Y g:i A',
                trim($row[0]) . ' ' . trim($row[3]),
                events_timezone()
            );

            if (!$startDate instanceof DateTimeImmutable || !$endDate instanceof DateTimeImmutable) {
                continue;
            }

            if ($endDate <= $startDate) {
                $endDate = $endDate->modify('+1 day');
            }

            $events[] = events_live_build_sopa_event(
                $source,
                (string) $row[1],
                $startDate,
                $endDate,
                $attendance,
                false
            );
        }
    }

    return $events;
}

function events_live_fetch_sopa_combined_events(array $source, DateTimeImmutable $now): array
{
    $events = [];

    foreach (events_live_extract_html_tables(events_live_http_get($source['source_page_url'])) as $table) {
        $headers = array_map('events_live_normalize_header', $table['headers']);
        if (count($headers) < 3) {
            continue;
        }

        if (!str_contains($headers[0], 'date and time') || $headers[1] !== 'event' || $headers[2] !== 'crowd') {
            continue;
        }

        foreach ($table['rows'] as $row) {
            if (count($row) < 3) {
                continue;
            }

            $attendance = events_live_parse_crowd_value($row[2]);
            if ($attendance === null || $attendance <= 0) {
                continue;
            }

            $window = events_live_parse_combined_date_window((string) $row[0], $now);
            if ($window === null) {
                continue;
            }

            $parkingAffected = isset($row[3]) && preg_match('/x/i', (string) $row[3]) === 1;

            $events[] = events_live_build_sopa_event(
                $source,
                (string) $row[1],
                $window['starts_at'],
                $window['ends_at'],
                $attendance,
                $parkingAffected
            );
        }
    }

    return $events;
}

function events_live_build_sopa_event(
    array $source,
    string $rawTitle,
    DateTimeImmutable $startsAt,
    DateTimeImmutable $endsAt,
    int $attendance,
    bool $parkingAffected
): array {
    $title = events_live_clean_title($rawTitle);
    $spilloverRatio = (float) ($source['spillover_ratio'] ?? 0.05);
    if ($parkingAffected) {
        $spilloverRatio += (float) ($source['parking_affected_boost'] ?? 0.0);
    }

    $attendanceNote = sprintf(
        'The official %s calendar lists a crowd of %s for this session.',
        $source['source_label'],
        number_format($attendance)
    );
    if ($parkingAffected) {
        $attendanceNote .= ' The venue also marks parking as affected for this session.';
    }

    $categoryMeta = events_live_category_metadata(
        events_live_sopa_categories($source, $title),
        $title
    );

    return [
        'id' => sprintf(
            '%s-%s-%s',
            $source['source_key'],
            events_live_slugify($title),
            $startsAt->format('Y-m-d-H-i')
        ),
        'title' => $title,
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
        'ends_at' => $endsAt->format('Y-m-d H:i:s'),
        'venue_name' => $source['venue_name'],
        'venue_area' => $source['venue_area'],
        'venue_address' => $source['venue_address'],
        'venue_lat' => $source['venue_lat'],
        'venue_lng' => $source['venue_lng'],
        'source_label' => $source['source_label'],
        'source_url' => $source['source_page_url'],
        'source_basis' => $source['source_basis'],
        'attendance_estimate' => $attendance,
        'attendance_label' => $source['attendance_label'],
        'attendance_note' => $attendanceNote,
        'peak_vehicle_demand' => max(8, (int) round($attendance * $spilloverRatio)),
        'demand_note' => $parkingAffected
            ? 'The official venue page flags this session as parking-affected, so the spillover forecast assumes stronger pressure on nearby monitored facilities.'
            : 'This session is forecast from the official venue crowd listing with a venue-specific spillover ratio into the monitored parking network.',
        'distance_decay_km' => $source['distance_decay_km'],
        'max_distance_km' => $source['max_distance_km'],
        'display_max_distance_km' => $source['display_max_distance_km'],
        'network_floor' => $source['network_floor'],
        'focus_keywords' => $source['focus_keywords'],
        'secondary_keywords' => $source['secondary_keywords'],
        'featured_facility_id' => $source['featured_facility_id'],
        'featured_reason' => $source['featured_reason'],
        'category_slug' => $categoryMeta['category_slug'],
        'category_label' => $categoryMeta['category_label'],
        'category_slugs' => $categoryMeta['category_slugs'],
        'category_labels' => $categoryMeta['category_labels'],
        'event_tags' => $categoryMeta['event_tags'],
        'source_priority' => (int) ($source['source_priority'] ?? 100),
        'dedupe_key' => events_live_build_dedupe_key($title, $startsAt, (string) $source['venue_name']),
    ];
}

function events_live_sopa_categories(array $source, string $title): array
{
    $normalizedTitle = strtolower($title);

    if (str_contains($normalizedTitle, 'school carnival')) {
        return ['school-event'];
    }

    if (preg_match('/\b(swim|aquatic|water polo|hockey|athletic|league|championship|tournament|sport)\b/', $normalizedTitle) === 1) {
        return ['sport'];
    }

    $sourceLabel = strtolower((string) ($source['source_label'] ?? ''));
    if (preg_match('/athletic|aquatic|hockey|sports halls/', $sourceLabel) === 1) {
        return ['sport'];
    }

    return ['community'];
}

function events_live_parse_combined_date_window(string $value, DateTimeImmutable $now): ?array
{
    $text = events_live_plain_text($value);
    $parts = array_map('trim', explode('|', $text));
    if (count($parts) !== 2) {
        return null;
    }

    $dateText = preg_replace('/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', '', $parts[0]);
    $dateText = trim(preg_replace('/\s+/', ' ', (string) $dateText));

    if (preg_match('/(\d{1,2})\s+([A-Za-z]+)/', $dateText, $dateMatches) !== 1) {
        return null;
    }

    $monthName = $dateMatches[2];
    $monthNumber = (int) date('n', strtotime($monthName . ' 1'));
    $year = events_live_infer_year($monthNumber, $now);
    $baseDate = DateTimeImmutable::createFromFormat(
        '!j F Y',
        sprintf('%d %s %d', (int) $dateMatches[1], $monthName, $year),
        events_timezone()
    );

    if (!$baseDate instanceof DateTimeImmutable) {
        return null;
    }

    if (preg_match('/(\d{1,2}(?::\d{2})?\s*[ap]m)\s*-\s*(\d{1,2}(?::\d{2})?\s*[ap]m)/i', $parts[1], $timeMatches) !== 1) {
        return null;
    }

    $startsAt = events_live_build_datetime_from_label($baseDate, $timeMatches[1]);
    $endsAt = events_live_build_datetime_from_label($baseDate, $timeMatches[2]);
    if ($endsAt <= $startsAt) {
        $endsAt = $endsAt->modify('+1 day');
    }

    return ['starts_at' => $startsAt, 'ends_at' => $endsAt];
}

function events_live_extract_html_tables(string $html): array
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = new DOMXPath($document);
    $tables = [];

    foreach ($xpath->query('//table[contains(@class, "tablefield")]') as $tableNode) {
        $headers = [];
        foreach ($xpath->query('.//thead/tr/th', $tableNode) as $headerNode) {
            $headers[] = events_live_plain_text($headerNode->textContent);
        }

        $rows = [];
        foreach ($xpath->query('.//tbody/tr', $tableNode) as $rowNode) {
            $cells = [];
            foreach ($xpath->query('./td', $rowNode) as $cellNode) {
                $cells[] = events_live_plain_text($cellNode->textContent);
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($headers !== [] && $rows !== []) {
            $tables[] = ['headers' => $headers, 'rows' => $rows];
        }
    }

    return $tables;
}

function events_live_http_get_json(string $url): array
{
    $body = events_live_http_get($url, ['Accept: application/json']);
    $decoded = json_decode($body, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('The remote event feed did not return valid JSON.');
    }

    return $decoded;
}

function events_live_http_get(string $url, array $headers = []): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is not available for live event syncing.');
    }

    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize cURL for live event syncing.');
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING => '',
        CURLOPT_TIMEOUT => events_live_http_timeout_seconds(),
        CURLOPT_CONNECTTIMEOUT => events_live_http_connect_timeout_seconds(),
        CURLOPT_HTTPHEADER => array_merge([
            'Accept-Language: en-AU,en;q=0.9',
            'User-Agent: SmartParkingNSW/1.0 (+http://localhost/smart-parking-live)',
        ], $headers),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($handle);
    $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($body === false || $error !== '') {
        throw new RuntimeException('Request failed: ' . $error);
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException('Request returned HTTP ' . $statusCode . '.');
    }

    return (string) $body;
}

function events_live_normalize_catalog(array $events, DateTimeImmutable $now): array
{
    // Keep only useful upcoming events, remove duplicates, then sort them by start time.
    $normalized = [];
    $futureCutoff = $now->modify('+120 days');

    foreach ($events as $event) {
        if (!is_array($event) || empty($event['id']) || empty($event['starts_at']) || empty($event['ends_at'])) {
            continue;
        }

        $end = events_parse_datetime((string) $event['ends_at']);
        $start = events_parse_datetime((string) $event['starts_at']);

        if ($end < $now || $start > $futureCutoff) {
            continue;
        }

        $dedupeKey = trim((string) ($event['dedupe_key'] ?? ''));
        $storageKey = $dedupeKey !== '' ? 'dedupe:' . $dedupeKey : 'id:' . (string) $event['id'];

        if (
            !isset($normalized[$storageKey])
            || ((int) ($event['source_priority'] ?? 0)) > ((int) ($normalized[$storageKey]['source_priority'] ?? 0))
        ) {
            $normalized[$storageKey] = $event;
        }
    }

    $catalog = array_values($normalized);
    usort($catalog, static fn(array $a, array $b): int => strcmp((string) $a['starts_at'], (string) $b['starts_at']));

    return events_live_disambiguate_titles($catalog);
}

function events_live_disambiguate_titles(array $events): array
{
    $groups = [];

    foreach ($events as $index => $event) {
        $key = strtolower(trim((string) ($event['title'] ?? '')));
        if ($key === '') {
            continue;
        }

        $groups[$key][] = $index;
    }

    foreach ($groups as $indexes) {
        if (count($indexes) <= 1) {
            continue;
        }

        $venueCounts = [];
        foreach ($indexes as $index) {
            $venueLabel = events_live_short_venue_label((string) ($events[$index]['venue_name'] ?? ''));
            $venueKey = strtolower($venueLabel);
            $venueCounts[$venueKey] = ($venueCounts[$venueKey] ?? 0) + 1;
        }

        foreach ($indexes as $index) {
            $baseTitle = trim((string) ($events[$index]['title'] ?? ''));
            $venueLabel = events_live_short_venue_label((string) ($events[$index]['venue_name'] ?? ''));
            $suffix = $venueLabel !== '' ? ' - ' . $venueLabel : '';

            if (($venueCounts[strtolower($venueLabel)] ?? 0) > 1) {
                $sessionLabel = events_live_event_session_label((string) ($events[$index]['starts_at'] ?? ''));
                if ($sessionLabel !== '') {
                    $suffix .= ' (' . $sessionLabel . ')';
                }
            }

            $events[$index]['title'] = $baseTitle . $suffix;
        }
    }

    return $events;
}

function events_live_short_venue_label(string $venueName): string
{
    $venue = events_live_plain_text($venueName);
    $venue = preg_replace('/^Sydney Olympic Park\s+/i', '', $venue);
    $venue = str_replace('Centre', 'Centre', $venue);

    return trim((string) $venue);
}

function events_live_event_session_label(string $startsAtValue): string
{
    try {
        $startsAt = events_parse_datetime($startsAtValue);
    } catch (Throwable $error) {
        return '';
    }

    return strtolower($startsAt->format('g:ia')) . ' session';
}

function events_live_cache_is_fresh(array $cache, DateTimeImmutable $now): bool
{
    $generatedAt = trim((string) ($cache['generated_at'] ?? ''));
    if ($generatedAt === '') {
        return false;
    }

    try {
        $generatedTime = new DateTimeImmutable($generatedAt, events_timezone());
    } catch (Throwable $error) {
        return false;
    }

    if ($generatedTime > $now) {
        return false;
    }

    return ($now->getTimestamp() - $generatedTime->getTimestamp()) <= events_live_cache_ttl_seconds();
}

function events_live_cache_read(): ?array
{
    // Read whichever cache copy is newest, either from disk or the current browser session.
    $latest = null;

    foreach (events_live_cache_paths() as $path) {
        if (!is_file($path)) {
            continue;
        }

        $contents = file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            continue;
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            continue;
        }

        if ($latest === null || strcmp((string) ($decoded['generated_at'] ?? ''), (string) ($latest['generated_at'] ?? '')) > 0) {
            $latest = $decoded;
        }
    }

    $sessionCache = events_live_session_cache_read();
    if (
        is_array($sessionCache)
        && ($latest === null || strcmp((string) ($sessionCache['generated_at'] ?? ''), (string) ($latest['generated_at'] ?? '')) > 0)
    ) {
        $latest = $sessionCache;
    }

    return $latest;
}

function events_live_cache_write(array $payload): void
{
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return;
    }

    $wroteToDisk = false;

    foreach (events_live_cache_paths() as $path) {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        if (!is_dir($directory)) {
            continue;
        }

        $written = @file_put_contents($path, $encoded, LOCK_EX);
        if ($written !== false) {
            $wroteToDisk = true;
        }
    }

    if (!$wroteToDisk || PHP_SAPI !== 'cli') {
        events_live_session_cache_write($payload);
    }
}

function events_live_session_cache_read(): ?array
{
    if (PHP_SAPI === 'cli') {
        return null;
    }

    events_live_session_cache_boot();
    $payload = $_SESSION['events_live_cache'] ?? null;

    return is_array($payload) ? $payload : null;
}

function events_live_session_cache_write(array $payload): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    events_live_session_cache_boot();
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['events_live_cache'] = $payload;
    }
}

function events_live_session_cache_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
        return;
    }

    @session_start();
}

function events_live_parse_crowd_value(string $value): ?int
{
    if (preg_match('/\d[\d,]*/', $value, $matches) !== 1) {
        return null;
    }

    return (int) str_replace(',', '', $matches[0]);
}

function events_live_clean_title(string $value): string
{
    $text = events_live_plain_text($value);
    $parts = array_map('trim', explode('|', $text));

    foreach ($parts as $part) {
        if ($part !== '') {
            return $part;
        }
    }

    return $text;
}

function events_live_build_datetime_from_label(DateTimeImmutable $date, string $timeLabel): DateTimeImmutable
{
    $normalized = events_live_normalize_time_label($timeLabel);
    $candidate = DateTimeImmutable::createFromFormat(
        '!Y-m-d g:ia',
        $date->format('Y-m-d') . ' ' . $normalized,
        events_timezone()
    );

    if ($candidate instanceof DateTimeImmutable) {
        return $candidate;
    }

    return $date->setTime(19, 0);
}

function events_live_normalize_time_label(string $value): string
{
    $text = strtolower(trim(events_live_plain_text($value)));
    $text = preg_replace('/\s+/', '', $text);

    if (preg_match('/^(\d{1,2})(am|pm)$/', $text, $matches) === 1) {
        return $matches[1] . ':00' . $matches[2];
    }

    return $text;
}

function events_live_normalize_header(string $value): string
{
    $text = strtolower(events_live_plain_text($value));
    $text = str_replace('&', ' and ', $text);
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
    return trim((string) $text);
}

function events_live_plain_text(string $value): string
{
    $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    return trim((string) $text);
}

function events_live_slugify(string $value): string
{
    $slug = strtolower(events_live_plain_text($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim((string) $slug, '-');
}

function events_live_infer_year(int $monthNumber, DateTimeImmutable $now): int
{
    $year = (int) $now->format('Y');
    $currentMonth = (int) $now->format('n');

    if ($currentMonth === 12 && $monthNumber === 1) {
        return $year + 1;
    }

    return $year;
}
