<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired when a transaction is committed.
 */
final class TransactionCommittedEvent implements StoppableEventInterface
{
    /**
     * @param string $driver Database driver name (mysql, pgsql, sqlite)
     * @param float $duration Transaction duration in milliseconds
     */
    public function __construct(
        private string $driver,
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
     * Get transaction duration in milliseconds.
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
