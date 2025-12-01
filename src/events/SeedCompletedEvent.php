<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired when a seed completes successfully.
 */
final class SeedCompletedEvent implements StoppableEventInterface
{
    /**
     * @param string $name Seed name
     * @param string $driver Database driver name (mysql, pgsql, sqlite)
     * @param float $duration Seed execution duration in milliseconds
     */
    public function __construct(
        private string $name,
        private string $driver,
        private float $duration
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
     * Get seed execution duration in milliseconds.
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
