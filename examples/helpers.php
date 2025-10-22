<?php
/**
 * Helper functions for examples to work across different database dialects
 */

/**
 * Get database configuration based on environment or default to SQLite
 */
function getExampleConfig(): array
{
    $driver = getenv('PDODB_DRIVER') ?: 'sqlite';
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
function createExampleDb(): \tommyknocker\pdodb\PdoDb
{
    $config = getExampleConfig();
    $driver = $config['driver'];
    unset($config['driver']);
    
    return new \tommyknocker\pdodb\PdoDb($driver, $config);
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
function getCreateTableSql(\tommyknocker\pdodb\PdoDb $db, string $tableName, array $columns, array $options = []): string
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
    
    // Add table options (e.g., ENGINE=InnoDB for MySQL)
    if ($driver === 'mysql') {
        $engine = $options['engine'] ?? 'InnoDB';
        $sql .= " ENGINE=$engine";
    }
    
    return $sql;
}

/**
 * Drop table if exists and create new one
 * Handles foreign key constraints properly for each database
 */
function recreateTable(\tommyknocker\pdodb\PdoDb $db, string $tableName, array $columns, array $options = []): void
{
    $connection = $db->connection;
    if ($connection === null) {
        throw new RuntimeException('Database connection not initialized');
    }
    
    $driver = $connection->getDriverName();
    
    // Disable foreign key checks for MySQL
    if ($driver === 'mysql') {
        $db->rawQuery("SET FOREIGN_KEY_CHECKS=0");
    }
    
    // Drop table with CASCADE for PostgreSQL to handle dependent objects
    if ($driver === 'pgsql') {
        $db->rawQuery("DROP TABLE IF EXISTS $tableName CASCADE");
    } else {
        $db->rawQuery("DROP TABLE IF EXISTS $tableName");
    }
    
    // Create table
    $sql = getCreateTableSql($db, $tableName, $columns, $options);
    $db->rawQuery($sql);
    
    // Re-enable foreign key checks for MySQL
    if ($driver === 'mysql') {
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
    if ($driver === 'mysql') {
        // Convert to MySQL syntax
        $normalized = str_replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'INT AUTO_INCREMENT PRIMARY KEY', $normalized);
        $normalized = str_replace('SERIAL PRIMARY KEY', 'INT AUTO_INCREMENT PRIMARY KEY', $normalized);
        
        // Handle data types - TEXT must become VARCHAR for UNIQUE/INDEX constraints
        $normalized = str_replace('TEXT', 'VARCHAR(255)', $normalized);
        $normalized = str_replace('REAL', 'DECIMAL(10,2)', $normalized);
        $normalized = str_replace('DATETIME DEFAULT CURRENT_TIMESTAMP', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP', $normalized);
        $normalized = str_replace('DATETIME', 'TIMESTAMP', $normalized);
        
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
function getCurrentDriver(\tommyknocker\pdodb\PdoDb $db): string
{
    $connection = $db->connection;
    if ($connection === null) {
        return 'unknown';
    }
    return $connection->getDriverName();
}

