<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

/**
 * Invalid profile report operation.
 */
class ProfileReportException extends QueryException
{
    public function getCategory(): string
    {
        return 'profile_report';
    }
}
