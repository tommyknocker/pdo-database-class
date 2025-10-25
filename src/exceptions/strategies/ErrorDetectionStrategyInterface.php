<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions\strategies;

/**
 * Interface for error detection strategies.
 *
 * Each strategy is responsible for detecting a specific type of database error
 * and determining the appropriate exception class to instantiate.
 */
interface ErrorDetectionStrategyInterface
{
    /**
     * Check if the given error code and message match this strategy.
     *
     * @param string $code The error code
     * @param string $message The error message
     *
     * @return bool True if this strategy should handle the error
     */
    public function isMatch(string $code, string $message): bool;

    /**
     * Get the exception class name that should be instantiated.
     *
     * @return string The fully qualified class name
     */
    public function getExceptionClass(): string;

    /**
     * Get the priority of this strategy (higher numbers = higher priority).
     *
     * @return int Priority value
     */
    public function getPriority(): int;
}
