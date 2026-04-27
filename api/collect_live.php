<?php
// Trigger endpoint: runs one live NSW parking sync and returns collector status.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/live_collector.php';

// Allow the sync to finish even if the browser leaves the page mid-request.
ignore_user_abort(true);
@set_time_limit(60);

$result = live_collector_run();

if (($result['status'] ?? '') === 'error') {
    http_response_code(500);
} elseif (($result['status'] ?? '') === 'busy') {
    http_response_code(202);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
