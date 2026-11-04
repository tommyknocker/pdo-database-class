<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

/**
 * A dialect that chooses the PDO type a parameter is bound with.
 *
 * Without it the connection binds `null` as NULL, an `int` as an integer, a `bool` as a boolean and everything else as a
 * string: the database then sees the type the caller passed. A dialect implements this interface only when its database
 * needs something else. It is separate from DialectInterface so that existing dialects do not have to implement it.
 */
interface DialectParameterTypesInterface
{
    /**
     * One of the `PDO::PARAM_*` constants for the value.
     */
    public function pdoParameterType(mixed $value): int;
}
