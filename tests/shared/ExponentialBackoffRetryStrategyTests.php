<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PDOException;
use tommyknocker\pdodb\connection\ExponentialBackoffRetryStrategy;
use tommyknocker\pdodb\helpers\DbError;

/**
 * Tests for ExponentialBackoffRetryStrategy class.
 */
final class ExponentialBackoffRetryStrategyTests extends BaseSharedTestCase
{
    protected ExponentialBackoffRetryStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new ExponentialBackoffRetryStrategy();
    }

    public function testShouldRetryReturnsFalseWhenDisabled(): void
    {
        $exception = new PDOException('Connection failed', 2002);
        $config = [
            'enabled' => false,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'sqlite',
        ];

        $result = $this->strategy->shouldRetry($exception, 1, $config);
        $this->assertFalse($result);
    }

    public function testShouldRetryReturnsFalseWhenMaxAttemptsReached(): void
    {
        $exception = new PDOException('Connection failed', 2002);
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'sqlite',
        ];

        $result = $this->strategy->shouldRetry($exception, 3, $config);
        $this->assertFalse($result);
    }

    public function testShouldRetryUsesDbErrorWhenNoCustomRetryableErrors(): void
    {
        $exception = new PDOException('Connection lost', 2006);
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'mysql',
        ];

        // 2006 is a retryable error for MySQL
        $result = $this->strategy->shouldRetry($exception, 1, $config);
        $this->assertEquals(DbError::isRetryable(2006, 'mysql'), $result);
    }

    public function testShouldRetryUsesCustomRetryableErrors(): void
    {
        $exception = new PDOException('Custom error', 9999);
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [9999, 8888],
            'driver' => 'sqlite',
        ];

        $result = $this->strategy->shouldRetry($exception, 1, $config);
        $this->assertTrue($result);
    }

    public function testShouldRetryReturnsFalseForNonRetryableError(): void
    {
        $exception = new PDOException('Syntax error', 1064);
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [2002, 2006],
            'driver' => 'mysql',
        ];

        $result = $this->strategy->shouldRetry($exception, 1, $config);
        $this->assertFalse($result);
    }

    public function testCalculateDelayFirstAttempt(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'sqlite',
        ];

        // First attempt (attempt = 1): delay = 1000 * 2^(1-1) = 1000 * 1 = 1000
        $delay = $this->strategy->calculateDelay(1, $config);
        $this->assertEquals(1000, $delay);
    }

    public function testCalculateDelaySecondAttempt(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'sqlite',
        ];

        // Second attempt (attempt = 2): delay = 1000 * 2^(2-1) = 1000 * 2 = 2000
        $delay = $this->strategy->calculateDelay(2, $config);
        $this->assertEquals(2000, $delay);
    }

    public function testCalculateDelayThirdAttempt(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'sqlite',
        ];

        // Third attempt (attempt = 3): delay = 1000 * 2^(3-1) = 1000 * 4 = 4000
        $delay = $this->strategy->calculateDelay(3, $config);
        $this->assertEquals(4000, $delay);
    }

    public function testCalculateDelayRespectsMaxDelay(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 5,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 3000,
            'retryable_errors' => [],
            'driver' => 'sqlite',
        ];

        // Fourth attempt (attempt = 4): delay = 1000 * 2^(4-1) = 1000 * 8 = 8000
        // But max_delay_ms = 3000, so should return 3000
        $delay = $this->strategy->calculateDelay(4, $config);
        $this->assertEquals(3000, $delay);
    }

    public function testCalculateDelayWithCustomMultiplier(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 500,
            'backoff_multiplier' => 1.5,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'sqlite',
        ];

        // First attempt: 500 * 1.5^0 = 500
        $delay1 = $this->strategy->calculateDelay(1, $config);
        $this->assertEquals(500, $delay1);

        // Second attempt: 500 * 1.5^1 = 750
        $delay2 = $this->strategy->calculateDelay(2, $config);
        $this->assertEquals(750, $delay2);

        // Third attempt: 500 * 1.5^2 = 1125
        $delay3 = $this->strategy->calculateDelay(3, $config);
        $this->assertEquals(1125, $delay3);
    }

    public function testCalculateDelayWithZeroBaseDelay(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 0,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'sqlite',
        ];

        $delay = $this->strategy->calculateDelay(1, $config);
        $this->assertEquals(0, $delay);
    }

    public function testShouldRetryWithStringErrorCodes(): void
    {
        // PDOException requires int code, so we create exception with int code
        // but test with string error codes in retryable_errors array
        $exception = new PDOException('Error', 0);
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => ['HY000', '08S01', 0], // Include 0 to match exception code
            'driver' => 'mysql',
        ];

        $result = $this->strategy->shouldRetry($exception, 1, $config);
        $this->assertTrue($result);
    }
}
