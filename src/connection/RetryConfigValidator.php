<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use InvalidArgumentException;

/**
 * RetryConfigValidator class.
 *
 * Validates retry configuration parameters.
 * Separates validation logic following the Single Responsibility Principle.
 */
class RetryConfigValidator
{
    /**
     * Validate the complete retry configuration.
     *
     * @param array<string, mixed> $config The retry configuration
     *
     * @throws InvalidArgumentException If configuration is invalid
     */
    public function validate(array $config): void
    {
        $this->validateEnabled($config['enabled']);
        $this->validateMaxAttempts($config['max_attempts']);
        $this->validateDelays($config);
        $this->validateRetryableErrors($config['retryable_errors']);
        $this->validateLogicalConstraints($config);
    }

    /**
     * Validate the enabled flag.
     *
     * @param mixed $enabled The enabled flag value
     *
     * @throws InvalidArgumentException If enabled is not a boolean
     */
    protected function validateEnabled(mixed $enabled): void
    {
        if (!is_bool($enabled)) {
            throw new InvalidArgumentException('retry.enabled must be a boolean');
        }
    }

    /**
     * Validate the max attempts parameter.
     *
     * @param mixed $maxAttempts The max attempts value
     *
     * @throws InvalidArgumentException If max attempts is invalid
     */
    protected function validateMaxAttempts(mixed $maxAttempts): void
    {
        if (!is_int($maxAttempts) || $maxAttempts < 1) {
            throw new InvalidArgumentException('retry.max_attempts must be a positive integer');
        }

        if ($maxAttempts > 100) {
            throw new InvalidArgumentException('retry.max_attempts cannot exceed 100');
        }
    }

    /**
     * Validate delay-related parameters.
     *
     * @param array<string, mixed> $config The retry configuration
     *
     * @throws InvalidArgumentException If delay parameters are invalid
     */
    protected function validateDelays(array $config): void
    {
        $this->validateDelayMs($config['delay_ms']);
        $this->validateBackoffMultiplier($config['backoff_multiplier']);
        $this->validateMaxDelayMs($config['max_delay_ms']);
    }

    /**
     * Validate the delay_ms parameter.
     *
     * @param mixed $delayMs The delay in milliseconds
     *
     * @throws InvalidArgumentException If delay_ms is invalid
     */
    protected function validateDelayMs(mixed $delayMs): void
    {
        if (!is_int($delayMs) || $delayMs < 0) {
            throw new InvalidArgumentException('retry.delay_ms must be a non-negative integer');
        }

        if ($delayMs > 300000) { // 5 minutes
            throw new InvalidArgumentException('retry.delay_ms cannot exceed 300000ms (5 minutes)');
        }
    }

    /**
     * Validate the backoff multiplier parameter.
     *
     * @param mixed $backoffMultiplier The backoff multiplier value
     *
     * @throws InvalidArgumentException If backoff multiplier is invalid
     */
    protected function validateBackoffMultiplier(mixed $backoffMultiplier): void
    {
        if (!is_numeric($backoffMultiplier) || $backoffMultiplier < 1.0) {
            throw new InvalidArgumentException('retry.backoff_multiplier must be a number >= 1.0');
        }

        if ($backoffMultiplier > 10.0) {
            throw new InvalidArgumentException('retry.backoff_multiplier cannot exceed 10.0');
        }
    }

    /**
     * Validate the max_delay_ms parameter.
     *
     * @param mixed $maxDelayMs The maximum delay in milliseconds
     *
     * @throws InvalidArgumentException If max_delay_ms is invalid
     */
    protected function validateMaxDelayMs(mixed $maxDelayMs): void
    {
        if (!is_int($maxDelayMs) || $maxDelayMs < 0) {
            throw new InvalidArgumentException('retry.max_delay_ms must be a non-negative integer');
        }

        if ($maxDelayMs > 300000) { // 5 minutes
            throw new InvalidArgumentException('retry.max_delay_ms cannot exceed 300000ms (5 minutes)');
        }
    }

    /**
     * Validate the retryable errors array.
     *
     * @param mixed $retryableErrors The retryable errors array
     *
     * @throws InvalidArgumentException If retryable errors is invalid
     */
    protected function validateRetryableErrors(mixed $retryableErrors): void
    {
        if (!is_array($retryableErrors)) {
            throw new InvalidArgumentException('retry.retryable_errors must be an array');
        }

        foreach ($retryableErrors as $error) {
            if (!is_int($error) && !is_string($error)) {
                throw new InvalidArgumentException('retry.retryable_errors must contain only integers or strings');
            }
        }
    }

    /**
     * Validate logical constraints between parameters.
     *
     * @param array<string, mixed> $config The retry configuration
     *
     * @throws InvalidArgumentException If logical constraints are violated
     */
    protected function validateLogicalConstraints(array $config): void
    {
        if ($config['max_delay_ms'] < $config['delay_ms']) {
            throw new InvalidArgumentException('retry.max_delay_ms cannot be less than retry.delay_ms');
        }
    }
}
