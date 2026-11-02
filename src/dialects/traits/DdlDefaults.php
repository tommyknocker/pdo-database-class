<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

use PDO;
use RuntimeException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\roles\DialectDdlInterface;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;

/**
 * Default implementation of the schema changes part of the dialect ({@see DialectDdlInterface}),
 * shared by every dialect through DialectAbstract.
 */
trait DdlDefaults
{
    /**
     * {@inheritDoc}
     */
    public function getBooleanType(): array
    {
        // Default: TINYINT(1) for MySQL/MariaDB/SQLite
        return ['type' => 'TINYINT', 'length' => 1];
    }

    /**
     * {@inheritDoc}
     */
    public function getTimestampType(): string
    {
        // Default: DATETIME for MySQL/MariaDB/SQLite
        return 'DATETIME';
    }

    /**
     * {@inheritDoc}
     */
    public function getDatetimeType(): string
    {
        // Default: DATETIME for MySQL/MariaDB/SQLite
        return 'DATETIME';
    }

    /**
     * {@inheritDoc}
     */
    public function isNoFieldsError(\PDOException $e): bool
    {
        // Default: no special handling for "no fields" errors
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function appendLimitOffset(string $sql, int $limit, int $offset): string
    {
        // Standard LIMIT/OFFSET syntax for MySQL, PostgreSQL, SQLite
        return $sql . " LIMIT {$limit} OFFSET {$offset}";
    }

    /**
     * {@inheritDoc}
     */
    public function getPrimaryKeyType(): string
    {
        // Default: INT for MySQL/MariaDB/MSSQL/SQLite
        return 'INT';
    }

    /**
     * {@inheritDoc}
     */
    public function getBigPrimaryKeyType(): string
    {
        // Default: BIGINT for MySQL/MariaDB/MSSQL/SQLite
        return 'BIGINT';
    }

    /**
     * {@inheritDoc}
     */
    public function getPrimaryKeyColumns(PdoDb $db, string $table): array
    {
        // Default implementation: check indexes for PRIMARY key (MySQL/MariaDB/SQLite)
        try {
            $indexes = $db->indexes($table);
            $pkColumns = [];

            foreach ($indexes as $idx) {
                $name = $idx['Key_name'] ?? $idx['indexname'] ?? $idx['name'] ?? $idx['INDEX_NAME'] ?? null;
                $column = $idx['Column_name'] ?? $idx['column_name'] ?? $idx['column'] ?? $idx['COLUMN_NAME'] ?? null;

                if (is_string($name) && strtoupper($name) === 'PRIMARY' && is_string($column)) {
                    $pkColumns[] = $column;
                }
            }

            return array_unique($pkColumns);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getStringType(): string
    {
        // Default: VARCHAR for MySQL/MariaDB/MSSQL/PostgreSQL
        return 'VARCHAR';
    }

    /**
     * {@inheritDoc}
     */
    public function getTextType(): string
    {
        // Default: TEXT for MySQL/MariaDB/PostgreSQL/SQLite
        return 'TEXT';
    }

    /**
     * {@inheritDoc}
     */
    public function getCharType(): string
    {
        // Default: CHAR for MySQL/MariaDB/PostgreSQL
        return 'CHAR';
    }

    /**
     * {@inheritDoc}
     */
    public function formatMaterializedCte(string $cteSql, bool $isMaterialized): string
    {
        // Default: no materialization support
        return $cteSql;
    }

    /**
     * {@inheritDoc}
     */
    public function registerRegexpFunctions(PDO $pdo, bool $force = false): void
    {
        // Default: no REGEXP function registration needed
        // Dialects that need REGEXP support (SQLite, MSSQL) override this method
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeDefaultValue(string $value): string
    {
        // Default: return DEFAULT as-is (most dialects support DEFAULT keyword)
        return $value;
    }

    /**
     * {@inheritDoc}
     */
    public function buildMigrationTableSql(string $tableName): string
    {
        // Default implementation for MySQL/MariaDB
        $tableQuoted = $this->quoteTable($tableName);
        return "CREATE TABLE {$tableQuoted} (
            version VARCHAR(255) PRIMARY KEY,
            apply_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            batch INTEGER NOT NULL
        ) ENGINE=InnoDB";
    }

    /**
     * {@inheritDoc}
     */
    public function buildMigrationInsertSql(string $tableName, string $version, int $batch): array
    {
        // Default: use positional parameters (?, ?)
        $tableQuoted = $this->quoteTable($tableName);
        return [
            "INSERT INTO {$tableQuoted} (version, batch) VALUES (?, ?)",
            [$version, $batch],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function extractErrorCode(\PDOException $e): string
    {
        // Default: use exception code
        return (string) $e->getCode();
    }

    /**
     * {@inheritDoc}
     */
    public function getExplainParser(): ExplainParserInterface
    {
        throw new RuntimeException(
            sprintf('EXPLAIN parser not implemented for %s dialect', $this->getDriverName())
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getRecursiveCteKeyword(): string
    {
        return 'WITH RECURSIVE';
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeRecursiveCteSql(string $sql, string $cteName, bool $isRecursive): string
    {
        // Default implementation: no normalization needed
        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function extractEnumValues(array $column, PdoDb $db, string $tableName, string $columnName): array
    {
        $type = $column['Type'] ?? $column['data_type'] ?? $column['type'] ?? '';
        if (!is_string($type)) {
            return [];
        }

        $typeUpper = strtoupper($type);

        // Check if it's an ENUM type
        if (!str_starts_with($typeUpper, 'ENUM')) {
            return [];
        }

        // Extract values from ENUM definition: ENUM('value1','value2','value3')
        if (preg_match("/^ENUM\s*\((.+)\)$/i", $type, $matches)) {
            $valuesStr = $matches[1];
            // Split by comma, but handle quoted values
            $values = [];
            $current = '';
            $inQuotes = false;
            $quoteChar = '';

            for ($i = 0; $i < strlen($valuesStr); $i++) {
                $char = $valuesStr[$i];

                if (($char === '"' || $char === "'") && ($i === 0 || $valuesStr[$i - 1] !== '\\')) {
                    if (!$inQuotes) {
                        $inQuotes = true;
                        $quoteChar = $char;
                    } elseif ($char === $quoteChar) {
                        $inQuotes = false;
                        $quoteChar = '';
                    } else {
                        $current .= $char;
                    }
                } elseif ($char === ',' && !$inQuotes) {
                    if (trim($current) !== '') {
                        $values[] = trim($current, " \t\n\r\0\x0B\"'");
                    }
                    $current = '';
                } else {
                    $current .= $char;
                }
            }

            if (trim($current) !== '') {
                $values[] = trim($current, " \t\n\r\0\x0B\"'");
            }

            return array_filter($values, fn ($v) => $v !== '');
        }

        // Fallback: return empty array (no database queries to avoid connection issues)
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function afterCreateTable(
        ConnectionInterface $connection,
        string $tableName,
        array $columns,
        string $sql
    ): void {
        // Default implementation does nothing
        // Dialects can override to perform post-processing (e.g., create triggers)
    }

    /**
     * {@inheritDoc}
     */
    public function beforeCreateTable(
        ConnectionInterface $connection,
        string $tableName,
        array $columns
    ): void {
        // Default implementation does nothing
        // Dialects can override to drop existing objects (e.g., sequences, triggers)
    }

    /**
     * {@inheritDoc}
     */
    public function addsUniqueColumnInline(): bool
    {
        return true;
    }
}
