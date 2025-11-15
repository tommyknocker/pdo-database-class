<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\sqlite;

use tommyknocker\pdodb\dialects\FeatureSupportInterface;

/**
 * SQLite feature support implementation.
 *
 * Defines which SQL features are supported by SQLite database.
 */
class SqliteFeatureSupport implements FeatureSupportInterface
{
    /**
     * {@inheritDoc}
     */
    public function supportsLateralJoin(): bool
    {
        // SQLite does not support LATERAL JOINs
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsJoinInUpdateDelete(): bool
    {
        // SQLite does not support JOIN in UPDATE/DELETE statements
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsMerge(): bool
    {
        // SQLite doesn't support MERGE natively, but we can emulate it
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsFilterClause(): bool
    {
        // SQLite 3.30+ supports FILTER clause
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsDistinctOn(): bool
    {
        // SQLite does not support DISTINCT ON
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsMaterializedCte(): bool
    {
        // SQLite does not support MATERIALIZED CTE
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsLimitInExists(): bool
    {
        return true;
    }
}

