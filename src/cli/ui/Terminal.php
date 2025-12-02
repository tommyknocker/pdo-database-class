<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui;

/**
 * Terminal utility for ANSI escape codes and terminal operations.
 */
class Terminal
{
    /**
     * Clear screen and move cursor to home position.
     */
    public static function clear(): void
    {
        echo "\033[2J\033[H";
    }

    /**
     * Move cursor to specific position.
     *
     * @param int $row Row (1-based)
     * @param int $col Column (1-based)
     */
    public static function moveTo(int $row, int $col): void
    {
        echo "\033[{$row};{$col}H";
    }

    /**
     * Hide cursor.
     */
    public static function hideCursor(): void
    {
        echo "\033[?25l";
    }

    /**
     * Show cursor.
     */
    public static function showCursor(): void
    {
        echo "\033[?25h";
    }

    /**
     * Clear line from cursor to end.
     */
    public static function clearLine(): void
    {
        echo "\033[K";
    }

    /**
     * Clear entire line.
     */
    public static function clearLineFull(): void
    {
        echo "\033[2K";
    }

    /**
     * Reset all formatting.
     */
    public static function reset(): void
    {
        echo "\033[0m";
    }

    /**
     * Set bold text.
     */
    public static function bold(): void
    {
        echo "\033[1m";
    }

    /**
     * Set text color.
     *
     * @param int $color Color code (30-37 for foreground, 40-47 for background)
     */
    public static function color(int $color): void
    {
        echo "\033[{$color}m";
    }

    /**
     * Get terminal size.
     *
     * @return array{0: int, 1: int} [rows, cols]
     */
    public static function getSize(): array
    {
        // Try tput first (most reliable)
        if (PHP_OS_FAMILY !== 'Windows') {
            $lines = @shell_exec('tput lines 2>/dev/null');
            $cols = @shell_exec('tput cols 2>/dev/null');

            if ($lines !== false && $lines !== null && $cols !== false && $cols !== null) {
                $rows = (int)trim($lines);
                $colsInt = (int)trim($cols);
                if ($rows > 0 && $colsInt > 0) {
                    return [$rows, $colsInt];
                }
            }

            // Fallback: try stty
            $stty = @shell_exec('stty size 2>/dev/null');
            if ($stty !== false && $stty !== null && preg_match('/^(\d+)\s+(\d+)$/', trim($stty), $matches)) {
                $rows = (int)$matches[1];
                $cols = (int)$matches[2];
                if ($rows > 0 && $cols > 0) {
                    return [$rows, $cols];
                }
            }
        }

        // Ultimate fallback: standard terminal size
        return [24, 80];
    }

    /**
     * Check if terminal supports colors.
     *
     * @return bool
     */
    public static function supportsColors(): bool
    {
        // Check NO_COLOR environment variable
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        // Check FORCE_COLOR environment variable
        if (getenv('FORCE_COLOR') !== false) {
            return true;
        }

        // Check if stdout is a TTY
        if (!stream_isatty(STDOUT)) {
            return false;
        }

        // On Windows, colors are supported in modern terminals
        if (PHP_OS_FAMILY === 'Windows') {
            return true;
        }

        // Check TERM environment variable
        $term = getenv('TERM');
        if ($term === false || $term === '') {
            return false;
        }

        // Most modern terminals support colors
        return !in_array($term, ['dumb', 'unknown'], true);
    }

    /**
     * Color constants.
     */
    public const COLOR_BLACK = 30;
    public const COLOR_RED = 31;
    public const COLOR_GREEN = 32;
    public const COLOR_YELLOW = 33;
    public const COLOR_BLUE = 34;
    public const COLOR_MAGENTA = 35;
    public const COLOR_CYAN = 36;
    public const COLOR_WHITE = 37;

    public const BG_BLACK = 40;
    public const BG_RED = 41;
    public const BG_GREEN = 42;
    public const BG_YELLOW = 43;
    public const BG_BLUE = 44;
    public const BG_MAGENTA = 45;
    public const BG_CYAN = 46;
    public const BG_WHITE = 47;
}
