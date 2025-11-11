<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\PdoDb;

/**
 * Schema inspector CLI tool.
 *
 * Provides command-line interface for inspecting database schema,
 * including tables, columns, indexes, foreign keys, and constraints.
 */
class SchemaInspector extends BaseCliCommand
{
    /**
     * Inspect database schema.
     *
     * @param string|null $tableName Table name (optional, shows all tables if not provided)
     * @param string|null $format Output format (table, json, yaml)
     * @param PdoDb|null $db Database instance (optional, creates new instance if not provided)
     */
    public static function inspect(?string $tableName = null, ?string $format = null, ?PdoDb $db = null): void
    {
        if ($db === null) {
            $db = static::createDatabase();
        }
        $driver = static::getDriverName($db);

        // Skip header for JSON/YAML formats
        if ($format !== 'json' && $format !== 'yaml') {
            echo "PDOdb Schema Inspector\n";
            echo "Database: {$driver}\n\n";
        }

        if ($tableName === null) {
            static::listTables($db, $format);
        } else {
            static::inspectTable($db, $tableName, $format);
        }
    }

    /**
     * List all tables in the database.
     *
     * @param PdoDb $db Database instance
     * @param string|null $format Output format
     */
    protected static function listTables(PdoDb $db, ?string $format = null): void
    {
        $tables = static::getAllTables($db);

        if (empty($tables)) {
            static::info('No tables found in the database.');
            return;
        }

        echo 'Tables (' . count($tables) . "):\n\n";

        if ($format === 'json') {
            echo json_encode($tables, JSON_PRETTY_PRINT) . "\n";
            return;
        }

        if ($format === 'yaml') {
            echo static::arrayToYaml($tables) . "\n";
            return;
        }

        // Table format
        printf("%-30s %-15s %-10s\n", 'Table Name', 'Columns', 'Rows');
        echo str_repeat('-', 60) . "\n";

        foreach ($tables as $table) {
            $rowCount = static::getTableRowCount($db, $table['name']);
            printf("%-30s %-15s %-10s\n", $table['name'], $table['columns'], $rowCount);
        }
    }

    /**
     * Inspect a specific table.
     *
     * @param PdoDb $db Database instance
     * @param string $tableName Table name
     * @param string|null $format Output format
     */
    protected static function inspectTable(PdoDb $db, string $tableName, ?string $format = null): void
    {
        if (!$db->schema()->tableExists($tableName)) {
            throw new QueryException("Table '{$tableName}' does not exist.");
        }

        $schema = [
            'table' => $tableName,
            'columns' => static::getTableColumns($db, $tableName),
            'indexes' => static::getTableIndexes($db, $tableName),
            'foreign_keys' => static::getTableForeignKeys($db, $tableName),
            'constraints' => static::getTableConstraints($db, $tableName),
        ];

        if ($format === 'json') {
            echo json_encode($schema, JSON_PRETTY_PRINT) . "\n";
            return;
        }

        if ($format === 'yaml') {
            // Convert to generic array for YAML conversion
            $schemaArray = [
                'table' => $schema['table'],
                'columns' => $schema['columns'],
                'indexes' => $schema['indexes'],
                'foreign_keys' => $schema['foreign_keys'],
                'constraints' => $schema['constraints'],
            ];
            echo static::arrayToYaml($schemaArray) . "\n";
            return;
        }

        // Table format
        echo "Table: {$tableName}\n";
        echo str_repeat('=', 60) . "\n\n";

        // Columns
        echo "Columns:\n";
        echo str_repeat('-', 60) . "\n";
        printf("%-20s %-15s %-10s %-10s\n", 'Name', 'Type', 'Nullable', 'Default');
        echo str_repeat('-', 60) . "\n";
        foreach ($schema['columns'] as $column) {
            $nullable = ($column['nullable'] ?? false) ? 'YES' : 'NO';
            $defaultValue = $column['default'] ?? null;
            $default = 'NULL';
            if ($defaultValue !== null && is_scalar($defaultValue)) {
                $defaultStr = (string)$defaultValue;
                if (strlen($defaultStr) > 10) {
                    $default = substr($defaultStr, 0, 7) . '...';
                } else {
                    $default = $defaultStr;
                }
            }
            printf("%-20s %-15s %-10s %-10s\n", $column['name'], $column['type'], $nullable, $default);
        }

        // Indexes
        if (!empty($schema['indexes'])) {
            echo "\nIndexes:\n";
            echo str_repeat('-', 60) . "\n";
            foreach ($schema['indexes'] as $index) {
                $unique = ($index['unique'] ?? false) ? 'UNIQUE' : '';
                $columns = implode(', ', $index['columns'] ?? []);
                echo "  {$index['name']} {$unique} ({$columns})\n";
            }
        }

        // Foreign Keys
        if (!empty($schema['foreign_keys'])) {
            echo "\nForeign Keys:\n";
            echo str_repeat('-', 60) . "\n";
            foreach ($schema['foreign_keys'] as $fk) {
                $columns = implode(', ', $fk['columns'] ?? []);
                $refTable = $fk['referenced_table'] ?? '';
                $refColumns = implode(', ', $fk['referenced_columns'] ?? []);
                echo "  {$fk['name']}: ({$columns}) -> {$refTable}({$refColumns})\n";
            }
        }
    }

    /**
     * Get all tables in the database.
     *
     * @param PdoDb $db Database instance
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function getAllTables(PdoDb $db): array
    {
        $driver = $db->connection->getDialect()->getDriverName();
        $tables = [];

        if ($driver === 'sqlite') {
            $result = $db->rawQuery("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
            foreach ($result as $row) {
                $tableName = $row['name'] ?? '';
                if ($tableName !== '') {
                    $columns = $db->describe($tableName);
                    $tables[] = [
                        'name' => $tableName,
                        'columns' => count($columns),
                    ];
                }
            }
        } elseif (in_array($driver, ['mysql', 'mariadb'])) {
            $dbName = $db->rawQueryValue('SELECT DATABASE()');
            $result = $db->rawQuery("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME", [$dbName]);
            foreach ($result as $row) {
                $tableName = $row['TABLE_NAME'] ?? '';
                if ($tableName !== '') {
                    $columns = $db->describe($tableName);
                    $tables[] = [
                        'name' => $tableName,
                        'columns' => count($columns),
                    ];
                }
            }
        } elseif ($driver === 'pgsql') {
            $result = $db->rawQuery("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");
            foreach ($result as $row) {
                $tableName = $row['tablename'] ?? '';
                if ($tableName !== '') {
                    $columns = $db->describe($tableName);
                    $tables[] = [
                        'name' => $tableName,
                        'columns' => count($columns),
                    ];
                }
            }
        } elseif ($driver === 'sqlsrv') {
            $result = $db->rawQuery("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
            foreach ($result as $row) {
                $tableName = $row['TABLE_NAME'] ?? '';
                if ($tableName !== '') {
                    $columns = $db->describe($tableName);
                    $tables[] = [
                        'name' => $tableName,
                        'columns' => count($columns),
                    ];
                }
            }
        }

        return $tables;
    }

    /**
     * Get table row count.
     *
     * @param PdoDb $db Database instance
     * @param string $tableName Table name
     *
     * @return string
     */
    protected static function getTableRowCount(PdoDb $db, string $tableName): string
    {
        try {
            $count = $db->find()->from($tableName)->select(['COUNT(*)'])->getValue();
            return (string)($count ?? 0);
        } catch (QueryException $e) {
            return 'N/A';
        }
    }

    /**
     * Get table columns.
     *
     * @param PdoDb $db Database instance
     * @param string $tableName Table name
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function getTableColumns(PdoDb $db, string $tableName): array
    {
        $columns = $db->describe($tableName);
        $result = [];

        foreach ($columns as $column) {
            $colName = $column['Field'] ?? $column['column_name'] ?? $column['name'] ?? '';
            $colType = $column['Type'] ?? $column['data_type'] ?? $column['type'] ?? '';
            $nullable = ($column['Null'] ?? $column['is_nullable'] ?? $column['nullable'] ?? 'NO') === 'YES' || ($column['Null'] ?? $column['is_nullable'] ?? $column['nullable'] ?? false) === true;
            $default = $column['Default'] ?? $column['column_default'] ?? $column['default'] ?? null;

            $result[] = [
                'name' => $colName,
                'type' => $colType,
                'nullable' => $nullable,
                'default' => $default,
            ];
        }

        return $result;
    }

    /**
     * Get table indexes.
     *
     * @param PdoDb $db Database instance
     * @param string $tableName Table name
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function getTableIndexes(PdoDb $db, string $tableName): array
    {
        try {
            return $db->schema()->getIndexes($tableName);
        } catch (QueryException $e) {
            return [];
        }
    }

    /**
     * Get table foreign keys.
     *
     * @param PdoDb $db Database instance
     * @param string $tableName Table name
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function getTableForeignKeys(PdoDb $db, string $tableName): array
    {
        try {
            return $db->schema()->getForeignKeys($tableName);
        } catch (QueryException $e) {
            return [];
        }
    }

    /**
     * Get table constraints.
     *
     * @param PdoDb $db Database instance
     * @param string $tableName Table name
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function getTableConstraints(PdoDb $db, string $tableName): array
    {
        try {
            $constraints = [];
            $checks = $db->schema()->getCheckConstraints($tableName);
            $uniques = $db->schema()->getUniqueConstraints($tableName);

            foreach ($checks as $check) {
                $constraints[] = [
                    'type' => 'CHECK',
                    'name' => $check['name'] ?? '',
                    'expression' => $check['expression'] ?? '',
                ];
            }

            foreach ($uniques as $unique) {
                $constraints[] = [
                    'type' => 'UNIQUE',
                    'name' => $unique['name'] ?? '',
                    'columns' => $unique['columns'] ?? [],
                ];
            }

            return $constraints;
        } catch (QueryException $e) {
            return [];
        }
    }

    /**
     * Convert array to YAML format (simple implementation).
     *
     * @param array<string|int, mixed> $array Array to convert
     * @param int $indent Indentation level
     *
     * @return string
     */
    protected static function arrayToYaml(array $array, int $indent = 0): string
    {
        $yaml = '';
        $prefix = str_repeat('  ', $indent);

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $yaml .= $prefix . $key . ":\n";
                $yaml .= static::arrayToYaml($value, $indent + 1);
            } else {
                $yaml .= $prefix . $key . ': ' . (is_string($value) ? "'{$value}'" : json_encode($value)) . "\n";
            }
        }

        return $yaml;
    }
}
