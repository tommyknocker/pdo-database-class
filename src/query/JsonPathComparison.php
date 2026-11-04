<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use tommyknocker\pdodb\dialects\roles\DialectJsonInterface;
use tommyknocker\pdodb\dialects\roles\DialectJsonNumberComparisonInterface;
use tommyknocker\pdodb\dialects\traits\JsonPathParser;
use tommyknocker\pdodb\query\interfaces\ParameterManagerInterface;

/**
 * `<value at a JSON path> <operator> <value>` as SQL, with the value registered as a parameter.
 *
 * A number is compared as a number when the dialect can read the JSON value as one ({@see DialectJsonNumberComparisonInterface}).
 * Anything else is compared as text, which is what the dialect extracts a JSON value as: so the parameter is text too.
 */
final class JsonPathComparison
{
    /**
     * @param array<int, string|int>|string $path
     */
    public static function sql(
        DialectJsonInterface $dialect,
        ParameterManagerInterface $parameters,
        string $paramName,
        string $column,
        array|string $path,
        string $operator,
        mixed $value
    ): string {
        $wholeDocument = is_array($path) ? $path === [] : JsonPathParser::parse($path) === [];
        if (!$wholeDocument && (is_int($value) || is_float($value)) && $dialect instanceof DialectJsonNumberComparisonInterface) {
            return $dialect->formatJsonNumberComparison($column, $path, $operator, $parameters->addParam($paramName, $value));
        }

        $placeholder = $parameters->addParam($paramName, is_scalar($value) ? (string)$value : $value);

        return $dialect->formatJsonGet($column, $path) . " {$operator} {$placeholder}";
    }
}
