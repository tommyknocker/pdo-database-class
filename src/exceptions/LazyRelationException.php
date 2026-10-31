<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

/**
 * Thrown when lazy loading is disabled and a relation is accessed unexpectedly.
 */
class LazyRelationException extends QueryException
{
    public function getCategory(): string
    {
        return 'lazy_relation';
    }
}
