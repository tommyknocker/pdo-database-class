<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired when a value is deleted from cache.
 */
final class CacheDeleteEvent implements StoppableEventInterface
{
    /**
     * @param string $key Cache key that was deleted
     * @param bool $success Whether the deletion was successful
     */
    public function __construct(
        private string $key,
        private bool $success
    ) {
    }

    /**
     * Get cache key that was deleted.
     *
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Check if deletion was successful.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
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
