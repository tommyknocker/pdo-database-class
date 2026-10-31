<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\outbox;

use tommyknocker\pdodb\exceptions\DatabaseException;

/**
 * Detect database errors that a worker may retry without changing the at-least-once guarantee.
 */
final class OutboxTransientError
{
    public static function isRetryable(\Throwable $error): bool
    {
        if ($error instanceof DatabaseException) {
            return $error->isRetryable();
        }

        $message = strtolower($error->getMessage());

        return str_contains($message, 'deadlock')
            || str_contains($message, '1213')
            || str_contains($message, '40001')
            || str_contains($message, '40p01');
    }
}
