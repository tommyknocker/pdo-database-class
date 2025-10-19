<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

use PDO;
use tommyknocker\pdodb\helpers\ConcatValue;
use tommyknocker\pdodb\helpers\ConfigValue;
use tommyknocker\pdodb\helpers\RawValue;

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
     * Set PDO instance
     * @param PDO $pdo
     * @return void
     */
    public function setPdo(PDO $pdo): void;

    /**
     * Build dsn string
     * @param array $params
     * @return string
     */
    public function buildDsn(array $params): string;

    /**
     * Returns the default PDO options.
     *
     * @return array The default PDO options.
     */
    public function defaultPdoOptions(): array;


    /* ---------------- Quoting / identifiers / table formatting ---------------- */

    /**
     * Quote table column in query
     * @param string $name
     * @return string
     */
    public function quoteIdentifier(string $name): string;

    /**
     * Quote table
     * @param string $table
     * @return string
     */
    public function quoteTable(string $table): string;


    /* ---------------- DML / DDL builders ---------------- */

    /**
     * Build insert sql
     * @param string $table
     * @param array $columns
     * @param array $placeholders
     * @param array $options
     * @return string
     */
    public function buildInsertSql(
        string $table,
        array $columns,
        array $placeholders,
        array $options
    ): string;

    /***
     * Format select query options (e.g. SELECT SQL_NO_CACHE for MySQL)
     * @param string $sql
     * @param array $options
     * @return string
     */
    public function formatSelectOptions(string $sql, array $options): string;

    /**
     * Build upsert clause
     * @param array $updateColumns
     * @param string $defaultConflictTarget
     * @return string
     */
    public function buildUpsertClause(array $updateColumns, string $defaultConflictTarget = 'id'): string;

    /**
     * Build replace sql
     * @param string $table
     * @param array $columns
     * @param array $placeholders
     * @param bool $isMultiple
     * @return string
     */
    public function buildReplaceSql(string $table, array $columns, array $placeholders, bool $isMultiple = false): string;

    /* ---------------- JSON methods ---------------- */

    /**
     * Format JSON_GET expression
     * @param string $col
     * @param array|string $path
     * @param bool $asText
     * @return string
     */
    public function formatJsonGet(string $col, array|string $path, bool $asText = true): string;

    /**
     * Format JSON_CONTAINS expression
     * @param string $col
     * @param mixed $value
     * @param array|string|null $path
     * @return array|string
     */
    public function formatJsonContains(string $col, mixed $value, array|string|null $path = null): array|string;

    /**
     * Format JSON_SET expression
     * @param string $col
     * @param array|string $path
     * @param mixed $value
     * @return array
     */
    public function formatJsonSet(string $col, array|string $path, mixed $value): array;

    /**
     * Format JSON_REMOVE expression
     * @param string $col
     * @param array|string $path
     * @return string
     */
    public function formatJsonRemove(string $col, array|string $path): string;

    /**
     * Format JSON_EXISTS expression
     * @param string $col
     * @param array|string $path
     * @return string
     */
    public function formatJsonExists(string $col, array|string $path): string;

    /**
     * Format JSON order expression
     * @param string $col
     * @param array|string $path
     * @return string
     */
    public function formatJsonOrderExpr(string $col, array|string $path): string;

    /**
     * Format JSON_LENGTH expression
     * @param string $col
     * @param array|string|null $path
     * @return string
     */
    public function formatJsonLength(string $col, array|string|null $path = null): string;

    /**
     * Format JSON_KEYS expression
     * @param string $col
     * @param array|string|null $path
     * @return string
     */
    public function formatJsonKeys(string $col, array|string|null $path = null): string;

    /**
     * Format JSON_TYPE expression
     * @param string $col
     * @param array|string|null $path
     * @return string
     */
    public function formatJsonType(string $col, array|string|null $path = null): string;


    /* ---------------- SQL helpers and dialect-specific expressions ---------------- */

    /**
     * Format IFNULL expression
     * @param string $expr
     * @param mixed $default
     * @return string
     */
    public function formatIfNull(string $expr, mixed $default): string;

    /**
     * Format GREATEST expression
     * @param array $values
     * @return string
     */
    public function formatGreatest(array $values): string;

    /**
     * Format LEAST expression
     * @param array $values
     * @return string
     */
    public function formatLeast(array $values): string;

    /**
     * Format SUBSTRING expression
     * @param string|RawValue $source
     * @param int $start
     * @param int|null $length
     * @return string
     */
    public function formatSubstring(string|RawValue $source, int $start, ?int $length): string;

    /**
     * Format MOD expression
     * @param string|RawValue $dividend
     * @param string|RawValue $divisor
     * @return string
     */
    public function formatMod(string|RawValue $dividend, string|RawValue $divisor): string;

    /**
     * Format CURDATE expression
     * @return string
     */
    public function formatCurDate(): string;

    /**
     * Format CURTIME expression
     * @return string
     */
    public function formatCurTime(): string;

    /**
     * Format YEAR extraction
     * @param string|RawValue $value
     * @return string
     */
    public function formatYear(string|RawValue $value): string;

    /**
     * Format MONTH extraction
     * @param string|RawValue $value
     * @return string
     */
    public function formatMonth(string|RawValue $value): string;

    /**
     * Format DAY extraction
     * @param string|RawValue $value
     * @return string
     */
    public function formatDay(string|RawValue $value): string;

    /**
     * Format HOUR extraction
     * @param string|RawValue $value
     * @return string
     */
    public function formatHour(string|RawValue $value): string;

    /**
     * Format MINUTE extraction
     * @param string|RawValue $value
     * @return string
     */
    public function formatMinute(string|RawValue $value): string;

    /**
     * Format SECOND extraction
     * @param string|RawValue $value
     * @return string
     */
    public function formatSecond(string|RawValue $value): string;

    /* ---------------- Original SQL helpers and dialect-specific expressions ---------------- */

    /**
     * NOW() with diff support
     * @param string|null $diff
     * @param bool $asTimestamp
     * @return string
     */
    public function now(?string $diff = '', bool $asTimestamp = false): string;

    /**
     * ILIKE syntax
     * @param string $column
     * @param string $pattern
     * @return RawValue
     */
    public function ilike(string $column, string $pattern): RawValue;

    /**
     * SET/PRAGMA statement syntax.
     * e.g. SET FOREIGN_KEY_CHECKS = 1 OR SET NAMES 'utf8mb4' in MySQL and PostgreSQL or PRAGMA statements in SQLite
     * @param ConfigValue $value
     * @return RawValue
     */
    public function config(ConfigValue $value): RawValue;

    /**
     * CONCAT syntax
     * @param ConcatValue $value Items to concatenate
     * @return RawValue
     */
    public function concat(ConcatValue $value): RawValue;


    /* ---------------- Introspection / utility SQL ---------------- */

    /**
     * EXPLAIN syntax
     * @param string $query
     * @param bool $analyze
     * @return string
     */
    public function buildExplainSql(string $query, bool $analyze = false): string;

    /**
     * EXISTS syntax
     * @param string $table
     * @return string
     */
    public function buildTableExistsSql(string $table): string;

    /**
     * DESCRIBE syntax
     * @param string $table
     * @return string
     */
    public function buildDescribeTableSql(string $table): string;

    /**
     * LOCK syntax
     * @param array $tables
     * @param string $prefix
     * @param string $lockMethod
     * @return string
     */
    public function buildLockSql(array $tables, string $prefix, string $lockMethod): string;

    /**
     * UNLOCK syntax
     * @return string
     */
    public function buildUnlockSql(): string;

    /**
     * TRUNCATE syntax
     * @param string $table
     * @return string
     */
    public function buildTruncateSql(string $table): string;


    /* ---------------- Loaders / bulk operations ---------------- */

    /**
     * Build SQL for loading data from XML file
     * @param string $table
     * @param string $filePath
     * @param array $options
     * @return string
     */
    public function buildLoadXML(string $table, string $filePath, array $options = []): string;

    /**
     * Build SQL for loading data from CSV file
     * @param string $table
     * @param string $filePath
     * @param array $options
     * @return string
     */
    public function buildLoadCsvSql(string $table, string $filePath, array $options = []): string;
}
