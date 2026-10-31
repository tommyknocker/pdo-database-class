<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions\strategies;

use tommyknocker\pdodb\exceptions\registry\ErrorCodeRegistry;
use tommyknocker\pdodb\exceptions\ResourceException;

/**
 * Strategy for detecting resource exhaustion errors.
 *
 * Handles too many connections, memory limits, disk full, etc.
 */
class ResourceStrategy extends AbstractErrorDetectionStrategy
{
    public function __construct()
    {
        $this->errorCodes = ErrorCodeRegistry::getMergedErrorCodes('resource');

        $this->messagePatterns = [
            'too many connections',
            'out of memory',
            'disk full',
            'no space left',
            'resource limit',
            'memory allocation',
        ];
    }

    public function getExceptionClass(): string
    {
        return ResourceException::class;
    }

    public function getPriority(): int
    {
        return 70; // Higher priority than connection strategy
    }
}
