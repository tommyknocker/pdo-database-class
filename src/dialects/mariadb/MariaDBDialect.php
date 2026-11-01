<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mariadb;

use RuntimeException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\FeatureSupportInterface;
use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilyDdlBuilder;
use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilyDialect;
use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilyDmlBuilder;
use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilySqlFormatter;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;
use tommyknocker\pdodb\query\analysis\parsers\MariaDBExplainParser;
use tommyknocker\pdodb\query\DdlQueryBuilder;

class MariaDBDialect extends MySQLFamilyDialect
{
    /**
     * {@inheritDoc}
     */
    public function getDriverName(): string
    {
        return 'mariadb';
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
        return new MariaDBDdlQueryBuilder($connection, $prefix);
    }

    public function supportsMcpStatementTimeout(): bool
    {
        return true;
    }

    public function readMcpStatementTimeout(PdoDb $db): string
    {
        $read = $db->connection->getPdo()->query('SELECT @@SESSION.max_statement_time');
        if ($read === false) {
            throw new RuntimeException('Unable to read the current statement timeout');
        }
        $previous = $read->fetchColumn();
        $read->closeCursor();
        if (!is_scalar($previous) || is_bool($previous)) {
            throw new RuntimeException('Unable to read the current statement timeout');
        }

        return (string)$previous;
    }

    public function setMcpStatementTimeout(PdoDb $db, int $milliseconds): void
    {
        $db->connection->getPdo()->exec(
            'SET SESSION max_statement_time = ' . sprintf('%.6F', $milliseconds / 1000)
        );
    }

    public function restoreMcpStatementTimeout(PdoDb $db, string $previousValue): void
    {
        if (!is_numeric($previousValue)) {
            throw new RuntimeException('Invalid saved statement timeout');
        }
        $db->connection->getPdo()->exec(
            'SET SESSION max_statement_time = ' . sprintf('%.6F', (float)$previousValue)
        );
    }

    public function mcpStatementTimeoutStoredInMilliseconds(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function getExplainParser(): ExplainParserInterface
    {
        return new MariaDBExplainParser();
    }

    protected function createFeatureSupport(): FeatureSupportInterface
    {
        return new MariaDBFeatureSupport();
    }

    protected function createSqlFormatter(): MySQLFamilySqlFormatter
    {
        return new MariaDBSqlFormatter($this);
    }

    protected function createDmlBuilder(): MySQLFamilyDmlBuilder
    {
        return new MariaDBDmlBuilder($this);
    }

    protected function createDdlBuilder(): MySQLFamilyDdlBuilder
    {
        return new MariaDBDdlBuilder($this);
    }
}
