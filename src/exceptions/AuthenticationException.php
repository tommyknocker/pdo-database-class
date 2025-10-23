<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

/**
 * Authentication/authorization exceptions.
 *
 * Thrown when there are authentication or authorization issues,
 * such as invalid credentials, insufficient permissions, etc.
 */
class AuthenticationException extends DatabaseException
{
    public function getCategory(): string
    {
        return 'authentication';
    }

    public function isRetryable(): bool
    {
        // Authentication errors are generally not retryable
        return false;
    }

    public function getDescription(): string
    {
        $description = parent::getDescription();

        // Don't include query details for security
        return $description;
    }
}
