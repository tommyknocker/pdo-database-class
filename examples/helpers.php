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
    $configFile = __DIR__ . "/config.{$driver}.php";
    
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
 * Create a PdoDb instance for examples
 */
function createExampleDb(): PdoDb
{
    $config = getExampleConfig();
    $driver = $config['driver'];
    unset($config['driver']);
    
    return new PdoDb($driver, $config);
}

/**
 * Get CREATE TABLE statement with proper syntax for current driver
 * 
 * Usage:
 *   getCreateTableSql($db, 'users', [
 *       'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
 *       'name' => 'TEXT NOT NULL',
 *       'age' => 'INTEGER'
 *   ]);
 */
function getCreateTableSql(PdoDb $db, string $tableName, array $columns, array $options = []): string
{
    $connection = $db->connection;
    if ($connection === null) {
        throw new RuntimeException('Database connection not initialized');
    }
    
    $driver = $connection->getDriverName();
    $columnDefs = [];
    $constraints = [];
    
    foreach ($columns as $colName => $colDef) {
        // Check if this is a table constraint (e.g., PRIMARY KEY (...), FOREIGN KEY (...))
        if (str_starts_with($colName, 'PRIMARY KEY') || 
            str_starts_with($colName, 'FOREIGN KEY') || 
            str_starts_with($colName, 'UNIQUE') ||
            str_starts_with($colName, 'INDEX') ||
            str_starts_with($colName, 'CHECK')) {
            $constraints[] = $colName;
            continue;
        }
        
        // Normalize column definition
        $colDef = normalizeColumnDef($driver, $colDef);
        $columnDefs[] = "$colName $colDef";
    }
    
    // Combine columns and constraints
    $allDefs = array_merge($columnDefs, $constraints);
    $sql = "CREATE TABLE $tableName (\n    " . implode(",\n    ", $allDefs) . "\n)";
    
    // Add table options (e.g., ENGINE=InnoDB for MySQL/MariaDB)
    if ($driver === 'mysql' || $driver === 'mariadb') {
        $engine = $options['engine'] ?? 'InnoDB';
        $sql .= " ENGINE=$engine";
    }
    
    return $sql;
}

/**
 * Drop table if exists and create new one
 * Handles foreign key constraints properly for each database
 */
function recreateTable(PdoDb $db, string $tableName, array $columns, array $options = []): void
{
    $connection = $db->connection;
    if ($connection === null) {
        throw new RuntimeException('Database connection not initialized');
    }
    
    $driver = $connection->getDriverName();
    
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
            $schema = $fk['schema_name'] ?? 'dbo';
            $fkTable = $fk['table_name'];
            $fkName = $fk['fk_name'];
            // Use connection->query() for DDL operations in MSSQL
            $connection->query("ALTER TABLE [{$schema}].[{$fkTable}] DROP CONSTRAINT [{$fkName}]");
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
            // Use connection->query() for DDL operations in MSSQL
            $connection->query("ALTER TABLE [{$tableName}] DROP CONSTRAINT [{$fkName}]");
        }
    }
    
    // Drop table with CASCADE for PostgreSQL to handle dependent objects
    if ($driver === 'pgsql') {
        $db->rawQuery("DROP TABLE IF EXISTS $tableName CASCADE");
    } elseif ($driver === 'sqlsrv') {
        // MSSQL doesn't support DROP TABLE IF EXISTS, use IF OBJECT_ID check
        // Use connection->query() for DDL operations in MSSQL
        $connection->query("IF OBJECT_ID('$tableName', 'U') IS NOT NULL DROP TABLE [$tableName]");
    } else {
        $db->rawQuery("DROP TABLE IF EXISTS $tableName");
    }
    
    // Create table
    $sql = getCreateTableSql($db, $tableName, $columns, $options);
    if ($driver === 'sqlsrv') {
        // Use connection->query() for DDL operations in MSSQL
        $connection->query($sql);
    } else {
        $db->rawQuery($sql);
    }
    
    // Re-enable foreign key checks for MySQL/MariaDB
    if ($driver === 'mysql' || $driver === 'mariadb') {
        $db->rawQuery("SET FOREIGN_KEY_CHECKS=1");
    }
}

/**
 * Normalize column definition for specific driver
 */
function normalizeColumnDef(string $driver, string $colDef): string
{
    // First, normalize the base definition
    $normalized = $colDef;
    
    // Handle auto-increment primary keys
    if ($driver === 'mysql' || $driver === 'mariadb') {
        // Convert to MySQL/MariaDB syntax
        $normalized = str_replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'INT AUTO_INCREMENT PRIMARY KEY', $normalized);
        $normalized = str_replace('SERIAL PRIMARY KEY', 'INT AUTO_INCREMENT PRIMARY KEY', $normalized);
        
        // Handle data types - TEXT must become VARCHAR for UNIQUE/INDEX constraints
        $normalized = str_replace('TEXT', 'VARCHAR(255)', $normalized);
        $normalized = str_replace('REAL', 'DECIMAL(10,2)', $normalized);
        $normalized = str_replace('DATETIME DEFAULT CURRENT_TIMESTAMP', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP', $normalized);
        $normalized = str_replace('DATETIME', 'TIMESTAMP', $normalized);
        
    } elseif ($driver === 'sqlsrv') {
        // Convert to MSSQL syntax
        $normalized = str_replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'INT IDENTITY(1,1) PRIMARY KEY', $normalized);
        $normalized = str_replace('INTEGER AUTOINCREMENT', 'INT IDENTITY(1,1)', $normalized);
        $normalized = str_replace('AUTOINCREMENT', 'IDENTITY(1,1)', $normalized);
        $normalized = str_replace('AUTO_INCREMENT', 'IDENTITY(1,1)', $normalized);
        $normalized = str_replace('SERIAL PRIMARY KEY', 'INT IDENTITY(1,1) PRIMARY KEY', $normalized);
        
        // Handle data types - use preg_replace with word boundaries to avoid double replacement
        $normalized = preg_replace('/\bDATETIME\s+DEFAULT\s+CURRENT_TIMESTAMP\b/i', 'DATETIME2 DEFAULT GETDATE()', $normalized);
        $normalized = preg_replace('/\bTIMESTAMP\s+DEFAULT\s+CURRENT_TIMESTAMP\b/i', 'DATETIME2 DEFAULT GETDATE()', $normalized);
        $normalized = preg_replace('/\bCURRENT_TIMESTAMP\b/i', 'GETDATE()', $normalized);
        $normalized = preg_replace('/\bTIMESTAMP\b/i', 'DATETIME2', $normalized);
        $normalized = preg_replace('/\bDATETIME\b/i', 'DATETIME2', $normalized);
        // Replace VARCHAR before TEXT to avoid double replacement
        $normalized = preg_replace('/\bVARCHAR\s*\(\s*255\s*\)\b/i', 'NVARCHAR(255)', $normalized);
        // For TEXT with UNIQUE/INDEX constraints, use NVARCHAR(255) instead of NVARCHAR(MAX)
        // MSSQL doesn't allow NVARCHAR(MAX) in indexes
        if (preg_match('/\bTEXT\b/i', $normalized) && (stripos($normalized, 'UNIQUE') !== false || stripos($normalized, 'INDEX') !== false)) {
            $normalized = preg_replace('/\bTEXT\b/i', 'NVARCHAR(255)', $normalized);
        } else {
            $normalized = preg_replace('/\bTEXT\b/i', 'NVARCHAR(MAX)', $normalized);
        }
        $normalized = preg_replace('/\bREAL\b/i', 'DECIMAL(10,2)', $normalized);
        $normalized = preg_replace('/\bBOOLEAN\b/i', 'BIT', $normalized);
        $normalized = preg_replace('/\bBOOL\b/i', 'BIT', $normalized);
        
    } elseif ($driver === 'pgsql') {
        // Convert to PostgreSQL syntax
        $normalized = str_replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'SERIAL PRIMARY KEY', $normalized);
        $normalized = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'SERIAL PRIMARY KEY', $normalized);
        
        // Handle data types
        $normalized = str_replace('REAL', 'NUMERIC', $normalized);
        $normalized = str_replace('DATETIME', 'TIMESTAMP', $normalized);
        
    } else {
        // SQLite - keep as is, just normalize variations to standard
        $normalized = str_replace('SERIAL PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $normalized);
        $normalized = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $normalized);
    }
    
    return $normalized;
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

