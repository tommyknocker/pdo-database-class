<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;
use Throwable;

/**
 * Event fired when a transaction is rolled back.
 */
final class TransactionRolledBackEvent implements StoppableEventInterface
{
    /**
     * @param string $driver Database driver name (mysql, pgsql, sqlite)
     * @param float $duration Transaction duration in milliseconds
     * @param Throwable|null $exception Exception that caused rollback (if any)
     */
    public function __construct(
        private string $driver,
        private float $duration,
        private ?Throwable $exception = null
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
     * Get exception that caused rollback (if any).
     *
     * @return Throwable|null
     */
    public function getException(): ?Throwable
    {
        return $this->exception;
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
