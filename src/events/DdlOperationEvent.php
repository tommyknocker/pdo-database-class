<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired when a DDL (Data Definition Language) operation is executed.
 *
 * DDL operations include CREATE TABLE, DROP TABLE, ALTER TABLE, CREATE INDEX, etc.
 */
final class DdlOperationEvent implements StoppableEventInterface
{
    /**
     * @param string $operation Operation type (CREATE_TABLE, DROP_TABLE, ALTER_TABLE, ADD_COLUMN, DROP_COLUMN, CREATE_INDEX, DROP_INDEX, etc.)
     * @param string $table Table name (if applicable)
     * @param string $sql The SQL statement that was executed
     * @param string $driver Database driver name (mysql, pgsql, sqlite)
     */
    public function __construct(
        private string $operation,
        private string $table,
        private string $sql,
        private string $driver
    ) {
    }

    /**
     * Get operation type.
     *
     * @return string
     */
    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * Get table name (if applicable).
     *
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get the SQL statement that was executed.
     *
     * @return string
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * Get database driver name.
     *
     * @return string
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Check if event propagation is stopped.
     *
     * @return bool
     */
    public function isPropagationStopped(): bool
    {
        return false;
    }
}
