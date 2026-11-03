<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

use PDOStatement;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface;

/**
 * INSERT/UPDATE/DELETE/MERGE building and SELECT clause formatting.
 *
 * One role of {@see DialectInterface}; code that needs only this part can depend on
 * this interface instead of the whole dialect.
 */
interface DialectDmlInterface
{
    /**
     * Enhance INSERT options if needed (e.g., add RETURNING clause for Oracle).
     *
     * @param array<int|string, mixed> $options Original options
     * @param array<int, string> $columns Column names
     * @param string $table Table name
     *
     * @return array<int|string, mixed> Enhanced options
     */
    public function enhanceInsertOptions(array $options, array $columns, string $table): array;

    /**
     * Extract inserted ID from statement result.
     * For dialects that use RETURNING clause, this should fetch the ID from the statement.
     * For other dialects, this should return null to fall back to lastInsertId().
     * A dialect that can tell the row has no generated id returns 0 (the caller then reports no id).
     *
     * @param PDOStatement $stmt The executed statement
     * @param string $sql The SQL query that was executed
     * @param array<string, string|int|float|bool|null> $params The parameters that were used
     *
     * @return int|null The inserted ID, or null to use lastInsertId()
     */
    public function extractInsertId(PDOStatement $stmt, string $sql, array $params): ?int;

    /**
     * Build insert sql.
     *
     * @param string $table
     * @param array<int, string> $columns
     * @param array<int, string> $placeholders
     * @param array<int|string, mixed> $options
     *
     * @return string
     */
    public function buildInsertSql(
        string $table,
        array $columns,
        array $placeholders,
        array $options
    ): string;

    /**
     * Build multi-row insert sql.
     * Some dialects (like Oracle) use INSERT ALL instead of INSERT ... VALUES (...), (...).
     *
     * @param string $table
     * @param array<int, string> $columns
     * @param array<int, string> $tuples Array of tuple strings like "(ph1, ph2)" or "(raw, ph)"
     * @param array<int|string, mixed> $options
     *
     * @return string SQL for multi-row insert
     */
    public function buildInsertMultiSql(
        string $table,
        array $columns,
        array $tuples,
        array $options
    ): string;

    /**
     * Build INSERT ... SELECT sql.
     *
     * @param string $table Target table name
     * @param array<int, string> $columns Column names to insert into (empty = use SELECT columns)
     * @param string $selectSql SELECT query SQL
     * @param array<int|string, mixed> $options INSERT options (e.g., IGNORE, LOW_PRIORITY)
     *
     * @return string
     */
    public function buildInsertSelectSql(
        string $table,
        array $columns,
        string $selectSql,
        array $options = []
    ): string;

    /**
     * Format select query options (e.g. SELECT SQL_NO_CACHE for MySQL).
     *
     * @param string $sql
     * @param array<int|string, mixed> $options
     *
     * @return string
     */
    public function formatSelectOptions(string $sql, array $options): string;

    /**
     * Format LIMIT and OFFSET clause for SELECT statement.
     * MSSQL uses TOP or OFFSET...FETCH NEXT instead of LIMIT/OFFSET.
     *
     * @param string $sql The SQL query
     * @param int|null $limit LIMIT value (null = no limit)
     * @param int|null $offset OFFSET value (null = no offset)
     *
     * @return string SQL with properly formatted LIMIT/OFFSET
     */
    public function formatLimitOffset(string $sql, ?int $limit, ?int $offset): string;

    /**
     * Format SELECT query for use in UNION operations.
     * Some dialects require parentheses or removal of TOP/LIMIT clauses.
     *
     * @param string $selectSql SELECT query SQL
     * @param bool $isBaseQuery Whether this is the base query (first in UNION)
     *
     * @return string Formatted SELECT query for UNION
     */
    public function formatUnionSelect(string $selectSql, bool $isBaseQuery = false): string;

    /**
     * Whether UNION queries require parentheses around each SELECT.
     *
     * @return bool
     */
    public function needsUnionParentheses(): bool;

    /**
     * Format ORDER BY clause for UNION queries.
     * Some dialects (like Oracle) require positional numbers instead of column names.
     *
     * @param array<string> $orderBy Array of ORDER BY expressions (e.g., ['name', 'price DESC'])
     * @param array<string> $selectColumns Array of SELECT column expressions (e.g., ['name', 'price'])
     *
     * @return string Formatted ORDER BY clause
     */
    public function formatUnionOrderBy(array $orderBy, array $selectColumns): string;

    /**
     * Build upsert clause.
     *
     * @param array<int, string>|array<string, mixed> $updateColumns Either array of column names or associative array with expressions
     * @param string $defaultConflictTarget
     * @param string $tableName Table name for PostgreSQL to resolve ambiguous column references
     *
     * @return string
     */
    public function buildUpsertClause(array $updateColumns, string $defaultConflictTarget = 'id', string $tableName = ''): string;

    /**
     * The upsert clause for `INSERT ... VALUES`, which also knows the inserted columns. Same as
     * {@see buildUpsertClause()} except on MySQL 8.0.19+, where the clause refers to the new row by an alias instead of
     * the deprecated `VALUES(column)` (the alias exists only for `VALUES`, not for `INSERT ... SELECT`).
     *
     * @param array<int|string, mixed> $updateColumns
     * @param list<string> $insertColumns
     */
    public function buildUpsertClauseForValues(array $updateColumns, array $insertColumns, string $defaultConflictTarget = 'id', string $tableName = ''): string;

    /**
     * Insert a row or leave the existing row unchanged when the conflict columns match.
     *
     * Update columns are assigned from the inserted row. An empty update list keeps
     * the existing row (insert-if-absent). Conflict columns must not also be updated.
     *
     * @param array<string, string|int|float|RawValue|null> $row
     * @param list<string> $conflictColumns
     * @param list<string> $updateColumns
     *
     * @return array{0: string, 1: array<string, string|int|float|null>}
     */
    public function buildIdempotentUpsertSql(
        string $table,
        array $row,
        array $conflictColumns,
        array $updateColumns
    ): array;

    /**
     * Build replace sql.
     *
     * @param string $table
     * @param array<int, string> $columns
     * @param array<int, string|array<int, string>> $placeholders
     * @param bool $isMultiple
     *
     * @return string
     */
    public function buildReplaceSql(string $table, array $columns, array $placeholders, bool $isMultiple = false): string;

    /**
     * Whether `INSERT ... ON CONFLICT` / `ON DUPLICATE KEY UPDATE` is available (`onDuplicate()` on an insert).
     *
     * SQL Server and Oracle have no such clause; they upsert with MERGE, see {@see supportsMerge()}. Check this before
     * calling `onDuplicate()` in code that runs on several databases.
     */
    public function supportsUpsertClause(): bool;

    /**
     * Check if MERGE statement is supported.
     *
     * @return bool
     */
    public function supportsMerge(): bool;

    /**
     * Build MERGE SQL statement.
     *
     * @param string $targetTable Target table name
     * @param string $sourceSql Source table/subquery SQL
     * @param string $onClause ON clause conditions
     * @param array{whenMatched: string|null, whenNotMatched: string|null, whenNotMatchedBySourceDelete: bool} $whenClauses WHEN clauses
     *
     * @return string
     * @throws \RuntimeException If MERGE is not supported
     */
    public function buildMergeSql(
        string $targetTable,
        string $sourceSql,
        string $onClause,
        array $whenClauses
    ): string;

    /**
     * Check if JOIN in UPDATE/DELETE statements is supported.
     *
     * @return bool
     */
    public function supportsJoinInUpdateDelete(): bool;

    /**
     * Build UPDATE SQL statement with JOIN clauses.
     *
     * @param string $table Main table name
     * @param string $setClause SET clause (e.g., "column1 = value1, column2 = value2")
     * @param array<int, string> $joins Array of JOIN clauses
     * @param string $whereClause WHERE clause (including "WHERE" keyword)
     * @param int|null $limit LIMIT value (null = no limit)
     * @param string $options Query options (e.g., "IGNORE ", "LOW_PRIORITY ")
     *
     * @return string
     */
    public function buildUpdateWithJoinSql(
        string $table,
        string $setClause,
        array $joins,
        string $whereClause,
        ?int $limit = null,
        string $options = ''
    ): string;

    /**
     * Build DELETE SQL statement with JOIN clauses.
     *
     * @param string $table Main table name
     * @param array<int, string> $joins Array of JOIN clauses
     * @param string $whereClause WHERE clause (including "WHERE" keyword)
     * @param string $options Query options (e.g., "IGNORE ", "LOW_PRIORITY ")
     *
     * @return string
     */
    public function buildDeleteWithJoinSql(
        string $table,
        array $joins,
        string $whereClause,
        string $options = ''
    ): string;

    /**
     * Check if column names need to be qualified with table name in UPDATE SET clause when JOIN is used.
     *
     * PostgreSQL uses FROM clause in UPDATE, so column names don't need table prefix.
     * Other dialects use JOIN syntax and may require table qualification to avoid ambiguity.
     *
     * @return bool True if column qualification is needed when JOIN is used
     */
    public function needsColumnQualificationInUpdateSet(): bool;

    /**
     * Resolve INSERT ... SELECT source SQL for dialect-specific column expansion (e.g. MSSQL SELECT *).
     *
     * @param array<int, string> $columns Target insert columns
     */
    public function resolveInsertSelectSourceSql(
        string $sourceSql,
        array $columns,
        ExecutionEngineInterface $executionEngine,
    ): string;

    /**
     * Build EXISTS expression for dialect.
     *
     * @param string $subquery Subquery SQL
     *
     * @return string EXISTS expression SQL
     */
    public function buildExistsExpression(string $subquery): string;

    /**
     * Whether LIMIT can be used in EXISTS subqueries.
     *
     * @return bool
     */
    public function supportsLimitInExists(): bool;

    /**
     * Check if the dialect supports LATERAL JOINs.
     *
     * LATERAL JOINs allow correlated subqueries in FROM clause,
     * where the subquery can reference columns from preceding tables.
     *
     * @return bool True if LATERAL JOINs are supported
     */
    public function supportsLateralJoin(): bool;

    /**
     * Format LATERAL JOIN SQL for dialect-specific syntax.
     *
     * Converts standard LATERAL JOIN syntax to dialect-specific format.
     * For MSSQL, converts to CROSS APPLY/OUTER APPLY.
     * For other dialects, uses standard LATERAL JOIN syntax.
     *
     * @param string $tableSql Table SQL with LATERAL prefix (e.g., "LATERAL ({$subquerySql}) AS {$aliasQuoted}")
     * @param string $type JOIN type (LEFT, INNER, CROSS, etc.)
     * @param string $aliasQuoted Quoted alias for the lateral subquery/table
     * @param string|RawValue|null $condition Optional ON condition (ignored for MSSQL APPLY syntax)
     *
     * @return string Formatted JOIN SQL clause
     */
    public function formatLateralJoin(string $tableSql, string $type, string $aliasQuoted, string|RawValue|null $condition = null): string;
}
