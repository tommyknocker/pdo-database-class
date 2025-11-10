<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\helpers\traits;

use tommyknocker\pdodb\helpers\values\AsValue;
use tommyknocker\pdodb\helpers\values\ConfigValue;
use tommyknocker\pdodb\helpers\values\EscapeValue;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Trait for basic database operations.
 */
trait CoreHelpersTrait
{
    /**
     * Returns a raw value.
     *
     * @param string $sql The SQL to execute.
     * @param array<int|string, string|int|float|bool|null> $params The parameters to bind to the SQL.
     *
     * @return RawValue The raw value.
     */
    public static function raw(string $sql, array $params = []): RawValue
    {
        return new RawValue($sql, $params);
    }

    /**
     * Escapes a string for use in a SQL query.
     *
     * @param string $str The string to escape.
     *
     * @return EscapeValue The EscapeValue instance
     */
    public static function escape(string $str): EscapeValue
    {
        return new EscapeValue($str);
    }

    /**
     * Returns a ConfigValue instance representing a SET statement.
     * e.g. SET FOREIGN_KEY_CHECKS = 1 OR SET NAMES 'utf8mb4' in MySQL and PostgreSQL or PRAGMA statements in SQLite.
     *
     * @param string $key The column name.
     * @param mixed $value The value to set.
     * @param bool $useEqualSign Whether to use an equal sign (=) in the statement. Default is true.
     * @param bool $quoteValue Whether to quote value or not.
     *
     * @return ConfigValue The ConfigValue instance for the SET/PRAGMA operation.
     */
    public static function config(
        string $key,
        mixed $value,
        bool $useEqualSign = true,
        bool $quoteValue = false
    ): ConfigValue {
        return new ConfigValue($key, $value, $useEqualSign, $quoteValue);
    }

    /**
     * Returns an AsValue instance for creating column aliases.
     * Useful in SELECT clauses: select(['level' => Db::as(0, 'level')])
     * or select(['level' => Db::as(Db::add('ct.level', 1), 'level')]).
     *
     * @param string|int|float|RawValue $value The value to alias.
     * @param string $alias The alias name.
     *
     * @return AsValue The AsValue instance.
     */
    public static function as(string|int|float|RawValue $value, string $alias): AsValue
    {
        return new AsValue($value, $alias);
    }
}
