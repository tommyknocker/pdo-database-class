<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\builders;

/**
 * Interface for DML (Data Manipulation Language) SQL builders.
 */
interface DmlBuilderInterface
{
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
}
