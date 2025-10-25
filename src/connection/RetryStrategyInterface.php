<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use PDOException;

/**
 * RetryStrategyInterface.
 *
 * Defines the contract for retry strategies.
 * Allows different retry logic implementations following the Strategy pattern.
 */
interface RetryStrategyInterface
{
    /**
     * Determine if an operation should be retried.
     *
     * @param PDOException $exception The exception that occurred
     * @param int $attempt The current attempt number
     * @param array<string, mixed> $config The retry configuration
     *
     * @return bool True if the operation should be retried
     */
    public function shouldRetry(PDOException $exception, int $attempt, array $config): bool;

    /**
     * Calculate the delay before the next retry attempt.
     *
     * @param int $attempt The current attempt number
     * @param array<string, mixed> $config The retry configuration
     *
     * @return int The delay in milliseconds
     */
    public function calculateDelay(int $attempt, array $config): int;
}
