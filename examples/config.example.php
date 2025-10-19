<?php
/**
 * Database configuration for examples
 * 
 * Copy this file to config.php and update with your credentials
 */

return [
    'mysql' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => '',
        'dbname' => 'pdodb_examples',
        'charset' => 'utf8mb4',
    ],
    
    'pgsql' => [
        'driver' => 'pgsql',
        'host' => 'localhost',
        'port' => 5432,
        'username' => 'postgres',
        'password' => '',
        'dbname' => 'pdodb_examples',
    ],
    
    'sqlite' => [
        'driver' => 'sqlite',
        'path' => ':memory:',  // Use in-memory database for examples
        // 'path' => __DIR__ . '/database.sqlite',  // Or use file-based
    ],
];

