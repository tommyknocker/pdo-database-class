<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

/**
 * How an id that the database reported is handed to the caller.
 *
 * PDO and the catalogs answer with strings, because a driver cannot know whether an id is a number or fits an int.
 * A whole number that fits becomes an int; one that does not (a BIGINT UNSIGNED above PHP_INT_MAX, a NUMBER(28))
 * stays a string, because casting it would silently return PHP_INT_MAX. '', '0', false and null mean no id.
 */
final class InsertId
{
    public static function from(string|int|float|false|null $raw): int|string|null
    {
        if ($raw === false || $raw === null || $raw === '' || $raw === '0' || $raw === 0) {
            return null;
        }
        if (is_int($raw)) {
            return $raw;
        }
        if (is_float($raw)) {
            // A driver that hands a large NUMBER over as float has already lost digits: do not present it as exact
            $raw = sprintf('%.0f', $raw);
        }
        if (preg_match('/^-?[1-9][0-9]*$/', $raw) === 1 && (string)(int)$raw === $raw) {
            return (int)$raw;
        }

        return $raw;
    }
}
