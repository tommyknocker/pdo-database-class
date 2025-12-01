<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired when a migration completes successfully.
 */
final class MigrationCompletedEvent implements StoppableEventInterface
{
    /**
     * @param string $version Migration version/name
     * @param string $driver Database driver name (mysql, pgsql, sqlite)
     * @param float $duration Migration execution duration in milliseconds
     */
    public function __construct(
        private string $version,
        private string $driver,
        private float $duration
    ) {
    }

    /**
     * Get migration version/name.
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
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
     * Get migration execution duration in milliseconds.
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
