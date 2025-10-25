<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions\strategies;

/**
 * Abstract base class for error detection strategies.
 *
 * Provides common functionality for matching error codes and message patterns.
 */
abstract class AbstractErrorDetectionStrategy implements ErrorDetectionStrategyInterface
{
    /** @var array<int|string> Error codes to match */
    protected array $errorCodes = [];

    /** @var array<string> Message patterns to match */
    protected array $messagePatterns = [];

    /**
     * Check if the given error code and message match this strategy.
     *
     * @param string $code The error code
     * @param string $message The error message
     *
     * @return bool True if this strategy should handle the error
     */
    public function isMatch(string $code, string $message): bool
    {
        return $this->isCodeMatch($code) || $this->isMessageMatch($message);
    }

    /**
     * Check if the error code matches any of the configured codes.
     *
     * @param string $code The error code to check
     *
     * @return bool True if the code matches
     */
    protected function isCodeMatch(string $code): bool
    {
        // Convert string code to int for comparison with integer constants
        $intCode = is_numeric($code) ? (int) $code : $code;

        return in_array($code, $this->errorCodes, true) ||
               in_array($intCode, $this->errorCodes, true);
    }

    /**
     * Check if the error message contains any of the configured patterns.
     *
     * @param string $message The error message to check
     *
     * @return bool True if any pattern matches
     */
    protected function isMessageMatch(string $message): bool
    {
        foreach ($this->messagePatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the priority of this strategy (higher numbers = higher priority).
     *
     * @return int Priority value
     */
    public function getPriority(): int
    {
        return 100; // Default priority
    }
}
