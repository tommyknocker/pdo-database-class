<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\oracle;

use tommyknocker\pdodb\dialects\formatters\SqlFormatterAbstract;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Oracle SQL formatter implementation.
 */
class OracleSqlFormatter extends SqlFormatterAbstract
{
    /**
     * {@inheritDoc}
     */
    public function formatLimitOffset(string $sql, ?int $limit, ?int $offset): string
    {
        // Oracle 12c+ uses OFFSET ... ROWS FETCH NEXT ... ROWS ONLY
        if ($offset !== null && $limit !== null) {
            $sql .= ' OFFSET ' . (int)$offset . ' ROWS FETCH NEXT ' . (int)$limit . ' ROWS ONLY';
        } elseif ($limit !== null) {
            $sql .= ' FETCH NEXT ' . (int)$limit . ' ROWS ONLY';
        } elseif ($offset !== null) {
            // Oracle requires FETCH when using OFFSET
            $sql .= ' OFFSET ' . (int)$offset . ' ROWS';
        }
        return $sql;
    }

    /**
     * Format SELECT options (FOR UPDATE, etc.).
     *
     * @param string $sql The SQL query
     * @param array<int|string, mixed> $options SELECT options
     *
     * @return string SQL with options appended
     */
    public function formatSelectOptions(string $sql, array $options): string
    {
        $tail = [];
        foreach ($options as $opt) {
            $u = strtoupper(trim($opt));
            // Oracle supports FOR UPDATE / FOR UPDATE NOWAIT / FOR UPDATE WAIT
            if (in_array($u, ['FOR UPDATE', 'FOR UPDATE NOWAIT', 'FOR UPDATE WAIT'])) {
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
    public function formatWindowFunction(
        string $function,
        array $args,
        array $partitionBy,
        array $orderBy,
        ?string $frameClause
    ): string {
        $sql = strtoupper($function) . '(';

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

        if (!empty($partitionBy)) {
            $quotedPartitions = array_map(
                fn ($col) => $this->quoteIdentifier($col),
                $partitionBy
            );
            $sql .= 'PARTITION BY ' . implode(', ', $quotedPartitions);
        }

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

        if ($frameClause !== null) {
            $sql .= ' ' . $frameClause;
        }

        $sql .= ')';

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function formatGroupConcat(string|RawValue $column, string $separator, bool $distinct): string
    {
        $col = $this->resolveValue($column);
        $sep = addslashes($separator);
        $dist = $distinct ? 'DISTINCT ' : '';
        // Oracle uses LISTAGG for string aggregation
        return "LISTAGG($dist$col, '$sep') WITHIN GROUP (ORDER BY $col)";
    }

    /* ---------------- JSON methods ---------------- */

    /**
     * {@inheritDoc}
     */
    public function formatJsonGet(string $col, array|string $path, bool $asText = true): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        $colQuoted = $this->quoteIdentifier($col);

        if ($asText) {
            // Oracle uses JSON_VALUE for scalar values
            return "JSON_VALUE($colQuoted, '$jsonPath')";
        }
        // Oracle uses JSON_QUERY for objects/arrays
        return "JSON_QUERY($colQuoted, '$jsonPath')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonContains(string $col, mixed $value, array|string|null $path = null): array|string
    {
        $colQuoted = $this->quoteIdentifier($col);
        $parts = $this->normalizeJsonPath($path ?? []);
        $jsonPath = $this->buildJsonPathString($parts);
        $param = $this->generateParameterName('jsonc', $col);

        // JSON_TABLE extracts values as strings without JSON quotes
        // So we need to compare the raw value, not the JSON-encoded value
        // For strings, use the raw string value; for other types, use JSON-encoded value
        $compareValue = is_string($value) ? $value : $this->encodeToJson($value);

        // Oracle doesn't support ? operator with parameters in JSON_EXISTS
        // Use JSON_TABLE with EXISTS instead
        // For arrays, check if any element equals the value
        // For objects, check if the path exists and equals the value
        if (empty($parts)) {
            // Check if root JSON contains the value (for arrays)
            $sql = "EXISTS (SELECT 1 FROM JSON_TABLE($colQuoted, '\$[*]' COLUMNS (val VARCHAR2(4000) PATH '\$')) WHERE val = $param)";
        } else {
            // Check if array at path contains the value
            $arrayPath = $jsonPath . '[*]';
            $sql = "EXISTS (SELECT 1 FROM JSON_TABLE($colQuoted, '$arrayPath' COLUMNS (val VARCHAR2(4000) PATH '\$')) WHERE val = $param)";
        }

        return [$sql, [$param => $compareValue]];
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonSet(string $col, array|string $path, mixed $value): array
    {
        $parts = $this->normalizeJsonPath($path);
        $colQuoted = $this->quoteIdentifier($col);
        $param = $this->generateParameterName('jsonset', $col . '|' . implode('.', $parts));
        $jsonText = $this->encodeToJson($value);

        // Oracle JSON_MERGEPATCH requires a proper JSON object
        // Build nested JSON object structure based on path
        // For path ['a', 'c'], we need {"a": {"c": value}}
        $mergeObject = $this->buildNestedJsonObject($parts, $param);

        // Oracle JSON_MERGEPATCH works with CLOB/TEXT columns directly
        // It returns JSON type, but UPDATE requires CLOB/VARCHAR2
        // Cast result to CLOB to ensure type compatibility
        // Note: Both operands must be JSON type, so cast column to JSON if needed
        // For CLOB columns containing JSON, Oracle can work with them directly
        $sql = "TO_CLOB(JSON_MERGEPATCH(CAST($colQuoted AS JSON), CAST($mergeObject AS JSON)))";

        return [$sql, [$param => $jsonText]];
    }

    /**
     * Build nested JSON object structure for JSON_MERGEPATCH.
     *
     * @param array<int, string|int> $parts Path parts
     * @param string $param Parameter placeholder
     *
     * @return string JSON object SQL expression
     */
    protected function buildNestedJsonObject(array $parts, string $param): string
    {
        if (empty($parts)) {
            // For leaf values, parameter is already a JSON string
            return $param;
        }

        // Build nested structure: {"part1": {"part2": {"partN": value}}}
        // Use parameter only once at the leaf level
        // Build structure from inside out
        $structure = $param;
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $part = $parts[$i];
            // Oracle JSON_OBJECT requires string keys for all keys, including array indices
            // Convert numeric indices to strings to avoid ORA-00932 error
            $key = "'" . str_replace("'", "''", (string)$part) . "'";
            // JSON_OBJECT creates nested structure
            // For Oracle, JSON_OBJECT requires string values, so we use the parameter directly
            // The parameter is already a JSON-encoded string from encodeToJson()
            $structure = "JSON_OBJECT($key : $structure)";
        }

        return $structure;
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonRemove(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        $colQuoted = $this->quoteIdentifier($col);

        // Oracle 21c+ uses JSON_TRANSFORM with REMOVE clause
        return "JSON_TRANSFORM($colQuoted, REMOVE '$jsonPath')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonReplace(string $col, array|string $path, mixed $value): array
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        $colQuoted = $this->quoteIdentifier($col);
        $param = $this->generateParameterName('jsonreplace', $col . '|' . $jsonPath);
        $jsonText = $this->encodeToJson($value);

        // Oracle uses JSON_TRANSFORM for replacement (21c+)
        // For older versions, use JSON_MERGEPATCH
        // Cast to CLOB to ensure type compatibility
        // Ensure parameter is treated as JSON string
        $sql = "TO_CLOB(JSON_MERGEPATCH($colQuoted, JSON_OBJECT('$jsonPath' : TO_CLOB($param))))";

        return [$sql, [$param => $jsonText]];
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonExists(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        $colQuoted = $this->quoteIdentifier($col);

        return "JSON_EXISTS($colQuoted, '$jsonPath')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonOrderExpr(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        $colQuoted = $this->quoteIdentifier($col);

        // Extract as text and convert to number if possible
        $expr = "JSON_VALUE($colQuoted, '$jsonPath')";
        return "TO_NUMBER($expr DEFAULT 0 ON CONVERSION ERROR)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonLength(string $col, array|string|null $path = null): string
    {
        $colQuoted = $this->quoteIdentifier($col);

        if ($path === null) {
            // Oracle doesn't have a direct JSON_LENGTH, use JSON_TABLE
            return "(SELECT COUNT(*) FROM JSON_TABLE($colQuoted, '$[*]' COLUMNS (val VARCHAR2(4000) PATH '$')))";
        }

        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        return "(SELECT COUNT(*) FROM JSON_TABLE($colQuoted, '{$jsonPath}[*]' COLUMNS (val VARCHAR2(4000) PATH '$')))";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonKeys(string $col, array|string|null $path = null): string
    {
        $colQuoted = $this->quoteIdentifier($col);

        if ($path === null) {
            // Oracle doesn't have a simple JSON_KEYS function
            return "'[keys]'";
        }

        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);

        // Oracle doesn't have a simple JSON_KEYS function
        return "'[keys]'";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonType(string $col, array|string|null $path = null): string
    {
        $colQuoted = $this->quoteIdentifier($col);

        if ($path === null) {
            return "JSON_VALUE($colQuoted, '$.type()')";
        }

        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        return "JSON_VALUE($colQuoted, '$jsonPath.type()')";
    }

    /* ---------------- SQL helpers ---------------- */

    /**
     * {@inheritDoc}
     */
    public function formatIfNull(string $expr, mixed $default): string
    {
        // Apply formatColumnForComparison for CLOB compatibility only if it's a column (not a literal)
        // Check if it's a quoted identifier (column) or a literal string
        if (preg_match('/^["`\[\]][^"`\[\]]+["`\[\]]$/', $expr) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $expr)) {
            $exprFormatted = $this->dialect->formatColumnForComparison($expr);
        } else {
            $exprFormatted = $expr;
        }
        // Oracle uses NVL instead of IFNULL
        return "NVL($exprFormatted, {$this->formatDefaultValue($default)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatGreatest(array $values): string
    {
        $args = $this->resolveValues($values);
        // Apply formatColumnForComparison for CLOB compatibility
        $formattedArgs = [];
        foreach ($args as $arg) {
            // Check if it's a CAST expression with a column
            if (preg_match('/CAST\s*\(([^,]+)\s+AS\s+([^)]+)\)/i', $arg, $castMatches)) {
                $castExpr = trim($castMatches[1]);
                $castType = trim($castMatches[2]);
                // Check if CAST expression is a column identifier
                if (preg_match('/^["`\[\]][^"`\[\]]+["`\[\]]$/', $castExpr) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $castExpr)) {
                    // It's a column - apply TO_CHAR() for CLOB compatibility before CAST
                    $castQuoted = preg_match('/^["`\[\]]/', $castExpr) ? $castExpr : $this->dialect->quoteIdentifier($castExpr);
                    $castExprFormatted = $this->dialect->formatColumnForComparison($castQuoted);

                    // For numeric types (INTEGER, NUMBER, etc.), use CASE WHEN for safe casting
                    // Oracle CAST throws error on invalid values, so we need to catch it
                    if (preg_match('/^(INTEGER|NUMBER|DECIMAL|NUMERIC|REAL|FLOAT|DOUBLE)/i', $castType)) {
                        // Use CASE WHEN REGEXP_LIKE for safe numeric casting
                        $formattedArgs[] = "CASE WHEN REGEXP_LIKE($castExprFormatted, '^-?[0-9]+(\\.[0-9]+)?\$') THEN CAST($castExprFormatted AS $castType) ELSE NULL END";
                    } else {
                        // For non-numeric types, use CAST directly
                        $formattedArgs[] = "CAST($castExprFormatted AS $castType)";
                    }
                } else {
                    // It's an expression - keep as-is
                    $formattedArgs[] = $arg;
                }
            } elseif (preg_match('/^["`\[\]][^"`\[\]]+["`\[\]]$/', $arg) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $arg)) {
                // It's a column - apply formatColumnForComparison for CLOB compatibility
                $formattedArgs[] = $this->dialect->formatColumnForComparison($arg);
            } else {
                // It's a literal or expression - keep as-is
                $formattedArgs[] = $arg;
            }
        }
        return 'GREATEST(' . implode(', ', $formattedArgs) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function formatLeast(array $values): string
    {
        $args = $this->resolveValues($values);
        // Apply formatColumnForComparison for CLOB compatibility
        $formattedArgs = [];
        foreach ($args as $arg) {
            // Check if it's a CAST expression with a column
            if (preg_match('/CAST\s*\(([^,]+)\s+AS\s+([^)]+)\)/i', $arg, $castMatches)) {
                $castExpr = trim($castMatches[1]);
                $castType = trim($castMatches[2]);
                // Check if CAST expression is a column identifier
                if (preg_match('/^["`\[\]][^"`\[\]]+["`\[\]]$/', $castExpr) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $castExpr)) {
                    // It's a column - apply TO_CHAR() for CLOB compatibility before CAST
                    $castQuoted = preg_match('/^["`\[\]]/', $castExpr) ? $castExpr : $this->dialect->quoteIdentifier($castExpr);
                    $castExprFormatted = $this->dialect->formatColumnForComparison($castQuoted);

                    // For numeric types (INTEGER, NUMBER, etc.), use CASE WHEN for safe casting
                    // Oracle CAST throws error on invalid values, so we need to catch it
                    if (preg_match('/^(INTEGER|NUMBER|DECIMAL|NUMERIC|REAL|FLOAT|DOUBLE)/i', $castType)) {
                        // Use CASE WHEN REGEXP_LIKE for safe numeric casting
                        $formattedArgs[] = "CASE WHEN REGEXP_LIKE($castExprFormatted, '^-?[0-9]+(\\.[0-9]+)?\$') THEN CAST($castExprFormatted AS $castType) ELSE NULL END";
                    } else {
                        // For non-numeric types, use CAST directly
                        $formattedArgs[] = "CAST($castExprFormatted AS $castType)";
                    }
                } else {
                    // It's an expression - keep as-is
                    $formattedArgs[] = $arg;
                }
            } elseif (preg_match('/^["`\[\]][^"`\[\]]+["`\[\]]$/', $arg) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $arg)) {
                // It's a column - apply formatColumnForComparison for CLOB compatibility
                $formattedArgs[] = $this->dialect->formatColumnForComparison($arg);
            } else {
                // It's a literal or expression - keep as-is
                $formattedArgs[] = $arg;
            }
        }
        return 'LEAST(' . implode(', ', $formattedArgs) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function formatSubstring(string|RawValue $source, int $start, ?int $length): string
    {
        $src = $this->resolveValue($source);
        // Oracle uses SUBSTR (1-based indexing)
        if ($length === null) {
            return "SUBSTR($src, $start)";
        }
        return "SUBSTR($src, $start, $length)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatCurDate(): string
    {
        // Oracle: TRUNC(SYSDATE) returns DATE without time component
        return 'TRUNC(SYSDATE)';
    }

    /**
     * {@inheritDoc}
     */
    public function formatCurTime(): string
    {
        // Oracle: SYSTIMESTAMP returns TIMESTAMP with time component
        // For TIME type compatibility, extract time part
        return 'SYSTIMESTAMP';
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
        return 'TRUNC(' . $this->resolveValue($value) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function formatTimeOnly(string|RawValue $value): string
    {
        // Oracle: Extract time part from TIMESTAMP using TO_CHAR
        // Return as TIMESTAMP for compatibility with TIME type
        $resolved = $this->resolveValue($value);
        return "TO_TIMESTAMP(TO_CHAR($resolved, 'HH24:MI:SS'), 'HH24:MI:SS')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatInterval(string|RawValue $expr, string $value, string $unit, bool $isAdd): string
    {
        $e = $this->resolveValue($expr);
        $sign = $isAdd ? '+' : '-';
        // Oracle uses INTERVAL syntax
        return "{$e} {$sign} INTERVAL '{$value}' {$unit}";
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
        // Oracle uses INSTR
        return "INSTR($val, $sub)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatLeft(string|RawValue $value, int $length): string
    {
        $val = $this->resolveValue($value);
        // Oracle uses SUBSTR
        return "SUBSTR($val, 1, $length)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRight(string|RawValue $value, int $length): string
    {
        $val = $this->resolveValue($value);
        // Oracle uses SUBSTR with negative start
        return "SUBSTR($val, -$length)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRepeat(string|RawValue $value, int $count): string
    {
        $val = $this->resolveValue($value);
        // Apply formatColumnForComparison for CLOB compatibility only if it's a column (not a literal)
        // Check if it's a quoted identifier (column) or a literal string
        if (preg_match('/^["`\[\]][^"`\[\]]+["`\[\]]$/', $val) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $val)) {
            $valFormatted = $this->dialect->formatColumnForComparison($val);
        } elseif (preg_match("/^'.*'$/", $val)) {
            // It's already a quoted string literal - use as-is
            $valFormatted = $val;
        } else {
            // It's an unquoted string literal - add quotes
            $valFormatted = "'{$val}'";
        }
        // Oracle uses RPAD with empty string trick
        return "RPAD('', $count * LENGTH($valFormatted), $valFormatted)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatReverse(string|RawValue $value): string
    {
        $val = $this->resolveValue($value);
        // Apply formatColumnForComparison for CLOB compatibility only if it's a column (not a literal)
        // Check if it's a quoted identifier (column) or a literal string
        if (preg_match('/^["`\[\]][^"`\[\]]+["`\[\]]$/', $val) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $val)) {
            $valFormatted = $this->dialect->formatColumnForComparison($val);
        } else {
            $valFormatted = $val;
        }
        // Oracle doesn't have REVERSE, use recursive CTE or LISTAGG trick
        // Simple implementation using LISTAGG
        return "(SELECT LISTAGG(SUBSTR($valFormatted, LEVEL, 1)) WITHIN GROUP (ORDER BY LEVEL DESC) FROM DUAL CONNECT BY LEVEL <= LENGTH($valFormatted))";
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
        // Oracle uses REGEXP_LIKE
        return "CASE WHEN REGEXP_LIKE($val, '$pat') THEN 1 ELSE 0 END";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpReplace(string|RawValue $value, string $pattern, string $replacement): string
    {
        $val = $this->resolveValue($value);
        $pat = str_replace("'", "''", $pattern);
        $rep = str_replace("'", "''", $replacement);
        return "REGEXP_REPLACE($val, '$pat', '$rep')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpExtract(string|RawValue $value, string $pattern, ?int $groupIndex = null): string
    {
        $val = $this->resolveValue($value);
        $pat = str_replace("'", "''", $pattern);
        // Oracle uses REGEXP_SUBSTR
        if ($groupIndex !== null && $groupIndex > 0) {
            return "REGEXP_SUBSTR($val, '$pat', 1, 1, NULL, $groupIndex)";
        }
        return "REGEXP_SUBSTR($val, '$pat')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatFulltextMatch(string|array $columns, string $searchTerm, ?string $mode = null, bool $withQueryExpansion = false): array|string
    {
        // Oracle uses CONTAINS for full-text search (requires Oracle Text)
        $cols = is_array($columns) ? $columns : [$columns];
        $quotedCols = array_map([$this, 'quoteIdentifier'], $cols);
        $colList = implode(', ', $quotedCols);

        $ph = ':fulltext_search_term';
        $sql = "CONTAINS($colList, $ph) > 0";

        return [$sql, [$ph => $searchTerm]];
    }

    /**
     * {@inheritDoc}
     */
    public function formatMaterializedCte(string $cteSql, bool $isMaterialized): string
    {
        // Oracle supports materialized hints
        if ($isMaterialized) {
            if (preg_match('/^\s*SELECT\s+/i', $cteSql)) {
                return preg_replace(
                    '/^\s*(SELECT\s+)/i',
                    '$1/*+ MATERIALIZE */ ',
                    $cteSql
                ) ?? $cteSql;
            }
        }
        return $cteSql;
    }
}
