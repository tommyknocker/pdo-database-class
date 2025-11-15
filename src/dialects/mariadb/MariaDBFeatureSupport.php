<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mariadb;

use tommyknocker\pdodb\dialects\FeatureSupportInterface;

/**
 * MariaDB feature support implementation.
 *
 * Defines which SQL features are supported by MariaDB database.
 */
class MariaDBFeatureSupport implements FeatureSupportInterface
{
    /**
     * {@inheritDoc}
     */
    public function supportsLateralJoin(): bool
    {
        // MariaDB LATERAL JOIN support is inconsistent across versions
        // Some versions don't properly support it, so we disable it
        // Users can use regular JOINs with subqueries as alternative
        return false;
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
        // MariaDB doesn't support MERGE natively, but we can emulate it
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsFilterClause(): bool
    {
        // MariaDB does not support FILTER clause
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsDistinctOn(): bool
    {
        // MariaDB does not support DISTINCT ON
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsMaterializedCte(): bool
    {
        // MariaDB 8.0+ can use optimizer hints for materialization
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

