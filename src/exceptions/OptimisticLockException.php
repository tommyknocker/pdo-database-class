<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

/** The row was changed, deleted or became unavailable since it was read. */
class OptimisticLockException extends QueryException
{
}
