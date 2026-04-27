<?php
// JSON endpoint for Events and Event Forecast pages, including category filters.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/event_forecast_engine.php';

$selectedEventId = isset($_GET['event']) ? trim((string) $_GET['event']) : null;
$selectedCategory = isset($_GET['category']) ? trim((string) $_GET['category']) : null;
$payload = events_view_payload($selectedEventId, $selectedCategory);

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
