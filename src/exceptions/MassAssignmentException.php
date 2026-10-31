<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

/**
 * Thrown when mass assignment runs in safe mode without a declared allow list.
 */
class MassAssignmentException extends QueryException
{
    public function getCategory(): string
    {
        return 'mass_assignment';
    }
}
