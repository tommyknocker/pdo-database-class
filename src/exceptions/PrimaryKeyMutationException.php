<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

/**
 * Thrown when an existing model tries to change its primary key in memory.
 */
class PrimaryKeyMutationException extends QueryException
{
    public function getCategory(): string
    {
        return 'primary_key_mutation';
    }
}
