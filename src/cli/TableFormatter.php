<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

/**
 * Lays rows out as text tables for command output. Widths are measured in characters, not bytes, so text with
 * accents or CJK letters stays aligned.
 */
final class TableFormatter
{
    /**
     * A cell as text.
     */
    public static function cell(mixed $value, string $null = '(null)'): string
    {
        if ($value === null) {
            return $null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return $encoded !== false ? $encoded : '(invalid json)';
        }

        return (string)$value;
    }

    /**
     * Rows between horizontal rules, with `|` separators, narrowed to $maxWidth by cutting long cells.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return list<string> lines, without a title and without the row count
     */
    public static function bordered(array $rows, int $maxWidth = 80): array
    {
        if ($rows === []) {
            return [];
        }

        $columns = array_map('strval', array_keys($rows[0]));
        if ($columns === []) {
            return [];
        }

        $widths = self::widths($rows, $columns, '(null)');
        $total = array_sum($widths) + (count($columns) - 1) * 3 + 4; // " | " between columns, "| " and " |" at the ends
        // Take the excess from the widest columns first, so short ones stay readable; a column keeps at least 3 characters
        while ($total > $maxWidth) {
            $widest = array_key_first($widths);
            foreach ($widths as $column => $width) {
                if ($width > $widths[$widest]) {
                    $widest = $column;
                }
            }
            if ($widest === null || $widths[$widest] <= 3) {
                break;
            }
            $widths[$widest]--;
            $total--;
        }
        $rule = $total;

        $lines = [str_repeat('=', $rule)];
        $lines[] = '| ' . implode(' | ', array_map(
            static fn (string $column): string => self::pad($column, $widths[$column]),
            $columns
        )) . ' |';
        $lines[] = str_repeat('-', $rule);
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $cells[] = self::pad(mb_substr(self::cell($row[$column] ?? null), 0, $widths[$column]), $widths[$column]);
            }
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }
        $lines[] = str_repeat('=', $rule);

        return $lines;
    }

    /**
     * Rows under a header and a dashed line, indented, with every column that appears in any row.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return list<string>
     */
    public static function plain(array $rows, string $indent = '  '): array
    {
        if ($rows === []) {
            return [];
        }

        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                $column = (string)$column;
                if (!in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }

        $widths = self::widths($rows, $columns, '');
        $header = $indent;
        $rule = $indent;
        foreach ($columns as $column) {
            $header .= self::pad($column, $widths[$column]) . '  ';
            $rule .= str_repeat('-', $widths[$column]) . '  ';
        }

        $lines = [$header, $rule];
        foreach ($rows as $row) {
            $line = $indent;
            foreach ($columns as $column) {
                $line .= self::pad(self::cell($row[$column] ?? null, ''), $widths[$column]) . '  ';
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param list<string> $columns
     *
     * @return array<string, int>
     */
    private static function widths(array $rows, array $columns, string $null): array
    {
        $widths = [];
        foreach ($columns as $column) {
            $widths[$column] = mb_strlen($column);
            foreach ($rows as $row) {
                $widths[$column] = max($widths[$column], mb_strlen(self::cell($row[$column] ?? null, $null)));
            }
        }

        return $widths;
    }

    private static function pad(string $text, int $width): string
    {
        return $text . str_repeat(' ', max(0, $width - mb_strlen($text)));
    }
}
