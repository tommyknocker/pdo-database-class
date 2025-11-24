<?php

use tommyknocker\pdodb\PdoDb;

/**
 * Helper functions for examples to work across different database dialects
 */


/**
 * Get database configuration based on environment or default to SQLite
 */
function getExampleConfig(): array
{
    $driver = mb_strtolower(getenv('PDODB_DRIVER') ?: 'sqlite', 'UTF-8');
    
    // Map driver names to config file names
    $configFileMap = [
        'oci' => 'oracle',
        'sqlsrv' => 'sqlsrv',
    ];
    $configFileName = $configFileMap[$driver] ?? $driver;
    
    // For CI environments, use environment variables directly
    if ($driver === 'sqlsrv') {
        $dbUser = getenv('PDODB_USERNAME');
        $dbPass = getenv('PDODB_PASSWORD');
        $dbHost = getenv('PDODB_HOST') ?: 'localhost';
        $dbPort = getenv('PDODB_PORT') ?: '1433';
        $dbName = getenv('PDODB_DATABASE') ?: 'testdb';
        
        // If environment variables are set (CI), use them
        if ($dbUser !== false && $dbPass !== false) {
            return [
                'driver' => 'sqlsrv',
                'host' => $dbHost,
                'port' => (int)$dbPort,
                'username' => $dbUser,
                'password' => $dbPass,
                'dbname' => $dbName,
                'trust_server_certificate' => true,
                'encrypt' => true,
            ];
        }
    }
    
    // For MySQL/MariaDB CI environments, check for PDODB_USERNAME and PDODB_PASSWORD
    if ($driver === 'mysql' || $driver === 'mariadb') {
        $dbUser = getenv('PDODB_USERNAME');
        $dbPass = getenv('PDODB_PASSWORD');
        $dbHost = getenv('PDODB_HOST') ?: 'localhost';
        $dbPort = getenv('PDODB_PORT') ?: '3306';
        $dbName = getenv('PDODB_DATABASE') ?: 'testdb';
        $dbCharset = getenv('PDODB_CHARSET') ?: 'utf8mb4';
        
        // If environment variables are set (CI), use them
        if ($dbUser !== false && $dbPass !== false) {
            return [
                'driver' => $driver,
                'host' => $dbHost,
                'port' => (int)$dbPort,
                'username' => $dbUser,
                'password' => $dbPass,
                'dbname' => $dbName,
                'charset' => $dbCharset,
            ];
        }
    }
    
    // For Oracle CI environments, check for PDODB_USERNAME and PDODB_PASSWORD
    if ($driver === 'oci') {
        $dbUser = getenv('PDODB_USERNAME');
        $dbPass = getenv('PDODB_PASSWORD');
        $dbHost = getenv('PDODB_HOST') ?: 'localhost';
        $dbPort = getenv('PDODB_PORT') ?: '1521';
        $dbName = getenv('PDODB_DATABASE') ?: 'XE';
        $serviceName = getenv('PDODB_SERVICE_NAME') ?: getenv('PDODB_SID') ?: 'XEPDB1';
        $dbCharset = getenv('PDODB_CHARSET') ?: 'UTF8';
        
        // If environment variables are set (CI), use them
        if ($dbUser !== false && $dbPass !== false) {
            return [
                'driver' => 'oci',
                'host' => $dbHost,
                'port' => (int)$dbPort,
                'username' => $dbUser,
                'password' => $dbPass,
                'dbname' => $dbName,
                'service_name' => $serviceName,
                'charset' => $dbCharset,
            ];
        }
    }
    
    $configFile = __DIR__ . "/config.{$configFileName}.php";
    
    if (!file_exists($configFile)) {
        // Fallback to generic config.php or SQLite
        if (file_exists(__DIR__ . '/config.php')) {
            $config = require __DIR__ . '/config.php';
            return $config[$driver] ?? $config['sqlite'];
        }
        // Ultimate fallback
        return ['driver' => 'sqlite', 'path' => ':memory:'];
    }
    
    return require $configFile;
}

/**
 * Create a PdoDb instance for examples with unified error handling
 * 
 * @throws \Throwable if connection fails
 */
function createExampleDb(): PdoDb
{
    $config = getExampleConfig();
    $driver = $config['driver'];
    unset($config['driver']);
    
    try {
        return new PdoDb($driver, $config);
    } catch (\Throwable $e) {
        echo "⚠️  Connection failed: {$e->getMessage()}\n";
        echo "   (Check your database server and config settings)\n";
        exit(1);
    }
}

/**
 * Create a PdoDb instance with custom config and unified error handling
 * 
 * @param string $driver Database driver
 * @param array $config Database configuration
 * @return PdoDb
 */
function createPdoDbWithErrorHandling(string $driver, array $config): PdoDb
{
    try {
        return new PdoDb($driver, $config);
    } catch (\Throwable $e) {
        echo "⚠️  Connection failed: {$e->getMessage()}\n";
        echo "   (Check your database server and config settings)\n";
        exit(1);
    }
}

/**
 * Drop table if exists and create new one using Schema Builder
 * Handles foreign key constraints properly for each database
 * 
 * This function uses the library's Schema Builder API (Yii2-style fluent API)
 * to demonstrate proper cross-dialect DDL operations.
 * 
 * Usage with fluent API (recommended):
 *   $schema = $db->schema();
 *   recreateTable($db, 'users', [
 *       'id' => $schema->primaryKey(),
 *       'name' => $schema->text()->notNull(),
 *       'email' => $schema->text()->unique(),
 *   ]);
 */
function recreateTable(PdoDb $db, string $tableName, array $columns, array $options = []): void
{
    $connection = $db->connection;
    if ($connection === null) {
        throw new RuntimeException('Database connection not initialized');
    }
    
    $driver = $connection->getDriverName();
    $schema = $db->schema();
    
    // Disable foreign key checks for MySQL/MariaDB
    if ($driver === 'mysql' || $driver === 'mariadb') {
        $db->rawQuery("SET FOREIGN_KEY_CHECKS=0");
    }
    
    // For MSSQL, drop foreign key constraints before dropping table
    if ($driver === 'sqlsrv') {
        // Get all foreign key constraints referencing this table
        $fkQuery = "
            SELECT 
                fk.name AS fk_name,
                OBJECT_SCHEMA_NAME(fk.parent_object_id) AS schema_name,
                OBJECT_NAME(fk.parent_object_id) AS table_name
            FROM sys.foreign_keys fk
            WHERE OBJECT_NAME(fk.referenced_object_id) = '$tableName'
        ";
        $fks = $db->rawQuery($fkQuery);
        foreach ($fks as $fk) {
            $fkTable = $fk['table_name'];
            $fkName = $fk['fk_name'];
            try {
                // Use schema API to drop foreign key
                $schema->dropForeignKey($fkName, $fkTable);
            } catch (\Exception $e) {
                // If schema API fails, fall back to raw SQL
                $schemaName = $fk['schema_name'] ?? 'dbo';
                $connection->query("ALTER TABLE [{$schemaName}].[{$fkTable}] DROP CONSTRAINT [{$fkName}]");
            }
        }
        
        // Also drop foreign keys defined in this table
        $fkQuery2 = "
            SELECT 
                fk.name AS fk_name
            FROM sys.foreign_keys fk
            WHERE OBJECT_NAME(fk.parent_object_id) = '$tableName'
        ";
        $fks2 = $db->rawQuery($fkQuery2);
        foreach ($fks2 as $fk) {
            $fkName = $fk['fk_name'];
            try {
                // Use schema API to drop foreign key
                $schema->dropForeignKey($fkName, $tableName);
            } catch (\Exception $e) {
                // If schema API fails, fall back to raw SQL
                $connection->query("ALTER TABLE [{$tableName}] DROP CONSTRAINT [{$fkName}]");
            }
        }
    }
    
    // Drop table using schema API
    $schema->dropTableIfExists($tableName);
    
    // Create table using schema API (columns should use fluent API: $schema->primaryKey(), $schema->text()->notNull(), etc.)
    $schema->createTable($tableName, $columns, $options);
    
    // Re-enable foreign key checks for MySQL/MariaDB
    if ($driver === 'mysql' || $driver === 'mariadb') {
        $db->rawQuery("SET FOREIGN_KEY_CHECKS=1");
    }
}

/**
 * Get current database driver name
 */
function getCurrentDriver(PdoDb $db): string
{
    $connection = $db->connection;
    if ($connection === null) {
        return 'unknown';
    }
    return $connection->getDriverName();
}

/**
 * Normalize array keys to lowercase for Oracle compatibility
 * Oracle returns column names in uppercase, but examples use lowercase
 * 
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function normalizeRowKeys(array $row): array
{
    $normalized = [];
    foreach ($row as $key => $value) {
        // Convert CLOB resources to strings for Oracle
        if (is_resource($value) && get_resource_type($value) === 'stream') {
            $value = stream_get_contents($value);
        }
        $normalized[strtolower($key)] = $value;
    }
    return $normalized;
}

