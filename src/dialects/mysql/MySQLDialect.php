<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mysql;

use PDO;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\FeatureSupportInterface;
use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilyDdlBuilder;
use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilyDialect;
use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilyDmlBuilder;
use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilySqlFormatter;
use tommyknocker\pdodb\dialects\mysqlfamily\MySQLRowAlias;
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

    private ?bool $rowAlias = null;

    /**
     * Whether the server is MySQL 8.0.19 or newer, which takes the inserted row as an alias in
     * `INSERT ... VALUES (...) AS new ON DUPLICATE KEY UPDATE c = new.c`. `VALUES(c)` there is deprecated since
     * 8.0.20. Older servers, MariaDB behind the `mysql` driver and an unknown version keep `VALUES(c)`.
     */
    public function supportsRowAlias(): bool
    {
        if ($this->rowAlias !== null) {
            return $this->rowAlias;
        }
        if ($this->pdo === null) {
            return false;
        }

        try {
            $version = (string)$this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (\Throwable) {
            return false;
        }

        return $this->rowAlias = self::versionHasRowAlias($version);
    }

    /**
     * @param string $version As reported by the server, for example `8.0.46`, `8.4.2-log` or `5.5.5-10.11.6-MariaDB`
     */
    public static function versionHasRowAlias(string $version): bool
    {
        if (stripos($version, 'mariadb') !== false || preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m) !== 1) {
            return false;
        }

        return [(int)$m[1], (int)$m[2], (int)$m[3]] >= [8, 0, 19];
    }

    /**
     * {@inheritDoc}
     */
    protected function duplicateKeyRowAlias(array $quotedColumns): ?MySQLRowAlias
    {
        return $this->supportsRowAlias() ? new MySQLRowAlias($quotedColumns) : null;
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
