<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\roles;

/**
 * A dialect that compares a value inside a JSON document with a number as a number.
 *
 * Without it a JSON value is read as text, so `age > 27` is `'5' > '27'` and picks the row with 5. The expression a dialect
 * returns reads the value at the path as a number, and as NULL when it is not one (a string, a boolean, null, a missing
 * key), so such rows do not match and no row fails to convert. It is separate from DialectJsonInterface so that existing
 * dialects do not have to implement it.
 */
interface DialectJsonNumberComparisonInterface
{
    /**
     * The condition `<value at path as a number> <operator> <placeholder>`. The path is not empty: the whole document is
     * compared as text by the caller.
     *
     * @param array<int, string|int>|string $path
     */
    public function formatJsonNumberComparison(string $col, array|string $path, string $operator, string $placeholder): string;
}
