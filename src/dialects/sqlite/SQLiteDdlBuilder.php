<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\sqlite;

use tommyknocker\pdodb\dialects\builders\DdlBuilderInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * SQLite DDL builder implementation.
 */
class SQLiteDdlBuilder implements DdlBuilderInterface
{
    protected DialectInterface $dialect;

    public function __construct(DialectInterface $dialect)
    {
        $this->dialect = $dialect;
    }

    /**
     * Quote identifier using dialect's method.
     */
    protected function quoteIdentifier(string $name): string
    {
        return $this->dialect->quoteIdentifier($name);
    }

    /**
     * Quote table using dialect's method.
     */
    protected function quoteTable(string $table): string
    {
        return $this->dialect->quoteTable($table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateTableSql(
        string $table,
        array $columns,
        array $options = []
    ): string {
        $tableQuoted = $this->quoteTable($table);
        $columnDefs = [];

        foreach ($columns as $name => $def) {
            if ($def instanceof ColumnSchema) {
                $columnDefs[] = $this->formatColumnDefinition($name, $def);
            } elseif (is_array($def)) {
                // Short syntax: ['type' => 'TEXT', 'null' => false]
                $schema = $this->parseColumnDefinition($def);
                $columnDefs[] = $this->formatColumnDefinition($name, $schema);
            } else {
                // String type: 'TEXT'
                $schema = new ColumnSchema((string)$def);
                $columnDefs[] = $this->formatColumnDefinition($name, $schema);
            }
        }

        // Add PRIMARY KEY constraint from options if specified
        if (!empty($options['primaryKey'])) {
            $pkColumns = is_array($options['primaryKey']) ? $options['primaryKey'] : [$options['primaryKey']];
            $pkQuoted = array_map([$this, 'quoteIdentifier'], $pkColumns);
            $columnDefs[] = 'PRIMARY KEY (' . implode(', ', $pkQuoted) . ')';
        }

        $sql = "CREATE TABLE {$tableQuoted} (\n    " . implode(",\n    ", $columnDefs) . "\n)";

        // SQLite doesn't support table options like MySQL/PostgreSQL
        // Options are silently ignored

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropTableSql(string $table): string
    {
        return 'DROP TABLE ' . $this->quoteTable($table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropTableIfExistsSql(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quoteTable($table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddColumnSql(
        string $table,
        string $column,
        ColumnSchema $schema
    ): string {
        $tableQuoted = $this->quoteTable($table);
        $columnDef = $this->formatColumnDefinition($column, $schema);
        // SQLite doesn't support FIRST/AFTER in ALTER TABLE ADD COLUMN
        return "ALTER TABLE {$tableQuoted} ADD COLUMN {$columnDef}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropColumnSql(string $table, string $column): string
    {
        // SQLite 3.35.0+ supports DROP COLUMN
        // For older versions, we throw an exception
        $tableQuoted = $this->quoteTable($table);
        $columnQuoted = $this->quoteIdentifier($column);
        // Note: SQLite DROP COLUMN requires complex table recreation
        // This is a simplified version - in production, you'd need to:
        // 1. Create new table without column
        // 2. Copy data
        // 3. Drop old table
        // 4. Rename new table
        return "ALTER TABLE {$tableQuoted} DROP COLUMN {$columnQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildAlterColumnSql(
        string $table,
        string $column,
        ColumnSchema $schema
    ): string {
        // SQLite doesn't support ALTER COLUMN to change type
        // This would require table recreation which is complex
        throw new QueryException(
            'SQLite does not support ALTER COLUMN to change column type. ' .
            'You must recreate the table. Use buildRenameColumnSql to rename columns.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameColumnSql(string $table, string $oldName, string $newName): string
    {
        // SQLite 3.25.0+ supports RENAME COLUMN
        $tableQuoted = $this->quoteTable($table);
        $oldQuoted = $this->quoteIdentifier($oldName);
        $newQuoted = $this->quoteIdentifier($newName);
        return "ALTER TABLE {$tableQuoted} RENAME COLUMN {$oldQuoted} TO {$newQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateIndexSql(
        string $name,
        string $table,
        array $columns,
        bool $unique = false,
        ?string $where = null,
        ?array $includeColumns = null,
        array $options = []
    ): string {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $uniqueClause = $unique ? 'UNIQUE ' : '';

        // Process columns with sorting support and RawValue support (functional indexes)
        $colsQuoted = [];
        foreach ($columns as $col) {
            if (is_array($col)) {
                // Array format: ['column' => 'ASC'/'DESC'] - associative array with column name as key
                foreach ($col as $colName => $direction) {
                    // $colName is always string (array key), $direction is the sort direction
                    $dir = strtoupper((string)$direction) === 'DESC' ? 'DESC' : 'ASC';
                    $colsQuoted[] = $this->quoteIdentifier((string)$colName) . ' ' . $dir;
                }
            } elseif ($col instanceof RawValue) {
                // RawValue expression (functional index)
                $colsQuoted[] = $col->getValue();
            } else {
                $colsQuoted[] = $this->quoteIdentifier((string)$col);
            }
        }
        $colsList = implode(', ', $colsQuoted);

        $sql = "CREATE {$uniqueClause}INDEX {$nameQuoted} ON {$tableQuoted} ({$colsList})";

        // SQLite supports WHERE clause for partial indexes
        if ($where !== null && $where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        // SQLite doesn't support INCLUDE columns
        if ($includeColumns !== null && !empty($includeColumns)) {
            throw new QueryException(
                'SQLite does not support INCLUDE columns in indexes. ' .
                'Include columns must be part of the main index columns list.'
            );
        }

        // SQLite doesn't support fillfactor or other index options

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropIndexSql(string $name, string $table): string
    {
        // SQLite: DROP INDEX name (table is not needed, but kept for compatibility)
        $nameQuoted = $this->quoteIdentifier($name);
        return "DROP INDEX {$nameQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateFulltextIndexSql(string $name, string $table, array $columns, ?string $parser = null): string
    {
        // SQLite doesn't have native fulltext indexes, but supports FTS (Full-Text Search) virtual tables
        throw new QueryException(
            'SQLite does not support FULLTEXT indexes. ' .
            'Use FTS (Full-Text Search) virtual tables instead: CREATE VIRTUAL TABLE ... USING FTS5(...)'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateSpatialIndexSql(string $name, string $table, array $columns): string
    {
        // SQLite doesn't have native spatial indexes
        throw new QueryException(
            'SQLite does not support SPATIAL indexes. ' .
            'Use R-Tree virtual tables for spatial data: CREATE VIRTUAL TABLE ... USING rtree(...)'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameIndexSql(string $oldName, string $table, string $newName): string
    {
        // SQLite doesn't support RENAME INDEX directly
        // Need to drop and recreate
        throw new QueryException(
            'SQLite does not support renaming indexes directly. ' .
            'You must drop the index and create a new one with the desired name.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameForeignKeySql(string $oldName, string $table, string $newName): string
    {
        // SQLite doesn't support renaming foreign keys
        throw new QueryException(
            'SQLite does not support renaming foreign keys. ' .
            'You must recreate the table without the constraint and add a new one.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddForeignKeySql(
        string $name,
        string $table,
        array $columns,
        string $refTable,
        array $refColumns,
        ?string $delete = null,
        ?string $update = null
    ): string {
        // SQLite foreign keys must be defined during CREATE TABLE
        // Adding them via ALTER TABLE requires table recreation
        // This is a limitation - we throw exception for now
        // In production, you might want to implement table recreation logic
        throw new QueryException(
            'SQLite does not support adding foreign keys via ALTER TABLE. ' .
            'Foreign keys must be defined during CREATE TABLE. ' .
            'To add a foreign key to an existing table, you must recreate the table.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropForeignKeySql(string $name, string $table): string
    {
        // SQLite foreign keys can't be dropped directly
        // Requires table recreation
        throw new QueryException(
            'SQLite does not support dropping foreign keys via ALTER TABLE. ' .
            'To drop a foreign key, you must recreate the table without the constraint.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddPrimaryKeySql(string $name, string $table, array $columns): string
    {
        // SQLite doesn't support adding PRIMARY KEY via ALTER TABLE
        // Requires table recreation
        throw new QueryException(
            'SQLite does not support adding PRIMARY KEY via ALTER TABLE. ' .
            'To add a PRIMARY KEY, you must recreate the table with the constraint.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropPrimaryKeySql(string $name, string $table): string
    {
        // SQLite doesn't support dropping PRIMARY KEY via ALTER TABLE
        // Requires table recreation
        throw new QueryException(
            'SQLite does not support dropping PRIMARY KEY via ALTER TABLE. ' .
            'To drop a PRIMARY KEY, you must recreate the table without the constraint.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddUniqueSql(string $name, string $table, array $columns): string
    {
        // SQLite supports UNIQUE via CREATE UNIQUE INDEX
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $colsList = implode(', ', $colsQuoted);
        return "CREATE UNIQUE INDEX {$nameQuoted} ON {$tableQuoted} ({$colsList})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropUniqueSql(string $name, string $table): string
    {
        // SQLite UNIQUE constraints are implemented as indexes, so drop as index
        $nameQuoted = $this->quoteIdentifier($name);
        return "DROP INDEX {$nameQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddCheckSql(string $name, string $table, string $expression): string
    {
        // SQLite 3.37.0+ supports adding CHECK constraints via ALTER TABLE
        // For older versions, we throw an exception
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        return "ALTER TABLE {$tableQuoted} ADD CONSTRAINT {$nameQuoted} CHECK ({$expression})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropCheckSql(string $name, string $table): string
    {
        // SQLite doesn't support dropping CHECK constraint via ALTER TABLE
        // Requires table recreation
        throw new QueryException(
            'SQLite does not support dropping CHECK constraint via ALTER TABLE. ' .
            'To drop a CHECK constraint, you must recreate the table without the constraint.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameTableSql(string $table, string $newName): string
    {
        $tableQuoted = $this->quoteTable($table);
        $newQuoted = $this->quoteTable($newName);
        return "ALTER TABLE {$tableQuoted} RENAME TO {$newQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function formatColumnDefinition(string $name, ColumnSchema $schema): string
    {
        $nameQuoted = $this->quoteIdentifier($name);
        $type = $schema->getType();

        // SQLite type mapping
        if ($type === 'INT' || $type === 'INTEGER') {
            $type = 'INTEGER';
        } elseif ($type === 'TINYINT' || $type === 'SMALLINT') {
            $type = 'INTEGER';
        } elseif ($type === 'BIGINT') {
            $type = 'INTEGER';
        } elseif ($type === 'TEXT') {
            $type = 'TEXT';
        } elseif ($type === 'DATETIME' || $type === 'TIMESTAMP') {
            $type = 'TEXT';
        }

        // Build type with length/scale
        $typeDef = $type;
        if ($schema->getLength() !== null) {
            if ($schema->getScale() !== null) {
                $typeDef .= '(' . $schema->getLength() . ',' . $schema->getScale() . ')';
            } else {
                // SQLite ignores length for most types, but we include it for compatibility
                if (in_array(strtoupper($type), ['VARCHAR', 'CHAR', 'CHARACTER VARYING', 'CHARACTER'], true)) {
                    $typeDef .= '(' . $schema->getLength() . ')';
                }
            }
        }

        $parts = [$nameQuoted, $typeDef];

        // PRIMARY KEY and AUTOINCREMENT handling for SQLite
        // SQLite AUTOINCREMENT requires INTEGER PRIMARY KEY
        if ($schema->isAutoIncrement()) {
            // SQLite requires INTEGER PRIMARY KEY for AUTOINCREMENT
            if ($type === 'INTEGER') {
                $parts[1] = 'INTEGER PRIMARY KEY AUTOINCREMENT';
            } else {
                // For other types, we can't use AUTOINCREMENT, but can mark as PRIMARY KEY
                $parts[] = 'PRIMARY KEY';
            }
        }

        // NOT NULL / NULL
        if ($schema->isNotNull()) {
            $parts[] = 'NOT NULL';
        }

        // DEFAULT
        if ($schema->getDefaultValue() !== null) {
            if ($schema->isDefaultExpression()) {
                $parts[] = 'DEFAULT ' . $schema->getDefaultValue();
            } else {
                $default = $this->formatDefaultValue($schema->getDefaultValue());
                $parts[] = 'DEFAULT ' . $default;
            }
        }

        // UNIQUE is handled separately (not in column definition)
        // It's created via CREATE INDEX or table constraint

        return implode(' ', $parts);
    }

    /**
     * Parse column definition from array.
     *
     * @param array<string, mixed> $def
     */
    protected function parseColumnDefinition(array $def): ColumnSchema
    {
        $type = $def['type'] ?? 'TEXT';
        $length = $def['length'] ?? $def['size'] ?? null;
        $scale = $def['scale'] ?? null;

        $schema = new ColumnSchema((string)$type, $length, $scale);

        if (isset($def['null']) && $def['null'] === false) {
            $schema->notNull();
        }
        if (isset($def['default'])) {
            if (isset($def['defaultExpression']) && $def['defaultExpression']) {
                $schema->defaultExpression((string)$def['default']);
            } else {
                $schema->defaultValue($def['default']);
            }
        }
        if (isset($def['comment'])) {
            // SQLite comments are set separately via COMMENT ON COLUMN (SQLite 3.31.0+)
            $schema->comment((string)$def['comment']);
        }
        if (isset($def['autoIncrement']) && $def['autoIncrement']) {
            $schema->autoIncrement();
        }
        if (isset($def['unique']) && $def['unique']) {
            $schema->unique();
        }
        // Note: primaryKey is handled at table level via options['primaryKey'], not at column level

        // SQLite doesn't support FIRST/AFTER in ALTER TABLE
        // These are silently ignored

        return $schema;
    }

    /**
     * Format default value for SQL.
     */
    protected function formatDefaultValue(mixed $value): string
    {
        if ($value instanceof RawValue) {
            return $value->getValue();
        }
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_numeric($value)) {
            return (string)$value;
        }
        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }
        return "'" . addslashes((string)$value) . "'";
    }
}
