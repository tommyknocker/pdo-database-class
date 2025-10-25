<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions\strategies;

use tommyknocker\pdodb\exceptions\ConnectionException;
use tommyknocker\pdodb\exceptions\registry\ErrorCodeRegistry;

/**
 * Strategy for detecting connection-related errors.
 *
 * Handles connection failures, server gone away, broken pipes, etc.
 */
class ConnectionStrategy extends AbstractErrorDetectionStrategy
{
    public function __construct()
    {
        $this->errorCodes = array_merge(
            ErrorCodeRegistry::getErrorCodes('mysql', 'connection'),
            ErrorCodeRegistry::getErrorCodes('pgsql', 'connection'),
            ErrorCodeRegistry::getErrorCodes('sqlite', 'connection')
        );

        $this->messagePatterns = [
            'connection',
            'server has gone away',
            'lost connection',
            'can\'t connect',
            'connection refused',
            'connection timeout',
            'connection reset',
            'broken pipe',
        ];
    }

    public function getExceptionClass(): string
    {
        return ConnectionException::class;
    }

    public function getPriority(): int
    {
        return 60;
    }
}
