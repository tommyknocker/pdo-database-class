<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired when a cache hit occurs (value found in cache).
 */
final class CacheHitEvent implements StoppableEventInterface
{
    /**
     * @param string $key Cache key
     * @param mixed $value Cached value
     * @param int|null $ttl Time-to-live in seconds (null if not specified)
     */
    public function __construct(
        private string $key,
        private mixed $value,
        private ?int $ttl = null
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
     * Get cached value.
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
     * @return int|null
     */
    public function getTtl(): ?int
    {
        return $this->ttl;
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
