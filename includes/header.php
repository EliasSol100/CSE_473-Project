<?php require_once __DIR__ . '/functions.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(site_title($pageTitle ?? '')) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<header class="site-header">
    <div class="header-glow" aria-hidden="true"></div>
    <div class="container nav-wrap">
        <a class="brand" href="index.php" aria-label="Smart Parking NSW home">
            <span class="brand-mark">SP</span>
            <div class="brand-copy">
                <strong>Smart Parking NSW</strong>
                <small>Live Occupancy Intelligence Platform</small>
            </div>
        </a>
        <nav class="main-nav" aria-label="Primary">
            <a class="<?= nav_active('index.php') ?>" href="index.php">Home</a>
            <a class="<?= nav_active('dashboard.php') ?>" href="dashboard.php">Dashboard</a>
            <a class="<?= nav_active('facilities.php') ?>" href="facilities.php">Facilities</a>
            <a class="<?= nav_active('insights.php') ?>" href="insights.php">Insights</a>
            <a class="<?= nav_active('events.php') ?>" href="events.php">Events</a>
            <a class="<?= nav_active('about.php') ?>" href="about.php">About</a>
        </nav>
    </div>
</header>
<main class="page-shell">
