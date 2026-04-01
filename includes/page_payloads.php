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
    $latest = latest_snapshots();
    $predictionBundle = facility_hourly_predictions($latest);
    $predictions = is_array($predictionBundle['predictions'] ?? null) ? $predictionBundle['predictions'] : [];
    $selectedSummary = $selectedFacilityId !== '' ? facility_summary($selectedFacilityId) : null;
    if ($selectedFacilityId !== '' && $selectedSummary === null) {
        $selectedFacilityId = '';
    }

    $selectedPrediction = $selectedFacilityId !== '' && isset($predictions[$selectedFacilityId])
        ? $predictions[$selectedFacilityId]
        : null;

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
        'facilities' => $latest,
        'selected_summary' => $selectedSummary,
        'selected_prediction' => $selectedPrediction,
        'history_labels' => $historyLabels,
        'history_values' => $historyValues,
        'prediction_windows' => $predictionBundle['windows'] ?? [],
        'hourly_predictions' => $predictions,
        'prediction_summary' => $predictionBundle['summary'] ?? [],
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
    $metricsSource = insights_metrics_source();
    $allRegMetrics = regression_metrics_for_source($metricsSource);
    $allClsMetrics = classification_metrics_for_source($metricsSource);
    $regMetrics = array_slice($allRegMetrics, 0, 10);
    $clsMetrics = array_slice($allClsMetrics, 0, 10);
    $metricsSourceLabel = $metricsSource === 'live' ? 'Live collector history' : 'Imported SQL baseline';
    $classificationContextNote = $metricsSource === 'live'
        ? 'Average baseline classification accuracy is calculated from real sequential facility history, using the previous recorded status as the next-status baseline.'
        : 'The live collector is not currently active, so this view is using the imported SQL baseline metrics as a fallback.';
    $regressionNote = $metricsSource === 'live'
        ? 'Uses the previous recorded occupancy rate at each facility as the next-reading baseline prediction.'
        : 'Showing the regression metrics imported from the SQL setup file as the current fallback baseline.';
    $classificationNote = $metricsSource === 'live'
        ? 'Shows how often the previous recorded availability class matched the next observed class for each facility.'
        : 'Showing the classification metrics imported from the SQL setup file as the current fallback baseline.';

    return [
        'summary' => summary_metrics(),
        'dataset' => dataset_overview(),
        'peak' => $peak,
        'top_average' => $topAverage,
        'capacity_leaders' => $capacityLeaders,
        'regression_metrics' => $regMetrics,
        'classification_metrics' => $clsMetrics,
        'avg_accuracy' => insights_average_accuracy($allClsMetrics),
        'metrics_source' => $metricsSource,
        'metrics_source_label' => $metricsSourceLabel,
        'classification_context_note' => $classificationContextNote,
        'regression_note' => $regressionNote,
        'classification_note' => $classificationNote,
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
