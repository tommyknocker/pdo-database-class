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
        $this->errorCodes = ErrorCodeRegistry::getMergedErrorCodes('connection');
        $this->errorType = 'connection';

        $this->messagePatterns = [
            'connection',
            'server has gone away',
            'lost connection',
            'can\'t connect',
            'connection refused',
            'connection timeout',
            'connection reset',
            'broken pipe',
            'communication link failure',
            'named pipes provider',
            'tns:',
            'ora-03113',
            'ora-03114',
            'ora-03135',
            'ora-12154',
            'ora-12170',
            'ora-12514',
            'ora-12541',
            'ora-12545',
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
