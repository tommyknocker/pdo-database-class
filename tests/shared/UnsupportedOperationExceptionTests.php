<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\exceptions\UnsupportedOperationException;

/**
 * Tests for UnsupportedOperationException.
 */
final class UnsupportedOperationExceptionTests extends BaseSharedTestCase
{
    public function testUnsupportedOperationExceptionCategory(): void
    {
        $exception = new UnsupportedOperationException('Operation not supported', 0, null, 'mysql');
        $this->assertEquals('unsupported_operation', $exception->getCategory());
    }

    public function testUnsupportedOperationExceptionIsNotRetryable(): void
    {
        $exception = new UnsupportedOperationException('Operation not supported', 0, null, 'mysql');
        $this->assertFalse($exception->isRetryable());
    }

    public function testUnsupportedOperationExceptionWithDriver(): void
    {
        $exception = new UnsupportedOperationException('Operation not supported', 0, null, 'postgresql');
        $this->assertEquals('postgresql', $exception->getDriver());
    }

    public function testUnsupportedOperationExceptionWithQuery(): void
    {
        $exception = new UnsupportedOperationException(
            'Operation not supported',
            0,
            null,
            'mysql',
            'SELECT * FROM users'
        );
        $this->assertEquals('SELECT * FROM users', $exception->getQuery());
    }
}
