<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event fired when the entire cache is cleared.
 */
final class CacheClearedEvent implements StoppableEventInterface
{
    /**
     * @param bool $success Whether the cache clear was successful
     */
    public function __construct(
        private bool $success
    ) {
    }

    /**
     * Check if cache clear was successful.
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
