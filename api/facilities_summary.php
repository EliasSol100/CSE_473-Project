<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/page_payloads.php';

$selectedFacilityId = isset($_GET['facility_id']) ? trim((string) $_GET['facility_id']) : '';

echo json_encode(facilities_page_payload($selectedFacilityId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
