<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mysql;

use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\FeatureSupportInterface;
use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilyDdlBuilder;
use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilyDialect;
use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilyDmlBuilder;
use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilySqlFormatter;
use tommyknocker\pdodb\query\DdlQueryBuilder;

class MySQLDialect extends MySQLFamilyDialect
{
    /**
     * {@inheritDoc}
     */
    public function getDriverName(): string
    {
        return 'mysql';
    }

    /**
     * Get dialect-specific DDL query builder.
     *
     * @param ConnectionInterface $connection
     * @param string $prefix
     *
     * @return DdlQueryBuilder
     */
    public function getDdlQueryBuilder(ConnectionInterface $connection, string $prefix = ''): DdlQueryBuilder
    {
        return new MySQLDdlQueryBuilder($connection, $prefix);
    }

    protected function createFeatureSupport(): FeatureSupportInterface
    {
        return new MySQLFeatureSupport();
    }

    protected function createSqlFormatter(): MySQLFamilySqlFormatter
    {
        return new MySQLSqlFormatter($this);
    }

    protected function createDmlBuilder(): MySQLFamilyDmlBuilder
    {
        return new MySQLDmlBuilder($this);
    }

    protected function createDdlBuilder(): MySQLFamilyDdlBuilder
    {
        return new MySQLDdlBuilder($this);
    }
}
