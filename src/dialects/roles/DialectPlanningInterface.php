<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

use PDO;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\dialects\PlanExplainAvailability;
use tommyknocker\pdodb\query\analysis\ExplainAnalysis;

/**
 * EXPLAIN and query plan analysis.
 *
 * One role of {@see DialectInterface}; code that needs only this part can depend on
 * this interface instead of the whole dialect.
 */
interface DialectPlanningInterface
{
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
     * Execute the plan used by local query advice.
     *
     * SQLite advice reads EXPLAIN QUERY PLAN. Other dialects use executeExplain().
     *
     * @param PDO $pdo PDO connection instance
     * @param string $sql SQL query to explain
     * @param array<int|string, string|int|float|bool|null> $params Query parameters
     *
     * @return array<int, array<string, mixed>>
     * @throws \PDOException
     */
    public function executePlanExplain(PDO $pdo, string $sql, array $params = []): array;

    /**
     * Whether estimated plan collection is supported for the given bound parameters.
     *
     * Used by query inspection and AI advice without driver checks in shared services.
     *
     * @param array<int|string, string|int|float|bool|null> $params
     */
    public function resolvePlanExplainAvailability(array $params = []): PlanExplainAvailability;

    /**
     * Build the explain_plan section for AI query context.
     *
     * @param array<string, mixed> $settings AI context collection settings
     *
     * @return array<string, mixed>
     */
    public function buildAiExplainPlanSection(?ExplainAnalysis $analysis, array $settings): array;

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
}
