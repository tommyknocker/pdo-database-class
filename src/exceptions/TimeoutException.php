<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

use PDOException;

/**
 * Timeout exceptions.
 * 
 * Thrown when database operations exceed timeout limits.
 */
class TimeoutException extends DatabaseException
{
    protected ?float $timeoutSeconds = null;

    public function __construct(
        string $message = '',
        int|string $code = 0,
        ?PDOException $previous = null,
        string $driver = 'unknown',
        ?string $query = null,
        array $context = [],
        ?float $timeoutSeconds = null
    ) {
        parent::__construct($message, $code, $previous, $driver, $query, $context);
        
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function getCategory(): string
    {
        return 'timeout';
    }

    public function isRetryable(): bool
    {
        // Timeout errors are often retryable
        return true;
    }

    public function getTimeoutSeconds(): ?float
    {
        return $this->timeoutSeconds;
    }

    public function getDescription(): string
    {
        $description = parent::getDescription();
        
        if ($this->timeoutSeconds) {
            $description .= " (Timeout: {$this->timeoutSeconds}s)";
        }
        
        return $description;
    }

    public function toArray(): array
    {
        $data = parent::toArray();
        $data['timeout_seconds'] = $this->timeoutSeconds;
        
        return $data;
    }
}
