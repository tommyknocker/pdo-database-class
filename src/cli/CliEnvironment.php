<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

/**
 * Describes the terminal the CLI runs in.
 */
final class CliEnvironment
{
    /**
     * Whether there is nobody to ask: PDODB_NON_INTERACTIVE is set, or the stream is not a terminal.
     *
     * @param resource|null $stream Stream that has to be a terminal (STDIN by default)
     */
    public static function isNonInteractive($stream = null): bool
    {
        if (getenv('PDODB_NON_INTERACTIVE') !== false) {
            return true;
        }

        return !stream_isatty($stream ?? STDIN);
    }
}
