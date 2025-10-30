<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

use PDO;
use tommyknocker\pdodb\helpers\values\ConcatValue;
use tommyknocker\pdodb\helpers\values\ConfigValue;
use tommyknocker\pdodb\helpers\values\RawValue;

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

    /* ---------------- DML / DDL builders ---------------- */

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
     * Format select query options (e.g. SELECT SQL_NO_CACHE for MySQL).
     *
     * @param string $sql
     * @param array<int|string, mixed> $options
     *
     * @return string
     */
    public function formatSelectOptions(string $sql, array $options): string;

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
}
