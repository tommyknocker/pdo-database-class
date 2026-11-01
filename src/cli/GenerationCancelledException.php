<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use RuntimeException;

/**
 * Thrown by a generator when the user declines to overwrite an existing file.
 *
 * It is a normal outcome, not a failure: the command that called the generator ends with exit code 0.
 */
final class GenerationCancelledException extends RuntimeException
{
}
