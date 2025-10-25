<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions\strategies;

use tommyknocker\pdodb\exceptions\ConstraintViolationException;
use tommyknocker\pdodb\exceptions\registry\ErrorCodeRegistry;

/**
 * Strategy for detecting constraint violation errors.
 *
 * Handles unique key violations, foreign key violations, check constraints, etc.
 */
class ConstraintViolationStrategy extends AbstractErrorDetectionStrategy
{
    public function __construct()
    {
        $this->errorCodes = array_merge(
            ErrorCodeRegistry::getErrorCodes('mysql', 'constraint'),
            ErrorCodeRegistry::getErrorCodes('pgsql', 'constraint'),
            ErrorCodeRegistry::getErrorCodes('sqlite', 'constraint')
        );

        $this->messagePatterns = [
            'duplicate entry',
            'foreign key constraint',
            'unique constraint',
            'check constraint',
            'not null constraint',
            'constraint violation',
            'duplicate key',
            'integrity constraint',
        ];
    }

    public function getExceptionClass(): string
    {
        return ConstraintViolationException::class;
    }

    public function getPriority(): int
    {
        return 10; // Highest priority - most specific
    }
}
