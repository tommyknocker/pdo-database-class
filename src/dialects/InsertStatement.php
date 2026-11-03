<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

/**
 * Table, column names and value expressions of a single-row `INSERT INTO t (a, b) VALUES (x, y)`, so a dialect can
 * tell whether the statement supplied its own key.
 */
final class InsertStatement
{
    /**
     * @param list<string> $columns
     * @param list<string> $values One expression per column, as written (`:name`, `1 + 1`, `TO_DATE(:a, :b)`)
     * @param string $tail What follows the VALUES list (`ON CONFLICT ...`, `RETURNING ...`)
     * @param int $valuesOffset Where the VALUES keyword starts, for a clause that goes between the columns and the values
     */
    private function __construct(
        public readonly string $table,
        public readonly string $qualifiedTable,
        public readonly array $columns,
        public readonly array $values,
        public readonly string $tail = '',
        public readonly int $valuesOffset = 0,
    ) {
    }

    /**
     * Null for anything that is not a single-row INSERT with a column list.
     */
    public static function parse(string $sql): ?self
    {
        $identifier = '(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|[A-Za-z_][\w$#]*)';
        if (preg_match('/^\s*(?:INSERT\s+(?:(?:OR\s+\w+|IGNORE|LOW_PRIORITY|HIGH_PRIORITY|DELAYED)\s+)*|REPLACE\s+(?:(?:LOW_PRIORITY|DELAYED)\s+)*)INTO\s+(' . $identifier . '(?:\s*\.\s*' . $identifier . ')*)\s*\(([^)]*)\)\s*(VALUES)\s*\(/is', $sql, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $qualified = trim($m[1][0]);
        $parts = preg_split('/\s*\.\s*(?=(?:[^"]*"[^"]*")*[^"]*$)/', $qualified) ?: [$qualified];
        $table = trim((string)end($parts), '"`[] ');
        $columns = array_map(static fn (string $c): string => trim($c, " \t\n\r\"`[]"), explode(',', $m[2][0]));

        // The first row: split at top-level commas (TO_DATE(:a, :b) is one value), skip quoted text, stop at the closing
        // parenthesis. What follows (ON CONFLICT ..., RETURNING ...) is not needed.
        $start = $m[0][1] + strlen($m[0][0]);
        $values = [];
        $depth = 1;
        $current = '';
        $quote = null;
        for ($i = $start, $length = strlen($sql); $i < $length; $i++) {
            $char = $sql[$i];
            if ($quote !== null) {
                $current .= $char;
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($char === ',' && $depth === 1) {
                $values[] = trim($current);
                $current = '';

                continue;
            }
            $current .= $char;
        }
        if ($depth !== 0) {
            return null;
        }
        $values[] = trim($current);

        // a second row, INSERT ... SELECT or anything else we do not read as one row
        if (count($values) !== count($columns) || preg_match('/^\s*,\s*\(/', substr($sql, $i + 1)) === 1) {
            return null;
        }

        return new self($table, $qualified, $columns, $values, substr($sql, $i + 1), $m[3][1]);
    }

    /**
     * The position of a column in the statement's column list (case-insensitive), or null when it was left out.
     */
    public function positionOf(string $column): ?int
    {
        $position = array_search(strtolower($column), array_map('strtolower', $this->columns), true);

        return $position === false ? null : $position;
    }

    /**
     * The whole number the statement supplies for a column, when it is a bound parameter or a numeric literal; an int
     * when it fits, the numeric string otherwise. Null when the column is not set, or is not a whole number.
     *
     * @param array<int|string, mixed> $params
     */
    public function suppliedId(string $column, array $params): int|string|null
    {
        $position = $this->positionOf($column);
        if ($position === null) {
            return null;
        }
        $expression = $this->values[$position];
        $value = str_starts_with($expression, ':') ? ($params[$expression] ?? $params[substr($expression, 1)] ?? null) : $expression;

        if (is_int($value)) {
            return InsertId::from($value);
        }

        return is_string($value) && preg_match('/^-?[0-9]+$/', trim($value)) === 1 ? InsertId::from(ltrim(trim($value), '+')) : null;
    }

    /**
     * The key value the statement supplies for a column, of any type: a bound parameter or a literal number or string.
     * An int when it is a whole number that fits, otherwise the string as sent. Null when the column is not set, is NULL,
     * or is an expression the database evaluates (a function call, a subquery).
     *
     * @param array<int|string, mixed> $params
     */
    public function suppliedKey(string $column, array $params): int|string|null
    {
        $position = $this->positionOf($column);
        if ($position === null) {
            return null;
        }
        $expression = $this->values[$position];
        if (str_starts_with($expression, ':')) {
            $value = $params[$expression] ?? $params[substr($expression, 1)] ?? null;
        } elseif (preg_match("/^'((?:[^']|'')*)'$/", $expression, $m) === 1) {
            $value = str_replace("''", "'", $m[1]);
        } elseif (preg_match('/^-?[0-9]+$/', $expression) === 1) {
            $value = $expression;
        } else {
            return null;
        }

        if (is_int($value)) {
            return InsertId::from($value);
        }
        if (!is_string($value) || $value === '') {
            return null;
        }

        return InsertId::from($value) ?? $value;
    }
}
