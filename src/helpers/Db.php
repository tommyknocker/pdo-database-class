<?php

namespace tommyknocker\pdodb\helpers;

/**
 * Database helpers
 */
class Db
{
    /**
     * Returns a raw value.
     *
     * @param string $sql The SQL to execute.
     * @param array $params The parameters to bind to the SQL.
     * @return RawValue The raw value.
     */
    public static function raw(string $sql, array $params = []): RawValue
    {
        return new RawValue($sql, $params);
    }

    /**
     * Returns a NowValue instance representing the current timestamp with an optional difference.
     *
     * @param string|null $diff An optional time difference (e.g., '+1 day', '-2 hours').
     * @return NowValue The NowValue instance.
     */
    public static function now(?string $diff = null): NowValue
    {
        return new NowValue($diff);
    }

    /**
     * Returns an array with an increment operation.
     *
     * @param int|float $num The number to increment by.
     * @return array The array with the increment operation.
     */
    public static function inc(int|float $num = 1): array
    {
        return ['__op' => 'inc', 'val' => $num];
    }

    /**
     * Returns an array with a decrement operation.
     *
     * @param int|float $num The number to decrement by.
     * @return array The array with the decrement operation.
     */
    public static function dec(int|float $num = 1): array
    {
        return ['__op' => 'dec', 'val' => $num];
    }
}