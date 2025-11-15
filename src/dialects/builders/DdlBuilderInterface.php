<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\builders;

use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * Interface for DDL (Data Definition Language) SQL builders.
 */
interface DdlBuilderInterface
{
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
}
