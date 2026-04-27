<?php
// Shared header: loads helpers, theme setup, navigation, and Chart.js defaults.
require_once __DIR__ . '/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(site_title($pageTitle ?? '')) ?></title>
    <script>
        // Apply the saved theme before CSS loads to prevent a light/dark flash.
        (() => {
            try {
                const storedTheme = window.localStorage.getItem('smartParkingTheme');
                const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                const theme = storedTheme === 'dark' || storedTheme === 'light'
                    ? storedTheme
                    : systemTheme;
                document.documentElement.setAttribute('data-theme', theme);
            } catch (error) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/style.css')) ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Central Chart.js theme helpers keep chart colors readable in light and dark mode.
        window.smartParkingChartColors = () => {
            const rootStyles = getComputedStyle(document.documentElement);
            return {
                muted: rootStyles.getPropertyValue('--muted').trim() || '#53627a',
                primary: rootStyles.getPropertyValue('--chart-primary').trim() || '#0e5eb5',
                secondary: rootStyles.getPropertyValue('--chart-secondary').trim() || '#0ea5a8',
                accent: rootStyles.getPropertyValue('--chart-accent').trim() || '#f59e0b',
                danger: rootStyles.getPropertyValue('--chart-danger').trim() || '#b0302f'
            };
        };
        window.applySmartParkingChartTheme = () => {
            if (!window.Chart) {
                return;
            }

            const colors = window.smartParkingChartColors();
            Chart.defaults.font.family = '"Public Sans", "Segoe UI", sans-serif';
            Chart.defaults.color = colors.muted;
            Chart.defaults.borderColor = 'rgba(128, 146, 173, 0.2)';
            Chart.defaults.plugins.legend.labels.usePointStyle = true;
        };
        window.applySmartParkingChartTheme();
    </script>
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
        <div class="nav-actions">
            <nav class="main-nav" aria-label="Primary">
                <a class="<?= nav_active('index.php') ?>" href="index.php">Home</a>
                <a class="<?= nav_active('dashboard.php') ?>" href="dashboard.php">Dashboard</a>
                <a class="<?= nav_active('facilities.php') ?>" href="facilities.php">Facilities</a>
                <a class="<?= nav_active('insights.php') ?>" href="insights.php">Insights</a>
                <a class="<?= nav_active('events.php') ?>" href="events.php">Events</a>
                <a class="<?= nav_active('about.php') ?>" href="about.php">About</a>
            </nav>
            <button
                class="theme-toggle"
                type="button"
                data-theme-toggle
                aria-label="Switch to dark mode"
                aria-pressed="false"
                title="Toggle dark mode"
            >
                <span class="theme-toggle-icon theme-toggle-icon-moon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false">
                        <path d="M19.5 14.5A7.5 7.5 0 0 1 9.5 4.5a8.5 8.5 0 1 0 10 10Z" fill="currentColor"></path>
                    </svg>
                </span>
                <span class="theme-toggle-icon theme-toggle-icon-sun" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false">
                        <circle cx="12" cy="12" r="4.2" fill="currentColor"></circle>
                        <path d="M12 1.75v2.5M12 19.75v2.5M4.78 4.78l1.77 1.77M17.45 17.45l1.77 1.77M1.75 12h2.5M19.75 12h2.5M4.78 19.22l1.77-1.77M17.45 6.55l1.77-1.77" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="1.8"></path>
                    </svg>
                </span>
                <span class="theme-toggle-label">Theme</span>
            </button>
        </div>
    </div>
</header>
<main class="page-shell">
