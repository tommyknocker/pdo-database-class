<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

/**
 * Splits a string JSON path such as `$.a.b[0].c` into its segments.
 */
final class JsonPathParser
{
    /**
     * @return array<int, string>
     */
    public static function parse(string $path): array
    {
        $path = trim($path);
        if ($path === '' || $path === '$') {
            return [];
        }
        if ($path[0] === '$') {
            $path = substr($path, 1);
        }

        preg_match_all('/\[(\d+)\]|([^.\[\]]+)/', $path, $matches, PREG_SET_ORDER);
        $parts = [];
        foreach ($matches as $match) {
            $parts[] = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
        }

        return $parts;
    }
}
