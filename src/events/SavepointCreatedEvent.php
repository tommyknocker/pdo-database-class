<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired when a savepoint is created within a transaction.
 */
final class SavepointCreatedEvent implements StoppableEventInterface
{
    /**
     * @param string $name Savepoint name
     * @param string $driver Database driver name (mysql, pgsql, sqlite)
     */
    public function __construct(
        private string $name,
        private string $driver
    ) {
    }

    /**
     * Get savepoint name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
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
