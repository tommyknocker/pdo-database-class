<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use RuntimeException;

/**
 * A command gave up with an error that is already reported; {@see Application::run()} turns it into an exit code.
 */
class CliExitException extends RuntimeException
{
}
