<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;
use tommyknocker\pdodb\ai\SqlContextTokenizer;

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
     * @param array<int|string, string|int|float|bool|null> $params Bound values exactly as sent to the database. They can
     *                                                              hold passwords, tokens and personal data, so a listener
     *                                                              that logs them must filter them first.
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
     * What is safe to write to a log: the statement with string and numeric literals removed, the number of bound
     * values (never the values), and the timing.
     *
     * {@see getParams()} and {@see getSql()} return what was sent to the database, which can hold passwords, tokens
     * and personal data; log this instead.
     *
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        return [
            'sql' => SqlContextTokenizer::redact($this->sql, in_array($this->driver, ['mysql', 'mariadb', 'sqlite'], true)),
            'param_count' => count($this->params),
            'execution_time_ms' => round($this->executionTime, 2),
            'rows_affected' => $this->rowsAffected,
            'driver' => $this->driver,
            'from_cache' => $this->fromCache,
        ];
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
