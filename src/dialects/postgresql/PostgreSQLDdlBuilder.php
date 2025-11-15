<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\postgresql;

use tommyknocker\pdodb\dialects\builders\DdlBuilderInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * PostgreSQL DDL builder implementation.
 */
class PostgreSQLDdlBuilder implements DdlBuilderInterface
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
                // Short syntax: ['type' => 'VARCHAR(255)', 'null' => false]
                $schema = $this->parseColumnDefinition($def);
                $columnDefs[] = $this->formatColumnDefinition($name, $schema);
            } else {
                // String type: 'VARCHAR(255)'
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

        // PostgreSQL table inheritance
        if (!empty($options['inherits'])) {
            $inherits = is_array($options['inherits']) ? $options['inherits'] : [$options['inherits']];
            $inheritsQuoted = array_map([$this, 'quoteTable'], $inherits);
            $sql .= ' INHERITS (' . implode(', ', $inheritsQuoted) . ')';
        }

        // PostgreSQL table options (TABLESPACE, WITH, etc.)
        if (!empty($options['tablespace'])) {
            $sql .= ' TABLESPACE ' . $this->quoteIdentifier($options['tablespace']);
        }
        if (isset($options['with']) && is_array($options['with'])) {
            $withOptions = [];
            foreach ($options['with'] as $key => $value) {
                $withOptions[] = $this->quoteIdentifier($key) . ' = ' . (is_numeric($value) ? $value : "'" . addslashes((string)$value) . "'");
            }
            if (!empty($withOptions)) {
                $sql .= ' WITH (' . implode(', ', $withOptions) . ')';
            }
        }

        // Add partitioning (PostgreSQL)
        if (!empty($options['partition'])) {
            $sql .= ' ' . $options['partition'];
        }

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
        // Use CASCADE to drop dependent objects (foreign keys, constraints, etc.)
        // This is necessary when tables have dependencies from previous test runs
        return 'DROP TABLE IF EXISTS ' . $this->quoteTable($table) . ' CASCADE';
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
        // PostgreSQL doesn't support FIRST/AFTER in ALTER TABLE ADD COLUMN
        return "ALTER TABLE {$tableQuoted} ADD COLUMN {$columnDef}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropColumnSql(string $table, string $column): string
    {
        $tableQuoted = $this->quoteTable($table);
        $columnQuoted = $this->quoteIdentifier($column);
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
        $tableQuoted = $this->quoteTable($table);
        $columnQuoted = $this->quoteIdentifier($column);

        $parts = [];
        $type = $schema->getType();
        if ($schema->getLength() !== null) {
            if ($schema->getScale() !== null) {
                $type .= '(' . $schema->getLength() . ',' . $schema->getScale() . ')';
            } else {
                $type .= '(' . $schema->getLength() . ')';
            }
        }
        if ($type !== '') {
            $parts[] = "ALTER COLUMN {$columnQuoted} TYPE {$type}";
        }
        if ($schema->isNotNull()) {
            $parts[] = "ALTER COLUMN {$columnQuoted} SET NOT NULL";
        }
        if ($schema->getDefaultValue() !== null) {
            if ($schema->isDefaultExpression()) {
                $parts[] = "ALTER COLUMN {$columnQuoted} SET DEFAULT " . $schema->getDefaultValue();
            } else {
                $default = $this->formatDefaultValue($schema->getDefaultValue());
                $parts[] = "ALTER COLUMN {$columnQuoted} SET DEFAULT {$default}";
            }
        }

        if (empty($parts)) {
            return "ALTER TABLE {$tableQuoted} ALTER COLUMN {$columnQuoted} TYPE " . ($schema->getType() ?: 'text');
        }

        return "ALTER TABLE {$tableQuoted} " . implode(', ', $parts);
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameColumnSql(string $table, string $oldName, string $newName): string
    {
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

        // PostgreSQL: USING must come before column list
        $usingClause = '';
        if (!empty($options['using'])) {
            $usingClause = ' USING ' . strtoupper($options['using']);
        }

        $sql = "CREATE {$uniqueClause}INDEX {$nameQuoted} ON {$tableQuoted}{$usingClause} ({$colsList})";

        // Add INCLUDE columns if provided
        if ($includeColumns !== null && !empty($includeColumns)) {
            $includeQuoted = array_map([$this, 'quoteIdentifier'], $includeColumns);
            $sql .= ' INCLUDE (' . implode(', ', $includeQuoted) . ')';
        }

        // Add WHERE clause for partial indexes
        if ($where !== null && $where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        // Add options (fillfactor, etc.)
        if (!empty($options['fillfactor'])) {
            $sql .= ' WITH (fillfactor = ' . (int)$options['fillfactor'] . ')';
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropIndexSql(string $name, string $table): string
    {
        // PostgreSQL: DROP INDEX name (table is not needed)
        $nameQuoted = $this->quoteIdentifier($name);
        return "DROP INDEX {$nameQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateFulltextIndexSql(string $name, string $table, array $columns, ?string $parser = null): string
    {
        // PostgreSQL uses GIN index with tsvector for fulltext search
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        // Create tsvector expression for fulltext search
        $tsvectorExpr = "to_tsvector('" . ($parser ?? 'english') . "', " . implode(" || ' ' || ", $colsQuoted) . ')';
        return "CREATE INDEX {$nameQuoted} ON {$tableQuoted} USING GIN ({$tsvectorExpr})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateSpatialIndexSql(string $name, string $table, array $columns): string
    {
        // PostgreSQL uses GIST index for spatial data
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $colsList = implode(', ', $colsQuoted);
        return "CREATE INDEX {$nameQuoted} ON {$tableQuoted} USING GIST ({$colsList})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameIndexSql(string $oldName, string $table, string $newName): string
    {
        $oldQuoted = $this->quoteIdentifier($oldName);
        $newQuoted = $this->quoteIdentifier($newName);
        return "ALTER INDEX {$oldQuoted} RENAME TO {$newQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameForeignKeySql(string $oldName, string $table, string $newName): string
    {
        $tableQuoted = $this->quoteTable($table);
        $oldQuoted = $this->quoteIdentifier($oldName);
        $newQuoted = $this->quoteIdentifier($newName);
        return "ALTER TABLE {$tableQuoted} RENAME CONSTRAINT {$oldQuoted} TO {$newQuoted}";
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
        $tableQuoted = $this->quoteTable($table);
        $refTableQuoted = $this->quoteTable($refTable);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $refColsQuoted = array_map([$this, 'quoteIdentifier'], $refColumns);
        $colsList = implode(', ', $colsQuoted);
        $refColsList = implode(', ', $refColsQuoted);

        $sql = "ALTER TABLE {$tableQuoted} ADD CONSTRAINT {$nameQuoted}";
        $sql .= " FOREIGN KEY ({$colsList}) REFERENCES {$refTableQuoted} ({$refColsList})";

        if ($delete !== null) {
            $sql .= ' ON DELETE ' . strtoupper($delete);
        }
        if ($update !== null) {
            $sql .= ' ON UPDATE ' . strtoupper($update);
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropForeignKeySql(string $name, string $table): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        return "ALTER TABLE {$tableQuoted} DROP CONSTRAINT {$nameQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddPrimaryKeySql(string $name, string $table, array $columns): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $colsList = implode(', ', $colsQuoted);
        return "ALTER TABLE {$tableQuoted} ADD CONSTRAINT {$nameQuoted} PRIMARY KEY ({$colsList})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropPrimaryKeySql(string $name, string $table): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        return "ALTER TABLE {$tableQuoted} DROP CONSTRAINT {$nameQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddUniqueSql(string $name, string $table, array $columns): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $colsList = implode(', ', $colsQuoted);
        return "ALTER TABLE {$tableQuoted} ADD CONSTRAINT {$nameQuoted} UNIQUE ({$colsList})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropUniqueSql(string $name, string $table): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        return "ALTER TABLE {$tableQuoted} DROP CONSTRAINT {$nameQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddCheckSql(string $name, string $table, string $expression): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        return "ALTER TABLE {$tableQuoted} ADD CONSTRAINT {$nameQuoted} CHECK ({$expression})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropCheckSql(string $name, string $table): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        return "ALTER TABLE {$tableQuoted} DROP CONSTRAINT {$nameQuoted}";
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

        // PostgreSQL type mapping
        if ($type === 'INT' || $type === 'INTEGER') {
            $type = 'INTEGER';
        } elseif ($type === 'TINYINT' || $type === 'SMALLINT') {
            $type = 'SMALLINT';
        } elseif ($type === 'BIGINT') {
            $type = 'BIGINT';
        } elseif ($type === 'TEXT') {
            $type = 'TEXT';
        } elseif ($type === 'DATETIME' || $type === 'TIMESTAMP') {
            $type = 'TIMESTAMP';
        }

        // Build type with length/scale
        $typeDef = $type;
        if ($schema->getLength() !== null) {
            if ($schema->getScale() !== null) {
                $typeDef .= '(' . $schema->getLength() . ',' . $schema->getScale() . ')';
            } else {
                // For VARCHAR and similar
                if (in_array(strtoupper($type), ['VARCHAR', 'CHAR', 'CHARACTER VARYING', 'CHARACTER'], true)) {
                    $typeDef .= '(' . $schema->getLength() . ')';
                }
            }
        }

        // SERIAL type handling (PostgreSQL auto-increment)
        if ($schema->isAutoIncrement()) {
            if ($type === 'INTEGER' || $type === 'INT') {
                $typeDef = 'SERIAL';
            } elseif ($type === 'BIGINT') {
                $typeDef = 'BIGSERIAL';
            } elseif ($type === 'SMALLINT') {
                $typeDef = 'SMALLSERIAL';
            }
        }

        $parts = [$nameQuoted, $typeDef];

        // NOT NULL / NULL
        if ($schema->isNotNull()) {
            $parts[] = 'NOT NULL';
        }

        // DEFAULT (only if not SERIAL, as SERIAL includes auto-increment)
        if ($schema->getDefaultValue() !== null && !$schema->isAutoIncrement()) {
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
     */
    protected function parseColumnDefinition(array $def): ColumnSchema
    {
        $type = $def['type'] ?? 'VARCHAR';
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
            // PostgreSQL comments are set separately via COMMENT ON COLUMN
            $schema->comment((string)$def['comment']);
        }
        if (isset($def['autoIncrement']) && $def['autoIncrement']) {
            $schema->autoIncrement();
        }
        if (isset($def['unique']) && $def['unique']) {
            $schema->unique();
        }

        // PostgreSQL doesn't support FIRST/AFTER in ALTER TABLE
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
            return $value ? 'TRUE' : 'FALSE';
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

