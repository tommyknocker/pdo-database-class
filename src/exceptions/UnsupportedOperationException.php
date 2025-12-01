<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

/**
 * Exception thrown when an operation is not supported by the current database dialect.
 */
class UnsupportedOperationException extends DatabaseException
{
    public function getCategory(): string
    {
        return 'unsupported_operation';
    }

    public function isRetryable(): bool
    {
        return false;
    }
}
