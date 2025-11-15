<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\sqlite;

use tommyknocker\pdodb\dialects\formatters\SqlFormatterAbstract;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * SQLite SQL formatter implementation.
 */
class SQLiteSqlFormatter extends SqlFormatterAbstract
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
        $middle = [];
        foreach ($options as $opt) {
            $u = strtoupper(trim($opt));
            // SQLite supports DISTINCT, ALL
            if (in_array($u, ['DISTINCT', 'ALL'])) {
                $middle[] = $opt;
            }
            // Other options are not supported by SQLite
        }
        if ($middle) {
            $result = preg_replace('/^SELECT\s+/i', 'SELECT ' . implode(' ', $middle) . ' ', $sql, 1);
            $sql = $result !== null ? $result : $sql;
        }
        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function formatUnionSelect(string $selectSql, bool $isBaseQuery = false): string
    {
        // SQLite doesn't require parentheses in UNION
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
        $jsonPath = $this->buildJsonPathString($parts);
        $colQuoted = $this->quoteIdentifier($col);
        $base = "json_extract({$colQuoted}, '{$jsonPath}')";
        return $asText ? "({$base} || '')" : $base;
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonContains(string $col, mixed $value, array|string|null $path = null): array|string
    {
        $parts = $this->normalizeJsonPath($path ?? []);
        $jsonPath = $this->buildJsonPathString($parts);
        $quotedCol = $this->quoteIdentifier($col);

        // If value is an array, check that all elements are present in the JSON array
        if (is_array($value)) {
            $conditions = [];
            $params = [];

            foreach ($value as $idx => $item) {
                $param = $this->generateParameterName('jsonc', $col . '_' . $idx);
                $conditions[] = "EXISTS (SELECT 1 FROM json_each({$quotedCol}, '{$jsonPath}') WHERE json_each.value = {$param})";
                $params[$param] = is_string($item) ? $item : $this->encodeToJson($item);
            }

            $sql = '(' . implode(' AND ', $conditions) . ')';
            return [$sql, $params];
        }

        // For scalar values, use simple json_each check
        $param = $this->generateParameterName('jsonc', $col);
        $sql = "EXISTS (SELECT 1 FROM json_each({$quotedCol}, '{$jsonPath}') WHERE json_each.value = {$param})";

        // Encode value as JSON if not a simple scalar
        $paramValue = is_string($value) ? $value : $this->encodeToJson($value);

        return [$sql, [$param => $paramValue]];
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonSet(string $col, array|string $path, mixed $value): array
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        $param = $this->generateParameterName('jsonset', $col);
        // Use json() to parse the JSON-encoded value properly
        $expr = 'JSON_SET(' . $this->quoteIdentifier($col) . ", '{$jsonPath}', json({$param}))";
        return [$expr, [$param => $this->encodeToJson($value)]];
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonRemove(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        $colQuoted = $this->quoteIdentifier($col);

        // If last segment is numeric, replace array element with JSON null to preserve indices
        $last = $this->getLastSegment($parts);
        if ($this->isNumericIndex($last)) {
            // Use JSON_SET to set the element to JSON null (using json('null'))
            return "JSON_SET({$colQuoted}, '{$jsonPath}', json('null'))";
        }

        // Otherwise remove object key
        return "JSON_REMOVE({$colQuoted}, '{$jsonPath}')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonReplace(string $col, array|string $path, mixed $value): array
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        $param = $this->generateParameterName('jsonreplace', $col);

        // SQLite JSON_REPLACE only replaces if path exists
        $expr = 'JSON_REPLACE(' . $this->quoteIdentifier($col) . ", '{$jsonPath}', json({$param}))";

        return [$expr, [$param => $this->encodeToJson($value)]];
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonExists(string $col, array|string $path): string
    {
        $expr = $this->formatJsonGet($col, $path, false);
        return "{$expr} IS NOT NULL";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonOrderExpr(string $col, array|string $path): string
    {
        $expr = $this->formatJsonGet($col, $path, true);
        // Force numeric coercion for ordering: add 0 to convert string to number
        // This ensures numeric ordering instead of lexicographic ordering
        return "({$expr} + 0)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonLength(string $col, array|string|null $path = null): string
    {
        $colQuoted = $this->quoteIdentifier($col);

        if ($path === null) {
            // For whole column: use json_array_length for arrays, approximate for objects
            return "COALESCE(json_array_length({$colQuoted}), 0)";
        }

        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);

        $extracted = "json_extract({$colQuoted}, '{$jsonPath}')";
        return "COALESCE(json_array_length({$extracted}), 0)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonKeys(string $col, array|string|null $path = null): string
    {
        $colQuoted = $this->quoteIdentifier($col);

        if ($path === null) {
            // SQLite doesn't have a direct JSON_KEYS function, return a placeholder
            return "'[keys]'";
        }

        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);

        // SQLite doesn't have a direct JSON_KEYS function, return a placeholder
        return "'[keys]'";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonType(string $col, array|string|null $path = null): string
    {
        $colQuoted = $this->quoteIdentifier($col);

        if ($path === null) {
            return "json_type({$colQuoted})";
        }

        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);

        return "json_type(json_extract({$colQuoted}, '{$jsonPath}'))";
    }

    /**
     * {@inheritDoc}
     */
    public function formatIfNull(string $expr, mixed $default): string
    {
        return "IFNULL($expr, {$this->formatDefaultValue($default)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatGreatest(array $values): string
    {
        // SQLite doesn't have GREATEST, use MAX.
        return 'MAX(' . implode(', ', $this->resolveValues($values)) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function formatLeast(array $values): string
    {
        // SQLite doesn't have LEAST, use MIN.
        return 'MIN(' . implode(', ', $this->resolveValues($values)) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function formatSubstring(string|RawValue $source, int $start, ?int $length): string
    {
        $src = $this->resolveValue($source);
        if ($length === null) {
            return "SUBSTR($src, $start)";
        }
        return "SUBSTR($src, $start, $length)";
    }

    /**
     * {@inheritDoc}
     * SQLite doesn't have MOD function, use % operator.
     */
    public function formatMod(string|RawValue $dividend, string|RawValue $divisor): string
    {
        return "({$this->resolveValue($dividend)} % {$this->resolveValue($divisor)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatCurDate(): string
    {
        return "DATE('now')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatCurTime(): string
    {
        return "TIME('now')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatYear(string|RawValue $value): string
    {
        return "CAST(STRFTIME('%Y', {$this->resolveValue($value)}) AS INTEGER)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatMonth(string|RawValue $value): string
    {
        return "CAST(STRFTIME('%m', {$this->resolveValue($value)}) AS INTEGER)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatDay(string|RawValue $value): string
    {
        return "CAST(STRFTIME('%d', {$this->resolveValue($value)}) AS INTEGER)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatHour(string|RawValue $value): string
    {
        return "CAST(STRFTIME('%H', {$this->resolveValue($value)}) AS INTEGER)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatMinute(string|RawValue $value): string
    {
        return "CAST(STRFTIME('%M', {$this->resolveValue($value)}) AS INTEGER)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatSecond(string|RawValue $value): string
    {
        return "CAST(STRFTIME('%S', {$this->resolveValue($value)}) AS INTEGER)";
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
    public function formatInterval(string|RawValue $expr, string $value, string $unit, bool $isAdd): string
    {
        $e = $this->resolveValue($expr);
        $sign = $isAdd ? '+' : '-';
        // SQLite uses datetime(expr, '±value unit') or date(expr, '±value unit') syntax
        // Normalize unit names for SQLite (it uses lowercase: day, month, year, hour, minute, second)
        $unitLower = strtolower($unit);
        return "datetime({$e}, '{$sign}{$value} {$unitLower}')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatGroupConcat(string|RawValue $column, string $separator, bool $distinct): string
    {
        $col = $this->resolveValue($column);
        $sep = addslashes($separator);
        $dist = $distinct ? 'DISTINCT ' : '';
        return "GROUP_CONCAT($dist$col, '$sep')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatTruncate(string|RawValue $value, int $precision): string
    {
        $val = $this->resolveValue($value);
        if ($precision === 0) {
            return "CAST($val AS INTEGER)";
        }
        // For non-zero precision, use multiplication/division trick: ROUND(value * power, 0) / power
        $power = pow(10, $precision);
        return "ROUND($val * $power - 0.5, 0) / $power";
    }

    /**
     * {@inheritDoc}
     */
    public function formatPosition(string|RawValue $substring, string|RawValue $value): string
    {
        $sub = $substring instanceof RawValue ? $substring->getValue() : "'" . addslashes((string)$substring) . "'";
        $val = $this->resolveValue($value);
        // SQLite uses INSTR(string, substring) instead of POSITION(substring IN string).
        return "INSTR($val, $sub)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatLeft(string|RawValue $value, int $length): string
    {
        $val = $this->resolveValue($value);
        // SQLite doesn't have LEFT function, use SUBSTR(value, 1, length).
        return "SUBSTR($val, 1, $length)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRight(string|RawValue $value, int $length): string
    {
        $val = $this->resolveValue($value);
        // SQLite doesn't have RIGHT function, use SUBSTR(value, -length) or SUBSTR(value, LENGTH(value) - length + 1).
        return "SUBSTR($val, -$length)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRepeat(string|RawValue $value, int $count): string
    {
        if ($count <= 0) {
            return "''";
        }

        // Handle string literals and RawValue differently
        // String literals should be quoted, RawValue should be used as-is
        if ($value instanceof RawValue) {
            $val = $value->getValue();
        } else {
            // Escape string literal
            $val = "'" . str_replace("'", "''", $value) . "'";
        }

        // Use recursive CTE to repeat the string
        // SQLite supports recursive CTEs in scalar subqueries
        return "(SELECT result FROM (
            WITH RECURSIVE repeat_cte(n, result) AS (
                SELECT 1, {$val}
                UNION ALL
                SELECT n+1, result || {$val} FROM repeat_cte WHERE n < {$count}
            )
            SELECT result FROM repeat_cte WHERE n = {$count}
        ))";
    }

    /**
     * {@inheritDoc}
     */
    public function formatReverse(string|RawValue $value): string
    {
        // Use resolveValue to handle both RawValue and column names
        $val = $this->resolveValue($value);

        // Use recursive CTE to reverse the string character by character
        return "(SELECT GROUP_CONCAT(char, '') FROM (
            WITH RECURSIVE reverse_cte(n, char) AS (
                SELECT LENGTH({$val}), SUBSTR({$val}, LENGTH({$val}), 1)
                UNION ALL
                SELECT n-1, SUBSTR({$val}, n-1, 1) FROM reverse_cte WHERE n > 1
            )
            SELECT char FROM reverse_cte
        ))";
    }

    /**
     * {@inheritDoc}
     */
    public function formatPad(string|RawValue $value, int $length, string $padString, bool $isLeft): string
    {
        // Use resolveValue to handle both RawValue and column names
        $val = $this->resolveValue($value);
        $escapedPad = addslashes($padString);

        // Calculate how much padding is needed
        // Use REPEAT emulation to generate padding, then concatenate and truncate
        $paddingCount = "MAX(0, {$length} - LENGTH({$val}))";
        $paddingSubquery = "(SELECT result FROM (
            WITH RECURSIVE pad_cte(n, result) AS (
                SELECT 1, '{$escapedPad}'
                UNION ALL
                SELECT n+1, result || '{$escapedPad}' FROM pad_cte
                WHERE LENGTH(result || '{$escapedPad}') <= {$paddingCount}
            )
            SELECT result FROM pad_cte ORDER BY n DESC LIMIT 1
        ))";

        if ($isLeft) {
            // LPAD: pad on the left
            return "CASE
                WHEN LENGTH({$val}) >= {$length} THEN SUBSTR({$val}, 1, {$length})
                ELSE SUBSTR({$paddingSubquery} || {$val}, -{$length})
            END";
        } else {
            // RPAD: pad on the right
            return "CASE
                WHEN LENGTH({$val}) >= {$length} THEN SUBSTR({$val}, 1, {$length})
                ELSE SUBSTR({$val} || {$paddingSubquery}, 1, {$length})
            END";
        }
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpMatch(string|RawValue $value, string $pattern): string
    {
        // SQLite REGEXP requires extension to be loaded
        // If extension is not available, this will cause a runtime error
        // Users should ensure REGEXP extension is loaded: PRAGMA compile_options LIKE '%REGEXP%'
        $val = $this->resolveValue($value);
        $pat = str_replace("'", "''", $pattern);
        return "($val REGEXP '$pat')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpReplace(string|RawValue $value, string $pattern, string $replacement): string
    {
        // SQLite doesn't have native REGEXP_REPLACE
        // This requires REGEXP extension and regexp_replace function
        // If not available, this will cause a runtime error
        $val = $this->resolveValue($value);
        $pat = str_replace("'", "''", $pattern);
        $rep = str_replace("'", "''", $replacement);
        return "regexp_replace($val, '$pat', '$rep')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpExtract(string|RawValue $value, string $pattern, ?int $groupIndex = null): string
    {
        // SQLite doesn't have native REGEXP_EXTRACT
        // This requires REGEXP extension and regexp_extract function
        // If not available, this will cause a runtime error
        $val = $this->resolveValue($value);
        $pat = str_replace("'", "''", $pattern);
        if ($groupIndex !== null && $groupIndex > 0) {
            return "regexp_extract($val, '$pat', $groupIndex)";
        }
        return "regexp_extract($val, '$pat', 0)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatFulltextMatch(string|array $columns, string $searchTerm, ?string $mode = null, bool $withQueryExpansion = false): array|string
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $quotedCols = array_map([$this->dialect, 'quoteIdentifier'], $cols);
        $colList = implode(', ', $quotedCols);

        $ph = ':fulltext_search_term';
        $sql = "$colList MATCH $ph";

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
        // SQLite doesn't support MATERIALIZED CTEs
        return $cteSql;
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeRawValue(string $sql): string
    {
        // Convert standard SUBSTRING(expr, start, length) to SQLite SUBSTR(expr, start, length)
        // Pattern: SUBSTRING(expr, start, length) -> SUBSTR(expr, start, length)
        $sql = preg_replace('/\bSUBSTRING\s*\(/i', 'SUBSTR(', $sql) ?? $sql;

        return $sql;
    }

    /**
     * Register REGEXP functions for SQLite using PHP's preg_* functions.
     * This method registers REGEXP, regexp_replace, and regexp_extract functions
     * if they are not already available.
     *
     * @param \PDO $pdo The PDO instance
     * @param bool $force Force re-registration even if functions exist
     */
    public function registerRegexpFunctions(\PDO $pdo, bool $force = false): void
    {
        // Check if REGEXP is already available (unless forced)
        if (!$force) {
            try {
                $pdo->query("SELECT 'test' REGEXP 'test'");
                // REGEXP is already available, skip registration
                return;
            } catch (\PDOException $e) {
                // REGEXP is not available, proceed with registration
                // Check if error is about missing function (not other error)
                if (strpos($e->getMessage(), 'no such function') === false) {
                    // Different error, don't register
                    return;
                }
            }
        }

        // Register regexp function (2 arguments: pattern, subject)
        // Note: In SQLite, REGEXP is a binary operator that calls the 'regexp' function
        // When SQLite sees "subject REGEXP pattern", it calls regexp(pattern, subject)
        // So we register it as regexp(pattern, subject)
        $pdo->sqliteCreateFunction('regexp', function (string $pattern, string $subject): int {
            return preg_match("/$pattern/", $subject) ? 1 : 0;
        }, 2);

        // Register regexp_replace function (3 arguments: subject, pattern, replacement)
        $pdo->sqliteCreateFunction('regexp_replace', function (string $subject, string $pattern, string $replacement): string {
            $result = preg_replace("/$pattern/", $replacement, $subject);
            return $result ?? '';
        }, 3);

        // Register regexp_extract function (3 arguments: subject, pattern, groupIndex)
        $pdo->sqliteCreateFunction('regexp_extract', function (string $subject, string $pattern, int $groupIndex = 0): string {
            if (preg_match("/$pattern/", $subject, $matches)) {
                $index = (int)$groupIndex;
                return (string)($matches[$index] ?? '');
            }
            return '';
        }, 3);
    }
}
