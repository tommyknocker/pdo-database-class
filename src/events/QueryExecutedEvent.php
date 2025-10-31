<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired after a query is executed successfully.
 *
 * Provides information about the executed query including SQL, parameters,
 * execution time, rows affected, and whether result came from cache.
 */
final class QueryExecutedEvent implements StoppableEventInterface
{
    /**
     * @param string $sql The executed SQL query
     * @param array<int|string, string|int|float|bool|null> $params Query parameters
     * @param float $executionTime Execution time in milliseconds
     * @param int $rowsAffected Number of rows affected/returned
     * @param string $driver Database driver name (mysql, pgsql, sqlite)
     * @param bool $fromCache Whether the result came from cache
     */
    public function __construct(
        private string $sql,
        private array $params,
        private float $executionTime,
        private int $rowsAffected,
        private string $driver,
        private bool $fromCache = false
    ) {
    }

    /**
     * Get the executed SQL query.
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
     * Get execution time in milliseconds.
     *
     * @return float
     */
    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }

    /**
     * Get number of rows affected or returned.
     *
     * @return int
     */
    public function getRowsAffected(): int
    {
        return $this->rowsAffected;
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
     * Check if result came from cache.
     *
     * @return bool
     */
    public function isFromCache(): bool
    {
        return $this->fromCache;
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
