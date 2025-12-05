<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui;

/**
 * Input handler for non-blocking keyboard input.
 */
class InputHandler
{
    /**
     * Read a key from stdin (non-blocking).
     *
     * @param int $timeoutMicroseconds Timeout in microseconds (default: 100000 = 0.1s)
     *
     * @return string|null Key pressed or null if timeout
     */
    public static function readKey(int $timeoutMicroseconds = 100000): ?string
    {
        // Check if stdin is available
        if (!stream_isatty(STDIN)) {
            return null;
        }

        $read = [STDIN];
        $write = [];
        $except = [];

        // Non-blocking select with timeout
        $result = @stream_select($read, $write, $except, 0, $timeoutMicroseconds);

        if ($result === false || $result === 0) {
            return null;
        }

        // Read first character
        $char = @fread(STDIN, 1);
        if ($char === false || $char === '' || strlen($char) === 0) {
            return null;
        }

        $charCode = ord($char);

        // Handle escape sequences (arrow keys, etc.)
        if ($charCode === 27) { // ESC
            // Read next characters with small timeout
            $seq = '';
            $read = [STDIN];
            $write = [];
            $except = [];
            // Wait up to 50ms for escape sequence
            if (@stream_select($read, $write, $except, 0, 50000) > 0) {
                // Read first 2 characters (most sequences need only 2: [A, [B, etc.)
                $seq = @fread(STDIN, 2);
                if ($seq === false) {
                    $seq = '';
                }

                // If sequence starts with [, read more characters for special keys
                if (strlen($seq) >= 2 && $seq[0] === '[') {
                    $secondChar = $seq[1];
                    // PageUp/PageDown: [5~ or [6~
                    if ($secondChar === '5' || $secondChar === '6') {
                        $read = [STDIN];
                        $write = [];
                        $except = [];
                        if (@stream_select($read, $write, $except, 0, 10000) > 0) {
                            $additional = @fread(STDIN, 1);
                            if ($additional !== false) {
                                $seq .= $additional;
                            }
                        }
                    }
                    // F-keys: [11~, [12~, [13~, [14~, [15~, etc. or [1;2P, [1;2Q, etc.
                    if ($secondChar === '1') {
                        $read = [STDIN];
                        $write = [];
                        $except = [];
                        if (@stream_select($read, $write, $except, 0, 10000) > 0) {
                            // Try to read up to 3 more characters
                            $additional = @fread(STDIN, 3);
                            if ($additional !== false) {
                                $seq .= $additional;
                            }
                        }
                    }
                    // F-keys: [20~, [21~, [23~ (F10-F12)
                    if ($secondChar === '2') {
                        $read = [STDIN];
                        $write = [];
                        $except = [];
                        if (@stream_select($read, $write, $except, 0, 10000) > 0) {
                            $additional = @fread(STDIN, 2);
                            if ($additional !== false) {
                                $seq .= $additional;
                            }
                        }
                    }
                }
                // F-keys: ESCOP (F1), ESCOQ (F2), ESCOR (F3), ESCOS (F4)
                if (strlen($seq) >= 1 && $seq[0] === 'O') {
                    $read = [STDIN];
                    $write = [];
                    $except = [];
                    if (@stream_select($read, $write, $except, 0, 10000) > 0) {
                        $additional = @fread(STDIN, 1);
                        if ($additional !== false) {
                            $seq .= $additional;
                        }
                    }
                }
            }

            if ($seq === '') {
                return 'esc';
            }

            return self::parseEscapeSequence($seq);
        }

        // Handle special characters
        if ($charCode === 10 || $charCode === 13) {
            return 'enter';
        }

        if ($charCode === 9) {
            return 'tab';
        }

        if ($charCode === 127 || $charCode === 8) {
            return 'backspace';
        }

        // Return lowercase for consistency
        return strtolower($char);
    }

    /**
     * Parse escape sequence for arrow keys and special keys.
     *
     * @param string $seq Escape sequence (2 characters after ESC)
     *
     * @return string Key name
     */
    protected static function parseEscapeSequence(string $seq): string
    {
        if (strlen($seq) < 2) {
            return 'esc';
        }

        // F-keys: ESCOP (F1), ESCOQ (F2), ESCOR (F3), ESCOS (F4) - check first
        if ($seq[0] === 'O') {
            $fKey = $seq[1];
            $fMap = ['P' => 1, 'Q' => 2, 'R' => 3, 'S' => 4];
            if (isset($fMap[$fKey])) {
                return 'f' . $fMap[$fKey];
            }
        }

        // Arrow keys and special keys: ESC[...
        if ($seq[0] === '[') {
            // PageUp: ESC[5~ (standard) - 3 characters
            if (strlen($seq) >= 3 && $seq[1] === '5' && $seq[2] === '~') {
                return 'pageup';
            }
            // PageDown: ESC[6~ (standard) - 3 characters
            if (strlen($seq) >= 3 && $seq[1] === '6' && $seq[2] === '~') {
                return 'pagedown';
            }
            // F-keys: ESC[11~, ESC[12~, etc. (F1-F9)
            if (strlen($seq) >= 4 && $seq[1] === '1' && $seq[3] === '~') {
                $fNum = (int)$seq[2];
                if ($fNum >= 1 && $fNum <= 9) {
                    return 'f' . $fNum;
                }
            }
            // F-keys: ESC[1;2P, ESC[1;2Q, etc. (alternative format)
            if (strlen($seq) >= 5 && $seq[1] === '1' && $seq[2] === ';' && $seq[3] === '2') {
                $fKey = $seq[4];
                $fMap = ['P' => 1, 'Q' => 2, 'R' => 3, 'S' => 4];
                if (isset($fMap[$fKey])) {
                    return 'f' . $fMap[$fKey];
                }
            }
            // F-keys: ESC[20~, ESC[21~, ESC[23~ (F10-F12)
            if (strlen($seq) >= 5 && $seq[1] === '2' && $seq[4] === '~') {
                $secondDigit = (int)$seq[2];
                $thirdDigit = (int)$seq[3];
                if ($secondDigit === 0 && $thirdDigit >= 0 && $thirdDigit <= 2) {
                    $fNum = 10 + $thirdDigit;
                    return 'f' . $fNum;
                }
            }

            // Handle extended sequences (e.g., ESC[1;5A for Ctrl+Up)
            if (strlen($seq) > 2 && $seq[1] === '1' && $seq[2] === ';') {
                $rest = @fread(STDIN, 2);
                if ($rest !== false) {
                    return self::parseEscapeSequence('[' . $rest);
                }
            }

            // Check for arrow keys (2 characters: [A, [B, [C, [D)
            if (strlen($seq) === 2) {
                switch ($seq[1]) {
                    case 'A':
                        return 'up';
                    case 'B':
                        return 'down';
                    case 'C':
                        return 'right';
                    case 'D':
                        return 'left';
                    case 'H':
                        return 'home';
                    case 'F':
                        return 'end';
                }
            }
        }

        // ESC key
        if ($seq[0] === 'O') {
            return 'esc';
        }

        return 'esc';
    }

    /**
     * Wait for a specific key press (blocking).
     *
     * @param array<string> $allowedKeys Allowed keys (empty = any key)
     *
     * @return string Key pressed
     */
    public static function waitForKey(array $allowedKeys = []): string
    {
        while (true) {
            $key = self::readKey(1000000); // 1 second timeout, but will loop

            if ($key === null) {
                continue;
            }

            if (empty($allowedKeys) || in_array($key, $allowedKeys, true)) {
                return $key;
            }
        }
    }

    /**
     * Check if a key is available (non-blocking check).
     *
     * @return bool True if key is available
     */
    public static function hasKey(): bool
    {
        if (!stream_isatty(STDIN)) {
            return false;
        }

        $read = [STDIN];
        $write = [];
        $except = [];

        $result = @stream_select($read, $write, $except, 0, 0);

        return $result !== false && $result > 0;
    }
}
