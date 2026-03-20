<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/page_payloads.php';

echo json_encode(about_page_payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
