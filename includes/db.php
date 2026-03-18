<?php
function app_config(): array
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/config.php';
        $localConfig = __DIR__ . '/config.local.php';
        if (file_exists($localConfig)) {
            $config = array_merge($config, require $localConfig);
        }
    }

    return $config;
}

function db(): mysqli
{
    static $connection = null;

    if ($connection instanceof mysqli) {
        return $connection;
    }

    $config = app_config();
    mysqli_report(MYSQLI_REPORT_OFF);

    $connection = @new mysqli(
        $config['db_host'],
        $config['db_user'],
        $config['db_pass'],
        $config['db_name'],
        (int) $config['db_port']
    );

    if ($connection->connect_error) {
        http_response_code(500);
        die('<h2>Database connection failed</h2><p>Please import <code>database/smart_parking_web.sql</code> into phpMyAdmin and check <code>includes/config.php</code>.</p><p>Error: ' . htmlspecialchars($connection->connect_error) . '</p>');
    }

    $connection->set_charset('utf8mb4');
    return $connection;
}
