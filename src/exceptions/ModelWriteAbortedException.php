<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\exceptions;

use RuntimeException;

/**
 * Internal signal that a model write stopped without throwing.
 *
 * Transactional save and delete convert this into a rolled-back transaction
 * and a false return value. Callers of save() and delete() do not see it.
 *
 * @internal
 */
class ModelWriteAbortedException extends RuntimeException
{
}
