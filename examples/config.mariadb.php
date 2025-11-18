<?php
/**
 * MariaDB Database configuration for examples
 * 
 * This config file checks for CI environment variables (DB_*) first,
 * then falls back to local development defaults.
 */

// Check for CI environment variables first
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');
$dbHost = getenv('DB_HOST');
$dbPort = getenv('DB_PORT');
$dbName = getenv('DB_NAME');
$dbCharset = getenv('DB_CHARSET');

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

