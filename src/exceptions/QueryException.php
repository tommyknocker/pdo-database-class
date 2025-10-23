<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

/**
 * Query execution exceptions.
 *
 * Thrown when there are issues executing SQL queries,
 * such as syntax errors, missing tables, etc.
 */
class QueryException extends DatabaseException
{
    public function getCategory(): string
    {
        return 'query';
    }

    public function isRetryable(): bool
    {
        // Query errors are generally not retryable
        return false;
    }

    public function getDescription(): string
    {
        $description = parent::getDescription();

        if ($this->query) {
            $description .= " (Query: {$this->query})";
        }

        return $description;
    }
}
