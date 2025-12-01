<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired when a migration starts executing.
 */
final class MigrationStartedEvent implements StoppableEventInterface
{
    /**
     * @param string $version Migration version/name
     * @param string $driver Database driver name (mysql, pgsql, sqlite)
     */
    public function __construct(
        private string $version,
        private string $driver
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
     * Check if event propagation is stopped.
     *
     * @return bool
     */
    public function isPropagationStopped(): bool
    {
        return false;
    }
}
