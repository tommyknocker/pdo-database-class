<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired when a seed starts executing.
 */
final class SeedStartedEvent implements StoppableEventInterface
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
