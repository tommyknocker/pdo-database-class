<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

use tommyknocker\pdodb\dialects\catalog\CatalogQueriesInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\PdoDb;

/**
 * Table and column discovery: existence checks, SHOW/DESCRIBE statements, column kinds.
 *
 * One role of {@see DialectInterface}; code that needs only this part can depend on
 * this interface instead of the whole dialect.
 */
interface DialectCatalogInterface
{
    /**
     * Dialect-specific catalog introspection queries for AI/MCP metadata.
     */
    public function catalogQueries(): CatalogQueriesInterface;

    /**
     * EXISTS syntax.
     *
     * @param string $table
     *
     * @return string
     */
    public function buildTableExistsSql(string $table): string;

    /**
     * DESCRIBE syntax.
     *
     * @param string $table
     *
     * @return string
     */
    public function buildDescribeSql(string $table): string;

    /**
     * Get indexes for a table.
     *
     * @param string $table
     *
     * @return string
     */
    public function buildShowIndexesSql(string $table): string;

    /**
     * Get foreign keys for a table.
     *
     * @param string $table
     *
     * @return string
     */
    public function buildShowForeignKeysSql(string $table): string;

    /**
     * One statement that returns the foreign keys of every table, for a database where asking per table is slow (the
     * Oracle data dictionary costs about a second per query). Rows have the columns of
     * {@see buildShowForeignKeysSql()} plus `TABLE_NAME`. Null when per-table queries are fine.
     */
    public function buildShowAllForeignKeysSql(): ?string;

    /**
     * Get constraints for a table.
     *
     * @param string $table
     *
     * @return string
     */
    public function buildShowConstraintsSql(string $table): string;

    /**
     * List all tables in the database.
     *
     * @param PdoDb $db Database instance
     * @param string|null $schema Schema name (for PostgreSQL, MSSQL)
     *
     * @return array<int, string> Array of table names
     */
    public function listTables(PdoDb $db, ?string $schema = null): array;

    /**
     * Check if column is JSON type based on column metadata.
     *
     * @param array<string, mixed> $columnMetadata Column metadata from describe
     *
     * @return bool
     */
    public function isJsonColumn(array $columnMetadata): bool;

    /**
     * Check if column is array type (PostgreSQL arrays).
     *
     * @param array<string, mixed> $columnMetadata Column metadata from describe
     *
     * @return bool
     */
    public function isArrayColumn(array $columnMetadata): bool;

    /**
     * Check if column is numeric type.
     *
     * @param array<string, mixed> $columnMetadata Column metadata from describe
     *
     * @return bool
     */
    public function isNumericColumn(array $columnMetadata): bool;

    /**
     * Build search condition for a column.
     *
     * @param string $columnName Column name (already quoted)
     * @param string $searchTerm Search term
     * @param array<string, mixed> $columnMetadata Column metadata from describe
     * @param bool $searchInJson Whether to search in JSON columns
     * @param array<string, mixed> $params Query parameters (by reference, will be modified)
     *
     * @return string|null SQL condition or null if column should be skipped
     */
    public function buildColumnSearchCondition(
        string $columnName,
        string $searchTerm,
        array $columnMetadata,
        bool $searchInJson,
        array &$params
    ): ?string;

    /**
     * Get column name from column metadata.
     *
     * @param array<string, mixed> $columnMetadata Column metadata from describe
     *
     * @return string|null Column name or null if not found
     */
    public function getColumnNameFromMetadata(array $columnMetadata): ?string;
}
