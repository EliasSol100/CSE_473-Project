<?php
// Browser pages call this endpoint to run one NSW parking sync in the background.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/live_collector.php';

// Let the sync finish even if the user navigates away while the request is running.
ignore_user_abort(true);
@set_time_limit(60);

$result = live_collector_run();

if (($result['status'] ?? '') === 'error') {
    http_response_code(500);
} elseif (($result['status'] ?? '') === 'busy') {
    http_response_code(202);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
