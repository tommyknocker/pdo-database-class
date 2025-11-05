<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use PDOException;
use tommyknocker\pdodb\helpers\DbError;

/**
 * ExponentialBackoffRetryStrategy class.
 *
 * Implements exponential backoff retry strategy.
 * Provides configurable retry logic with exponential backoff.
 */
class ExponentialBackoffRetryStrategy implements RetryStrategyInterface
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
    public function shouldRetry(PDOException $exception, int $attempt, array $config): bool
    {
        if (!$config['enabled']) {
            return false;
        }

        if ($attempt >= $config['max_attempts']) {
            return false;
        }

        $errorCode = $exception->getCode();
        $driver = $this->getDriverName($config);

        // Use DbError constants if no custom retryable_errors are configured
        if (empty($config['retryable_errors'])) {
            return DbError::isRetryable($errorCode, $driver);
        }

        // Use custom retryable_errors if configured
        return in_array($errorCode, $config['retryable_errors'], true);
    }

    /**
     * Calculate the delay before the next retry attempt using exponential backoff.
     *
     * @param int $attempt The current attempt number
     * @param array<string, mixed> $config The retry configuration
     *
     * @return int The delay in milliseconds
     */
    public function calculateDelay(int $attempt, array $config): int
    {
        $baseDelay = $config['delay_ms'];
        $multiplier = $config['backoff_multiplier'];
        $attemptIndex = $attempt - 1; // Previous attempt number

        $calculatedDelay = $baseDelay * pow($multiplier, $attemptIndex);
        return (int)min($calculatedDelay, $config['max_delay_ms']);
    }

    /**
     * Get the driver name from configuration or exception context.
     *
     * @param array<string, mixed> $config The retry configuration
     *
     * @return string The driver name
     */
    protected function getDriverName(array $config): string
    {
        return $config['driver'] ?? 'unknown';
    }
}
