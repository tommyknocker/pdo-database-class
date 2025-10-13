<?php

namespace tommyknocker\pdodb\dialects;

use PDO;
use tommyknocker\pdodb\helpers\RawValue;

interface DialectInterface
{
    /**
     * Returns the driver name.
     *
     * @return string The driver name.
     */
    public function getDriverName(): string;

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

    /**
     * Build insert sql
     * @param string $fullTable
     * @param array $columns
     * @param array $placeholders
     * @param array $options
     * @return string
     */
    public function buildInsertSql(
        string $fullTable,
        array $columns,
        array $placeholders,
        array $options
    ): string;

    /***
     * Format select query options (e.g. SELECT SLQ_NO_CACHE for MySQL)
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

    /**
     * NOW() with diff support
     * @param string|null $diff
     * @return RawValue
     */
    public function now(?string $diff = ''): RawValue;

    /**
     * EXPLAIN syntax
     * @param string $query
     * @param bool $analyze
     * @return string
     */
    public function explainSql(string $query, bool $analyze = false): string;

    /**
     * EXISTS syntax
     * @param string $table
     * @return string
     */
    public function tableExistsSql(string $table): string;

    /**
     * DESCRIBE syntax
     * @param string $table
     * @return string
     */
    public function describeTableSql(string $table): string;

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
     * LOAD XML support flag
     * @return bool
     */
    public function canLoadXml(): bool;

    /**
     * LOAD DATA support flag
     * @return bool
     */
    public function canLoadData(): bool;

    /**
     * LOAD DATA syntax
     * @param PDO $pdo
     * @param string $table
     * @param string $filePath
     * @param array $options
     * @return string
     */
    public function buildLoadDataSql(PDO $pdo, string $table, string $filePath, array $options): string;
}