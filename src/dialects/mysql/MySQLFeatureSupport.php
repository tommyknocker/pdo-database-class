<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mysql;

use tommyknocker\pdodb\dialects\FeatureSupportInterface;

/**
 * MySQL feature support implementation.
 *
 * Defines which SQL features are supported by MySQL database.
 */
class MySQLFeatureSupport implements FeatureSupportInterface
{
    /**
     * {@inheritDoc}
     */
    public function supportsLateralJoin(): bool
    {
        // MySQL supports LATERAL JOINs since version 8.0.14
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
        // MySQL doesn't support MERGE natively, but we can emulate it
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsFilterClause(): bool
    {
        // MySQL does not support FILTER clause
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsDistinctOn(): bool
    {
        // MySQL does not support DISTINCT ON
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsMaterializedCte(): bool
    {
        // MySQL 8.0+ can use optimizer hints for materialization
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
