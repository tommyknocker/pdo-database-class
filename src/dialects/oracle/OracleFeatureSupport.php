<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\oracle;

use tommyknocker\pdodb\dialects\FeatureSupportInterface;

/**
 * Oracle feature support implementation.
 *
 * Defines which SQL features are supported by Oracle database.
 */
class OracleFeatureSupport implements FeatureSupportInterface
{
    /**
     * {@inheritDoc}
     */
    public function supportsLateralJoin(): bool
    {
        // Oracle supports LATERAL JOINs since version 12c
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsJoinInUpdateDelete(): bool
    {
        // Oracle supports JOINs in UPDATE/DELETE using subqueries or MERGE
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsMerge(): bool
    {
        // Oracle has native MERGE support
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsFilterClause(): bool
    {
        // Oracle does not support FILTER clause
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsDistinctOn(): bool
    {
        // Oracle does not support DISTINCT ON
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsMaterializedCte(): bool
    {
        // Oracle supports materialized hints for CTEs
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


