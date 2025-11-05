<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use InvalidArgumentException;
use tommyknocker\pdodb\connection\RetryConfigValidator;

/**
 * Tests for RetryConfigValidator class.
 */
final class RetryConfigValidatorTests extends BaseSharedTestCase
{
    protected RetryConfigValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new RetryConfigValidator();
    }

    public function testValidateValidConfig(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [2002, 2006],
            'driver' => 'mysql',
        ];

        $this->validator->validate($config);
        $this->assertTrue(true); // No exception thrown
    }

    public function testValidateEnabledMustBeBoolean(): void
    {
        $config = [
            'enabled' => 'true',
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'mysql',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.enabled must be a boolean');
        $this->validator->validate($config);
    }

    public function testValidateMaxAttemptsMustBePositiveInteger(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 0,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'mysql',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.max_attempts must be a positive integer');
        $this->validator->validate($config);
    }

    public function testValidateMaxAttemptsCannotExceed100(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 101,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'mysql',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.max_attempts cannot exceed 100');
        $this->validator->validate($config);
    }

    public function testValidateDelayMsMustBeNonNegative(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => -1,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'mysql',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.delay_ms must be a non-negative integer');
        $this->validator->validate($config);
    }

    public function testValidateDelayMsCannotExceed300000(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 300001,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'mysql',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.delay_ms cannot exceed 300000ms (5 minutes)');
        $this->validator->validate($config);
    }

    public function testValidateBackoffMultiplierMustBeAtLeast1(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 0.9,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'mysql',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.backoff_multiplier must be a number >= 1.0');
        $this->validator->validate($config);
    }

    public function testValidateBackoffMultiplierCannotExceed10(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 10.1,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'mysql',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.backoff_multiplier cannot exceed 10.0');
        $this->validator->validate($config);
    }

    public function testValidateMaxDelayMsMustBeNonNegative(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => -1,
            'retryable_errors' => [],
            'driver' => 'mysql',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.max_delay_ms must be a non-negative integer');
        $this->validator->validate($config);
    }

    public function testValidateMaxDelayMsCannotExceed300000(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 300001,
            'retryable_errors' => [],
            'driver' => 'mysql',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.max_delay_ms cannot exceed 300000ms (5 minutes)');
        $this->validator->validate($config);
    }

    public function testValidateRetryableErrorsMustBeArray(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => 'not an array',
            'driver' => 'mysql',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.retryable_errors must be an array');
        $this->validator->validate($config);
    }

    public function testValidateRetryableErrorsMustContainOnlyIntegersOrStrings(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [2002, ['nested']],
            'driver' => 'mysql',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.retryable_errors must contain only integers or strings');
        $this->validator->validate($config);
    }

    public function testValidateLogicalConstraintsMaxDelayCannotBeLessThanDelay(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 10000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 1000,
            'retryable_errors' => [],
            'driver' => 'mysql',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.max_delay_ms cannot be less than retry.delay_ms');
        $this->validator->validate($config);
    }

    public function testValidateRetryableErrorsWithIntegers(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [2002, 2006, 2013],
            'driver' => 'mysql',
        ];

        $this->validator->validate($config);
        $this->assertTrue(true); // No exception thrown
    }

    public function testValidateRetryableErrorsWithStrings(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => ['HY000', '08S01'],
            'driver' => 'mysql',
        ];

        $this->validator->validate($config);
        $this->assertTrue(true); // No exception thrown
    }

    public function testValidateRetryableErrorsWithMixedIntegersAndStrings(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [2002, 'HY000', 2006],
            'driver' => 'mysql',
        ];

        $this->validator->validate($config);
        $this->assertTrue(true); // No exception thrown
    }

    public function testValidateEmptyRetryableErrors(): void
    {
        $config = [
            'enabled' => true,
            'max_attempts' => 3,
            'delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
            'retryable_errors' => [],
            'driver' => 'mysql',
        ];

        $this->validator->validate($config);
        $this->assertTrue(true); // No exception thrown
    }
}
