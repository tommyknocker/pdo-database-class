<?php
/**
 * MariaDB Database configuration for examples
 * 
 * This config file checks for CI environment variables (PDODB_*) first,
 * then falls back to local development defaults.
 */

// Check for CI environment variables first
$dbUser = getenv('PDODB_USERNAME');
$dbPass = getenv('PDODB_PASSWORD');
$dbHost = getenv('PDODB_HOST');
$dbPort = getenv('PDODB_PORT');
$dbName = getenv('PDODB_DATABASE');
$dbCharset = getenv('PDODB_CHARSET');

// If CI variables are set, use them
if ($dbUser !== false && $dbPass !== false) {
    return [
        'driver' => 'mariadb',
        'host' => $dbHost !== false ? $dbHost : '127.0.0.1',
        'port' => $dbPort !== false ? (int)$dbPort : 3305,
        'username' => $dbUser,
        'password' => $dbPass,
        'dbname' => $dbName !== false ? $dbName : 'testdb',
        'charset' => $dbCharset !== false ? $dbCharset : 'utf8mb4',
    ];
}

// Fallback to local development defaults
return [
    'driver' => 'mariadb',
    'host' => '127.0.0.1',
    'port' => 3305,
    'username' => 'testuser',
    'password' => 'testpass',
    'dbname' => 'testdb',
    'charset' => 'utf8mb4',
];

