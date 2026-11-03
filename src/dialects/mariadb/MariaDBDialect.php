<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mariadb;

use PDO;
use RuntimeException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\FeatureSupportInterface;
use tommyknocker\pdodb\dialects\InsertStatement;
use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilyDdlBuilder;
use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilyDialect;
use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilyDmlBuilder;
use tommyknocker\pdodb\dialects\mysqlfamily\MySQLFamilySqlFormatter;
use tommyknocker\pdodb\dialects\roles\DialectInsertReturningInterface;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;
use tommyknocker\pdodb\query\analysis\parsers\MariaDBExplainParser;
use tommyknocker\pdodb\query\DdlQueryBuilder;

class MariaDBDialect extends MySQLFamilyDialect implements DialectInsertReturningInterface
{
    private ?bool $insertReturning = null;

    /**
     * {@inheritDoc}
     *
     * MariaDB 10.5+ can return the key of the written row. It is asked for when the connection cannot report it: a
     * single-column primary key that is not AUTO_INCREMENT (a UUID, a text key, a key filled from a default), and any
     * `ON DUPLICATE KEY UPDATE`, whose update that changes nothing leaves the connection with no id. A plain insert into an
     * AUTO_INCREMENT table stays on the connection and needs no extra query.
     */
    public function insertIdReturningSql(string $sql): ?string
    {
        if ($this->pdo === null || !$this->serverSupportsInsertReturning()) {
            return null;
        }
        $insert = InsertStatement::parse($sql);
        if ($insert === null || preg_match('/\bRETURNING\b/i', $insert->tail) === 1) {
            return null;
        }
        $upsert = preg_match('/\bON\s+DUPLICATE\s+KEY\b/i', $insert->tail) === 1;

        $primaryKey = $this->primaryKeyOf($insert, true);
        if ($primaryKey['key'] !== null && !$primaryKey['autoIncrement']) {
            // The column name goes into the SQL: look it up afresh, so an altered table cannot break the insert
            $primaryKey = $this->primaryKeyOf($insert, false);
        }
        if ($primaryKey['key'] === null || ($primaryKey['autoIncrement'] && !$upsert)) {
            return null; // no single key, or a plain insert the connection answers
        }

        return rtrim($sql, " \t\n\r;") . ' RETURNING ' . $this->quoteIdentifier($primaryKey['key']);
    }

    private function serverSupportsInsertReturning(): bool
    {
        if ($this->insertReturning === null) {
            // "5.5.5-10.6.12-MariaDB": older replication-compatible prefix before the real version
            $version = preg_replace('/^5\.5\.5-/', '', (string)$this->pdo?->getAttribute(PDO::ATTR_SERVER_VERSION));
            $this->insertReturning = preg_match('/^(\d+)\.(\d+)/', (string)$version, $m) === 1
                && ((int)$m[1] > 10 || ((int)$m[1] === 10 && (int)$m[2] >= 5));
        }

        return $this->insertReturning;
    }

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
