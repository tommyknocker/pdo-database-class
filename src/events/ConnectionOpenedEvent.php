<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired when a database connection is opened.
 *
 * Provides information about the connection including driver, DSN, and options.
 */
final class ConnectionOpenedEvent implements StoppableEventInterface
{
    /**
     * @param string $driver Database driver name (mysql, pgsql, sqlite)
     * @param string $dsn Data Source Name
     * @param array<string, mixed> $options Connection options
     */
    public function __construct(
        private string $driver,
        private string $dsn,
        private array $options
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
     * Get connection options.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
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
