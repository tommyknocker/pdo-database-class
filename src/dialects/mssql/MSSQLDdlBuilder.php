<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mssql;

use PDO;
use PDOException;
use RuntimeException;
use tommyknocker\pdodb\dialects\builders\DdlBuilderInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * MSSQL DDL builder implementation.
 */
class MSSQLDdlBuilder implements DdlBuilderInterface
{
    protected DialectInterface $dialect;

    /** @var PDO|null PDO instance (accessed via reflection from dialect) */
    protected ?PDO $pdo = null;

    public function __construct(DialectInterface $dialect)
    {
        $this->dialect = $dialect;

        // Get PDO from dialect
        $pdo = $dialect->getPdo();
        if ($pdo instanceof PDO) {
            $this->pdo = $pdo;
        }
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
    public function buildTableExistsSql(string $table): string
    {
        // MSSQL: Check if table exists in INFORMATION_SCHEMA
        $parts = explode('.', $table);
        if (count($parts) === 2) {
            $schema = $parts[0];
            $tableName = $parts[1];
            return "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$schema}' AND TABLE_NAME = '{$tableName}'";
        }
        return "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = '{$table}'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDescribeSql(string $table): string
    {
        // MSSQL: Get column information from INFORMATION_SCHEMA including IDENTITY flag
        $parts = explode('.', $table);
        if (count($parts) === 2) {
            $schema = $parts[0];
            $tableName = $parts[1];
            return "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMNPROPERTY(OBJECT_ID('{$schema}.{$tableName}'), COLUMN_NAME, 'IsIdentity') AS is_identity FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$schema}' AND TABLE_NAME = '{$tableName}' ORDER BY ORDINAL_POSITION";
        }
        return "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMNPROPERTY(OBJECT_ID('{$table}'), COLUMN_NAME, 'IsIdentity') AS is_identity FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '{$table}' ORDER BY ORDINAL_POSITION";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowIndexesSql(string $table): string
    {
        $parts = explode('.', $table);
        if (count($parts) === 2) {
            $schema = $parts[0];
            $tableName = $parts[1];
            return "SELECT i.name AS IndexName, i.type_desc AS IndexType, ic.key_ordinal AS KeyOrdinal, c.name AS ColumnName FROM sys.indexes i INNER JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id INNER JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id WHERE OBJECT_SCHEMA_NAME(i.object_id) = '{$schema}' AND OBJECT_NAME(i.object_id) = '{$tableName}' ORDER BY i.name, ic.key_ordinal";
        }
        return "SELECT i.name AS IndexName, i.type_desc AS IndexType, ic.key_ordinal AS KeyOrdinal, c.name AS ColumnName FROM sys.indexes i INNER JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id INNER JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id WHERE OBJECT_NAME(i.object_id) = '{$table}' ORDER BY i.name, ic.key_ordinal";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowForeignKeysSql(string $table): string
    {
        $parts = explode('.', $table);
        if (count($parts) === 2) {
            $schema = $parts[0];
            $tableName = $parts[1];
            return "SELECT fk.name AS CONSTRAINT_NAME, c.name AS COLUMN_NAME, OBJECT_SCHEMA_NAME(fk.referenced_object_id) AS REFERENCED_TABLE_SCHEMA, OBJECT_NAME(fk.referenced_object_id) AS REFERENCED_TABLE_NAME, rc.name AS REFERENCED_COLUMN_NAME FROM sys.foreign_keys fk INNER JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id INNER JOIN sys.columns c ON fkc.parent_object_id = c.object_id AND fkc.parent_column_id = c.column_id INNER JOIN sys.columns rc ON fkc.referenced_object_id = rc.object_id AND fkc.referenced_column_id = rc.column_id WHERE OBJECT_SCHEMA_NAME(fk.parent_object_id) = '{$schema}' AND OBJECT_NAME(fk.parent_object_id) = '{$tableName}'";
        }
        return "SELECT fk.name AS CONSTRAINT_NAME, c.name AS COLUMN_NAME, OBJECT_SCHEMA_NAME(fk.referenced_object_id) AS REFERENCED_TABLE_SCHEMA, OBJECT_NAME(fk.referenced_object_id) AS REFERENCED_TABLE_NAME, rc.name AS REFERENCED_COLUMN_NAME FROM sys.foreign_keys fk INNER JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id INNER JOIN sys.columns c ON fkc.parent_object_id = c.object_id AND fkc.parent_column_id = c.column_id INNER JOIN sys.columns rc ON fkc.referenced_object_id = rc.object_id AND fkc.referenced_column_id = rc.column_id WHERE OBJECT_NAME(fk.parent_object_id) = '{$table}'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowConstraintsSql(string $table): string
    {
        $parts = explode('.', $table);
        if (count($parts) === 2) {
            $schema = $parts[0];
            $tableName = $parts[1];
            return "SELECT tc.CONSTRAINT_NAME, tc.CONSTRAINT_TYPE, tc.TABLE_NAME, kcu.COLUMN_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA AND tc.TABLE_NAME = kcu.TABLE_NAME WHERE tc.TABLE_SCHEMA = '{$schema}' AND tc.TABLE_NAME = '{$tableName}'";
        }
        return "SELECT tc.CONSTRAINT_NAME, tc.CONSTRAINT_TYPE, tc.TABLE_NAME, kcu.COLUMN_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA AND tc.TABLE_NAME = kcu.TABLE_NAME WHERE tc.TABLE_NAME = '{$table}'";
    }

    /**
     * {@inheritDoc}
     *
     * @param array<int, string> $tables
     */
    public function buildLockSql(array $tables, string $prefix, string $lockMethod): string
    {
        // MSSQL doesn't support LOCK TABLES like MySQL
        // Table-level locking should be done via transaction isolation levels or table hints in queries
        throw new RuntimeException('Table locking is not supported by MSSQL. Use transaction isolation levels or table hints in queries instead.');
    }

    /**
     * {@inheritDoc}
     */
    public function buildUnlockSql(): string
    {
        // MSSQL doesn't support UNLOCK TABLES like MySQL
        throw new RuntimeException('Table unlocking is not supported by MSSQL.');
    }

    /**
     * {@inheritDoc}
     */
    public function buildTruncateSql(string $table): string
    {
        return 'TRUNCATE TABLE ' . $this->quoteTable($table);
    }

    /**
     * Get current database name.
     *
     * @return string
     */
    protected function getCurrentDatabase(): string
    {
        if ($this->pdo === null) {
            return '';
        }
        $stmt = $this->pdo->query('SELECT DB_NAME()');
        if ($stmt === false) {
            return '';
        }
        $result = $stmt->fetchColumn();
        return (string)($result ?: '');
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
        $primaryKeyColumn = null;

        foreach ($columns as $name => $def) {
            if ($def instanceof ColumnSchema) {
                $columnDefs[] = $this->formatColumnDefinition($name, $def);
                if ($def->isAutoIncrement() && $primaryKeyColumn === null) {
                    $primaryKeyColumn = $name;
                }
            } elseif (is_array($def)) {
                $schema = $this->parseColumnDefinition($def);
                $columnDefs[] = $this->formatColumnDefinition($name, $schema);
                if ($schema->isAutoIncrement() && $primaryKeyColumn === null) {
                    $primaryKeyColumn = $name;
                }
            } else {
                $schema = new ColumnSchema((string)$def);
                $columnDefs[] = $this->formatColumnDefinition($name, $schema);
                if ($schema->isAutoIncrement() && $primaryKeyColumn === null) {
                    $primaryKeyColumn = $name;
                }
            }
        }

        // Add PRIMARY KEY if AUTO_INCREMENT column exists
        if ($primaryKeyColumn !== null) {
            $columnDefs[] = 'PRIMARY KEY (' . $this->quoteIdentifier($primaryKeyColumn) . ')';
        }

        // Add PRIMARY KEY constraint from options if specified
        if (!empty($options['primaryKey'])) {
            $pkColumns = is_array($options['primaryKey']) ? $options['primaryKey'] : [$options['primaryKey']];
            $pkQuoted = array_map([$this, 'quoteIdentifier'], $pkColumns);
            $columnDefs[] = 'PRIMARY KEY (' . implode(', ', $pkQuoted) . ')';
        }

        $sql = "CREATE TABLE {$tableQuoted} (\n    " . implode(",\n    ", $columnDefs) . "\n)";

        // MSSQL table options
        if (!empty($options['on'])) {
            $sql .= ' ON ' . $options['on'];
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
        // MSSQL 2016+ supports IF EXISTS
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
        return "ALTER TABLE {$tableQuoted} ADD {$columnDef}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropColumnSql(string $table, string $column): string
    {
        $tableQuoted = $this->quoteTable($table);
        $columnQuoted = $this->quoteIdentifier($column);

        // MSSQL requires dropping default constraints before dropping the column
        // Extract table name without schema for OBJECT_ID
        $tableParts = explode('.', $table);
        $tableNameOnly = end($tableParts);
        $tableNameOnly = trim($tableNameOnly, '[]');
        $columnNameOnly = trim($column, '[]');

        // Build SQL batch that drops default constraints first, then the column
        // MSSQL allows multiple statements separated by semicolons
        $dropConstraintsSql = "
            DECLARE @constraintName NVARCHAR(255);
            DECLARE constraint_cursor CURSOR FOR
                SELECT name FROM sys.default_constraints
                WHERE parent_object_id = OBJECT_ID('{$tableNameOnly}')
                AND parent_column_id = COLUMNPROPERTY(OBJECT_ID('{$tableNameOnly}'), '{$columnNameOnly}', 'ColumnId');
            OPEN constraint_cursor;
            FETCH NEXT FROM constraint_cursor INTO @constraintName;
            WHILE @@FETCH_STATUS = 0
            BEGIN
                DECLARE @sql NVARCHAR(MAX) = 'ALTER TABLE {$tableQuoted} DROP CONSTRAINT [' + @constraintName + ']';
                EXEC sp_executesql @sql;
                FETCH NEXT FROM constraint_cursor INTO @constraintName;
            END;
            CLOSE constraint_cursor;
            DEALLOCATE constraint_cursor;
        ";

        // Return SQL batch that drops constraints first, then the column
        return $dropConstraintsSql . ";\nALTER TABLE {$tableQuoted} DROP COLUMN {$columnQuoted}";
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
        $columnDef = $this->formatColumnDefinition($column, $schema);
        // MSSQL uses ALTER COLUMN
        return "ALTER TABLE {$tableQuoted} ALTER COLUMN {$columnDef}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameColumnSql(string $table, string $oldName, string $newName): string
    {
        // sp_rename requires unquoted names in string format: 'schema.table.column'
        // Remove brackets for all sp_rename parameters (like buildRenameTableSql)
        $tableUnquoted = str_replace(['[', ']'], '', $this->quoteTable($table));
        $oldUnquoted = str_replace(['[', ']'], '', $this->quoteIdentifier($oldName));
        $newNameClean = trim($newName, '[]');
        // MSSQL uses sp_rename stored procedure
        return "EXEC sp_rename '{$tableUnquoted}.{$oldUnquoted}', '{$newNameClean}', 'COLUMN'";
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
        $type = $unique ? 'UNIQUE INDEX' : 'INDEX';

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

        $sql = "CREATE {$type} {$nameQuoted} ON {$tableQuoted} ({$colsList})";

        // Add INCLUDE columns if provided
        if ($includeColumns !== null && !empty($includeColumns)) {
            $includeQuoted = array_map([$this, 'quoteIdentifier'], $includeColumns);
            $sql .= ' INCLUDE (' . implode(', ', $includeQuoted) . ')';
        }

        // Add WHERE clause for filtered indexes (MSSQL 2008+)
        if ($where !== null && $where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        // Add options (fillfactor, etc.)
        if (!empty($options['fillfactor'])) {
            $sql .= ' WITH (FILLFACTOR = ' . (int)$options['fillfactor'] . ')';
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropIndexSql(string $name, string $table): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        return "DROP INDEX {$nameQuoted} ON {$tableQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateFulltextIndexSql(string $name, string $table, array $columns, ?string $parser = null): string
    {
        // MSSQL uses FULLTEXT CATALOG and FULLTEXT INDEX
        // This is a simplified version - full implementation would require catalog management
        throw new RuntimeException(
            'MSSQL fulltext indexes require FULLTEXT CATALOG setup. ' .
            'Please use CREATE FULLTEXT INDEX directly or set up the catalog first.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateSpatialIndexSql(string $name, string $table, array $columns): string
    {
        // MSSQL supports spatial indexes via CREATE SPATIAL INDEX
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $colsList = implode(', ', $colsQuoted);
        return "CREATE SPATIAL INDEX {$nameQuoted} ON {$tableQuoted} ({$colsList})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameIndexSql(string $oldName, string $table, string $newName): string
    {
        // MSSQL uses sp_rename for renaming indexes
        $tableUnquoted = str_replace(['[', ']'], '', $this->quoteTable($table));
        $oldUnquoted = str_replace(['[', ']'], '', $this->quoteIdentifier($oldName));
        $newNameClean = trim($newName, '[]');
        return "EXEC sp_rename '{$tableUnquoted}.{$oldUnquoted}', '{$newNameClean}', 'INDEX'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameForeignKeySql(string $oldName, string $table, string $newName): string
    {
        // MSSQL uses sp_rename for renaming foreign keys
        $tableUnquoted = str_replace(['[', ']'], '', $this->quoteTable($table));
        $oldUnquoted = str_replace(['[', ']'], '', $this->quoteIdentifier($oldName));
        $newNameClean = trim($newName, '[]');
        return "EXEC sp_rename '{$tableUnquoted}.{$oldUnquoted}', '{$newNameClean}', 'OBJECT'";
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
        // MSSQL sp_rename doesn't need brackets in the new name parameter
        // Remove brackets if present to avoid "already in use" errors
        $newNameClean = trim($newName, '[]');
        // Don't quote the new name - sp_rename expects unquoted name
        // MSSQL uses sp_rename stored procedure
        return "EXEC sp_rename '{$tableQuoted}', '{$newNameClean}'";
    }

    /**
     * {@inheritDoc}
     */
    public function formatColumnDefinition(string $name, ColumnSchema $schema): string
    {
        $quotedName = $this->quoteIdentifier($name);
        $type = $schema->getType();
        $length = $schema->getLength();
        $precision = $length; // In ColumnSchema, length is used for precision
        $scale = $schema->getScale();
        $null = $schema->isNotNull() ? 'NOT NULL' : 'NULL';
        $default = $schema->getDefaultValue();
        $autoIncrement = $schema->isAutoIncrement();

        // Build type with length/precision
        // BIT type in MSSQL doesn't accept length specification
        $typeDef = $type;
        if ($type === 'BIT') {
            // BIT doesn't accept length in MSSQL
            $typeDef = 'BIT';
        } elseif ($type === 'TEXT' || $type === 'LONGTEXT' || $type === 'MEDIUMTEXT' || $type === 'TINYTEXT') {
            // MSSQL doesn't have TEXT type - use NVARCHAR(MAX) instead
            $typeDef = 'NVARCHAR(MAX)';
        } elseif ($type === 'NVARCHAR' && $length === null) {
            // NVARCHAR without length defaults to NVARCHAR(MAX) in MSSQL
            $typeDef = 'NVARCHAR(MAX)';
        } elseif ($type === 'VARCHAR' && $length === null) {
            // VARCHAR without length defaults to VARCHAR(MAX) in MSSQL
            $typeDef = 'VARCHAR(MAX)';
        } elseif ($length !== null) {
            $typeDef .= "({$length})";
        } elseif ($precision !== null && $scale !== null) {
            $typeDef .= "({$precision},{$scale})";
        } elseif ($precision !== null) {
            $typeDef .= "({$precision})";
        }

        // Handle AUTO_INCREMENT -> IDENTITY
        if ($autoIncrement) {
            $typeDef .= ' IDENTITY(1,1)';
        }

        $sql = "{$quotedName} {$typeDef} {$null}";

        if ($default !== null && !$autoIncrement) {
            if ($schema->isDefaultExpression()) {
                // Handle default expressions (e.g., CURRENT_TIMESTAMP -> GETDATE() for MSSQL)
                $defaultExpr = (string)$default;
                if (strtoupper($defaultExpr) === 'CURRENT_TIMESTAMP') {
                    $sql .= ' DEFAULT GETDATE()';
                } else {
                    $sql .= " DEFAULT {$defaultExpr}";
                }
            } else {
                // BIT type in MSSQL uses 0/1 for boolean values
                if ($type === 'BIT') {
                    $bitValue = ($default === true || $default === 1 || $default === '1' || $default === 'true') ? 1 : 0;
                    $sql .= " DEFAULT {$bitValue}";
                } elseif (is_string($default)) {
                    $sql .= " DEFAULT '{$default}'";
                } else {
                    $sql .= " DEFAULT {$default}";
                }
            }
        }

        return $sql;
    }

    /**
     * Parse column definition array to ColumnSchema.
     *
     * @param array<string, mixed> $def
     */
    public function parseColumnDefinition(array $def): ColumnSchema
    {
        $type = $def['type'] ?? 'NVARCHAR(255)';
        // Convert BOOLEAN to BIT for MSSQL
        if (strtoupper($type) === 'BOOLEAN') {
            $type = 'BIT';
        }
        $length = isset($def['length']) ? (int)$def['length'] : (isset($def['precision']) ? (int)$def['precision'] : null);
        $scale = isset($def['scale']) ? (int)$def['scale'] : null;
        // BIT doesn't accept length in MSSQL
        if ($type === 'BIT') {
            $length = null;
        }
        $schema = new ColumnSchema($type, $length, $scale);

        if (isset($def['null'])) {
            if ((bool)$def['null']) {
                $schema->null();
            } else {
                $schema->notNull();
            }
        }
        if (isset($def['default'])) {
            $schema->defaultValue($def['default']);
        }
        if (isset($def['auto_increment']) || isset($def['autoIncrement'])) {
            $schema->autoIncrement();
        }

        return $schema;
    }

    /**
     * {@inheritDoc}
     *
     * @return array<string, mixed>
     */
    public function getBooleanType(): array
    {
        return ['type' => 'BIT', 'length' => null];
    }

    /**
     * {@inheritDoc}
     */
    public function getTimestampType(): string
    {
        // MSSQL can only have one TIMESTAMP column per table, so use DATETIME instead
        return 'DATETIME';
    }

    /**
     * {@inheritDoc}
     */
    public function getDatetimeType(): string
    {
        return 'DATETIME';
    }

    /**
     * {@inheritDoc}
     */
    public function isNoFieldsError(PDOException $e): bool
    {
        $errorMessage = $e->getMessage();
        // MSSQL throws "The active result for the query contains no fields" for DDL/DDL-like queries
        return str_contains($errorMessage, 'contains no fields') ||
               str_contains($errorMessage, 'IMSSP');
    }

    /**
     * {@inheritDoc}
     */
    public function appendLimitOffset(string $sql, int $limit, int $offset): string
    {
        // MSSQL uses OFFSET ... FETCH NEXT ... ROWS ONLY
        // Check if ORDER BY exists in SQL
        if (stripos($sql, 'ORDER BY') === false) {
            // MSSQL requires ORDER BY for OFFSET/FETCH
            // Use a simple ordering that works for any query
            return $sql . " ORDER BY (SELECT NULL) OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        } else {
            // ORDER BY exists, just add OFFSET/FETCH
            return $sql . " OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getPrimaryKeyType(): string
    {
        return 'INT';
    }

    /**
     * {@inheritDoc}
     */
    public function getBigPrimaryKeyType(): string
    {
        return 'BIGINT';
    }

    /**
     * {@inheritDoc}
     */
    public function getStringType(): string
    {
        // MSSQL uses NVARCHAR for Unicode strings
        return 'NVARCHAR';
    }

    /**
     * {@inheritDoc}
     */
    public function getTextType(): string
    {
        // MSSQL doesn't have TEXT type - use NVARCHAR(MAX)
        // Conversion to NVARCHAR(MAX) happens in formatColumnDefinition
        return 'TEXT';
    }

    /**
     * {@inheritDoc}
     */
    public function getCharType(): string
    {
        // MSSQL uses NCHAR for Unicode fixed-length strings
        return 'NCHAR';
    }

    /**
     * {@inheritDoc}
     */
    public function formatMaterializedCte(string $cteSql, bool $isMaterialized): string
    {
        // MSSQL doesn't support materialized CTEs
        return $cteSql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildMigrationTableSql(string $tableName): string
    {
        // MSSQL requires explicit length for VARCHAR/NVARCHAR in PRIMARY KEY
        $tableQuoted = $this->quoteTable($tableName);
        return "CREATE TABLE {$tableQuoted} (
            version NVARCHAR(255) PRIMARY KEY,
            apply_time DATETIME DEFAULT GETDATE(),
            batch INT NOT NULL
        )";
    }
}
