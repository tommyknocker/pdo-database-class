<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

use PDOException;

/**
 * Base exception class for all database-related errors.
 * 
 * Extends PDOException to maintain compatibility while providing
 * additional context and structured error handling.
 */
abstract class DatabaseException extends PDOException
{
    protected string $driver;
    protected ?string $query = null;
    protected array $context = [];
    protected int|string $originalCode;

    public function __construct(
        string $message = '',
        int|string $code = 0,
        ?PDOException $previous = null,
        string $driver = 'unknown',
        ?string $query = null,
        array $context = []
    ) {
        // Convert string codes to int for PDOException compatibility
        $intCode = is_string($code) ? 0 : $code;
        parent::__construct($message, $intCode, $previous);
        
        $this->driver = $driver;
        $this->query = $query;
        $this->context = $context;
        $this->originalCode = $code;
    }

    /**
     * Get the database driver name.
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Get the original error code (preserves string codes for PostgreSQL).
     */
    public function getOriginalCode(): int|string
    {
        return $this->originalCode;
    }

    /**
     * Get the SQL query that caused the error (if available).
     */
    public function getQuery(): ?string
    {
        return $this->query;
    }

    /**
     * Get additional context information.
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Add context information.
     */
    public function addContext(string $key, mixed $value): self
    {
        $this->context[$key] = $value;
        return $this;
    }

    /**
     * Get a human-readable error description.
     */
    public function getDescription(): string
    {
        return $this->getMessage();
    }

    /**
     * Check if this error is retryable.
     */
    public function isRetryable(): bool
    {
        return false;
    }

    /**
     * Get error category for logging/monitoring.
     */
    abstract public function getCategory(): string;

    /**
     * Convert to array for logging.
     */
    public function toArray(): array
    {
        return [
            'exception' => static::class,
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'original_code' => $this->originalCode,
            'driver' => $this->driver,
            'query' => $this->query,
            'context' => $this->context,
            'category' => $this->getCategory(),
            'retryable' => $this->isRetryable(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'trace' => $this->getTraceAsString(),
        ];
    }
}
