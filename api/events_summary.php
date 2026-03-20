<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/event_forecasts.php';

$selectedEventId = isset($_GET['event']) ? trim((string) $_GET['event']) : null;
$payload = events_view_payload($selectedEventId);

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
