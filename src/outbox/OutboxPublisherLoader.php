<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

use tommyknocker\pdodb\exceptions\OutboxException;

/**
 * Load a user-defined outbox publisher from a PHP file.
 */
final class OutboxPublisherLoader
{
    /**
     * @return callable(OutboxMessage): void
     */
    public static function load(string $path): callable
    {
        if (!is_file($path)) {
            throw new OutboxException('Publisher file not found: ' . $path);
        }

        $publisher = require $path;
        if (!is_callable($publisher)) {
            throw new OutboxException('Publisher file must return a callable(OutboxMessage): void.');
        }

        /* @var callable(OutboxMessage): void $publisher */
        return $publisher;
    }
}
