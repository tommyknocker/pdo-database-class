<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

use PDO;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;
use tommyknocker\pdodb\query\DdlQueryBuilder;
use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * Schema changes: tables, columns, indexes, constraints and enums.
 *
 * One role of {@see DialectInterface}; code that needs only this part can depend on
 * this interface instead of the whole dialect.
 */
interface DialectDdlInterface
{
    /**
     * Get dialect-specific DDL query builder.
     *
     * @param ConnectionInterface $connection
     * @param string $prefix
     *
     * @return DdlQueryBuilder
     */
    public function getDdlQueryBuilder(ConnectionInterface $connection, string $prefix = ''): DdlQueryBuilder;

    /**
     * TRUNCATE syntax.
     *
     * @param string $table
     *
     * @return string
     */
    public function buildTruncateSql(string $table): string;

    /**
     * Build CREATE TABLE SQL statement.
     *
     * @param string $table Table name
     * @param array<string, ColumnSchema|array<string, mixed>|string> $columns Column definitions
     * @param array<string, mixed> $options Table options (ENGINE, CHARSET, etc.)
     *
     * @return string SQL statement
     */
    public function buildCreateTableSql(
        string $table,
        array $columns,
        array $options = []
    ): string;

    /**
     * Build DROP TABLE SQL statement.
     *
     * @param string $table Table name
     *
     * @return string SQL statement
     */
    public function buildDropTableSql(string $table): string;

    /**
     * Build DROP TABLE IF EXISTS SQL statement.
     *
     * @param string $table Table name
     *
     * @return string SQL statement
     */
    public function buildDropTableIfExistsSql(string $table): string;

    /**
     * Build ALTER TABLE ADD COLUMN SQL statement.
     *
     * @param string $table Table name
     * @param string $column Column name
     * @param ColumnSchema $schema Column schema
     *
     * @return string SQL statement
     */
    public function buildAddColumnSql(
        string $table,
        string $column,
        ColumnSchema $schema
    ): string;

    /**
     * Whether ADD COLUMN can carry a UNIQUE constraint. When it cannot (SQLite), the unique index is created in a
     * separate statement after the column.
     */
    public function addsUniqueColumnInline(): bool;

    /**
     * Build ALTER TABLE DROP COLUMN SQL statement.
     *
     * @param string $table Table name
     * @param string $column Column name
     *
     * @return string SQL statement
     */
    public function buildDropColumnSql(string $table, string $column): string;

    /**
     * Build ALTER TABLE ALTER COLUMN SQL statement.
     *
     * @param string $table Table name
     * @param string $column Column name
     * @param ColumnSchema $schema Column schema
     *
     * @return string SQL statement
     */
    public function buildAlterColumnSql(
        string $table,
        string $column,
        ColumnSchema $schema
    ): string;

    /**
     * Build ALTER TABLE RENAME COLUMN SQL statement.
     *
     * @param string $table Table name
     * @param string $oldName Old column name
     * @param string $newName New column name
     *
     * @return string SQL statement
     */
    public function buildRenameColumnSql(string $table, string $oldName, string $newName): string;

    /**
     * Build CREATE INDEX SQL statement.
     *
     * @param string $name Index name
     * @param string $table Table name
     * @param array<int, string|array<string, string>> $columns Column names (can be array with 'column' => 'ASC'/'DESC' for sorting)
     * @param bool $unique Whether index is unique
     * @param string|null $where WHERE clause for partial indexes
     * @param array<int, string>|null $includeColumns INCLUDE columns (for PostgreSQL/MSSQL)
     * @param array<string, mixed> $options Additional index options (fillfactor, using, etc.)
     *
     * @return string SQL statement
     */
    public function buildCreateIndexSql(
        string $name,
        string $table,
        array $columns,
        bool $unique = false,
        ?string $where = null,
        ?array $includeColumns = null,
        array $options = []
    ): string;

    /**
     * Build DROP INDEX SQL statement.
     *
     * @param string $name Index name
     * @param string $table Table name
     *
     * @return string SQL statement
     */
    public function buildDropIndexSql(string $name, string $table): string;

    /**
     * Build CREATE FULLTEXT INDEX SQL statement.
     *
     * @param string $name Index name
     * @param string $table Table name
     * @param array<int, string> $columns Column names
     * @param string|null $parser Parser name (for MySQL)
     *
     * @return string SQL statement
     */
    public function buildCreateFulltextIndexSql(string $name, string $table, array $columns, ?string $parser = null): string;

    /**
     * Build CREATE SPATIAL INDEX SQL statement.
     *
     * @param string $name Index name
     * @param string $table Table name
     * @param array<int, string> $columns Column names
     *
     * @return string SQL statement
     */
    public function buildCreateSpatialIndexSql(string $name, string $table, array $columns): string;

    /**
     * Build RENAME INDEX SQL statement.
     *
     * @param string $oldName Old index name
     * @param string $table Table name
     * @param string $newName New index name
     *
     * @return string SQL statement
     */
    public function buildRenameIndexSql(string $oldName, string $table, string $newName): string;

    /**
     * Build RENAME FOREIGN KEY SQL statement.
     *
     * @param string $oldName Old foreign key name
     * @param string $table Table name
     * @param string $newName New foreign key name
     *
     * @return string SQL statement
     */
    public function buildRenameForeignKeySql(string $oldName, string $table, string $newName): string;

    /**
     * Build ADD FOREIGN KEY SQL statement.
     *
     * @param string $name Foreign key name
     * @param string $table Table name
     * @param array<int, string> $columns Column names
     * @param string $refTable Referenced table name
     * @param array<int, string> $refColumns Referenced column names
     * @param string|null $delete ON DELETE action (CASCADE, RESTRICT, SET NULL, etc.)
     * @param string|null $update ON UPDATE action
     *
     * @return string SQL statement
     */
    public function buildAddForeignKeySql(
        string $name,
        string $table,
        array $columns,
        string $refTable,
        array $refColumns,
        ?string $delete = null,
        ?string $update = null
    ): string;

    /**
     * Build DROP FOREIGN KEY SQL statement.
     *
     * @param string $name Foreign key name
     * @param string $table Table name
     *
     * @return string SQL statement
     */
    public function buildDropForeignKeySql(string $name, string $table): string;

    /**
     * Build ADD PRIMARY KEY SQL statement.
     *
     * @param string $name Primary key name
     * @param string $table Table name
     * @param array<int, string> $columns Column names
     *
     * @return string SQL statement
     */
    public function buildAddPrimaryKeySql(string $name, string $table, array $columns): string;

    /**
     * Build DROP PRIMARY KEY SQL statement.
     *
     * @param string $name Primary key name
     * @param string $table Table name
     *
     * @return string SQL statement
     */
    public function buildDropPrimaryKeySql(string $name, string $table): string;

    /**
     * Build ADD UNIQUE constraint SQL statement.
     *
     * @param string $name Unique constraint name
     * @param string $table Table name
     * @param array<int, string> $columns Column names
     *
     * @return string SQL statement
     */
    public function buildAddUniqueSql(string $name, string $table, array $columns): string;

    /**
     * Build DROP UNIQUE constraint SQL statement.
     *
     * @param string $name Unique constraint name
     * @param string $table Table name
     *
     * @return string SQL statement
     */
    public function buildDropUniqueSql(string $name, string $table): string;

    /**
     * Build ADD CHECK constraint SQL statement.
     *
     * @param string $name Check constraint name
     * @param string $table Table name
     * @param string $expression Check expression
     *
     * @return string SQL statement
     */
    public function buildAddCheckSql(string $name, string $table, string $expression): string;

    /**
     * Build DROP CHECK constraint SQL statement.
     *
     * @param string $name Check constraint name
     * @param string $table Table name
     *
     * @return string SQL statement
     */
    public function buildDropCheckSql(string $name, string $table): string;

    /**
     * Build RENAME TABLE SQL statement.
     *
     * @param string $table Old table name
     * @param string $newName New table name
     *
     * @return string SQL statement
     */
    public function buildRenameTableSql(string $table, string $newName): string;

    /**
     * Format column definition for CREATE/ALTER TABLE.
     *
     * @param string $name Column name
     * @param ColumnSchema $schema Column schema
     *
     * @return string Column definition SQL
     */
    public function formatColumnDefinition(string $name, ColumnSchema $schema): string;

    /**
     * Get boolean column type for this dialect.
     *
     * @return array{type: string, length: int|null} Array with 'type' and 'length' keys
     */
    public function getBooleanType(): array;

    /**
     * Get timestamp column type for this dialect.
     *
     * @return string Column type name
     */
    public function getTimestampType(): string;

    /**
     * Get datetime column type for this dialect.
     *
     * @return string Column type name
     */
    public function getDatetimeType(): string;

    /**
     * Check if PDOException is a "no fields" error that should be handled gracefully.
     *
     * Some dialects (e.g., MSSQL) throw exceptions for DDL queries that don't return result sets.
     * This method checks if the exception should be treated as "no result" rather than an error.
     *
     * @param \PDOException $e The exception to check
     *
     * @return bool True if this is a "no fields" error that should return empty result
     */
    public function isNoFieldsError(\PDOException $e): bool;

    /**
     * Append LIMIT and OFFSET clause to SQL query.
     *
     * This method adds pagination to an existing SQL query. Some dialects (e.g., MSSQL)
     * require ORDER BY clause when using OFFSET/FETCH, so this method may add a default
     * ORDER BY if none exists.
     *
     * @param string $sql The SQL query
     * @param int $limit Number of rows to fetch
     * @param int $offset Number of rows to skip
     *
     * @return string SQL query with LIMIT/OFFSET appended
     */
    public function appendLimitOffset(string $sql, int $limit, int $offset): string;

    /**
     * Get primary key column type for this dialect.
     *
     * @return string Column type name (e.g., 'INT', 'INTEGER')
     */
    public function getPrimaryKeyType(): string;

    /**
     * Get big primary key column type for this dialect.
     *
     * @return string Column type name (e.g., 'BIGINT', 'BIGSERIAL')
     */
    public function getBigPrimaryKeyType(): string;

    /**
     * Get primary key columns for a table.
     *
     * @param PdoDb $db Database instance
     * @param string $table Table name
     *
     * @return array<int, string> Array of primary key column names
     */
    public function getPrimaryKeyColumns(PdoDb $db, string $table): array;

    /**
     * Get string column type for this dialect.
     *
     * @return string Column type name (e.g., 'VARCHAR', 'TEXT')
     */
    public function getStringType(): string;

    /**
     * Get text column type for this dialect.
     *
     * @return string Column type name (e.g., 'TEXT', 'NVARCHAR(MAX)')
     */
    public function getTextType(): string;

    /**
     * Get char column type for this dialect.
     *
     * @return string Column type name (e.g., 'CHAR', 'NCHAR')
     */
    public function getCharType(): string;

    /**
     * Format MATERIALIZED keyword for CTE.
     *
     * Some dialects support materialized CTEs with different syntax.
     *
     * @param string $cteSql The CTE SQL query
     * @param bool $isMaterialized Whether CTE should be materialized
     *
     * @return string Formatted CTE SQL with materialization applied if needed
     */
    public function formatMaterializedCte(string $cteSql, bool $isMaterialized): string;

    /**
     * Register REGEXP functions for this dialect.
     *
     * Some dialects (e.g., SQLite, MSSQL) require custom functions to support REGEXP operations.
     * This method registers those functions if needed. For dialects that don't support REGEXP
     * or have native support, this method does nothing.
     *
     * @param PDO $pdo The PDO instance
     * @param bool $force Force re-registration even if functions exist
     */
    public function registerRegexpFunctions(PDO $pdo, bool $force = false): void;

    /**
     * Normalize DEFAULT keyword value for use in UPDATE/INSERT statements.
     *
     * Some dialects don't support DEFAULT keyword in UPDATE statements (e.g., SQLite).
     * This method converts DEFAULT to an appropriate value for the dialect.
     *
     * @param string $value The value to normalize (should be 'DEFAULT' if it's the DEFAULT keyword)
     *
     * @return string Normalized value for the dialect
     */
    public function normalizeDefaultValue(string $value): string;

    /**
     * Build SQL for creating migration table.
     *
     * @param string $tableName Migration table name
     *
     * @return string CREATE TABLE SQL statement
     */
    public function buildMigrationTableSql(string $tableName): string;

    /**
     * Build SQL and parameters for inserting migration record.
     *
     * @param string $tableName Migration table name
     * @param string $version Migration version
     * @param int $batch Batch number
     *
     * @return array{string, array<int|string, mixed>} SQL statement and parameters
     */
    public function buildMigrationInsertSql(string $tableName, string $version, int $batch): array;

    /**
     * Extract error code from PDOException.
     *
     * Some dialects store error codes differently in PDOException.
     *
     * @param \PDOException $e The exception
     *
     * @return string Error code
     */
    public function extractErrorCode(\PDOException $e): string;

    /**
     * Get EXPLAIN parser for this dialect.
     *
     * @return ExplainParserInterface Parser instance
     */
    public function getExplainParser(): ExplainParserInterface;

    /**
     * Get the keyword for recursive CTE.
     * Most databases use 'WITH RECURSIVE', but MSSQL uses just 'WITH'.
     *
     * @return string Keyword for recursive CTE ('WITH' or 'WITH RECURSIVE')
     */
    public function getRecursiveCteKeyword(): string;

    /**
     * Normalize recursive CTE SQL for dialect-specific requirements.
     * Some dialects require special handling of CTE references in recursive parts.
     *
     * @param string $sql CTE SQL to normalize
     * @param string $cteName CTE name (quoted)
     * @param bool $isRecursive Whether this is a recursive CTE
     *
     * @return string Normalized SQL
     */
    public function normalizeRecursiveCteSql(string $sql, string $cteName, bool $isRecursive): string;

    /**
     * Extract ENUM values from column definition.
     *
     * @param array<string, mixed> $column Column definition from describe()
     * @param PdoDb $db Database instance
     * @param string $tableName Table name
     * @param string $columnName Column name
     *
     * @return array<int, string> Array of ENUM values, empty array if not an ENUM type
     */
    public function extractEnumValues(array $column, PdoDb $db, string $tableName, string $columnName): array;

    /**
     * Execute post-processing after table creation.
     * This method is called after createTable() to perform dialect-specific operations
     * like creating triggers, sequences, or other database objects.
     *
     * @param ConnectionInterface $connection Database connection
     * @param string $tableName Table name
     * @param array<string, ColumnSchema|array<string, mixed>|string> $columns Column definitions
     * @param string $sql The SQL that was executed to create the table
     */
    public function afterCreateTable(
        ConnectionInterface $connection,
        string $tableName,
        array $columns,
        string $sql
    ): void;

    /**
     * Drop existing database objects before table creation.
     * This method is called before createTable() to drop existing objects
     * like sequences, triggers, or other database objects that might conflict.
     *
     * @param ConnectionInterface $connection Database connection
     * @param string $tableName Table name
     * @param array<string, ColumnSchema|array<string, mixed>|string> $columns Column definitions
     */
    public function beforeCreateTable(
        ConnectionInterface $connection,
        string $tableName,
        array $columns
    ): void;
}
