<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

use PDO;
use PDOStatement;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\exceptions\UnsupportedOperationException;
use tommyknocker\pdodb\helpers\values\ConcatValue;
use tommyknocker\pdodb\helpers\values\ConfigValue;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;
use tommyknocker\pdodb\query\DdlQueryBuilder;
use tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface;
use tommyknocker\pdodb\query\schema\ColumnSchema;

interface DialectInterface
{
    /* ---------------- Construction / PDO ---------------- */

    /**
     * Returns the driver name.
     *
     * @return string The driver name.
     */
    public function getDriverName(): string;

    /**
     * Set PDO instance.
     *
     * @param PDO $pdo
     */
    public function setPdo(PDO $pdo): void;

    /**
     * Get PDO instance.
     *
     * @return PDO|null The PDO instance or null if not set
     */
    public function getPdo(): ?PDO;

    /**
     * Build dsn string.
     *
     * @param array<string, mixed> $params
     *
     * @return string
     */
    public function buildDsn(array $params): string;

    /**
     * Returns the default PDO options.
     *
     * @return array<int|string, mixed> The default PDO options.
     */
    public function defaultPdoOptions(): array;

    /* ---------------- Quoting / identifiers / table formatting ---------------- */

    /**
     * Quote table column in query.
     *
     * @param string $name
     *
     * @return string
     */
    public function quoteIdentifier(string $name): string;

    /**
     * Quote table.
     *
     * @param string $table
     *
     * @return string
     */
    public function quoteTable(string $table): string;

    /**
     * Get table alias keyword (e.g., "AS" or empty string).
     * Some dialects (like Oracle) do not support AS keyword for table aliases.
     *
     * @return string The keyword to use before table alias (e.g., " AS " or " ")
     */
    public function getTableAliasKeyword(): string;

    /* ---------------- DML / DDL builders ---------------- */

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

    /* ---------------- JSON methods ---------------- */

    /**
     * Format JSON_GET expression.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param bool $asText
     *
     * @return string
     */
    public function formatJsonGet(string $col, array|string $path, bool $asText = true): string;

    /**
     * Format JSON_CONTAINS expression.
     *
     * @param string $col
     * @param mixed $value
     * @param array<int, string|int>|string|null $path
     *
     * @return array<int|string, mixed>|string
     */
    public function formatJsonContains(string $col, mixed $value, array|string|null $path = null): array|string;

    /**
     * Format JSON_SET expression.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param mixed $value
     *
     * @return array<int|string, mixed>
     */
    public function formatJsonSet(string $col, array|string $path, mixed $value): array;

    /**
     * Format JSON_REMOVE expression.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     *
     * @return string
     */
    public function formatJsonRemove(string $col, array|string $path): string;

    /**
     * Format JSON_REPLACE expression (only replaces if path exists).
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     * @param mixed $value
     *
     * @return array<int|string, mixed> [sql, [param => value]]
     */
    public function formatJsonReplace(string $col, array|string $path, mixed $value): array;

    /**
     * Format JSON_EXISTS expression.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     *
     * @return string
     */
    public function formatJsonExists(string $col, array|string $path): string;

    /**
     * Format JSON order expression.
     *
     * @param string $col
     * @param array<int, string|int>|string $path
     *
     * @return string
     */
    public function formatJsonOrderExpr(string $col, array|string $path): string;

    /**
     * Format JSON_LENGTH expression.
     *
     * @param string $col
     * @param array<int, string|int>|string|null $path
     *
     * @return string
     */
    public function formatJsonLength(string $col, array|string|null $path = null): string;

    /**
     * Format JSON_KEYS expression.
     *
     * @param string $col
     * @param array<int, string|int>|string|null $path
     *
     * @return string
     */
    public function formatJsonKeys(string $col, array|string|null $path = null): string;

    /**
     * Format JSON_TYPE expression.
     *
     * @param string $col
     * @param array<int, string|int>|string|null $path
     *
     * @return string
     */
    public function formatJsonType(string $col, array|string|null $path = null): string;

    /* ---------------- SQL helpers and dialect-specific expressions ---------------- */

    /**
     * Format LIKE condition.
     * For Oracle, this handles CLOB columns by wrapping them in TO_CHAR().
     *
     * @param string $column Column name (may be qualified, e.g., "table.column")
     * @param string $pattern LIKE pattern
     *
     * @return string SQL expression (e.g., "column LIKE :pattern" or "TO_CHAR(column) LIKE :pattern" for Oracle CLOB)
     */
    public function formatLike(string $column, string $pattern): string;

    /**
     * Format column for comparison operations (equality, inequality, etc.).
     * Used when comparing CLOB/TEXT columns with string values in Oracle.
     *
     * @param string $column Column name (already quoted)
     *
     * @return string SQL expression (e.g., "column" or "TO_CHAR(column)" for Oracle CLOB)
     */
    public function formatColumnForComparison(string $column): string;

    /**
     * Normalize JOIN ON condition.
     * Used to quote identifiers in ON conditions for dialects that require it (e.g., Oracle).
     *
     * @param string $condition ON condition (e.g., "o.user_id = u.id")
     *
     * @return string Normalized condition with quoted identifiers if needed
     */
    public function normalizeJoinCondition(string $condition): string;

    /**
     * Normalize column key for result set.
     * Used to normalize column keys returned by database (e.g., Oracle returns uppercase keys).
     *
     * @param string $key Column key from result set
     *
     * @return string Normalized key
     */
    public function normalizeColumnKey(string $key): string;

    /**
     * Format IFNULL expression.
     *
     * @param string $expr
     * @param mixed $default
     *
     * @return string
     */
    public function formatIfNull(string $expr, mixed $default): string;

    /**
     * Format GREATEST expression.
     *
     * @param array<int, string|int|float|RawValue> $values
     *
     * @return string
     */
    public function formatGreatest(array $values): string;

    /**
     * Format LEAST expression.
     *
     * @param array<int, string|int|float|RawValue> $values
     *
     * @return string
     */
    public function formatLeast(array $values): string;

    /**
     * Format SUBSTRING expression.
     *
     * @param string|RawValue $source
     * @param int $start
     * @param int|null $length
     *
     * @return string
     */
    public function formatSubstring(string|RawValue $source, int $start, ?int $length): string;

    /**
     * Format MOD expression.
     *
     * @param string|RawValue $dividend
     * @param string|RawValue $divisor
     *
     * @return string
     */
    public function formatMod(string|RawValue $dividend, string|RawValue $divisor): string;

    /**
     * Format CURDATE expression.
     *
     * @return string
     */
    public function formatCurDate(): string;

    /**
     * Format CURTIME expression.
     *
     * @return string
     */
    public function formatCurTime(): string;

    /**
     * Get default timestamp expression for TIMESTAMP columns.
     * Returns dialect-specific expression (e.g., 'CURRENT_TIMESTAMP', 'SYSTIMESTAMP').
     *
     * @return string Default timestamp expression
     */
    public function getTimestampDefaultExpression(): string;

    /**
     * Format YEAR extraction.
     *
     * @param string|RawValue $value
     *
     * @return string
     */
    public function formatYear(string|RawValue $value): string;

    /**
     * Format MONTH extraction.
     *
     * @param string|RawValue $value
     *
     * @return string
     */
    public function formatMonth(string|RawValue $value): string;

    /**
     * Format DAY extraction.
     *
     * @param string|RawValue $value
     *
     * @return string
     */
    public function formatDay(string|RawValue $value): string;

    /**
     * Format HOUR extraction.
     *
     * @param string|RawValue $value
     *
     * @return string
     */
    public function formatHour(string|RawValue $value): string;

    /**
     * Format MINUTE extraction.
     *
     * @param string|RawValue $value
     *
     * @return string
     */
    public function formatMinute(string|RawValue $value): string;

    /**
     * Format SECOND extraction.
     *
     * @param string|RawValue $value
     *
     * @return string
     */
    public function formatSecond(string|RawValue $value): string;

    /**
     * Format DATE(value) extraction.
     *
     * @param string|RawValue $value
     *
     * @return string
     */
    public function formatDateOnly(string|RawValue $value): string;

    /**
     * Format TIME(value) extraction.
     *
     * @param string|RawValue $value
     *
     * @return string
     */
    public function formatTimeOnly(string|RawValue $value): string;

    /**
     * Format TO_TIMESTAMP expression (Oracle-specific).
     * Converts a string to TIMESTAMP with specified format.
     *
     * @param string $timestampString The timestamp string to convert
     * @param string $format The format string (e.g., 'YYYY-MM-DD HH24:MI:SS')
     *
     * @return string SQL expression (e.g., "TO_TIMESTAMP('2025-10-20 09:00:00', 'YYYY-MM-DD HH24:MI:SS')")
     * @throws UnsupportedOperationException For non-Oracle dialects
     */
    public function formatToTimestamp(string $timestampString, string $format): string;

    /**
     * Format TO_DATE expression (Oracle-specific).
     * Converts a string to DATE with specified format.
     *
     * @param string $dateString The date string to convert
     * @param string $format The format string (e.g., 'YYYY-MM-DD')
     *
     * @return string SQL expression (e.g., "TO_DATE('2024-01-15', 'YYYY-MM-DD')")
     * @throws UnsupportedOperationException For non-Oracle dialects
     */
    public function formatToDate(string $dateString, string $format): string;

    /**
     * Format TO_CHAR expression (Oracle-specific).
     * Converts a value to character string (useful for CLOB columns).
     *
     * @param string|RawValue $value The value to convert to character string
     *
     * @return string SQL expression (e.g., "TO_CHAR(column)")
     * @throws UnsupportedOperationException For non-Oracle dialects
     */
    public function formatToChar(string|RawValue $value): string;

    /**
     * Format DATE_ADD / DATE_SUB interval expression.
     *
     * @param string|RawValue $expr Source date/datetime expression
     * @param string $value Interval value
     * @param string $unit Interval unit (DAY, MONTH, YEAR, HOUR, MINUTE, SECOND, etc.)
     * @param bool $isAdd Whether to add (true) or subtract (false) the interval
     *
     * @return string
     */
    public function formatInterval(string|RawValue $expr, string $value, string $unit, bool $isAdd): string;

    /**
     * Format FULLTEXT MATCH expression.
     *
     * @param string|array<string> $columns Column name(s) to search in.
     * @param string $searchTerm The search term.
     * @param string|null $mode Search mode: 'natural', 'boolean', 'expansion' (MySQL only).
     * @param bool $withQueryExpansion Enable query expansion (MySQL only).
     *
     * @return array<int|string, mixed>|string Array with [sql, params] or just sql string.
     */
    public function formatFulltextMatch(string|array $columns, string $searchTerm, ?string $mode = null, bool $withQueryExpansion = false): array|string;

    /**
     * Format window function expression.
     *
     * @param string $function Window function name (ROW_NUMBER, RANK, etc.).
     * @param array<mixed> $args Function arguments (for LAG, LEAD, etc.).
     * @param array<string> $partitionBy PARTITION BY columns.
     * @param array<array<string, string>> $orderBy ORDER BY expressions.
     * @param string|null $frameClause Frame clause (ROWS BETWEEN, RANGE BETWEEN).
     *
     * @return string Formatted window function SQL.
     */
    public function formatWindowFunction(
        string $function,
        array $args,
        array $partitionBy,
        array $orderBy,
        ?string $frameClause
    ): string;

    /**
     * Check if database supports FILTER clause for aggregate functions.
     *
     * @return bool
     */
    public function supportsFilterClause(): bool;

    /**
     * Check if database supports DISTINCT ON clause.
     *
     * @return bool
     */
    public function supportsDistinctOn(): bool;

    /**
     * Check if database supports MATERIALIZED CTE clause.
     *
     * @return bool
     */
    public function supportsMaterializedCte(): bool;

    /**
     * Format GROUP_CONCAT / STRING_AGG expression.
     *
     * @param string|RawValue $column Column or expression to concatenate.
     * @param string $separator Separator string.
     * @param bool $distinct Whether to use DISTINCT.
     *
     * @return string
     */
    public function formatGroupConcat(string|RawValue $column, string $separator, bool $distinct): string;

    /**
     * Format TRUNCATE / TRUNC expression.
     *
     * @param string|RawValue $value Value to truncate.
     * @param int $precision Precision (number of decimal places).
     *
     * @return string
     */
    public function formatTruncate(string|RawValue $value, int $precision): string;

    /**
     * Format POSITION / LOCATE / INSTR expression.
     *
     * @param string|RawValue $substring Substring to search for.
     * @param string|RawValue $value Source string.
     *
     * @return string
     */
    public function formatPosition(string|RawValue $substring, string|RawValue $value): string;

    /**
     * Format LEFT expression.
     *
     * @param string|RawValue $value Source string.
     * @param int $length Number of characters to extract.
     *
     * @return string
     */
    public function formatLeft(string|RawValue $value, int $length): string;

    /**
     * Format RIGHT expression.
     *
     * @param string|RawValue $value Source string.
     * @param int $length Number of characters to extract.
     *
     * @return string
     */
    public function formatRight(string|RawValue $value, int $length): string;

    /**
     * Format REPEAT expression.
     *
     * @param string|RawValue $value Source string.
     * @param int $count Number of times to repeat.
     *
     * @return string
     */
    public function formatRepeat(string|RawValue $value, int $count): string;

    /**
     * Format REVERSE expression.
     *
     * @param string|RawValue $value Source string.
     *
     * @return string
     */
    public function formatReverse(string|RawValue $value): string;

    /**
     * Format LPAD/RPAD expression.
     *
     * @param string|RawValue $value Source string.
     * @param int $length Target length.
     * @param string $padString Padding string.
     * @param bool $isLeft Whether to pad on the left.
     *
     * @return string
     */
    public function formatPad(string|RawValue $value, int $length, string $padString, bool $isLeft): string;

    /**
     * Format REGEXP match expression (returns boolean).
     *
     * @param string|RawValue $value Source string.
     * @param string $pattern Regex pattern.
     *
     * @return string SQL expression that returns boolean (true if matches, false otherwise).
     */
    public function formatRegexpMatch(string|RawValue $value, string $pattern): string;

    /**
     * Format REGEXP_LIKE boolean expression for use in CASE statements.
     *
     * @param string|RawValue $value Source string.
     * @param string $pattern Regex pattern.
     *
     * @return string SQL boolean expression (e.g., REGEXP_LIKE(...) for Oracle, value REGEXP pattern for MySQL).
     */
    public function formatRegexpLike(string|RawValue $value, string $pattern): string;

    /**
     * Format REGEXP replace expression.
     *
     * @param string|RawValue $value Source string.
     * @param string $pattern Regex pattern.
     * @param string $replacement Replacement string.
     *
     * @return string SQL expression for regexp replacement.
     */
    public function formatRegexpReplace(string|RawValue $value, string $pattern, string $replacement): string;

    /**
     * Format REGEXP extract expression (extracts matched substring).
     *
     * @param string|RawValue $value Source string.
     * @param string $pattern Regex pattern.
     * @param int|null $groupIndex Capture group index (0 = full match, 1+ = specific group, null = full match).
     *
     * @return string SQL expression for regexp extraction.
     */
    public function formatRegexpExtract(string|RawValue $value, string $pattern, ?int $groupIndex = null): string;

    /* ---------------- Original SQL helpers and dialect-specific expressions ---------------- */

    /**
     * NOW() with diff support.
     *
     * @param string|null $diff
     * @param bool $asTimestamp
     *
     * @return string
     */
    public function now(?string $diff = '', bool $asTimestamp = false): string;

    /**
     * ILIKE syntax.
     *
     * @param string $column
     * @param string $pattern
     *
     * @return RawValue
     */
    public function ilike(string $column, string $pattern): RawValue;

    /**
     * SET/PRAGMA statement syntax.
     * e.g. SET FOREIGN_KEY_CHECKS = 1 OR SET NAMES 'utf8mb4' in MySQL and PostgreSQL or PRAGMA statements in SQLite.
     *
     * @param ConfigValue $value
     *
     * @return RawValue
     */
    public function config(ConfigValue $value): RawValue;

    /**
     * CONCAT syntax.
     *
     * @param ConcatValue $value Items to concatenate
     *
     * @return RawValue
     */
    public function concat(ConcatValue $value): RawValue;

    /**
     * Format concatenation expression for this dialect.
     * SQLite uses || operator, other dialects use CONCAT() function.
     *
     * @param array<int, string> $parts Parts to concatenate (already formatted/quoted)
     *
     * @return string SQL expression for concatenation
     */
    public function formatConcatExpression(array $parts): string;

    /* ---------------- Introspection / utility SQL ---------------- */

    /**
     * EXPLAIN syntax.
     *
     * @param string $query
     *
     * @return string
     */
    public function buildExplainSql(string $query): string;

    /**
     * EXPLAIN ANALYZE syntax (PostgreSQL) or EXPLAIN FORMAT=JSON (MySQL).
     *
     * @param string $query
     *
     * @return string
     */
    public function buildExplainAnalyzeSql(string $query): string;

    /**
     * Execute EXPLAIN query with dialect-specific logic.
     * For MSSQL, this handles SET SHOWPLAN_ALL ON/OFF separately.
     * For other dialects, this simply executes the explain SQL.
     *
     * @param PDO $pdo PDO connection instance
     * @param string $sql SQL query to explain
     * @param array<int|string, string|int|float|bool|null> $params Query parameters
     *
     * @return array<int, array<string, mixed>> Explain results
     * @throws \PDOException
     */
    public function executeExplain(PDO $pdo, string $sql, array $params = []): array;

    /**
     * Execute EXPLAIN ANALYZE query with dialect-specific logic.
     * For MSSQL, this handles SET STATISTICS XML ON/OFF separately.
     * For other dialects, this simply executes the explain analyze SQL.
     *
     * @param PDO $pdo PDO connection instance
     * @param string $sql SQL query to explain and analyze
     * @param array<int|string, string|int|float|bool|null> $params Query parameters
     *
     * @return array<int, array<string, mixed>> Explain analyze results
     * @throws \PDOException
     */
    public function executeExplainAnalyze(PDO $pdo, string $sql, array $params = []): array;

    /**
     * Normalize raw SQL value for dialect-specific function replacements.
     * Replaces function names like LENGTH->LEN, CEIL->CEILING, TRUE/FALSE->1/0, etc.
     *
     * @param string $sql Raw SQL string
     *
     * @return string Normalized SQL string
     */
    public function normalizeRawValue(string $sql): string;

    /**
     * Normalize SQL statement before execution (prepare/query/exec).
     * This is called before SQL is sent to PDO for execution.
     * Dialects can use this to remove trailing semicolons, fix syntax, etc.
     *
     * @param string $sql SQL statement to normalize
     *
     * @return string Normalized SQL statement
     */
    public function normalizeSqlForExecution(string $sql): string;

    /**
     * Normalize row keys for dialect-specific requirements.
     * For example, Oracle may need to convert CLOB resources to strings and lowercase keys.
     *
     * @param array<int, array<string, mixed>> $rows Result rows
     * @param array<string, mixed> $options Connection options
     *
     * @return array<int, array<string, mixed>> Normalized rows
     */
    public function normalizeRowKeys(array $rows, array $options = []): array;

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
     * Get dialect-specific DDL query builder.
     *
     * @param ConnectionInterface $connection
     * @param string $prefix
     *
     * @return DdlQueryBuilder
     */
    public function getDdlQueryBuilder(ConnectionInterface $connection, string $prefix = ''): DdlQueryBuilder;

    /**
     * Get foreign keys for a table.
     *
     * @param string $table
     *
     * @return string
     */
    public function buildShowForeignKeysSql(string $table): string;

    /**
     * Get constraints for a table.
     *
     * @param string $table
     *
     * @return string
     */
    public function buildShowConstraintsSql(string $table): string;

    /**
     * LOCK syntax.
     *
     * @param array<int, string> $tables
     * @param string $prefix
     * @param string $lockMethod
     *
     * @return string
     */
    public function buildLockSql(array $tables, string $prefix, string $lockMethod): string;

    /**
     * UNLOCK syntax.
     *
     * @return string
     */
    public function buildUnlockSql(): string;

    /**
     * TRUNCATE syntax.
     *
     * @param string $table
     *
     * @return string
     */
    public function buildTruncateSql(string $table): string;

    /* ---------------- Loaders / bulk operations ---------------- */

    /**
     * Build SQL for loading data from XML file.
     *
     * @param string $table
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function buildLoadXML(string $table, string $filePath, array $options = []): string;

    /**
     * Build SQL generator for loading data from XML file.
     *
     * @param string $table
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return \Generator<string>
     */
    public function buildLoadXMLGenerator(string $table, string $filePath, array $options = []): \Generator;

    /**
     * Build SQL for loading data from CSV file.
     *
     * @param string $table
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function buildLoadCsvSql(string $table, string $filePath, array $options = []): string;

    /**
     * Build SQL generator for loading data from CSV file.
     *
     * @param string $table
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return \Generator<string>
     */
    public function buildLoadCsvSqlGenerator(string $table, string $filePath, array $options = []): \Generator;

    /**
     * Build SQL for loading data from JSON file.
     *
     * @param string $table
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function buildLoadJson(string $table, string $filePath, array $options = []): string;

    /**
     * Build SQL generator for loading data from JSON file.
     *
     * @param string $table
     * @param string $filePath
     * @param array<string, mixed> $options
     *
     * @return \Generator<string>
     */
    public function buildLoadJsonGenerator(string $table, string $filePath, array $options = []): \Generator;

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

    /* ---------------- DDL Operations ---------------- */

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

    /* ---------------- Database Management ---------------- */

    /**
     * Create a database.
     *
     * @param string $databaseName Database name
     * @param PdoDb $db Database instance
     *
     * @return bool True on success
     * @throws ResourceException If database creation fails
     */
    public function createDatabase(string $databaseName, PdoDb $db): bool;

    /**
     * Drop a database.
     *
     * @param string $databaseName Database name
     * @param PdoDb $db Database instance
     *
     * @return bool True on success
     * @throws ResourceException If database deletion fails
     */
    public function dropDatabase(string $databaseName, PdoDb $db): bool;

    /**
     * Check if a database exists.
     *
     * @param string $databaseName Database name
     * @param PdoDb $db Database instance
     *
     * @return bool True if database exists
     */
    public function databaseExists(string $databaseName, PdoDb $db): bool;

    /**
     * List all databases.
     *
     * @param PdoDb $db Database instance
     *
     * @return array<int, string> List of database names
     * @throws ResourceException If listing fails
     */
    public function listDatabases(PdoDb $db): array;

    /**
     * Get database-specific information.
     *
     * @param PdoDb $db Database instance
     *
     * @return array<string, mixed> Database information
     */
    public function getDatabaseInfo(PdoDb $db): array;

    /* ---------------- User Management ---------------- */

    /**
     * Create a database user.
     *
     * @param string $username Username
     * @param string $password Password
     * @param string|null $host Host (for MySQL/MariaDB, default: '%')
     * @param PdoDb $db Database instance
     *
     * @return bool True on success
     * @throws ResourceException If user creation fails or not supported
     */
    public function createUser(string $username, string $password, ?string $host, PdoDb $db): bool;

    /**
     * Drop a database user.
     *
     * @param string $username Username
     * @param string|null $host Host (for MySQL/MariaDB, default: '%')
     * @param PdoDb $db Database instance
     *
     * @return bool True on success
     * @throws ResourceException If user deletion fails or not supported
     */
    public function dropUser(string $username, ?string $host, PdoDb $db): bool;

    /**
     * Check if a database user exists.
     *
     * @param string $username Username
     * @param string|null $host Host (for MySQL/MariaDB, default: '%')
     * @param PdoDb $db Database instance
     *
     * @return bool True if user exists
     * @throws ResourceException If check fails or not supported
     */
    public function userExists(string $username, ?string $host, PdoDb $db): bool;

    /**
     * List all database users.
     *
     * @param PdoDb $db Database instance
     *
     * @return array<int, array<string, mixed>> List of users with their information
     * @throws ResourceException If listing fails or not supported
     */
    public function listUsers(PdoDb $db): array;

    /**
     * Get user information and privileges.
     *
     * @param string $username Username
     * @param string|null $host Host (for MySQL/MariaDB, default: '%')
     * @param PdoDb $db Database instance
     *
     * @return array<string, mixed> User information
     * @throws ResourceException If retrieval fails or not supported
     */
    public function getUserInfo(string $username, ?string $host, PdoDb $db): array;

    /**
     * Grant privileges to a user.
     *
     * @param string $username Username
     * @param string $privileges Privileges (e.g., 'SELECT,INSERT,UPDATE' or 'ALL')
     * @param string|null $database Database name (null = all databases, '*' = all databases)
     * @param string|null $table Table name (null = all tables, '*' = all tables)
     * @param string|null $host Host (for MySQL/MariaDB, default: '%')
     * @param PdoDb $db Database instance
     *
     * @return bool True on success
     * @throws ResourceException If grant fails or not supported
     */
    public function grantPrivileges(
        string $username,
        string $privileges,
        ?string $database,
        ?string $table,
        ?string $host,
        PdoDb $db
    ): bool;

    /**
     * Revoke privileges from a user.
     *
     * @param string $username Username
     * @param string $privileges Privileges (e.g., 'SELECT,INSERT,UPDATE' or 'ALL')
     * @param string|null $database Database name (null = all databases, '*' = all databases)
     * @param string|null $table Table name (null = all tables, '*' = all tables)
     * @param string|null $host Host (for MySQL/MariaDB, default: '%')
     * @param PdoDb $db Database instance
     *
     * @return bool True on success
     * @throws ResourceException If revoke fails or not supported
     */
    public function revokePrivileges(
        string $username,
        string $privileges,
        ?string $database,
        ?string $table,
        ?string $host,
        PdoDb $db
    ): bool;

    /**
     * Change user password.
     *
     * @param string $username Username
     * @param string $newPassword New password
     * @param string|null $host Host (for MySQL/MariaDB, default: '%')
     * @param PdoDb $db Database instance
     *
     * @return bool True on success
     * @throws ResourceException If password change fails or not supported
     */
    public function changeUserPassword(string $username, string $newPassword, ?string $host, PdoDb $db): bool;

    /* ---------------- Enum Support ---------------- */

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

    /* ---------------- Dump and Restore ---------------- */

    /**
     * Dump database schema (CREATE TABLE, indexes, foreign keys).
     *
     * @param PdoDb $db Database instance
     * @param string|null $table Table name (null = all tables)
     * @param bool $dropTables Whether to add DROP TABLE IF EXISTS before CREATE TABLE
     *
     * @return string SQL schema dump
     * @throws ResourceException If operation fails or not supported
     */
    public function dumpSchema(PdoDb $db, ?string $table = null, bool $dropTables = true): string;

    /**
     * Dump database data (INSERT statements).
     *
     * @param PdoDb $db Database instance
     * @param string|null $table Table name (null = all tables)
     *
     * @return string SQL data dump
     * @throws ResourceException If operation fails or not supported
     */
    public function dumpData(PdoDb $db, ?string $table = null): string;

    /**
     * Restore database from SQL dump.
     *
     * @param PdoDb $db Database instance
     * @param string $sql SQL dump content
     * @param bool $continueOnError Continue on errors (skip failed statements)
     *
     * @throws ResourceException If restore fails
     */
    public function restoreFromSql(PdoDb $db, string $sql, bool $continueOnError = false): void;

    /* ---------------- Monitoring ---------------- */

    /**
     * Get active queries for this dialect.
     *
     * @param PdoDb $db Database instance
     *
     * @return array<int, array<string, mixed>> Array of active queries with their details
     */
    public function getActiveQueries(PdoDb $db): array;

    /**
     * Get active connections for this dialect.
     *
     * @param PdoDb $db Database instance
     *
     * @return array<string, mixed> Returns array with 'connections' and 'summary' keys
     */
    public function getActiveConnections(PdoDb $db): array;

    /**
     * Get slow queries for this dialect.
     *
     * @param PdoDb $db Database instance
     * @param float $thresholdSeconds Threshold in seconds for considering a query slow
     * @param int $limit Maximum number of queries to return
     *
     * @return array<int, array<string, mixed>> Array of slow queries sorted by execution time
     */
    public function getSlowQueries(PdoDb $db, float $thresholdSeconds, int $limit): array;

    /**
     * Get server metrics for this dialect.
     *
     * @param PdoDb $db Database instance
     *
     * @return array<string, mixed> Server metrics (version, uptime, key performance indicators)
     */
    public function getServerMetrics(PdoDb $db): array;

    /**
     * Get server variables (configuration settings).
     *
     * @param PdoDb $db Database instance
     *
     * @return array<int, array<string, mixed>> Array of variable arrays with 'name' and 'value' keys
     */
    public function getServerVariables(PdoDb $db): array;

    /**
     * Kill a query/process for this dialect.
     *
     * @param PdoDb $db Database instance
     * @param int|string $processId Process/query ID to kill
     *
     * @return bool True if successful, false otherwise
     */
    public function killQuery(PdoDb $db, int|string $processId): bool;

    /* ---------------- Table Management ---------------- */

    /**
     * List all tables in the database.
     *
     * @param PdoDb $db Database instance
     * @param string|null $schema Schema name (for PostgreSQL, MSSQL)
     *
     * @return array<int, string> Array of table names
     */
    public function listTables(PdoDb $db, ?string $schema = null): array;

    /* ---------------- Error Handling ---------------- */

    /**
     * Get retryable error codes for this dialect.
     *
     * @return array<int|string> Array of error codes that can be retried
     */
    public function getRetryableErrorCodes(): array;

    /**
     * Get human-readable error description for an error code.
     *
     * @param int|string $errorCode Error code
     *
     * @return string Human-readable error description
     */
    public function getErrorDescription(int|string $errorCode): string;

    /* ---------------- DML Operations ---------------- */

    /**
     * Get columns for INSERT ... SELECT operation, excluding auto-increment/identity columns.
     *
     * @param string $tableName Table name
     * @param array<int, string>|null $columns Explicit column list (null = auto-detect)
     * @param ExecutionEngineInterface $executionEngine Execution engine for query execution
     *
     * @return array<int, string> Array of column names to use in INSERT
     */
    public function getInsertSelectColumns(string $tableName, ?array $columns, ExecutionEngineInterface $executionEngine): array;

    /* ---------------- Configuration ---------------- */

    /**
     * Build configuration array from environment variables.
     *
     * @param array<string, string> $envVars Environment variables (PDODB_*)
     *
     * @return array<string, mixed> Configuration array
     */
    public function buildConfigFromEnv(array $envVars): array;

    /**
     * Normalize configuration parameters (e.g., 'database' -> 'dbname').
     *
     * @param array<string, mixed> $config Configuration array
     *
     * @return array<string, mixed> Normalized configuration array
     */
    public function normalizeConfigParams(array $config): array;

    /**
     * Build SQL for ping query.
     * Used to check database connection health.
     *
     * @return string SQL query for ping
     */
    public function buildPingSql(): string;

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

    /**
     * Transform a value before it's bound as a parameter in INSERT/UPDATE operations.
     * Dialects can override this to handle special conversions (e.g., UUID format for Oracle RAW).
     *
     * @param mixed $value The value to transform
     * @param string $columnName The column name this value is for
     *
     * @return mixed The transformed value, or original value if no transformation needed
     */
    public function transformValueForBinding(mixed $value, string $columnName): mixed;

    /* ---------------- Table Search ---------------- */

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
