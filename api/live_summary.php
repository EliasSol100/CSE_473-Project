<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/functions.php';

$summary = summary_metrics();
$latest = latest_snapshots();
$topLatest = array_slice($latest, 0, 8);
$predictionBundle = facility_hourly_predictions($latest);

echo json_encode([
    'summary' => $summary,
    'dataset' => dataset_overview(),
    'latest' => $latest,
    'top_latest' => $topLatest,
    'hourly' => hourly_average_occupancy(),
    'distribution' => availability_distribution(),
    'prediction_windows' => $predictionBundle['windows'] ?? [],
    'hourly_predictions' => $predictionBundle['predictions'] ?? [],
    'prediction_summary' => $predictionBundle['summary'] ?? [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
