<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

/**
 * A dialect that compares a value inside a JSON document with a number or a boolean as that type.
 *
 * Without it a JSON value is read as text, so `age > 27` is `'5' > '27'` and picks the row with 5, and `ok = true` compares
 * the text `'true'` with `'1'` and matches the number 1. The expression a dialect returns reads the value at the path as a
 * number (or a boolean), and as NULL when it is not one (a string, null, a missing key), so such rows do not match and no
 * row fails to convert. It is separate from DialectJsonInterface so that existing dialects do not have to implement it.
 *
 * The path is not empty in both methods: the caller compares the whole document as text.
 */
interface DialectJsonTypedComparisonInterface
{
    /**
     * The condition `<value at path as a number> <operator> <placeholder>`.
     *
     * @param array<int, string|int>|string $path
     */
    public function formatJsonNumberComparison(string $col, array|string $path, string $operator, string $placeholder): string;

    /**
     * The condition `<value at path as a boolean> <operator> <placeholder>`; the placeholder holds a boolean.
     *
     * @param array<int, string|int>|string $path
     */
    public function formatJsonBooleanComparison(string $col, array|string $path, string $operator, string $placeholder): string;
}
