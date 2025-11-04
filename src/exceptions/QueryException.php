<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

use tommyknocker\pdodb\debug\QueryDebugger;

/**
 * Query execution exceptions.
 *
 * Thrown when there are issues executing SQL queries,
 * such as syntax errors, missing tables, etc.
 */
class QueryException extends DatabaseException
{
    /** @var array<string, mixed>|null Query builder debug information */
    protected ?array $queryContext = null;

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $queryContext Query builder debug information
     */
    public function __construct(
        string $message = '',
        int|string $code = 0,
        ?\PDOException $previous = null,
        string $driver = 'unknown',
        ?string $query = null,
        array $context = [],
        ?array $queryContext = null
    ) {
        parent::__construct($message, $code, $previous, $driver, $query, $context);
        $this->queryContext = $queryContext;
    }

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

        // Add query context if available
        if ($this->queryContext !== null) {
            $contextStr = QueryDebugger::formatContext($this->queryContext);
            if ($contextStr !== '') {
                $description .= " | {$contextStr}";
            }
        } elseif (!empty($this->context['params'])) {
            // Fallback: include sanitized params from context if available
            $sanitized = QueryDebugger::sanitizeParams($this->context['params'], ['password', 'token', 'secret', 'key']);
            $description .= ' | Parameters: ' . json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $description;
    }

    /**
     * Get query builder context information.
     *
     * Returns comprehensive information about the query builder state
     * at the time the exception was thrown. This includes table name,
     * conditions, joins, parameters, and SQL structure.
     *
     * @return array<string, mixed>|null Query builder debug information or null if not available
     */
    public function getQueryContext(): ?array
    {
        return $this->queryContext;
    }
}
