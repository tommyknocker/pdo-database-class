<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions\strategies;

use tommyknocker\pdodb\exceptions\AuthenticationException;
use tommyknocker\pdodb\exceptions\registry\ErrorCodeRegistry;

/**
 * Strategy for detecting authentication and authorization errors.
 *
 * Handles invalid credentials, insufficient permissions, etc.
 */
class AuthenticationStrategy extends AbstractErrorDetectionStrategy
{
    public function __construct()
    {
        $this->errorCodes = ErrorCodeRegistry::getMergedErrorCodes('authentication');

        $this->messagePatterns = [
            'access denied',
            'authentication failed',
            'invalid password',
            'unknown user',
            'permission denied',
            'insufficient privilege',
            'login failed for user',
            'ora-01017',
            'ora-01045',
            'ora-28000',
            'ora-28001',
        ];
    }

    public function getExceptionClass(): string
    {
        return AuthenticationException::class;
    }

    public function getPriority(): int
    {
        return 20;
    }
}
