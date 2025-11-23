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

        // Call dialect's beforeCreateTable hook
        $this->dialect->beforeCreateTable($this->connection, $tableName, $columns);

        $sql = $this->dialect->buildCreateTableSql($tableName, $columns, $options);

        // Some dialects may return multiple SQL statements (e.g., sequences + CREATE TABLE)
        // Split by semicolon and execute separately
        if (str_contains($sql, ';')) {
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $stmt) {
                if ($stmt !== '') {
                    $this->connection->query($stmt);
                }
            }
        } else {
            $this->connection->query($sql);
        }

        // Call dialect's afterCreateTable hook for post-processing
        $this->dialect->afterCreateTable($this->connection, $tableName, $columns, $sql);

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
     * Create an index (Yii2-style with extended support).
     *
     * Supports standard indexes, unique indexes, partial indexes (WHERE clause),
     * indexes with sorting, INCLUDE columns (PostgreSQL/MSSQL), and functional indexes.
     *
     * @param string $name Index name
     * @param string $table Table name
     * @param string|array<int, string|array<string, string>> $columns Column name(s) or array with sorting ['col1' => 'ASC', 'col2' => 'DESC']
     * @param bool $unique Whether index is unique
     * @param string|null $where WHERE clause for partial indexes
     * @param array<int, string>|null $includeColumns INCLUDE columns (for PostgreSQL/MSSQL)
     * @param array<string, mixed> $options Additional index options (fillfactor, using, etc.)
     *
     * @return static
     *
     * @example
     * // Simple index
     * $db->schema()->createIndex('idx_email', 'users', 'email');
     * @example
     * // Composite index
     * $db->schema()->createIndex('idx_name_email', 'users', ['name', 'email']);
     * @example
     * // Index with sorting
     * $db->schema()->createIndex('idx_name_email', 'users', [
     *     'name' => 'ASC',
     *     'email' => 'DESC'
     * ]);
     * @example
     * // Partial index (PostgreSQL)
     * $db->schema()->createIndex('idx_active_users', 'users', 'email', false, "status = 'active'");
     * @example
     * // Index with INCLUDE columns (PostgreSQL/MSSQL)
     * $db->schema()->createIndex('idx_email', 'users', 'email', false, null, ['name', 'created_at']);
     * @example
     * // Functional index (PostgreSQL)
     * $db->schema()->createIndex('idx_lower_email', 'users', Db::raw('LOWER(email)'));
     * @example
     * // Index with options (PostgreSQL)
     * $db->schema()->createIndex('idx_email', 'users', 'email', false, null, null, [
     *     'fillfactor' => 80,
     *     'using' => 'btree'
     * ]);
     *
     * @warning Partial indexes (WHERE clause) are only supported by PostgreSQL and MSSQL.
     *          MySQL/MariaDB will ignore the WHERE clause.
     * @warning INCLUDE columns are only supported by PostgreSQL and MSSQL.
     *          MySQL/MariaDB will ignore includeColumns parameter.
     *
     * @note For functional indexes, use Db::raw() for column expressions.
     *
     * @see documentation/03-query-builder/12-ddl-operations.md#creating-indexes
     */
    public function createIndex(
        string $name,
        string $table,
        string|array $columns,
        bool $unique = false,
        ?string $where = null,
        ?array $includeColumns = null,
        array $options = []
    ): static {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;

        // Normalize columns: convert string to array, handle sorting arrays
        /** @var array<int, string|array<string, string>> $columnArray */
        $columnArray = [];

        if (is_string($columns)) {
            $columnArray = [$columns];
        } else {
            // Check if this is a sorting array format: ['col1' => 'ASC', 'col2' => 'DESC']
            // vs regular array format: ['col1', 'col2']
            $isSortingArray = false;
            foreach ($columns as $key => $value) {
                if (is_string($key) && is_string($value) && (strtoupper($value) === 'ASC' || strtoupper($value) === 'DESC')) {
                    $isSortingArray = true;
                    break;
                }
            }

            if ($isSortingArray) {
                // This is a sorting array: ['col1' => 'ASC', 'col2' => 'DESC']
                // Convert to format expected by buildCreateIndexSql: [['col1' => 'ASC'], ['col2' => 'DESC']]
                foreach ($columns as $colName => $direction) {
                    if (is_string($colName) && is_string($direction)) {
                        $columnArray[] = [$colName => $direction];
                    }
                }
            } else {
                // Regular array format: ['col1', 'col2'] or [RawValue, ...]
                $columnArray = $columns;
            }
        }

        $sql = $this->dialect->buildCreateIndexSql(
            $name,
            $tableName,
            $columnArray,
            $unique,
            $where,
            $includeColumns,
            $options
        );
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
     * Create a fulltext index (Yii2-style).
     *
     * @param string $name Index name
     * @param string $table Table name
     * @param string|array<int, string> $columns Column name(s)
     * @param string|null $parser Parser name (for MySQL)
     *
     * @return static
     */
    public function createFulltextIndex(string $name, string $table, string|array $columns, ?string $parser = null): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $columnArray = is_array($columns) ? $columns : [$columns];
        $sql = $this->dialect->buildCreateFulltextIndexSql($name, $tableName, $columnArray, $parser);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Create a spatial index (Yii2-style).
     *
     * @param string $name Index name
     * @param string $table Table name
     * @param string|array<int, string> $columns Column name(s)
     *
     * @return static
     */
    public function createSpatialIndex(string $name, string $table, string|array $columns): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $columnArray = is_array($columns) ? $columns : [$columns];
        $sql = $this->dialect->buildCreateSpatialIndexSql($name, $tableName, $columnArray);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Rename an index (Yii2-style).
     *
     * @param string $oldName Old index name
     * @param string $table Table name
     * @param string $newName New index name
     *
     * @return static
     */
    public function renameIndex(string $oldName, string $table, string $newName): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildRenameIndexSql($oldName, $tableName, $newName);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Rename a foreign key (Yii2-style).
     *
     * @param string $oldName Old foreign key name
     * @param string $table Table name
     * @param string $newName New foreign key name
     *
     * @return static
     */
    public function renameForeignKey(string $oldName, string $table, string $newName): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildRenameForeignKeySql($oldName, $tableName, $newName);
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
     * Add a primary key constraint (Yii2-style).
     *
     * @param string $name Primary key name
     * @param string $table Table name
     * @param string|array<int, string> $columns Column name(s)
     *
     * @return static
     */
    public function addPrimaryKey(string $name, string $table, string|array $columns): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $columnArray = is_array($columns) ? $columns : [$columns];
        $sql = $this->dialect->buildAddPrimaryKeySql($name, $tableName, $columnArray);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Drop a primary key constraint (Yii2-style).
     *
     * @param string $name Primary key name
     * @param string $table Table name
     *
     * @return static
     */
    public function dropPrimaryKey(string $name, string $table): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildDropPrimaryKeySql($name, $tableName);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Add a unique constraint (Yii2-style).
     *
     * @param string $name Unique constraint name
     * @param string $table Table name
     * @param string|array<int, string> $columns Column name(s)
     *
     * @return static
     */
    public function addUnique(string $name, string $table, string|array $columns): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $columnArray = is_array($columns) ? $columns : [$columns];
        $sql = $this->dialect->buildAddUniqueSql($name, $tableName, $columnArray);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Drop a unique constraint (Yii2-style).
     *
     * @param string $name Unique constraint name
     * @param string $table Table name
     *
     * @return static
     */
    public function dropUnique(string $name, string $table): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildDropUniqueSql($name, $tableName);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Add a check constraint (Yii2-style).
     *
     * @param string $name Check constraint name
     * @param string $table Table name
     * @param string $expression Check expression
     *
     * @return static
     */
    public function addCheck(string $name, string $table, string $expression): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildAddCheckSql($name, $tableName, $expression);
        $this->connection->query($sql);

        return $this;
    }

    /**
     * Drop a check constraint (Yii2-style).
     *
     * @param string $name Check constraint name
     * @param string $table Table name
     *
     * @return static
     */
    public function dropCheck(string $name, string $table): static
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildDropCheckSql($name, $tableName);
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
     * @param int|null $length Integer length
     *
     * @return ColumnSchema
     */
    public function smallInteger(?int $length = null): ColumnSchema
    {
        return new ColumnSchema('SMALLINT', $length);
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

    /**
     * Check if an index exists (Yii2-style).
     *
     * @param string $name Index name
     * @param string $table Table name
     *
     * @return bool True if index exists
     */
    public function indexExists(string $name, string $table): bool
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildShowIndexesSql($tableName);
        $stmt = $this->connection->query($sql);
        if ($stmt === false) {
            return false;
        }
        $indexes = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($indexes as $index) {
            // Index name might be in different columns depending on dialect
            $indexName = $index['Key_name'] ?? $index['indexname'] ?? $index['name'] ?? $index['INDEX_NAME'] ?? null;
            if ($indexName === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a foreign key exists (Yii2-style).
     *
     * @param string $name Foreign key name
     * @param string $table Table name
     *
     * @return bool True if foreign key exists
     */
    public function foreignKeyExists(string $name, string $table): bool
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildShowForeignKeysSql($tableName);
        $stmt = $this->connection->query($sql);
        if ($stmt === false) {
            return false;
        }
        $foreignKeys = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($foreignKeys as $fk) {
            // Foreign key name might be in different columns depending on dialect
            $fkName = $fk['CONSTRAINT_NAME'] ?? $fk['constraint_name'] ?? $fk['name'] ?? $fk['fk_name'] ?? null;
            if ($fkName === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a check constraint exists (Yii2-style).
     *
     * @param string $name Check constraint name
     * @param string $table Table name
     *
     * @return bool True if check constraint exists
     */
    public function checkExists(string $name, string $table): bool
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildShowConstraintsSql($tableName);
        $stmt = $this->connection->query($sql);
        if ($stmt === false) {
            return false;
        }
        $constraints = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($constraints as $constraint) {
            // Constraint name might be in different columns depending on dialect
            $constraintName = $constraint['CONSTRAINT_NAME'] ?? $constraint['constraint_name'] ?? $constraint['name'] ?? null;
            $constraintType = $constraint['CONSTRAINT_TYPE'] ?? $constraint['constraint_type'] ?? $constraint['type'] ?? null;
            if ($constraintName === $name && (
                strtoupper((string)$constraintType) === 'CHECK' ||
                strpos(strtoupper((string)$constraintType), 'CHECK') !== false
            )) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a unique constraint exists (Yii2-style).
     *
     * @param string $name Unique constraint name
     * @param string $table Table name
     *
     * @return bool True if unique constraint exists
     */
    public function uniqueExists(string $name, string $table): bool
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildShowConstraintsSql($tableName);
        $stmt = $this->connection->query($sql);
        if ($stmt === false) {
            return false;
        }
        $constraints = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($constraints as $constraint) {
            // Constraint name might be in different columns depending on dialect
            $constraintName = $constraint['CONSTRAINT_NAME'] ?? $constraint['constraint_name'] ?? $constraint['name'] ?? null;
            $constraintType = $constraint['CONSTRAINT_TYPE'] ?? $constraint['constraint_type'] ?? $constraint['type'] ?? null;
            if ($constraintName === $name && (
                strtoupper((string)$constraintType) === 'UNIQUE' ||
                strpos(strtoupper((string)$constraintType), 'UNIQUE') !== false
            )) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get all indexes for a table (Yii2-style).
     *
     * @param string $table Table name
     *
     * @return array<int, array<string, mixed>> Array of index information
     */
    public function getIndexes(string $table): array
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildShowIndexesSql($tableName);
        $stmt = $this->connection->query($sql);
        if ($stmt === false) {
            return [];
        }
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get all foreign keys for a table (Yii2-style).
     *
     * @param string $table Table name
     *
     * @return array<int, array<string, mixed>> Array of foreign key information
     */
    public function getForeignKeys(string $table): array
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildShowForeignKeysSql($tableName);
        $stmt = $this->connection->query($sql);
        if ($stmt === false) {
            return [];
        }
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get all check constraints for a table (Yii2-style).
     *
     * @param string $table Table name
     *
     * @return array<int, array<string, mixed>> Array of check constraint information
     */
    public function getCheckConstraints(string $table): array
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildShowConstraintsSql($tableName);
        $stmt = $this->connection->query($sql);
        if ($stmt === false) {
            return [];
        }
        $constraints = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $checkConstraints = [];
        foreach ($constraints as $constraint) {
            $constraintType = $constraint['CONSTRAINT_TYPE'] ?? $constraint['constraint_type'] ?? $constraint['type'] ?? null;
            if (strtoupper((string)$constraintType) === 'CHECK' ||
                strpos(strtoupper((string)$constraintType), 'CHECK') !== false) {
                $checkConstraints[] = $constraint;
            }
        }
        return $checkConstraints;
    }

    /**
     * Get all unique constraints for a table (Yii2-style).
     *
     * @param string $table Table name
     *
     * @return array<int, array<string, mixed>> Array of unique constraint information
     */
    public function getUniqueConstraints(string $table): array
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        $sql = $this->dialect->buildShowConstraintsSql($tableName);
        $stmt = $this->connection->query($sql);
        if ($stmt === false) {
            return [];
        }
        $constraints = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $uniqueConstraints = [];
        foreach ($constraints as $constraint) {
            $constraintType = $constraint['CONSTRAINT_TYPE'] ?? $constraint['constraint_type'] ?? $constraint['type'] ?? null;
            if (strtoupper((string)$constraintType) === 'UNIQUE' ||
                strpos(strtoupper((string)$constraintType), 'UNIQUE') !== false) {
                $uniqueConstraints[] = $constraint;
            }
        }
        return $uniqueConstraints;
    }
}
