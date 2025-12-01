<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired when a cache miss occurs (value not found in cache).
 */
final class CacheMissEvent implements StoppableEventInterface
{
    /**
     * @param string $key Cache key that was not found
     */
    public function __construct(
        private string $key
    ) {
    }

    /**
     * Get cache key that was not found.
     *
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
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
