<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired before a query is executed.
 *
 * This event allows modification of SQL and parameters before execution.
 * The event can be stopped to cancel the query execution.
 */
final class QueryBeforeExecuteEvent implements StoppableEventInterface
{
    protected bool $stopPropagation = false;

    /**
     * @param string $sql The SQL query to execute
     * @param array<int|string, string|int|float|bool|null> $params Query parameters
     * @param string $driver Database driver name (mysql, pgsql, sqlite)
     */
    public function __construct(
        private string $sql,
        private array $params,
        private string $driver
    ) {
    }

    /**
     * Get the SQL query.
     *
     * @return string
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * Set the SQL query (allows modification before execution).
     *
     * @param string $sql The modified SQL query
     */
    public function setSql(string $sql): void
    {
        $this->sql = $sql;
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
     * Set query parameters (allows modification before execution).
     *
     * @param array<int|string, string|int|float|bool|null> $params The modified parameters
     */
    public function setParams(array $params): void
    {
        $this->params = $params;
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
     * Stop event propagation to cancel the query execution.
     */
    public function stopPropagation(): void
    {
        $this->stopPropagation = true;
    }

    /**
     * Check if event propagation is stopped.
     *
     * @return bool
     */
    public function isPropagationStopped(): bool
    {
        return $this->stopPropagation;
    }
}
