<?php
// Events page: combines official Sydney events with nearby parking pressure forecasts.
$pageTitle = 'Events';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/event_forecast_engine.php';
require_once __DIR__ . '/includes/live_collector.php';

// Auto-sync keeps the event cards aligned with the newest parking snapshots.
$collectorIntervalMs = 10000;
try {
    $collectorIntervalMs = max(10000, ((int) (live_collector_config()['interval_seconds'] ?? 10)) * 1000);
} catch (Throwable) {
    $collectorIntervalMs = 10000;
}
$collectorIntervalSeconds = max(1, (int) round($collectorIntervalMs / 1000));

$requestedEventId = isset($_GET['event']) ? trim((string) $_GET['event']) : null;
$requestedCategory = isset($_GET['category']) ? trim((string) $_GET['category']) : 'all';
$eventsPayload = events_view_payload($requestedEventId, $requestedCategory);
$events = $eventsPayload['events'];
$selectedEvent = $eventsPayload['selected_event'];
$selectedEventId = (string) ($eventsPayload['selected_event_id'] ?? $requestedEventId ?? '');
$selectedCategory = (string) ($eventsPayload['selected_category'] ?? 'all');
$hasEvents = $events !== [];
$eventsSyncUrl = 'api/events_summary.php';
?>
<div class="container" data-live-collector-url="api/collect_live.php" data-live-events-url="<?= h($eventsSyncUrl) ?>" data-live-events-selected="<?= h($selectedEventId) ?>" data-live-events-category="<?= h($selectedCategory) ?>" data-live-events-page-type="overview" data-live-events-page-label="Events page" data-live-collector-interval="<?= h((string) $collectorIntervalMs) ?>">
    <div class="section-title">
        <div>
            <h2>Events outlook</h2>
            <p>Real Sydney events across the next seven Sydney calendar days, paired with short-range +1h, +2h, and +3h parking forecasts for the tracked live API network.</p>
        </div>
        <div class="tag-row section-tags">
            <span class="tag" data-events-window>Forecast window: <?= h($eventsPayload['window_label']) ?></span>
            <span class="tag" data-live-collector-status>Auto sync every <?= h((string) $collectorIntervalSeconds) ?> seconds while this Events page is open</span>
        </div>
    </div>

    <section class="notice" style="margin-bottom:24px;" data-events-note<?= empty($eventsPayload['note']) ? ' hidden' : '' ?>>
        <h3>Forecast coverage note</h3>
        <p class="muted" data-events-note-copy><?= h($eventsPayload['note'] ?? '') ?></p>
    </section>

    <section class="empty-state card" data-events-empty<?= $hasEvents ? ' hidden' : '' ?>>No current or upcoming researched Sydney events are available in this seven-day forecast window.</section>

    <div data-events-content<?= $hasEvents ? '' : ' hidden' ?>>
        <section class="info-grid">
            <article class="notice">
                <h3>Tracked events</h3>
                <div class="metric" data-events-tracked-count><?= h(format_number(count($events))) ?></div>
                <p class="muted">Each card below is tied to a real event source and forecasts parking pressure through short-range event-day windows only.</p>
            </article>
            <article class="notice">
                <h3>Selected event spillover</h3>
                <div class="metric" data-events-spillover><?= h(format_number($selectedEvent['peak_vehicle_demand'] ?? 0)) ?></div>
                <p class="muted">Estimated extra vehicles most likely to spill into the monitored park-and-ride network during the selected event peak.</p>
            </article>
            <article class="notice">
                <h3>Pressure snapshot</h3>
                <div class="metric" data-events-pressure-count><?= h(format_number(($selectedEvent['status_counts']['full'] ?? 0) + ($selectedEvent['status_counts']['limited'] ?? 0))) ?></div>
                <p class="muted" data-events-pressure-copy><?= h(format_number($selectedEvent['status_counts']['full'] ?? 0)) ?> full and <?= h(format_number($selectedEvent['status_counts']['limited'] ?? 0)) ?> limited sites are projected under the selected event.</p>
            </article>
        </section>

        <section class="table-card" style="margin-bottom:24px;" data-events-browser>
            <div class="section-title">
                <div>
                    <h3>Browse live event types</h3>
                    <p>Filter the official Sydney event feed by category, including music, festivals, sport, family events, and more.</p>
                </div>
                <p class="muted" style="margin:0;" data-events-card-count>Showing <?= h(format_number(count($events))) ?> events</p>
            </div>
            <form class="filters" method="get" data-events-category-form>
                <?php if ($selectedEventId !== ''): ?>
                    <input type="hidden" name="event" value="<?= h($selectedEventId) ?>">
                <?php endif; ?>
                <select class="select-field" name="category" data-events-category-filter>
                    <option value="all">All event types</option>
                    <?php foreach (($eventsPayload['category_options'] ?? []) as $option): ?>
                        <option value="<?= h($option['slug']) ?>"<?= $selectedCategory === (string) $option['slug'] ? ' selected' : '' ?>><?= h($option['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </section>

        <section class="grid-two" style="margin-bottom:24px;" data-events-cards>
            <?php foreach ($events as $event): ?>
                <?php $active = $selectedEvent && $selectedEvent['id'] === $event['id']; ?>
                <article class="panel event-selector<?= $active ? ' active' : '' ?>" data-event-id="<?= h($event['id']) ?>">
                    <div class="tag-row" style="margin-top:0;">
                        <span class="tag"><?= h(events_parse_datetime($event['starts_at'])->format('D, d M Y')) ?></span>
                        <span class="tag tag-category"><?= h($event['active_category_label'] ?? $event['category_label'] ?? 'Event') ?></span>
                        <span class="tag"><?= h($event['attendance_label']) ?>: <?= h(format_number($event['attendance_estimate'])) ?></span>
                    </div>
                    <h3><?= h($event['title']) ?></h3>
                    <div class="event-meta-list">
                        <p class="muted event-meta-item"><strong>Starts:</strong> <?= h($event['starts_at_display']) ?></p>
                        <p class="muted event-meta-item"><strong>Ends:</strong> <?= h($event['ends_at_display']) ?></p>
                        <p class="muted event-meta-item"><strong>Venue:</strong> <?= h($event['venue_name']) ?>, <?= h($event['venue_area']) ?></p>
                    </div>
                    <p class="muted"><?= h($event['network_headline']) ?></p>
                    <div class="event-actions">
                        <a class="btn btn-primary" href="event_forecasts.php?event=<?= urlencode($event['id']) ?>">Open forecast</a>
                        <a class="btn btn-secondary" href="<?= h($event['source_url']) ?>" target="_blank" rel="noopener noreferrer">Official source</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    </div>
</div>
<script>
// app.js reuses this payload when filtering event categories and refreshing forecasts.
window.eventsState = <?= json_encode($eventsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
