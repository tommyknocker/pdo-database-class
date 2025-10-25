<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\traits;

use tommyknocker\pdodb\helpers\values\RawValue;

trait UpsertBuilderTrait
{
    /**
     * Build upsert update expressions for associative array.
     *
     * @param array<int|string, mixed> $updateColumns
     *
     * @return array<int, string>
     */
    protected function buildUpsertExpressions(array $updateColumns, string $tableName = ''): array
    {
        $parts = [];

        foreach ($updateColumns as $col => $expr) {
            $colSql = $this->quoteIdentifier((string)$col);

            if (is_array($expr) && isset($expr['__op'])) {
                $parts[] = $this->buildIncrementExpression($colSql, $expr, $tableName);
            } elseif ($expr instanceof RawValue) {
                $parts[] = $this->buildRawValueExpression($colSql, $expr, $tableName, (string)$col);
            } else {
                $parts[] = $this->buildDefaultExpression($colSql, $expr, (string)$col);
            }
        }

        return $parts;
    }

    /**
     * Build increment/decrement expression.
     * Must be implemented by each dialect.
     */
    abstract protected function buildIncrementExpression(string $colSql, array $expr, string $tableName): string;

    /**
     * Build raw value expression.
     * Must be implemented by each dialect.
     */
    abstract protected function buildRawValueExpression(string $colSql, RawValue $expr, string $tableName, string $col): string;

    /**
     * Build default expression.
     * Must be implemented by each dialect.
     */
    abstract protected function buildDefaultExpression(string $colSql, mixed $expr, string $col): string;

    /**
     * Check if array is associative.
     *
     * @param array<int|string, mixed> $array
     */
    protected function isAssociativeArray(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Safely replace column references in expression.
     */
    protected function replaceColumnReferences(string $expression, string $column, string $replacement): string
    {
        $result = preg_replace_callback(
            '/\b' . preg_quote($column, '/') . '\b/i',
            static function ($matches) use ($expression, $replacement) {
                $pos = strpos($expression, $matches[0]);
                if ($pos === false) {
                    return $matches[0];
                }

                // Check if it's already qualified (has a dot or excluded prefix)
                $left = $pos > 0 ? substr($expression, max(0, $pos - 9), 9) : '';
                if (str_contains($left, '.') || stripos($left, 'excluded') !== false) {
                    return $matches[0];
                }

                return $replacement;
            },
            $expression
        );

        return $result ?? $expression;
    }
}
