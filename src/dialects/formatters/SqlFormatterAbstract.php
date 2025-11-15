<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\formatters;

use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\dialects\traits\JsonPathBuilderTrait;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Abstract base class for SQL formatters.
 * Provides common functionality and access to dialect methods.
 */
abstract class SqlFormatterAbstract implements SqlFormatterInterface
{
    use JsonPathBuilderTrait;

    protected DialectInterface $dialect;

    public function __construct(DialectInterface $dialect)
    {
        $this->dialect = $dialect;
    }

    /**
     * Quote identifier using dialect's method.
     */
    protected function quoteIdentifier(string $name): string
    {
        return $this->dialect->quoteIdentifier($name);
    }

    /**
     * Resolve RawValue or return value as-is.
     */
    protected function resolveValue(string|RawValue $value): string
    {
        return $value instanceof RawValue ? $value->getValue() : $value;
    }

    /**
     * Resolve array of values (handle RawValue instances).
     *
     * @param array<int|string, mixed> $values
     *
     * @return array<int|string, mixed>
     */
    protected function resolveValues(array $values): array
    {
        return array_map(fn ($v) => $v instanceof RawValue ? $v->getValue() : $v, $values);
    }

    /**
     * Format default value for IFNULL/COALESCE.
     */
    protected function formatDefaultValue(mixed $default): string
    {
        if ($default instanceof RawValue) {
            return $default->getValue();
        }
        if (is_string($default)) {
            return "'{$default}'";
        }
        return (string)$default;
    }

    /**
     * Normalize JSON path input.
     *
     * @param array<int, string|int>|string $path
     *
     * @return array<int, string|int>
     */
    protected function normalizeJsonPath(array|string $path): array
    {
        if (is_string($path)) {
            $path = trim($path);
            if ($path === '' || $path === '$') {
                return [];
            }
            return explode('.', $path);
        }
        return array_values($path);
    }
}
