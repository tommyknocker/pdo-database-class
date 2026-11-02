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
        $this->errorCodes = ErrorCodeRegistry::getMergedErrorCodes('constraint');
        $this->errorType = 'constraint';

        $this->messagePatterns = [
            'duplicate entry',
            'foreign key constraint',
            'unique constraint',
            'check constraint',
            'not null constraint',
            'constraint violation',
            'duplicate key',
            'integrity constraint',
            // SQL Server and Oracle report these without the generic wording above.
            'violation of unique key constraint',
            'violation of primary key constraint',
            'cannot insert duplicate key',
            'cannot insert the value null',
            'ora-00001',
            'ora-01400',
            'ora-02290',
            'ora-02291',
            'ora-02292',
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
