<?php
require_once __DIR__ . '/functions.php';

function home_page_payload(): array
{
    $summary = summary_metrics();
    $dataset = dataset_overview();
    $latest = latest_snapshots();

    return [
        'summary' => $summary,
        'dataset' => $dataset,
        'top_facilities' => array_slice($latest, 0, 3),
    ];
}

function facilities_page_payload(string $selectedFacilityId = ''): array
{
    $selectedFacilityId = trim($selectedFacilityId);
    $selectedSummary = $selectedFacilityId !== '' ? facility_summary($selectedFacilityId) : null;
    $history = $selectedFacilityId !== '' ? facility_history($selectedFacilityId) : [];
    $historyLabels = array_map(
        fn(array $row) => gmdate('H:i', strtotime((string) $row['recorded_at'])),
        $history
    );
    $historyValues = array_map(
        fn(array $row) => (float) $row['occupancy_percent'],
        $history
    );

    return [
        'summary' => summary_metrics(),
        'selected_facility_id' => $selectedFacilityId,
        'facilities' => latest_snapshots(),
        'selected_summary' => $selectedSummary,
        'history_labels' => $historyLabels,
        'history_values' => $historyValues,
    ];
}

function insights_average_accuracy(array $classificationMetrics): float
{
    if ($classificationMetrics === []) {
        return 0.0;
    }

    $scores = array_map(
        fn(array $row) => (float) ($row['accuracy'] ?? 0),
        $classificationMetrics
    );

    return (array_sum($scores) / count($scores)) * 100;
}

function insights_page_payload(): array
{
    $peak = peak_hour();
    $topAverage = top_average_occupancy(10);
    $capacityLeaders = capacity_leaders(10);
    $regMetrics = array_slice(regression_metrics(), 0, 10);
    $clsMetrics = array_slice(classification_metrics(), 0, 10);

    return [
        'summary' => summary_metrics(),
        'dataset' => dataset_overview(),
        'peak' => $peak,
        'top_average' => $topAverage,
        'capacity_leaders' => $capacityLeaders,
        'regression_metrics' => $regMetrics,
        'classification_metrics' => $clsMetrics,
        'avg_accuracy' => insights_average_accuracy($clsMetrics),
        'top_average_labels' => array_map(
            fn(array $row) => $row['facility_name'],
            array_slice($topAverage, 0, 8)
        ),
        'top_average_values' => array_map(
            fn(array $row) => (float) $row['average_occupancy'],
            array_slice($topAverage, 0, 8)
        ),
        'capacity_labels' => array_map(
            fn(array $row) => $row['facility_name'],
            array_slice($capacityLeaders, 0, 8)
        ),
        'capacity_values' => array_map(
            fn(array $row) => (int) $row['capacity'],
            array_slice($capacityLeaders, 0, 8)
        ),
    ];
}

function about_page_payload(): array
{
    return [
        'summary' => summary_metrics(),
        'dataset' => dataset_overview(),
    ];
}
