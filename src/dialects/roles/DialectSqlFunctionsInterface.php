<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\exceptions\UnsupportedOperationException;
use tommyknocker\pdodb\helpers\values\ConcatValue;
use tommyknocker\pdodb\helpers\values\ConfigValue;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Dialect-specific SQL functions and expressions.
 *
 * One role of {@see DialectInterface}; code that needs only this part can depend on
 * this interface instead of the whole dialect.
 */
interface DialectSqlFunctionsInterface
{
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
}
