<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

use PDOException;

/**
 * Connection-related database exceptions.
 * 
 * Thrown when there are issues establishing or maintaining
 * database connections.
 */
class ConnectionException extends DatabaseException
{
    public function getCategory(): string
    {
        return 'connection';
    }

    public function isRetryable(): bool
    {
        // Most connection errors are retryable
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
