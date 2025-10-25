<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions\strategies;

use tommyknocker\pdodb\exceptions\registry\ErrorCodeRegistry;
use tommyknocker\pdodb\exceptions\TransactionException;

/**
 * Strategy for detecting transaction-related errors.
 *
 * Handles deadlocks, transaction conflicts, serialization failures, etc.
 */
class TransactionStrategy extends AbstractErrorDetectionStrategy
{
    public function __construct()
    {
        $this->errorCodes = array_merge(
            ErrorCodeRegistry::getErrorCodes('mysql', 'transaction'),
            ErrorCodeRegistry::getErrorCodes('pgsql', 'transaction'),
            ErrorCodeRegistry::getErrorCodes('sqlite', 'transaction')
        );

        $this->messagePatterns = [
            'deadlock',
            'lock timeout',
            'serialization failure',
            'could not serialize',
            'transaction',
            'lock',
            'concurrent update',
        ];
    }

    public function getExceptionClass(): string
    {
        return TransactionException::class;
    }

    public function getPriority(): int
    {
        return 50;
    }
}
