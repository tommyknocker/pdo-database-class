<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions\strategies;

use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Strategy for detecting general query errors.
 *
 * This is the fallback strategy for errors that don't match more specific patterns.
 */
class QueryStrategy extends AbstractErrorDetectionStrategy
{
    public function __construct()
    {
        // Query strategy matches all errors (fallback)
        $this->errorCodes = [];
        $this->messagePatterns = [];
    }

    public function isMatch(string $code, string $message): bool
    {
        // Query strategy should only match if no other strategy matches
        // This is handled by the ExceptionFactory logic, not here
        return false;
    }

    public function getExceptionClass(): string
    {
        return QueryException::class;
    }

    public function getPriority(): int
    {
        return 1000; // Lowest priority - fallback strategy
    }
}
