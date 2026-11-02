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

    private bool $useUpsertAlias = false;

    /**
     * {@inheritDoc}
     *
     * `use_upsert_alias` (default false) makes upserts read the inserted row through a row alias instead of the
     * deprecated `VALUES(column)`; see {@see usesUpsertAlias()}.
     */
    public function applyConfig(array $config): void
    {
        $this->useUpsertAlias = filter_var($config['use_upsert_alias'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether upserts are written with the row alias: the connection asks for it (`use_upsert_alias`) and the server
     * is new enough. Off by default, because the alias makes a bare column name in an update expression
     * (`Db::raw('age + 5')`) ambiguous unless the alias has column names of its own, and `VALUES(column)` still works
     * on every MySQL 8 and 9 release; it only raises a deprecation warning.
     */
    public function usesUpsertAlias(): bool
    {
        return $this->useUpsertAlias && $this->supportsRowAlias();
    }

    /**
     * Whether the server is MySQL 8.0.19 or newer, which takes the inserted row as an alias in
     * `INSERT ... VALUES (...) AS new ON DUPLICATE KEY UPDATE c = new.c`. Older servers, MariaDB behind the `mysql`
     * driver and an unknown version cannot.
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
        return $this->usesUpsertAlias() ? new MySQLRowAlias($quotedColumns) : null;
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
