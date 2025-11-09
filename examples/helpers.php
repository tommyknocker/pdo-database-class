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
    
    // For CI environments, use environment variables directly
    if ($driver === 'mssql' || $driver === 'sqlsrv') {
        $dbUser = getenv('DB_USER');
        $dbPass = getenv('DB_PASS');
        $dbHost = getenv('DB_HOST') ?: 'localhost';
        $dbPort = getenv('DB_PORT') ?: '1433';
        $dbName = getenv('DB_NAME') ?: 'testdb';
        
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
    
    // First pass: collect column names that are used in constraints (for MSSQL TEXT->NVARCHAR conversion)
    $columnsInConstraints = [];
    foreach ($columns as $colName => $colDef) {
        if (str_starts_with($colName, 'PRIMARY KEY') || 
            str_starts_with($colName, 'FOREIGN KEY') || 
            str_starts_with($colName, 'UNIQUE') ||
            str_starts_with($colName, 'INDEX') ||
            str_starts_with($colName, 'CHECK')) {
            // Extract column names from constraint (e.g., "UNIQUE(role, resource, action)")
            if (preg_match('/\(([^)]+)\)/', $colName, $matches)) {
                $constraintCols = array_map('trim', explode(',', $matches[1]));
                foreach ($constraintCols as $constraintCol) {
                    $constraintCol = trim($constraintCol, '[]`"');
                    $columnsInConstraints[$constraintCol] = true;
                }
            }
        }
    }
    
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
        // For MSSQL, if column is used in constraints and is TEXT, convert to NVARCHAR(255)
        $colNameClean = trim($colName, '[]`"');
        if ($driver === 'sqlsrv' && isset($columnsInConstraints[$colNameClean]) && stripos($colDef, 'TEXT') !== false) {
            $colDef = str_ireplace('TEXT', 'NVARCHAR(255)', $colDef);
        }
        
        $colDef = normalizeColumnDef($driver, $colDef);
        
        // For MSSQL, quote column names that might be reserved keywords
        if ($driver === 'sqlsrv') {
            // Remove brackets if already present, then add them
            $colNameQuoted = '[' . trim($colName, '[]') . ']';
            $columnDefs[] = "$colNameQuoted $colDef";
        } else {
            $columnDefs[] = "$colName $colDef";
        }
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
 * Drop table if exists and create new one using Schema Builder
 * Handles foreign key constraints properly for each database
 * 
 * This function uses the library's Schema Builder instead of raw SQL
 * to demonstrate proper usage of the library's DDL API.
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
            $schemaName = $fk['schema_name'] ?? 'dbo';
            $fkTable = $fk['table_name'];
            $fkName = $fk['fk_name'];
            // Use connection->query() for DDL operations in MSSQL
            $connection->query("ALTER TABLE [{$schemaName}].[{$fkTable}] DROP CONSTRAINT [{$fkName}]");
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
    
    // Use Schema Builder to drop table if exists (demonstrates Schema Builder usage)
    $schema->dropTableIfExists($tableName);
    
    // Create table using raw SQL for now
    // Note: Schema Builder doesn't parse PRIMARY KEY from string definitions like 'INTEGER PRIMARY KEY AUTOINCREMENT'
    // For better Schema Builder usage, examples should use ColumnSchema objects or arrays instead of strings
    // Example: $schema->createTable('users', ['id' => $schema->primaryKey(), 'name' => $schema->string(255)])
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
        // For TEXT columns, use NVARCHAR(255) instead of NVARCHAR(MAX) if they're used in constraints
        // MSSQL doesn't allow NVARCHAR(MAX) in indexes or primary keys
        // Check if TEXT is used with UNIQUE/INDEX/PRIMARY KEY in the definition itself
        if (preg_match('/\bTEXT\b/i', $normalized) && (stripos($normalized, 'UNIQUE') !== false || stripos($normalized, 'INDEX') !== false || stripos($normalized, 'PRIMARY KEY') !== false)) {
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

