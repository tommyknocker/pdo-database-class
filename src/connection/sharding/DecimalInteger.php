<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection\sharding;

use RuntimeException;

/**
 * Whole numbers of any size, kept as decimal strings, for shard routing.
 *
 * A key such as a BIGINT UNSIGNED id above PHP_INT_MAX must not be cast to int: the cast clamps it and routes the row
 * to the wrong shard. Only plain PHP is used, so no extension is required.
 */
final class DecimalInteger
{
    /**
     * The canonical decimal form of a whole number: no sign for zero, no leading zeros, "-" for negatives.
     *
     * @throws RuntimeException If the value is not numeric or is a float too large to hold a whole number exactly
     */
    public static function normalize(mixed $value): string
    {
        if (is_int($value)) {
            return (string)$value;
        }
        if (is_string($value) && preg_match('/^\s*([+-]?)0*(\d+)\s*$/', $value, $m) === 1) {
            $digits = ltrim($m[2], '0');
            if ($digits === '') {
                return '0';
            }

            return ($m[1] === '-' ? '-' : '') . $digits;
        }
        if (!is_numeric($value)) {
            throw new RuntimeException('Shard key must be numeric, got: ' . gettype($value));
        }

        // Fractions (1.9, "1.9") and floats: the whole part, as (int) always gave
        $float = (float)$value;
        if (abs($float) >= 9.007199254740992E15) {
            throw new RuntimeException("Shard key {$value} is a float too large to route exactly; pass it as an integer or a string");
        }

        return (string)(int)$float;
    }

    /**
     * The remainder of a division by a positive int, with the sign of the dividend like PHP's % operator.
     *
     * @param string $number A value returned by normalize()
     */
    public static function modulo(string $number, int $divisor): int
    {
        $negative = $number[0] === '-';
        $digits = $negative ? substr($number, 1) : $number;

        $remainder = 0;
        foreach (str_split($digits) as $digit) {
            $remainder = ($remainder * 10 + (int)$digit) % $divisor;
        }

        return $negative ? -$remainder : $remainder;
    }

    /**
     * Compare two values returned by normalize(): -1, 0 or 1.
     */
    public static function compare(string $a, string $b): int
    {
        $negA = $a[0] === '-';
        $negB = $b[0] === '-';
        if ($negA !== $negB) {
            return $negA ? -1 : 1;
        }

        $absA = $negA ? substr($a, 1) : $a;
        $absB = $negB ? substr($b, 1) : $b;
        $byMagnitude = strlen($absA) <=> strlen($absB) ?: strcmp($absA, $absB) <=> 0;

        return $negA ? -$byMagnitude : $byMagnitude;
    }
}
