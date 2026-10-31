<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mssql;

/**
 * Quotes reserved-word identifiers in simple CASE/WHEN comparison expressions for MSSQL.
 */
final class MssqlCaseWhenConditionQuoter
{
    /**
     * Quote identifiers in a single WHEN condition fragment.
     */
    public static function quoteCondition(string $condition): string
    {
        $mssqlReservedWords = self::reservedWords();
        $sqlKeywords = self::sqlKeywords();
        $pattern = '/\b([a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?)\s*(=|!=|<>|<|>|<=|>=|LIKE|ILIKE|IN|NOT\s+IN|IS|IS\s+NOT)\s*/i';

        $result = preg_replace_callback(
            $pattern,
            static function (array $matches) use ($mssqlReservedWords, $sqlKeywords): string {
                $identifier = $matches[1];
                $operator = $matches[2];

                if (preg_match('/^\[.*\]$/', $identifier) || preg_match('/^".*"$/', $identifier)) {
                    return $matches[0];
                }

                $identifierLower = strtolower($identifier);
                if (in_array($identifierLower, $sqlKeywords, true)) {
                    return $matches[0];
                }

                $parts = explode('.', $identifier);
                $needsQuoting = false;
                foreach ($parts as $part) {
                    $partLower = strtolower($part);
                    if (in_array($partLower, $mssqlReservedWords, true) && !in_array($partLower, $sqlKeywords, true)) {
                        $needsQuoting = true;
                        break;
                    }
                }

                if (!$needsQuoting) {
                    return $matches[0];
                }

                $quotedParts = array_map(
                    static fn (string $part): string => '[' . str_replace(']', ']]', $part) . ']',
                    $parts
                );

                return implode('.', $quotedParts) . ' ' . $operator . ' ';
            },
            $condition
        );

        return $result ?? $condition;
    }

    /**
     * @return list<string>
     */
    private static function reservedWords(): array
    {
        return [
            'plan', 'order', 'group', 'user', 'table', 'index', 'key', 'value',
            'select', 'insert', 'update', 'delete', 'create', 'drop', 'alter',
            'where', 'from', 'join', 'inner', 'left', 'right', 'outer', 'on',
            'as', 'case', 'when', 'then', 'else', 'end', 'if', 'null', 'not',
            'like', 'in', 'exists', 'between', 'is', 'distinct', 'by', 'having',
            'union', 'intersect', 'except', 'all', 'any', 'some', 'over',
            'partition', 'rows', 'range', 'preceding', 'following', 'current',
            'row', 'unbounded', 'first', 'last', 'rank', 'dense_rank', 'row_number',
        ];
    }

    /**
     * @return list<string>
     */
    private static function sqlKeywords(): array
    {
        return [
            'and', 'or', 'not', 'is', 'null', 'true', 'false', 'default',
            'like', 'in', 'exists', 'between', 'distinct', 'all', 'any', 'some',
            'as', 'case', 'when', 'then', 'else', 'end', 'if', 'cast', 'convert',
        ];
    }
}
