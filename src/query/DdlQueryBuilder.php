<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\query\traits\DdlBuilderTrait;
use tommyknocker\pdodb\query\traits\TableManagementTrait;

/**
 * DDL Query Builder for database schema operations.
 *
 * This class provides a fluent API for creating, altering, and dropping
 * database objects like tables, indexes, and foreign keys.
 */
class DdlQueryBuilder
{
    use DdlBuilderTrait;
    use TableManagementTrait;

    /** @var ConnectionInterface Database connection instance */
    protected ConnectionInterface $connection;

    /** @var DialectInterface Dialect instance */
    protected DialectInterface $dialect;

    /** @var string|null Table name for TableManagementTrait */
    protected ?string $table = null;

    /**
     * DdlQueryBuilder constructor.
     *
     * @param ConnectionInterface $connection
     * @param string $prefix
     */
    public function __construct(
        ConnectionInterface $connection,
        string $prefix = ''
    ) {
        $this->connection = $connection;
        $this->dialect = $connection->getDialect();
        $this->setPrefix($prefix);
    }

    /**
     * Get dialect instance.
     *
     * @return DialectInterface
     */
    public function getDialect(): DialectInterface
    {
        return $this->dialect;
    }

    /**
     * Check if a table exists.
     *
     * @param string $table Table name
     *
     * @return bool True if table exists
     */
    public function tableExists(string $table): bool
    {
        $tableName = $this->prefix ? ($this->prefix . $table) : $table;
        // Remove quotes if present (SQLite stores table names without quotes)
        $tableNameUnquoted = str_replace(['"', '`', "'"], '', $tableName);
        $sql = $this->dialect->buildTableExistsSql($tableNameUnquoted);
        $stmt = $this->connection->query($sql);
        if ($stmt === false) {
            return false;
        }
        $result = $stmt->fetchColumn();
        // SQLite returns the table name, MySQL/PostgreSQL return boolean or count
        return !empty($result);
    }
}
