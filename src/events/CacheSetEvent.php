<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired when a value is stored in cache.
 */
final class CacheSetEvent implements StoppableEventInterface
{
    /**
     * @param string $key Cache key
     * @param mixed $value Value being cached
     * @param int $ttl Time-to-live in seconds
     * @param array<string>|null $tables Optional array of table names for metadata
     */
    public function __construct(
        private string $key,
        private mixed $value,
        private int $ttl,
        private ?array $tables = null
    ) {
    }

    /**
     * Get cache key.
     *
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Get value being cached.
     *
     * @return mixed
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * Get time-to-live in seconds.
     *
     * @return int
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }

    /**
     * Get associated table names (if provided).
     *
     * @return array<string>|null
     */
    public function getTables(): ?array
    {
        return $this->tables;
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
