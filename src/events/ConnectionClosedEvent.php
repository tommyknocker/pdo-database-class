<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired when a database connection is closed.
 *
 * Provides information about the connection including driver, DSN, and connection duration.
 */
final class ConnectionClosedEvent implements StoppableEventInterface
{
    /**
     * @param string $driver Database driver name (mysql, pgsql, sqlite)
     * @param string $dsn Data Source Name
     * @param float $duration Connection duration in seconds
     */
    public function __construct(
        private string $driver,
        private string $dsn,
        private float $duration
    ) {
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
     * Get Data Source Name.
     *
     * @return string
     */
    public function getDsn(): string
    {
        return $this->dsn;
    }

    /**
     * Get connection duration in seconds.
     *
     * @return float
     */
    public function getDuration(): float
    {
        return $this->duration;
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
