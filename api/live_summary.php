<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/functions.php';

echo json_encode([
    'summary' => summary_metrics(),
    'dataset' => dataset_overview(),
    'latest' => array_slice(latest_snapshots(), 0, 8),
    'hourly' => hourly_average_occupancy(),
    'distribution' => availability_distribution(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
