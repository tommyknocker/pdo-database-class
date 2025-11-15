<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mssql;

use tommyknocker\pdodb\dialects\FeatureSupportInterface;

/**
 * MSSQL feature support implementation.
 *
 * Defines which SQL features are supported by Microsoft SQL Server database.
 */
class MSSQLFeatureSupport implements FeatureSupportInterface
{
    /**
     * {@inheritDoc}
     */
    public function supportsLateralJoin(): bool
    {
        // MSSQL supports CROSS APPLY / OUTER APPLY (equivalent to LATERAL JOIN)
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
        // MSSQL supports MERGE statement
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsFilterClause(): bool
    {
        // MSSQL does not support FILTER clause
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsDistinctOn(): bool
    {
        // MSSQL does not support DISTINCT ON
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsMaterializedCte(): bool
    {
        // MSSQL does not support MATERIALIZED CTE
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsLimitInExists(): bool
    {
        // MSSQL doesn't support LIMIT in EXISTS subqueries
        return false;
    }
}
