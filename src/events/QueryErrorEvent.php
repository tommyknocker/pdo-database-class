<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;
use tommyknocker\pdodb\exceptions\DatabaseException;

/**
 * Event fired when a query error occurs.
 *
 * Provides information about the failed query including SQL, parameters,
 * exception, and driver name.
 */
final class QueryErrorEvent implements StoppableEventInterface
{
    /**
     * @param string $sql The SQL query that failed
     * @param array<int|string, string|int|float|bool|null> $params Query parameters
     * @param DatabaseException $exception The database exception
     * @param string $driver Database driver name (mysql, pgsql, sqlite)
     */
    public function __construct(
        private string $sql,
        private array $params,
        private DatabaseException $exception,
        private string $driver
    ) {
    }

    /**
     * Get the SQL query that failed.
     *
     * @return string
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * Get query parameters.
     *
     * @return array<int|string, string|int|float|bool|null>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Get the database exception.
     *
     * @return DatabaseException
     */
    public function getException(): DatabaseException
    {
        return $this->exception;
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
