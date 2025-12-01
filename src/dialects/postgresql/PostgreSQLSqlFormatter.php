<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\postgresql;

use tommyknocker\pdodb\dialects\formatters\SqlFormatterAbstract;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * PostgreSQL SQL formatter implementation.
 */
class PostgreSQLSqlFormatter extends SqlFormatterAbstract
{
    /**
     * {@inheritDoc}
     */
    public function formatLimitOffset(string $sql, ?int $limit, ?int $offset): string
    {
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
        if ($offset !== null) {
            $sql .= ' OFFSET ' . (int)$offset;
        }
        return $sql;
    }

    /**
     * {@inheritDoc}
     *
     * @param array<int|string, mixed> $options
     */
    public function formatSelectOptions(string $sql, array $options): string
    {
        $tail = [];
        foreach ($options as $opt) {
            $u = strtoupper(trim($opt));
            // PG supports FOR UPDATE / FOR SHARE as tail options
            if (in_array($u, ['FOR UPDATE', 'FOR SHARE', 'FOR NO KEY UPDATE', 'FOR KEY SHARE'])) {
                $tail[] = $opt;
            }
        }
        if ($tail) {
            $sql .= ' ' . implode(' ', $tail);
        }
        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function formatUnionSelect(string $selectSql, bool $isBaseQuery = false): string
    {
        // PostgreSQL doesn't require parentheses in UNION
        return $selectSql;
    }

    /**
     * {@inheritDoc}
     */
    public function needsUnionParentheses(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonGet(string $col, array|string $path, bool $asText = true): string
    {
        $parts = $this->normalizeJsonPath($path);
        if (empty($parts)) {
            return $asText ? $this->quoteIdentifier($col) . '::text' : $this->quoteIdentifier($col);
        }
        $arr = $this->buildPostgreSqlJsonPath($parts);
        // build #> or #>> with array literal
        if ($asText) {
            return $this->quoteIdentifier($col) . ' #>> ' . $arr;
        }
        return $this->quoteIdentifier($col) . ' #> ' . $arr;
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonContains(string $col, mixed $value, array|string|null $path = null): array|string
    {
        $colQuoted = $this->quoteIdentifier($col);
        $parts = $this->normalizeJsonPath($path ?? []);
        $paramJson = $this->generateParameterName('jsonc', $col);
        $json = $this->encodeToJson($value);

        if (empty($parts)) {
            $sql = "{$colQuoted}::jsonb @> {$paramJson}::jsonb";
            return [$sql, [$paramJson => $json]];
        }

        $arr = $this->buildPostgreSqlJsonPath($parts);
        $extracted = "{$colQuoted}::jsonb #> {$arr}";

        if (is_string($value)) {
            // Avoid using the '?' operator because PDO treats '?' as positional placeholder.
            // Use EXISTS with jsonb_array_elements_text to check presence of string element.
            $tokenParam = $this->generateParameterName('jsonc_token', $col);
            $sql = "EXISTS (SELECT 1 FROM jsonb_array_elements_text({$extracted}) AS _e(val) WHERE _e.val = {$tokenParam})";
            return [$sql, [$tokenParam => (string)$value]];
        }

        // non-string scalar or array/object: use jsonb containment against extracted node
        $sql = "{$extracted} @> {$paramJson}::jsonb";
        return [$sql, [$paramJson => $json]];
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonSet(string $col, array|string $path, mixed $value): array
    {
        $parts = $this->normalizeJsonPath($path);
        $colQuoted = $this->quoteIdentifier($col);

        // build pg path for full path and for parent path
        $pgPath = $this->buildPostgreSqlJsonbPath($parts);
        if (count($parts) > 1) {
            $parentParts = $this->getParentPathParts($parts);
            $pgParentPath = $this->buildPostgreSqlJsonbPath($parentParts);
            $lastKey = $this->getLastSegment($parts);
        } else {
            $pgParentPath = null;
            $lastKey = $parts[0];
        }

        $param = $this->generateParameterName('jsonset', $col . '|' . $pgPath);

        // produce json text to bind exactly once (use as-is if it already looks like JSON)
        if (is_string($value)) {
            $jsonText = $this->looksLikeJson($value) ? $value : $this->encodeToJson($value);
        } else {
            $jsonText = $this->encodeToJson($value);
        }

        // If there is a parent path, first ensure parent exists as object: set(parentPath, COALESCE(col->parent, {}))
        // Then set the final child inside that parent with jsonb_set(..., fullPath, to_jsonb(CAST(:param AS json)), true)
        if ($pgParentPath !== null) {
            $ensureParent = "jsonb_set({$colQuoted}::jsonb, '{$pgParentPath}', COALESCE({$colQuoted}::jsonb #> '{$pgParentPath}', '{}'::jsonb), true)";
            $expr = "jsonb_set({$ensureParent}, '{$pgPath}', to_jsonb(CAST({$param} AS json)), true)::json";
        } else {
            // single segment: just set directly
            $expr = "jsonb_set({$colQuoted}::jsonb, '{$pgPath}', to_jsonb(CAST({$param} AS json)), true)::json";
        }

        return [$expr, [$param => $jsonText]];
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonRemove(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $colQuoted = $this->quoteIdentifier($col);

        // Build pg path array literal for functions that need it
        $pgPathArr = $this->buildPostgreSqlJsonbPath($parts);

        // If last segment is numeric -> treat as array index: set that element to JSON null (preserve indices)
        $last = $this->getLastSegment($parts);
        if ($this->isNumericIndex($last)) {
            // Use jsonb_set to assign JSON null at exact path; create parents if needed
            return "jsonb_set({$colQuoted}::jsonb, '{$pgPathArr}', 'null'::jsonb, true)::jsonb";
        }

        // Otherwise treat as object key removal: use #- operator which removes key at path (works for nested paths)
        // Operator expects text[] on right-hand side; use the same pgPathArr
        // Return expression only (no "col ="), QueryBuilder will use: SET "meta" = <expr>
        return "({$colQuoted}::jsonb #- '{$pgPathArr}')::jsonb";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonReplace(string $col, array|string $path, mixed $value): array
    {
        $parts = $this->normalizeJsonPath($path);
        $colQuoted = $this->quoteIdentifier($col);
        $pgPath = $this->buildPostgreSqlJsonbPath($parts);

        $param = $this->generateParameterName('jsonreplace', $col . '|' . $pgPath);

        if (is_string($value)) {
            $jsonText = $this->looksLikeJson($value) ? $value : $this->encodeToJson($value);
        } else {
            $jsonText = $this->encodeToJson($value);
        }

        // jsonb_set with create_missing=false only replaces if path exists
        $expr = "jsonb_set({$colQuoted}::jsonb, '{$pgPath}', to_jsonb(CAST({$param} AS json)), false)::json";

        return [$expr, [$param => $jsonText]];
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonExists(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $colQuoted = $this->quoteIdentifier($col);

        // Build jsonpath for jsonb_path_exists
        $jsonPath = $this->buildJsonPathString($parts);

        $checks = ["jsonb_path_exists({$colQuoted}::jsonb, '{$jsonPath}')"];

        // Build prefix existence checks without using the ? operator to avoid PDO positional placeholder issues
        $prefixExpr = $colQuoted . '::jsonb';
        $accum = [];
        foreach ($parts as $i => $p) {
            $isIndex = $this->isNumericIndex($p);
            if ($isIndex) {
                $idx = (int)$p;
                $lenCheck = "(jsonb_typeof({$prefixExpr}) = 'array' AND jsonb_array_length({$prefixExpr}) > {$idx})";
                $accum[] = $lenCheck;
                $prefixExpr = "{$prefixExpr} -> {$idx}";
            } else {
                // use -> to get child and IS NOT NULL to check existence (avoids ? operator)
                $keyLiteral = "'" . str_replace("'", "''", (string)$p) . "'";
                $accum[] = "({$prefixExpr} -> {$keyLiteral}) IS NOT NULL";
                $prefixExpr = "{$prefixExpr} -> {$keyLiteral}";
            }
        }

        if (!empty($accum)) {
            $prefixCheck = '(' . implode(' AND ', $accum) . ')';
            $checks[] = $prefixCheck;
        }

        // Final fallback: #> path not null. Build pg path array literal safely.
        $pgPathArr = $this->buildPostgreSqlJsonbPath($parts);
        $checks[] = "({$colQuoted}::jsonb #> '{$pgPathArr}') IS NOT NULL";

        return '(' . implode(' OR ', $checks) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonOrderExpr(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        if (empty($parts)) {
            // whole column: cast text to numeric when possible
            $expr = $this->quoteIdentifier($col) . '::text';
        } else {
            $arr = $this->buildPostgreSqlJsonPath($parts);
            $expr = $this->quoteIdentifier($col) . ' #>> ' . $arr;
        }

        // CASE expression: if text looks like a number, cast to numeric for ordering, otherwise NULL
        // This yields numeric values for numeric entries and NULL for non-numeric ones.
        return "CASE WHEN ({$expr}) ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN ({$expr})::numeric ELSE NULL END";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonLength(string $col, array|string|null $path = null): string
    {
        $colQuoted = $this->quoteIdentifier($col);

        if ($path === null) {
            // For whole column, use jsonb_array_length for arrays, return 0 for others
            return "CASE WHEN jsonb_typeof({$colQuoted}::jsonb) = 'array' THEN jsonb_array_length({$colQuoted}::jsonb) ELSE 0 END";
        }

        $parts = $this->normalizeJsonPath($path);
        $arr = $this->buildPostgreSqlJsonPath($parts);
        $extracted = "{$colQuoted}::jsonb #> {$arr}";

        return "CASE WHEN jsonb_typeof({$extracted}) = 'array' THEN jsonb_array_length({$extracted}) ELSE 0 END";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonKeys(string $col, array|string|null $path = null): string
    {
        $colQuoted = $this->quoteIdentifier($col);

        if ($path === null) {
            // PostgreSQL doesn't have a simple JSON_KEYS function, return a placeholder
            return "'[keys]'";
        }

        $parts = $this->normalizeJsonPath($path);
        $arr = $this->buildPostgreSqlJsonPath($parts);

        // PostgreSQL doesn't have a simple JSON_KEYS function, return a placeholder
        return "'[keys]'";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonType(string $col, array|string|null $path = null): string
    {
        $colQuoted = $this->quoteIdentifier($col);

        if ($path === null) {
            return "jsonb_typeof({$colQuoted}::jsonb)";
        }

        $parts = $this->normalizeJsonPath($path);
        $arr = $this->buildPostgreSqlJsonPath($parts);
        $extracted = "{$colQuoted}::jsonb #> {$arr}";

        return "jsonb_typeof({$extracted})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatIfNull(string $expr, mixed $default): string
    {
        // PostgreSQL doesn't have IFNULL, use COALESCE
        return "COALESCE($expr, {$this->formatDefaultValue($default)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatSubstring(string|RawValue $source, int $start, ?int $length): string
    {
        $src = $this->resolveValue($source);
        if ($length === null) {
            return "SUBSTRING($src FROM $start)";
        }
        return "SUBSTRING($src FROM $start FOR $length)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatCurDate(): string
    {
        return 'CURRENT_DATE';
    }

    /**
     * {@inheritDoc}
     */
    public function formatCurTime(): string
    {
        return 'CURRENT_TIME';
    }

    /**
     * {@inheritDoc}
     */
    public function formatYear(string|RawValue $value): string
    {
        return "EXTRACT(YEAR FROM {$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatMonth(string|RawValue $value): string
    {
        return "EXTRACT(MONTH FROM {$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatDay(string|RawValue $value): string
    {
        return "EXTRACT(DAY FROM {$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatHour(string|RawValue $value): string
    {
        return "EXTRACT(HOUR FROM {$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatMinute(string|RawValue $value): string
    {
        return "EXTRACT(MINUTE FROM {$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatSecond(string|RawValue $value): string
    {
        return "EXTRACT(SECOND FROM {$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatDateOnly(string|RawValue $value): string
    {
        return $this->resolveValue($value) . '::DATE';
    }

    /**
     * {@inheritDoc}
     */
    public function formatTimeOnly(string|RawValue $value): string
    {
        return $this->resolveValue($value) . '::TIME';
    }

    /**
     * {@inheritDoc}
     */
    public function formatInterval(string|RawValue $expr, string $value, string $unit, bool $isAdd): string
    {
        $e = $this->resolveValue($expr);
        $sign = $isAdd ? '+' : '-';
        // PostgreSQL uses INTERVAL 'value unit' syntax
        return "{$e} {$sign} INTERVAL '{$value} {$unit}'";
    }

    /**
     * {@inheritDoc}
     */
    public function formatGroupConcat(string|RawValue $column, string $separator, bool $distinct): string
    {
        $col = $this->resolveValue($column);
        $sep = addslashes($separator);
        $dist = $distinct ? 'DISTINCT ' : '';
        return "STRING_AGG($dist$col, '$sep')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatTruncate(string|RawValue $value, int $precision): string
    {
        $val = $this->resolveValue($value);
        return "TRUNC($val, $precision)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatPosition(string|RawValue $substring, string|RawValue $value): string
    {
        $sub = $substring instanceof RawValue ? $substring->getValue() : "'" . addslashes((string)$substring) . "'";
        $val = $this->resolveValue($value);
        return "POSITION($sub IN $val)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatLeft(string|RawValue $value, int $length): string
    {
        $val = $this->resolveValue($value);
        return "LEFT($val, $length)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRight(string|RawValue $value, int $length): string
    {
        $val = $this->resolveValue($value);
        return "RIGHT($val, $length)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRepeat(string|RawValue $value, int $count): string
    {
        $val = $value instanceof RawValue ? $value->getValue() : "'" . addslashes((string)$value) . "'";
        return "REPEAT($val, $count)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatReverse(string|RawValue $value): string
    {
        $val = $this->resolveValue($value);
        return "REVERSE($val)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatPad(string|RawValue $value, int $length, string $padString, bool $isLeft): string
    {
        $val = $this->resolveValue($value);
        $pad = addslashes($padString);
        $func = $isLeft ? 'LPAD' : 'RPAD';
        return "{$func}($val, $length, '$pad')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpMatch(string|RawValue $value, string $pattern): string
    {
        $val = $this->resolveValue($value);
        $pat = str_replace("'", "''", $pattern);
        // PostgreSQL uses ~ operator for case-sensitive match
        return "($val ~ '$pat')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpLike(string|RawValue $value, string $pattern): string
    {
        $val = $this->resolveValue($value);
        $pat = str_replace("'", "''", $pattern);
        // PostgreSQL uses ~ operator for case-sensitive match
        return "($val ~ '$pat')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpReplace(string|RawValue $value, string $pattern, string $replacement): string
    {
        $val = $this->resolveValue($value);
        $pat = str_replace("'", "''", $pattern);
        $rep = str_replace("'", "''", $replacement);
        return "regexp_replace($val, '$pat', '$rep', 'g')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpExtract(string|RawValue $value, string $pattern, ?int $groupIndex = null): string
    {
        $val = $this->resolveValue($value);
        // Escape single quotes for PostgreSQL string literal (backslashes are handled by PostgreSQL regex)
        $pat = str_replace("'", "''", $pattern);
        // PostgreSQL regexp_match returns array, use (regexp_match(...))[1] for first group
        // For full match (groupIndex = 0 or null), use [1] (full match)
        // For specific group, use [groupIndex + 1] since PostgreSQL arrays are 1-indexed
        // [1] = full match, [2] = first capture group, [3] = second capture group, etc.
        // Note: regexp_match returns NULL if no match, so array indexing will also return NULL
        if ($groupIndex !== null && $groupIndex > 0) {
            $arrayIndex = $groupIndex + 1;
            return "(regexp_match($val, '$pat'))[$arrayIndex]";
        }
        // For full match, use [1] which is the full match
        return "(regexp_match($val, '$pat'))[1]";
    }

    /**
     * {@inheritDoc}
     */
    public function formatFulltextMatch(string|array $columns, string $searchTerm, ?string $mode = null, bool $withQueryExpansion = false): array|string
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $quotedCols = array_map([$this->dialect, 'quoteIdentifier'], $cols);
        $colList = implode(' || ', $quotedCols);

        $ph = ':fulltext_search_term';
        $sql = "$colList @@ to_tsquery('english', $ph)";

        return [$sql, [$ph => $searchTerm]];
    }

    /**
     * {@inheritDoc}
     */
    public function formatWindowFunction(
        string $function,
        array $args,
        array $partitionBy,
        array $orderBy,
        ?string $frameClause
    ): string {
        $sql = strtoupper($function) . '(';

        // Add function arguments
        if (!empty($args)) {
            $formattedArgs = array_map(function ($arg) {
                if (is_string($arg) && !is_numeric($arg)) {
                    return $this->quoteIdentifier($arg);
                }
                if (is_null($arg)) {
                    return 'NULL';
                }
                return (string)$arg;
            }, $args);
            $sql .= implode(', ', $formattedArgs);
        }

        $sql .= ') OVER (';

        // Add PARTITION BY
        if (!empty($partitionBy)) {
            $quotedPartitions = array_map(
                fn ($col) => $this->quoteIdentifier($col),
                $partitionBy
            );
            $sql .= 'PARTITION BY ' . implode(', ', $quotedPartitions);
        }

        // Add ORDER BY
        if (!empty($orderBy)) {
            if (!empty($partitionBy)) {
                $sql .= ' ';
            }
            $orderClauses = [];
            foreach ($orderBy as $order) {
                foreach ($order as $col => $dir) {
                    $orderClauses[] = $this->quoteIdentifier($col) . ' ' . strtoupper($dir);
                }
            }
            $sql .= 'ORDER BY ' . implode(', ', $orderClauses);
        }

        // Add frame clause
        if ($frameClause !== null) {
            $sql .= ' ' . $frameClause;
        }

        $sql .= ')';

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function formatMaterializedCte(string $cteSql, bool $isMaterialized): string
    {
        if ($isMaterialized) {
            // PostgreSQL 12+: MATERIALIZED goes after AS
            // Return SQL with MATERIALIZED marker that CteManager will use
            return 'MATERIALIZED:' . $cteSql;
        }
        return $cteSql;
    }

    /**
     * {@inheritDoc}
     */
    public function formatGreatest(array $values): string
    {
        $args = $this->resolveValues($values);
        // Apply normalizeRawValue to each argument to handle safe CAST conversion
        $normalizedArgs = array_map(function ($arg) {
            return $this->dialect->normalizeRawValue((string)$arg);
        }, $args);
        return 'GREATEST(' . implode(', ', $normalizedArgs) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function formatLeast(array $values): string
    {
        $args = $this->resolveValues($values);
        // Apply normalizeRawValue to each argument to handle safe CAST conversion
        $normalizedArgs = array_map(function ($arg) {
            return $this->dialect->normalizeRawValue((string)$arg);
        }, $args);
        return 'LEAST(' . implode(', ', $normalizedArgs) . ')';
    }
}
