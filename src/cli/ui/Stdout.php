<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui;

use tommyknocker\pdodb\cli\BaseCliCommand;

/**
 * TUI stdout/stderr access via shared {@see CliOutput}.
 */
final class Stdout
{
    public static function write(string $message): void
    {
        BaseCliCommand::io()->write($message);
    }

    public static function writeln(string $message = ''): void
    {
        BaseCliCommand::io()->writeln($message);
    }
}
