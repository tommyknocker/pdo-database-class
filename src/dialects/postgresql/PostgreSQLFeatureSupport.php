<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\postgresql;

use tommyknocker\pdodb\dialects\FeatureSupportInterface;

/**
 * PostgreSQL feature support implementation.
 *
 * Defines which SQL features are supported by PostgreSQL database.
 */
class PostgreSQLFeatureSupport implements FeatureSupportInterface
{
    /**
     * {@inheritDoc}
     */
    public function supportsLateralJoin(): bool
    {
        // PostgreSQL has native LATERAL JOIN support since version 9.3
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsJoinInUpdateDelete(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsMerge(): bool
    {
        // PostgreSQL 15+ supports MERGE
        // Check version if needed, or assume 15+ for now
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsFilterClause(): bool
    {
        // PostgreSQL supports FILTER clause
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsDistinctOn(): bool
    {
        // PostgreSQL supports DISTINCT ON
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsMaterializedCte(): bool
    {
        // PostgreSQL supports MATERIALIZED CTE (12+)
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsLimitInExists(): bool
    {
        return true;
    }
}
