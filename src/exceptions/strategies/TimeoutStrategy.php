<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions\strategies;

use tommyknocker\pdodb\exceptions\registry\ErrorCodeRegistry;
use tommyknocker\pdodb\exceptions\TimeoutException;

/**
 * Strategy for detecting timeout errors.
 *
 * Handles query timeouts, connection timeouts, etc.
 */
class TimeoutStrategy extends AbstractErrorDetectionStrategy
{
    public function __construct()
    {
        $this->errorCodes = array_merge(
            ErrorCodeRegistry::getErrorCodes('mysql', 'timeout'),
            ErrorCodeRegistry::getErrorCodes('pgsql', 'timeout'),
            ErrorCodeRegistry::getErrorCodes('sqlite', 'timeout')
        );

        $this->messagePatterns = [
            'timeout',
            'timed out',
            'query timeout',
            'connection timeout',
            'lock timeout',
        ];
    }

    public function getExceptionClass(): string
    {
        return TimeoutException::class;
    }

    public function getPriority(): int
    {
        return 30;
    }
}
