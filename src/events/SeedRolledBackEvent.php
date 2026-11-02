<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired when a seed is rolled back.
 */
final class SeedRolledBackEvent implements StoppableEventInterface
{
    /**
     * @param string $name Seed name
     * @param string $driver Database driver name (mysql, pgsql, sqlite)
     */
    public function __construct(
        private string $name,
        private string $driver
    ) {
    }

    /**
     * Get seed name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get database driver name.
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Check if event propagation is stopped.
     */
    public function isPropagationStopped(): bool
    {
        return false;
    }
}
