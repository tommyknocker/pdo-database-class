<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

use tommyknocker\pdodb\dialects\roles\DialectSqlFunctionsInterface;
use tommyknocker\pdodb\exceptions\UnsupportedOperationException;
use tommyknocker\pdodb\helpers\values\ConcatValue;
use tommyknocker\pdodb\helpers\values\ConfigValue;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Default implementation of the SQL functions and expressions part of the dialect ({@see DialectSqlFunctionsInterface}),
 * shared by every dialect through DialectAbstract.
 */
trait SqlFunctionsDefaults
{
    /**
     * Case-insensitive LIKE expression.
     *
     * @param string $column
     * @param string $pattern
     *
     * @return RawValue
     */
    public function ilike(string $column, string $pattern): RawValue
    {
        return new RawValue("LOWER($column) LIKE LOWER(:pattern)", ['pattern' => $pattern]);
    }

    /**
     * SET/PRAGMA statement syntax.
     * e.g. SET FOREIGN_KEY_CHECKS = 1 OR SET NAMES 'utf8mb4' in MySQL and PostgreSQL or PRAGMA statements in SQLite.
     *
     * @param ConfigValue $value
     *
     * @return RawValue
     */
    public function config(ConfigValue $value): RawValue
    {
        $sql = 'SET ' . strtoupper($value->getValue())
            . ($value->getUseEqualSign() ? ' = ' : ' ')
            . ($value->getQuoteValue() ? '\'' . $value->getParams()[0] . '\'' : $value->getParams()[0]);
        return new RawValue($sql);
    }

    /**
     * Concatenate values.
     *
     * @param ConcatValue $value
     *
     * @return RawValue
     */
    public function concat(ConcatValue $value): RawValue
    {
        $parts = $value->getValues();

        $mapped = [];
        foreach ($parts as $part) {
            if ($part instanceof RawValue) {
                $mapped[] = $part->getValue();
                continue;
            }

            if (is_numeric($part)) {
                $mapped[] = (string)$part;
                continue;
            }

            $mapped[] = $this->concatStringOperand((string)$part);
        }

        // Use dialect-specific concatenation formatting
        $sql = $this->formatConcatExpression($mapped);

        return new RawValue($sql);
    }

    /**
     * Format LEAST expression (default implementation)
     * Override in specific dialects if needed (e.g., SQLite uses MIN).
     *
     * @param array<int, string|int|float|RawValue> $values
     *
     * @return string
     */
    public function formatLeast(array $values): string
    {
        $args = $this->resolveValues($values);
        return 'LEAST(' . implode(', ', $args) . ')';
    }

    /**
     * Format MOD expression (default implementation)
     * Override in specific dialects if needed (e.g., SQLite uses %).
     *
     * @param string|RawValue $dividend
     * @param string|RawValue $divisor
     *
     * @return string
     */
    public function formatMod(string|RawValue $dividend, string|RawValue $divisor): string
    {
        $d1 = $this->resolveValue($dividend);
        $d2 = $this->resolveValue($divisor);
        return "MOD($d1, $d2)";
    }

    /**
     * Format interval expression (default implementation).
     * Should be overridden in specific dialects.
     *
     * @param string|RawValue $expr
     * @param string $value
     * @param string $unit
     * @param bool $isAdd
     *
     * @return string
     */
    public function formatInterval(string|RawValue $expr, string $value, string $unit, bool $isAdd): string
    {
        // Default implementation - should be overridden
        $e = $this->resolveValue($expr);
        $sign = $isAdd ? '+' : '-';
        return "$e $sign INTERVAL $value $unit";
    }

    /**
     * Format GROUP_CONCAT expression.
     *
     * Every dialect spells this differently, so there is no safe default: a MySQL-style fallback would produce
     * invalid SQL on any other database and only fail when it runs.
     *
     * @param string|RawValue $column
     * @param string $separator
     * @param bool $distinct
     *
     * @return string
     */
    public function formatGroupConcat(string|RawValue $column, string $separator, bool $distinct): string
    {
        throw new UnsupportedOperationException(
            static::class . ' does not define formatGroupConcat().',
            0,
            null,
            $this->getDriverName()
        );
    }

    /**
     * Format TRUNCATE expression. There is no portable form, so a dialect must define it.
     *
     * @param string|RawValue $value
     * @param int $precision
     *
     * @return string
     */
    public function formatTruncate(string|RawValue $value, int $precision): string
    {
        throw new UnsupportedOperationException(
            static::class . ' does not define formatTruncate().',
            0,
            null,
            $this->getDriverName()
        );
    }

    /**
     * Format POSITION expression (default implementation).
     * Should be overridden in specific dialects.
     *
     * @param string|RawValue $substring
     * @param string|RawValue $value
     *
     * @return string
     */
    public function formatPosition(string|RawValue $substring, string|RawValue $value): string
    {
        $sub = $substring instanceof RawValue ? $substring->getValue() : $this->quoteStringLiteral((string)$substring);
        $val = $this->resolveValue($value);
        return "POSITION($sub IN $val)";
    }

    /**
     * Format LEFT expression (default implementation).
     *
     * @param string|RawValue $value
     * @param int $length
     *
     * @return string
     */
    public function formatLeft(string|RawValue $value, int $length): string
    {
        $val = $this->resolveValue($value);
        return "LEFT($val, $length)";
    }

    /**
     * Format RIGHT expression (default implementation).
     *
     * @param string|RawValue $value
     * @param int $length
     *
     * @return string
     */
    public function formatRight(string|RawValue $value, int $length): string
    {
        $val = $this->resolveValue($value);
        return "RIGHT($val, $length)";
    }

    /**
     * Format REPEAT expression (default implementation).
     *
     * @param string|RawValue $value
     * @param int $count
     *
     * @return string
     */
    public function formatRepeat(string|RawValue $value, int $count): string
    {
        $val = $value instanceof RawValue ? $value->getValue() : $this->quoteStringLiteral((string)$value);
        return "REPEAT($val, $count)";
    }

    /**
     * Format REVERSE expression (default implementation).
     *
     * @param string|RawValue $value
     *
     * @return string
     */
    public function formatReverse(string|RawValue $value): string
    {
        $val = $this->resolveValue($value);
        return "REVERSE($val)";
    }

    /**
     * Format LPAD/RPAD expression (default implementation).
     *
     * @param string|RawValue $value
     * @param int $length
     * @param string $padString
     * @param bool $isLeft
     *
     * @return string
     */
    public function formatPad(string|RawValue $value, int $length, string $padString, bool $isLeft): string
    {
        $val = $this->resolveValue($value);
        $pad = $this->quoteStringLiteral($padString);
        $func = $isLeft ? 'LPAD' : 'RPAD';
        return "{$func}($val, $length, $pad)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatLike(string $column, string $pattern): string
    {
        // Default implementation: simple LIKE
        return "{$column} LIKE :pattern";
    }

    /**
     * {@inheritDoc}
     */
    public function formatColumnForComparison(string $column): string
    {
        // Default: return column as-is
        return $column;
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeJoinCondition(string $condition): string
    {
        // Default: return condition as-is
        return $condition;
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeColumnKey(string $key): string
    {
        // Default: return key as-is (case-sensitive)
        return $key;
    }

    /**
     * {@inheritDoc}
     */
    public function formatDateOnly(string|RawValue $value): string
    {
        return 'DATE(' . $this->resolveValue($value) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function formatTimeOnly(string|RawValue $value): string
    {
        return 'TIME(' . $this->resolveValue($value) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function formatToTimestamp(string $timestampString, string $format): string
    {
        throw new UnsupportedOperationException(
            'TO_TIMESTAMP is only supported in Oracle. Use date/time string literals for other dialects.',
            0,
            null,
            $this->getDriverName()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function formatToDate(string|RawValue $dateString, string $format): string
    {
        throw new UnsupportedOperationException(
            'TO_DATE is only supported in Oracle. Use date string literals for other dialects.',
            0,
            null,
            $this->getDriverName()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function formatToChar(string|RawValue $value): string
    {
        throw new UnsupportedOperationException(
            'TO_CHAR is only supported in Oracle.',
            0,
            null,
            $this->getDriverName()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getTimestampDefaultExpression(): string
    {
        return 'CURRENT_TIMESTAMP';
    }

    /**
     * {@inheritDoc}
     */
    public function formatConcatExpression(array $parts): string
    {
        // Default implementation uses CONCAT() function
        // SQLite dialect will override to use || operator
        return 'CONCAT(' . implode(', ', $parts) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpLike(string|RawValue $value, string $pattern): string
    {
        throw new UnsupportedOperationException(
            static::class . ' does not define formatRegexpLike().',
            0,
            null,
            $this->getDriverName()
        );
    }
}
