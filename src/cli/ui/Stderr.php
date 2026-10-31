<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui;

use tommyknocker\pdodb\cli\BaseCliCommand;

/**
 * TUI stderr access via shared {@see CliOutput}.
 */
final class Stderr
{
    public static function write(string $message): void
    {
        BaseCliCommand::io()->writeError($message);
    }

    public static function writeErrorLine(string $message = ''): void
    {
        BaseCliCommand::io()->writeErrorLine($message);
    }
}
