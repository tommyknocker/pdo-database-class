<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\oracle;

use tommyknocker\pdodb\dialects\builders\DdlBuilderInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * Oracle DDL builder implementation.
 */
class OracleDdlBuilder implements DdlBuilderInterface
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
        $sequences = [];

        $autoIncrementColumns = [];
        foreach ($columns as $name => $def) {
            if ($def instanceof ColumnSchema) {
                $columnDefs[] = $this->formatColumnDefinition($name, $def);
                if ($def->isAutoIncrement()) {
                    $sequenceName = $this->generateSequenceName($table, $name);
                    $sequences[] = $sequenceName;
                    $autoIncrementColumns[] = $name;
                }
            } elseif (is_array($def)) {
                $schema = $this->parseColumnDefinition($def);
                $columnDefs[] = $this->formatColumnDefinition($name, $schema);
                if ($schema->isAutoIncrement()) {
                    $sequenceName = $this->generateSequenceName($table, $name);
                    $sequences[] = $sequenceName;
                    $autoIncrementColumns[] = $name;
                }
            } else {
                $schema = new ColumnSchema((string)$def);
                $columnDefs[] = $this->formatColumnDefinition($name, $schema);
            }
        }

        // Add PRIMARY KEY constraint from options if specified
        if (!empty($options['primaryKey'])) {
            $pkColumns = is_array($options['primaryKey']) ? $options['primaryKey'] : [$options['primaryKey']];
            $pkQuoted = array_map([$this, 'quoteIdentifier'], $pkColumns);
            $columnDefs[] = 'PRIMARY KEY (' . implode(', ', $pkQuoted) . ')';
        } elseif (!empty($autoIncrementColumns)) {
            // Automatically add PRIMARY KEY for auto-increment columns if not specified in options
            // This is needed for Oracle because sequences don't automatically create PRIMARY KEY
            $pkQuoted = array_map([$this, 'quoteIdentifier'], $autoIncrementColumns);
            $columnDefs[] = 'PRIMARY KEY (' . implode(', ', $pkQuoted) . ')';
        }

        $sql = "CREATE TABLE {$tableQuoted} (\n    " . implode(",\n    ", $columnDefs) . "\n)";

        // Add table options
        if (!empty($options['tablespace'])) {
            $sql .= ' TABLESPACE ' . $this->quoteIdentifier($options['tablespace']);
        }

        // Create sequences for auto-increment columns
        // Triggers will be created via afterCreateTable() method
        if (!empty($sequences)) {
            $sequenceSqls = [];
            foreach ($sequences as $seqName) {
                $sequenceSqls[] = "CREATE SEQUENCE {$this->quoteIdentifier($seqName)} START WITH 1 INCREMENT BY 1";
            }
            $sql = implode(";\n", $sequenceSqls) . ";\n" . $sql;
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
        // Oracle doesn't support IF EXISTS, so we use a PL/SQL block to check existence
        $tableQuoted = $this->quoteTable($table);
        // Remove quotes for USER_TABLES query (Oracle stores unquoted names in uppercase)
        $tableUnquoted = str_replace(['"', "'"], '', $table);
        $tableUpper = strtoupper($tableUnquoted);
        
        return "BEGIN
    EXECUTE IMMEDIATE 'DROP TABLE {$tableQuoted} CASCADE CONSTRAINTS';
EXCEPTION
    WHEN OTHERS THEN
        IF SQLCODE != -942 THEN
            RAISE;
        END IF;
END;";
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
        // Oracle doesn't support FIRST/AFTER in ALTER TABLE ADD COLUMN
        return "ALTER TABLE {$tableQuoted} ADD {$columnDef}";
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
            $parts[] = "MODIFY {$columnQuoted} {$type}";
        }
        if ($schema->isNotNull()) {
            $parts[] = "MODIFY {$columnQuoted} NOT NULL";
        }
        if ($schema->getDefaultValue() !== null) {
            if ($schema->isDefaultExpression()) {
                $parts[] = "MODIFY {$columnQuoted} DEFAULT " . $schema->getDefaultValue();
            } else {
                $default = $this->formatDefaultValue($schema->getDefaultValue());
                $parts[] = "MODIFY {$columnQuoted} DEFAULT {$default}";
            }
        }

        if (empty($parts)) {
            return "ALTER TABLE {$tableQuoted} MODIFY {$columnQuoted} " . ($schema->getType() ?: 'VARCHAR2(4000)');
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

        $colsQuoted = [];
        foreach ($columns as $col) {
            if (is_array($col)) {
                foreach ($col as $colName => $direction) {
                    $dir = strtoupper((string)$direction) === 'DESC' ? 'DESC' : 'ASC';
                    $colsQuoted[] = $this->quoteIdentifier((string)$colName) . ' ' . $dir;
                }
            } elseif ($col instanceof RawValue) {
                $colsQuoted[] = $col->getValue();
            } else {
                $colsQuoted[] = $this->quoteIdentifier((string)$col);
            }
        }
        $colsList = implode(', ', $colsQuoted);

        $sql = "CREATE {$uniqueClause}INDEX {$nameQuoted} ON {$tableQuoted} ({$colsList})";

        // Add WHERE clause for partial indexes (function-based indexes in Oracle)
        if ($where !== null && $where !== '') {
            // Oracle uses function-based indexes for partial indexes
            $sql .= " WHERE {$where}";
        }

        // Add options
        if (!empty($options['tablespace'])) {
            $sql .= ' TABLESPACE ' . $this->quoteIdentifier($options['tablespace']);
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropIndexSql(string $name, string $table): string
    {
        // Oracle: DROP INDEX name (table is not needed)
        $nameQuoted = $this->quoteIdentifier($name);
        return "DROP INDEX {$nameQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateFulltextIndexSql(string $name, string $table, array $columns, ?string $parser = null): string
    {
        // Oracle uses Oracle Text for fulltext search
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $colsList = implode(', ', $colsQuoted);
        // Oracle Text requires CONTEXT index
        return "CREATE INDEX {$nameQuoted} ON {$tableQuoted} ({$colsList}) INDEXTYPE IS CTXSYS.CONTEXT";
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateSpatialIndexSql(string $name, string $table, array $columns): string
    {
        // Oracle uses spatial indexes (requires Oracle Spatial)
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $colsList = implode(', ', $colsQuoted);
        return "CREATE INDEX {$nameQuoted} ON {$tableQuoted} ({$colsList}) INDEXTYPE IS MDSYS.SPATIAL_INDEX";
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
        // Oracle doesn't support ON UPDATE CASCADE
        if ($update !== null && strtoupper($update) !== 'CASCADE') {
            // Oracle doesn't support ON UPDATE, ignore it
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

        // Oracle type mapping
        if ($type === 'INT' || $type === 'INTEGER') {
            $type = 'NUMBER';
        } elseif ($type === 'TINYINT' || $type === 'SMALLINT') {
            $type = 'NUMBER(5)';
        } elseif ($type === 'BIGINT') {
            $type = 'NUMBER(19)';
        } elseif ($type === 'TEXT' || $type === 'LONGTEXT') {
            // Use VARCHAR2(4000) instead of CLOB for better compatibility with LIKE and other operations
            // CLOB has limitations (cannot be used directly in WHERE with LIKE, cannot be indexed, etc.)
            $type = 'VARCHAR2(4000)';
        } elseif ($type === 'VARCHAR' && $schema->getLength() === null) {
            $type = 'VARCHAR2(4000)';
        } elseif ($type === 'VARCHAR') {
            $type = 'VARCHAR2';
        } elseif ($type === 'DATETIME' || $type === 'TIMESTAMP') {
            $type = 'TIMESTAMP';
        } elseif ($type === 'DATE') {
            $type = 'DATE';
        } elseif ($type === 'BOOLEAN' || $type === 'BOOL') {
            $type = 'NUMBER(1)';
        }

        // Build type with length/scale
        $typeDef = $type;
        $length = $schema->getLength();
        if ($length !== null && !in_array(strtoupper($type), ['CLOB', 'BLOB', 'DATE', 'TIMESTAMP'], true)) {
            $scale = $schema->getScale();
            if ($scale !== null) {
                $typeDef .= '(' . $length . ',' . $scale . ')';
            } else {
                if (in_array(strtoupper($type), ['VARCHAR2', 'CHAR', 'NVARCHAR2', 'NCHAR'], true)) {
                    $typeDef .= '(' . $length . ')';
                } elseif (strtoupper($type) === 'NUMBER') {
                    $typeDef = 'NUMBER(' . $length . ')';
                }
            }
        }

        $parts = [$nameQuoted, $typeDef];

        // NOT NULL / NULL
        if ($schema->isNotNull()) {
            $parts[] = 'NOT NULL';
        }

        // DEFAULT
        if ($schema->getDefaultValue() !== null && !$schema->isAutoIncrement()) {
            if ($schema->isDefaultExpression()) {
                $parts[] = 'DEFAULT ' . $schema->getDefaultValue();
            } else {
                $default = $this->formatDefaultValue($schema->getDefaultValue());
                $parts[] = 'DEFAULT ' . $default;
            }
        }

        // AUTO_INCREMENT is handled via sequences (created separately)

        return implode(' ', $parts);
    }

    /**
     * Parse column definition from array.
     *
     * @param array<string, mixed> $def
     */
    protected function parseColumnDefinition(array $def): ColumnSchema
    {
        $type = $def['type'] ?? 'VARCHAR2';
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
            $schema->comment((string)$def['comment']);
        }
        if (isset($def['autoIncrement']) && $def['autoIncrement']) {
            $schema->autoIncrement();
        }
        if (isset($def['unique']) && $def['unique']) {
            $schema->unique();
        }

        // Oracle doesn't support FIRST/AFTER in ALTER TABLE
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

    /**
     * Generate sequence name for auto-increment column.
     */
    protected function generateSequenceName(string $table, string $column): string
    {
        // Oracle sequence naming convention: table_column_seq
        return strtolower($table . '_' . $column . '_seq');
    }
}
