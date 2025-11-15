<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

/**
 * Interface for checking database feature support.
 *
 * Provides methods to check if a database dialect supports specific SQL features.
 * This allows the query builder to adapt its SQL generation based on available features.
 */
interface FeatureSupportInterface
{
    /**
     * Check if database supports LATERAL JOINs.
     *
     * LATERAL JOINs allow subqueries in the FROM clause to reference columns
     * from preceding tables in the same FROM clause.
     *
     * @return bool True if LATERAL JOINs are supported
     */
    public function supportsLateralJoin(): bool;

    /**
     * Check if database supports JOINs in UPDATE and DELETE statements.
     *
     * @return bool True if JOINs in UPDATE/DELETE are supported
     */
    public function supportsJoinInUpdateDelete(): bool;

    /**
     * Check if database supports MERGE statements.
     *
     * MERGE (or UPSERT) statements combine INSERT and UPDATE operations.
     *
     * @return bool True if MERGE statements are supported
     */
    public function supportsMerge(): bool;

    /**
     * Check if database supports FILTER clause for aggregate functions.
     *
     * FILTER clause allows conditional aggregation: SUM(x) FILTER (WHERE condition).
     *
     * @return bool True if FILTER clause is supported
     */
    public function supportsFilterClause(): bool;

    /**
     * Check if database supports DISTINCT ON clause.
     *
     * DISTINCT ON allows selecting distinct rows based on specific columns
     * (PostgreSQL-specific feature).
     *
     * @return bool True if DISTINCT ON is supported
     */
    public function supportsDistinctOn(): bool;

    /**
     * Check if database supports MATERIALIZED CTE clause.
     *
     * MATERIALIZED CTE forces materialization of Common Table Expressions
     * for performance optimization.
     *
     * @return bool True if MATERIALIZED CTE is supported
     */
    public function supportsMaterializedCte(): bool;

    /**
     * Check if database supports LIMIT clause in EXISTS subqueries.
     *
     * Some databases don't allow LIMIT in EXISTS subqueries.
     *
     * @return bool True if LIMIT in EXISTS is supported
     */
    public function supportsLimitInExists(): bool;
}

