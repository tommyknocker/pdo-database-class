<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\traits;

use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * DDL Builder trait providing fluent API for database schema operations.
 *
 * This trait is used by DdlQueryBuilder to provide methods for creating,
 * altering, and dropping database objects like tables, indexes, and foreign keys.
 */
trait DdlBuilderTrait
{
    /**
     * Create a new table.
     *
     * @param string $table Table name
     * @param array<string, ColumnSchema|array<string, mixed>|string> $columns Column definitions
     * @param array<string, mixed> $options Table options (ENGINE, CHARSET, etc.)
     *
     * @return static
     */
    public function createTable(string $table, array $columns, array $options = []): static
    {
        // Get table name without quotes (dialect will quote it)
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildCreateTableSql($tableName, $columns, $options);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Create a new table if it does not exist.
     *
     * @param string $table Table name
     * @param array<string, ColumnSchema|array<string, mixed>|string> $columns Column definitions
     * @param array<string, mixed> $options Table options
     *
     * @return static
     */
    public function createTableIfNotExists(string $table, array $columns, array $options = []): static
    {
        // Check table existence first
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        // Remove quotes if present (SQLite stores table names without quotes)
        $tableNameUnquoted = str_replace(['"', '`', "'"], '', $tableName);
        $tableExistsSql = $this->dialect->buildTableExistsSql($tableNameUnquoted);
        $stmt = $this->connection->query($tableExistsSql);
        if ($stmt === false) {
            $exists = false;
        } else {
            $result = $stmt->fetchColumn();
            $exists = !empty($result);
        }

        if (!$exists) {
            $this->createTable($table, $columns, $options);
        }

        return $this;
    }

    /**
     * Drop a table.
     *
     * @param string $table Table name
     *
     * @return static
     */
    public function dropTable(string $table): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildDropTableSql($tableName);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Drop a table if it exists.
     *
     * @param string $table Table name
     *
     * @return static
     */
    public function dropTableIfExists(string $table): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildDropTableIfExistsSql($tableName);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Rename a table.
     *
     * @param string $table Old table name
     * @param string $newName New table name
     *
     * @return static
     */
    public function renameTable(string $table, string $newName): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $newTableName = $this->prefix ? ($this->prefix . $newName) : $newName;
        $sql = $this->dialect->buildRenameTableSql($tableName, $newTableName);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Truncate a table.
     *
     * @param string $table Table name
     *
     * @return static
     */
    public function truncateTable(string $table): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildTruncateSql($tableName);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Add a column to a table.
     *
     * @param string $table Table name
     * @param string $column Column name
     * @param ColumnSchema|array<string, mixed>|string $type Column schema, definition array, or type string
     *
     * @return static
     */
    public function addColumn(string $table, string $column, ColumnSchema|array|string $type): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $schema = $this->normalizeColumnSchema($type);
        $sql = $this->dialect->buildAddColumnSql($tableName, $column, $schema);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Drop a column from a table.
     *
     * @param string $table Table name
     * @param string $column Column name
     *
     * @return static
     */
    public function dropColumn(string $table, string $column): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildDropColumnSql($tableName, $column);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Alter a column in a table.
     *
     * @param string $table Table name
     * @param string $column Column name
     * @param ColumnSchema|array<string, mixed>|string $type Column schema, definition array, or type string
     *
     * @return static
     */
    public function alterColumn(string $table, string $column, ColumnSchema|array|string $type): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $schema = $this->normalizeColumnSchema($type);
        $sql = $this->dialect->buildAlterColumnSql($tableName, $column, $schema);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Rename a column in a table.
     *
     * @param string $table Table name
     * @param string $oldName Old column name
     * @param string $newName New column name
     *
     * @return static
     */
    public function renameColumn(string $table, string $oldName, string $newName): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildRenameColumnSql($tableName, $oldName, $newName);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Create an index.
     *
     * @param string $name Index name
     * @param string $table Table name
     * @param string|array<int, string> $columns Column name(s)
     * @param bool $unique Whether index is unique
     *
     * @return static
     */
    public function createIndex(string $name, string $table, string|array $columns, bool $unique = false): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $columnArray = is_array($columns) ? $columns : [$columns];
        $sql = $this->dialect->buildCreateIndexSql($name, $tableName, $columnArray, $unique);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Drop an index.
     *
     * @param string $name Index name
     * @param string $table Table name
     *
     * @return static
     */
    public function dropIndex(string $name, string $table): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildDropIndexSql($name, $tableName);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Add a foreign key constraint.
     *
     * @param string $name Foreign key name
     * @param string $table Table name
     * @param string|array<int, string> $columns Column name(s)
     * @param string $refTable Referenced table name
     * @param string|array<int, string> $refColumns Referenced column name(s)
     * @param string|null $delete ON DELETE action (CASCADE, RESTRICT, SET NULL, etc.)
     * @param string|null $update ON UPDATE action
     *
     * @return static
     */
    public function addForeignKey(
        string $name,
        string $table,
        string|array $columns,
        string $refTable,
        string|array $refColumns,
        ?string $delete = null,
        ?string $update = null
    ): static {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $refTableName = $this->prefix ? ($this->prefix . $refTable) : $refTable;
        $columnArray = is_array($columns) ? $columns : [$columns];
        $refColumnArray = is_array($refColumns) ? $refColumns : [$refColumns];
        $sql = $this->dialect->buildAddForeignKeySql(
            $name,
            $tableName,
            $columnArray,
            $refTableName,
            $refColumnArray,
            $delete,
            $update
        );
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Drop a foreign key constraint.
     *
     * @param string $name Foreign key name
     * @param string $table Table name
     *
     * @return static
     */
    public function dropForeignKey(string $name, string $table): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildDropForeignKeySql($name, $tableName);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Create primary key column schema.
     *
     * @param int|null $length Length (usually not needed for primary keys)
     *
     * @return ColumnSchema
     */
    public function primaryKey(?int $length = null): ColumnSchema
    {
        $type = $this->dialect->getPrimaryKeyType();
        $schema = new ColumnSchema($type, $length);
        $schema->autoIncrement();
        $schema->notNull();

        return $schema;
    }

    /**
     * Create big primary key column schema.
     *
     * @return ColumnSchema
     */
    public function bigPrimaryKey(): ColumnSchema
    {
        $type = $this->dialect->getBigPrimaryKeyType();
        $schema = new ColumnSchema($type);
        $schema->autoIncrement();
        $schema->notNull();

        return $schema;
    }

    /**
     * Create string column schema.
     *
     * @param int|null $length String length
     *
     * @return ColumnSchema
     */
    public function string(?int $length = null): ColumnSchema
    {
        $type = $this->dialect->getStringType();
        return new ColumnSchema($type, $length);
    }

    /**
     * Create text column schema.
     *
     * @return ColumnSchema
     */
    public function text(): ColumnSchema
    {
        $type = $this->dialect->getTextType();
        return new ColumnSchema($type);
    }

    /**
     * Create char column schema.
     *
     * @param int|null $length Char length
     *
     * @return ColumnSchema
     */
    public function char(?int $length = null): ColumnSchema
    {
        $type = $this->dialect->getCharType();
        return new ColumnSchema($type, $length);
    }

    /**
     * Create integer column schema.
     *
     * @param int|null $length Integer length
     *
     * @return ColumnSchema
     */
    public function integer(?int $length = null): ColumnSchema
    {
        return new ColumnSchema('INT', $length);
    }

    /**
     * Create big integer column schema.
     *
     * @return ColumnSchema
     */
    public function bigInteger(): ColumnSchema
    {
        return new ColumnSchema('BIGINT');
    }

    /**
     * Create small integer column schema.
     *
     * @return ColumnSchema
     */
    public function smallInteger(): ColumnSchema
    {
        return new ColumnSchema('SMALLINT');
    }

    /**
     * Create boolean column schema.
     *
     * @return ColumnSchema
     */
    public function boolean(): ColumnSchema
    {
        $typeInfo = $this->dialect->getBooleanType();
        return new ColumnSchema($typeInfo['type'], $typeInfo['length']);
    }

    /**
     * Create float column schema.
     *
     * @param int|null $precision Precision
     * @param int|null $scale Scale
     *
     * @return ColumnSchema
     */
    public function float(?int $precision = null, ?int $scale = null): ColumnSchema
    {
        return new ColumnSchema('FLOAT', $precision, $scale);
    }

    /**
     * Create decimal column schema.
     *
     * @param int $precision Precision
     * @param int $scale Scale
     *
     * @return ColumnSchema
     */
    public function decimal(int $precision = 10, int $scale = 2): ColumnSchema
    {
        return new ColumnSchema('DECIMAL', $precision, $scale);
    }

    /**
     * Create date column schema.
     *
     * @return ColumnSchema
     */
    public function date(): ColumnSchema
    {
        return new ColumnSchema('DATE');
    }

    /**
     * Create time column schema.
     *
     * @return ColumnSchema
     */
    public function time(): ColumnSchema
    {
        return new ColumnSchema('TIME');
    }

    /**
     * Create datetime column schema.
     *
     * @return ColumnSchema
     */
    public function datetime(): ColumnSchema
    {
        $type = $this->dialect->getDatetimeType();
        return new ColumnSchema($type);
    }

    /**
     * Create timestamp column schema.
     *
     * @return ColumnSchema
     */
    public function timestamp(): ColumnSchema
    {
        $type = $this->dialect->getTimestampType();
        return new ColumnSchema($type);
    }

    /**
     * Create JSON column schema.
     *
     * @return ColumnSchema
     */
    public function json(): ColumnSchema
    {
        return new ColumnSchema('JSON');
    }

    /**
     * Normalize column schema from various input types.
     *
     * @param ColumnSchema|array<string, mixed>|string $type Column schema, definition array, or type string
     *
     * @return ColumnSchema
     */
    protected function normalizeColumnSchema(ColumnSchema|array|string $type): ColumnSchema
    {
        if ($type instanceof ColumnSchema) {
            return $type;
        }

        if (is_array($type)) {
            // Short syntax: ['type' => 'VARCHAR(255)', 'null' => false]
            $schemaType = $type['type'] ?? 'VARCHAR';
            $length = $type['length'] ?? $type['size'] ?? null;
            $scale = $type['scale'] ?? null;

            $schema = new ColumnSchema((string)$schemaType, $length, $scale);

            if (isset($type['null']) && $type['null'] === false) {
                $schema->notNull();
            }
            if (isset($type['default'])) {
                if (isset($type['defaultExpression']) && $type['defaultExpression']) {
                    $schema->defaultExpression((string)$type['default']);
                } else {
                    $schema->defaultValue($type['default']);
                }
            }
            if (isset($type['comment'])) {
                $schema->comment((string)$type['comment']);
            }
            if (isset($type['unsigned']) && $type['unsigned']) {
                $schema->unsigned();
            }
            if (isset($type['autoIncrement']) && $type['autoIncrement']) {
                $schema->autoIncrement();
            }
            if (isset($type['unique']) && $type['unique']) {
                $schema->unique();
            }
            if (isset($type['after'])) {
                $schema->after((string)$type['after']);
            }
            if (isset($type['first']) && $type['first']) {
                $schema->first();
            }

            return $schema;
        }

        // String type: 'VARCHAR(255)'
        return new ColumnSchema((string)$type);
    }
}
