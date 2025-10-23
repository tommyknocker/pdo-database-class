<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

use PDOException;

/**
 * Transaction-related exceptions.
 * 
 * Thrown when there are issues with database transactions,
 * such as deadlocks, transaction conflicts, etc.
 */
class TransactionException extends DatabaseException
{
    public function getCategory(): string
    {
        return 'transaction';
    }

    public function isRetryable(): bool
    {
        // Transaction errors are often retryable
        return true;
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
