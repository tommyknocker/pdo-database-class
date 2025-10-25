<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

/**
 * ConnectionState class.
 *
 * Manages the internal state of a database connection.
 * Encapsulates state management logic to reduce duplication.
 */
class ConnectionState
{
    /** @var string|null Last executed query */
    protected ?string $lastQuery = null;

    /** @var string|null Last error message */
    protected ?string $lastError = null;

    /** @var int Last error code */
    protected int $lastErrno = 0;

    /** @var mixed Last execute state (success/failure) */
    protected mixed $executeState = null;

    /**
     * Reset all state to initial values.
     */
    public function reset(): void
    {
        $this->lastError = null;
        $this->lastErrno = 0;
        $this->executeState = null;
    }

    /**
     * Set the last executed query.
     *
     * @param string|null $query The SQL query
     */
    public function setLastQuery(?string $query): void
    {
        $this->lastQuery = $query;
    }

    /**
     * Set error information.
     *
     * @param string $error The error message
     * @param int $errno The error code
     */
    public function setError(string $error, int $errno): void
    {
        $this->lastError = $error;
        $this->lastErrno = $errno;
    }

    /**
     * Set the execute state.
     *
     * @param mixed $state The execution state
     */
    public function setExecuteState(mixed $state): void
    {
        $this->executeState = $state;
    }

    /**
     * Get the last executed query.
     *
     * @return string|null The last query or null if no query has been executed
     */
    public function getLastQuery(): ?string
    {
        return $this->lastQuery;
    }

    /**
     * Get the last error message.
     *
     * @return string|null The last error message or null if no error has occurred
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Get the last error number.
     *
     * @return int The last error number
     */
    public function getLastErrno(): int
    {
        return $this->lastErrno;
    }

    /**
     * Get the last execute state.
     *
     * @return mixed The last execute state
     */
    public function getExecuteState(): mixed
    {
        return $this->executeState;
    }
}
