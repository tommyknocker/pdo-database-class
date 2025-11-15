<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mssql;

use PDO;
use tommyknocker\pdodb\dialects\formatters\SqlFormatterAbstract;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * MSSQL SQL formatter implementation.
 */
class MSSQLSqlFormatter extends SqlFormatterAbstract
{
    public function formatLateralJoin(string $tableSql, string $type, string $aliasQuoted, string|RawValue|null $condition = null): string
    {
        // MSSQL uses CROSS APPLY / OUTER APPLY instead of LATERAL JOIN
        // Extract subquery from tableSql which may be:
        // - "LATERAL ({$subquerySql}) AS {$aliasQuoted}" for callable
        // - "LATERAL {$tableSql}" or "LATERAL {$tableSql} AS {$aliasQuoted}" for table name
        $subqueryOnly = $tableSql;

        // Remove "LATERAL" prefix
        $subqueryOnly = preg_replace('/^LATERAL\s+/i', '', $subqueryOnly) ?? '';

        // For callable case: "({$subquerySql}) AS {$aliasQuoted}" -> extract subquery
        if ($subqueryOnly !== '' && preg_match('/^\((.+)\)\s+AS\s+' . preg_quote($aliasQuoted, '/') . '$/is', $subqueryOnly, $matches)) {
            $subqueryOnly = $matches[1];
        } else {
            // For table name case: remove "AS {$aliasQuoted}" if present
            $subqueryOnly = preg_replace('/\s+AS\s+' . preg_quote($aliasQuoted, '/') . '$/i', '', $subqueryOnly) ?? '';
        }

        // Determine APPLY type based on JOIN type
        $typeUpper = strtoupper(trim($type));
        if ($typeUpper === 'LEFT' || $typeUpper === 'RIGHT' || $typeUpper === 'FULL') {
            $applyType = 'OUTER APPLY';
        } else {
            // INNER, CROSS, etc. -> CROSS APPLY
            $applyType = 'CROSS APPLY';
        }

        // APPLY doesn't use ON clause, so ignore condition
        return "{$applyType} ({$subqueryOnly}) AS {$aliasQuoted}";
    }

    /**
     * {@inheritDoc}
     *
     * @param array<int|string, mixed> $options
     */
    public function formatSelectOptions(string $sql, array $options): string
    {
        // MSSQL doesn't support SELECT options like MySQL
        // Options like FOR UPDATE are handled differently
        $tail = [];
        foreach ($options as $opt) {
            $u = strtoupper(trim($opt));
            if (in_array($u, ['FOR UPDATE', 'WITH (UPDLOCK)', 'WITH (ROWLOCK)'])) {
                $tail[] = $opt;
            }
        }
        if ($tail) {
            $sql .= ' ' . implode(' ', $tail);
        }
        return $sql;
    }

    public function formatLimitOffset(string $sql, ?int $limit, ?int $offset): string
    {
        // MSSQL doesn't support LIMIT/OFFSET syntax
        // Use TOP for LIMIT only, or OFFSET...FETCH NEXT for LIMIT+OFFSET
        // ORDER BY is required for OFFSET...FETCH NEXT

        if ($limit !== null && $offset === null) {
            // Simple LIMIT: use TOP
            // TOP must be placed right after SELECT
            if (preg_match('/^SELECT\s+/i', $sql)) {
                $replaced = preg_replace('/^SELECT\s+/i', "SELECT TOP ({$limit}) ", $sql);
                $sql = $replaced !== null ? $replaced : $sql;
            }
        } elseif ($limit !== null && $offset !== null) {
            // LIMIT + OFFSET: use OFFSET...FETCH NEXT
            // Remove any existing LIMIT/OFFSET
            $replaced = preg_replace('/\s+LIMIT\s+\d+/i', '', $sql);
            $sql = $replaced !== null ? $replaced : $sql;
            $replaced = preg_replace('/\s+OFFSET\s+\d+/i', '', $sql);
            $sql = $replaced !== null ? $replaced : $sql;
            // Ensure ORDER BY exists (required for OFFSET...FETCH NEXT)
            if (!preg_match('/\s+ORDER\s+BY\s+/i', $sql)) {
                // If no ORDER BY, add a dummy one (MSSQL requires it)
                // Try to find a primary key or first column
                if (preg_match('/SELECT\s+.*?\s+FROM\s+(\w+)/i', $sql, $matches)) {
                    $table = $matches[1];
                    $sql .= ' ORDER BY (SELECT NULL)';
                } else {
                    $sql .= ' ORDER BY (SELECT NULL)';
                }
            }
            $sql .= " OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        } elseif ($offset !== null) {
            // OFFSET only: use OFFSET...FETCH NEXT (without limit, use a large number)
            $replaced = preg_replace('/\s+OFFSET\s+\d+/i', '', $sql);
            $sql = $replaced !== null ? $replaced : $sql;
            // Ensure ORDER BY exists
            if (!preg_match('/\s+ORDER\s+BY\s+/i', $sql)) {
                $sql .= ' ORDER BY (SELECT NULL)';
            }
            $sql .= " OFFSET {$offset} ROWS";
        }

        return $sql;
    }

    public function formatJsonGet(string $col, array|string $path, bool $asText = true): string
    {
        $parts = $this->normalizeJsonPath($path);
        $colQuoted = $this->quoteIdentifier($col);

        if (empty($parts)) {
            return $asText ? "CAST({$colQuoted} AS NVARCHAR(MAX))" : $colQuoted;
        }

        // Build JSON path for MSSQL: $.path.to.value
        $jsonPath = $this->buildJsonPathString($parts);

        if ($asText) {
            // JSON_VALUE returns scalar values as text
            return "JSON_VALUE({$colQuoted}, '{$jsonPath}')";
        }
        // JSON_QUERY returns objects/arrays
        return "JSON_QUERY({$colQuoted}, '{$jsonPath}')";
    }

    public function formatJsonContains(string $col, mixed $value, array|string|null $path = null): array|string
    {
        $parts = $this->normalizeJsonPath($path ?? []);
        $colQuoted = $this->quoteIdentifier($col);
        $param = $this->generateParameterName('jsoncontains', $col . '|' . json_encode($path ?? '') . '|' . json_encode($value));

        $jsonText = $this->encodeToJson($value);

        if ($path === null) {
            // MSSQL doesn't have JSON_CONTAINS, use OPENJSON to check if value exists
            // OPENJSON returns [key], [value], [type] for objects and arrays
            // Check if any [value] matches the parameter
            $sql = "EXISTS (SELECT 1 FROM OPENJSON({$colQuoted}) WHERE [value] = {$param})";
            return [$sql, [$param => $jsonText]];
        }

        $jsonPath = $this->buildJsonPathString($parts);
        // Extract JSON at path and check if it contains the value
        $extracted = "JSON_QUERY({$colQuoted}, '{$jsonPath}')";
        // For extracted JSON, check if value exists using OPENJSON
        $sql = "EXISTS (SELECT 1 FROM OPENJSON({$extracted}) WHERE [value] = {$param})";
        return [$sql, [$param => $jsonText]];
    }

    public function formatJsonSet(string $col, array|string $path, mixed $value): array
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        $colQuoted = $this->quoteIdentifier($col);
        $param = $this->generateParameterName('jsonset', $col . '|' . $jsonPath);

        $jsonText = $this->encodeToJson($value);

        // MSSQL JSON_MODIFY: JSON_MODIFY(column, path, new_value)
        $sql = "JSON_MODIFY({$colQuoted}, '{$jsonPath}', {$param})";

        return [$sql, [$param => $jsonText]];
    }

    public function formatJsonRemove(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        $colQuoted = $this->quoteIdentifier($col);

        // MSSQL JSON_MODIFY with NULL removes the path
        return "JSON_MODIFY({$colQuoted}, '{$jsonPath}', NULL)";
    }

    public function formatJsonReplace(string $col, array|string $path, mixed $value): array
    {
        // MSSQL JSON_MODIFY replaces if path exists, creates if not
        // For replace-only behavior, check if path exists first
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        $colQuoted = $this->quoteIdentifier($col);
        $param = $this->generateParameterName('jsonreplace', $col . '|' . $jsonPath);

        $jsonText = $this->encodeToJson($value);

        // JSON_MODIFY replaces if path exists
        $sql = "JSON_MODIFY({$colQuoted}, '{$jsonPath}', {$param})";

        return [$sql, [$param => $jsonText]];
    }

    public function formatJsonExists(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        $colQuoted = $this->quoteIdentifier($col);

        // MSSQL: Check if JSON path exists using ISJSON or JSON_VALUE IS NOT NULL
        return "(ISJSON(JSON_QUERY({$colQuoted}, '{$jsonPath}')) = 1 OR JSON_VALUE({$colQuoted}, '{$jsonPath}') IS NOT NULL)";
    }

    public function formatJsonOrderExpr(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        $colQuoted = $this->quoteIdentifier($col);

        // Use JSON_VALUE for ordering (returns text, can be cast to numeric)
        $value = "JSON_VALUE({$colQuoted}, '{$jsonPath}')";
        return "CAST({$value} AS FLOAT)";
    }

    public function formatJsonLength(string $col, array|string|null $path = null): string
    {
        $colQuoted = $this->quoteIdentifier($col);

        if ($path === null) {
            // Count keys in root object or elements in root array
            return "(SELECT COUNT(*) FROM OPENJSON({$colQuoted}))";
        }

        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);

        // Get JSON at path and count elements
        return "(SELECT COUNT(*) FROM OPENJSON(JSON_QUERY({$colQuoted}, '{$jsonPath}')))";
    }

    public function formatJsonKeys(string $col, array|string|null $path = null): string
    {
        $colQuoted = $this->quoteIdentifier($col);

        if ($path === null) {
            // Return keys from root object
            return "(SELECT [key] FROM OPENJSON({$colQuoted}) FOR JSON PATH)";
        }

        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);

        // Get JSON at path and return keys
        return "(SELECT [key] FROM OPENJSON(JSON_QUERY({$colQuoted}, '{$jsonPath}')) FOR JSON PATH)";
    }

    public function formatJsonType(string $col, array|string|null $path = null): string
    {
        $colQuoted = $this->quoteIdentifier($col);

        if ($path === null) {
            return "JSON_VALUE({$colQuoted}, '$')";
        }

        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);

        // MSSQL doesn't have direct JSON_TYPE, use ISJSON and type detection
        return "CASE
            WHEN ISJSON(JSON_QUERY({$colQuoted}, '{$jsonPath}')) = 1 THEN
                CASE
                    WHEN JSON_VALUE({$colQuoted}, '{$jsonPath}') IS NULL THEN 'NULL'
                    WHEN JSON_VALUE({$colQuoted}, '{$jsonPath}') LIKE 'true' OR JSON_VALUE({$colQuoted}, '{$jsonPath}') LIKE 'false' THEN 'BOOLEAN'
                    WHEN ISNUMERIC(JSON_VALUE({$colQuoted}, '{$jsonPath}')) = 1 THEN 'NUMBER'
                    ELSE 'STRING'
                END
            ELSE 'OBJECT'
        END";
    }

    public function formatIfNull(string $expr, mixed $default): string
    {
        return "ISNULL($expr, {$this->formatDefaultValue($default)})";
    }

    public function formatSubstring(string|RawValue $source, int $start, ?int $length): string
    {
        $src = $this->resolveValue($source);
        // MSSQL SUBSTRING is 1-based, and length is required
        if ($length === null) {
            // If length not specified, use LEN to get remaining characters
            $length = "LEN($src) - $start + 1";
        }
        return "SUBSTRING($src, $start, $length)";
    }

    public function formatMod(string|RawValue $dividend, string|RawValue $divisor): string
    {
        $d1 = $this->resolveValue($dividend);
        $d2 = $this->resolveValue($divisor);
        return "($d1 % $d2)";
    }

    public function formatCurDate(): string
    {
        return 'CAST(GETDATE() AS DATE)';
    }

    public function formatCurTime(): string
    {
        return 'CAST(GETDATE() AS TIME)';
    }

    public function formatYear(string|RawValue $value): string
    {
        return "YEAR({$this->resolveValue($value)})";
    }

    public function formatMonth(string|RawValue $value): string
    {
        return "MONTH({$this->resolveValue($value)})";
    }

    public function formatDay(string|RawValue $value): string
    {
        return "DAY({$this->resolveValue($value)})";
    }

    public function formatHour(string|RawValue $value): string
    {
        return "DATEPART(HOUR, {$this->resolveValue($value)})";
    }

    public function formatMinute(string|RawValue $value): string
    {
        return "DATEPART(MINUTE, {$this->resolveValue($value)})";
    }

    public function formatSecond(string|RawValue $value): string
    {
        return "DATEPART(SECOND, {$this->resolveValue($value)})";
    }

    public function formatDateOnly(string|RawValue $value): string
    {
        return 'CAST(' . $this->resolveValue($value) . ' AS DATE)';
    }

    public function formatTimeOnly(string|RawValue $value): string
    {
        return 'CAST(' . $this->resolveValue($value) . ' AS TIME)';
    }

    public function formatInterval(string|RawValue $expr, string $value, string $unit, bool $isAdd): string
    {
        $e = $this->resolveValue($expr);
        // MSSQL uses DATEADD: DATEADD(unit, value, date)
        // Unit mapping: DAY -> day, MONTH -> month, YEAR -> year, etc.
        $unitUpper = strtoupper($unit);
        $mssqlUnit = match ($unitUpper) {
            'DAY' => 'day',
            'MONTH' => 'month',
            'YEAR' => 'year',
            'HOUR' => 'hour',
            'MINUTE' => 'minute',
            'SECOND' => 'second',
            'WEEK' => 'week',
            default => 'day',
        };

        if ($isAdd) {
            return "DATEADD({$mssqlUnit}, {$value}, {$e})";
        }
        // For subtraction, use negative value
        return "DATEADD({$mssqlUnit}, -{$value}, {$e})";
    }

    public function formatFulltextMatch(string|array $columns, string $searchTerm, ?string $mode = null, bool $withQueryExpansion = false): array|string
    {
        // MSSQL full-text search uses CONTAINS or FREETEXT
        $cols = is_array($columns) ? $columns : [$columns];
        $quotedCols = array_map([$this, 'quoteIdentifier'], $cols);
        $colList = implode(', ', $quotedCols);

        $ph = ':fulltext_search_term';

        // Use CONTAINS for exact phrase matching, FREETEXT for natural language
        if ($mode === 'boolean' || $mode === 'natural language') {
            $sql = "CONTAINS(($colList), {$ph})";
        } else {
            $sql = "FREETEXT(($colList), {$ph})";
        }

        return [$sql, [$ph => $searchTerm]];
    }

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

    public function formatGroupConcat(string|RawValue $column, string $separator, bool $distinct): string
    {
        $col = $this->resolveValue($column);
        $sep = str_replace("'", "''", $separator);
        // MSSQL STRING_AGG supports DISTINCT starting from SQL Server 2022
        // For older versions, we'll use DISTINCT in a subquery if needed
        // For now, we'll use DISTINCT directly (will fail on older versions)
        $dist = $distinct ? 'DISTINCT ' : '';
        // MSSQL uses STRING_AGG with WITHIN GROUP (ORDER BY ...)
        // For ordering, use the column itself
        // Note: MSSQL requires the ORDER BY column to be in the SELECT list or be a simple column reference
        return "STRING_AGG({$dist}{$col}, '{$sep}') WITHIN GROUP (ORDER BY {$col})";
    }

    public function formatTruncate(string|RawValue $value, int $precision): string
    {
        $val = $this->resolveValue($value);
        // MSSQL uses ROUND with third parameter for truncation
        return "ROUND($val, $precision, 1)";
    }

    public function formatPosition(string|RawValue $substring, string|RawValue $value): string
    {
        $sub = $substring instanceof RawValue ? $substring->getValue() : "'" . str_replace("'", "''", (string)$substring) . "'";
        $val = $this->resolveValue($value);
        // MSSQL uses CHARINDEX: CHARINDEX(substring, string, start)
        return "CHARINDEX($sub, $val)";
    }

    public function formatLeft(string|RawValue $value, int $length): string
    {
        $val = $this->resolveValue($value);
        return "LEFT($val, $length)";
    }

    public function formatRight(string|RawValue $value, int $length): string
    {
        $val = $this->resolveValue($value);
        return "RIGHT($val, $length)";
    }

    public function formatRepeat(string|RawValue $value, int $count): string
    {
        $val = $value instanceof RawValue ? $value->getValue() : "'" . str_replace("'", "''", (string)$value) . "'";
        // MSSQL uses REPLICATE
        return "REPLICATE($val, $count)";
    }

    public function formatReverse(string|RawValue $value): string
    {
        $val = $this->resolveValue($value);
        return "REVERSE($val)";
    }

    public function formatPad(string|RawValue $value, int $length, string $padString, bool $isLeft): string
    {
        $val = $this->resolveValue($value);
        $pad = str_replace("'", "''", $padString);
        // MSSQL doesn't have LPAD/RPAD, use REPLICATE + concatenation
        if ($isLeft) {
            return "RIGHT(REPLICATE('{$pad}', $length) + $val, $length)";
        }
        return "LEFT($val + REPLICATE('{$pad}', $length), $length)";
    }

    public function formatRegexpMatch(string|RawValue $value, string $pattern): string
    {
        $val = $this->resolveValue($value);
        // Use registered user-defined function
        // PATINDEX pattern: convert basic regex patterns to SQL Server patterns
        // Note: This is a simplified conversion - full regex requires CLR
        $sqlPattern = $this->convertRegexToSqlPattern($pattern);
        $sqlPatternEscaped = str_replace("'", "''", $sqlPattern);
        // PATINDEX searches anywhere, so add % around pattern
        return "(dbo.regexp_match($val, '%{$sqlPatternEscaped}%') = 1)";
    }

    public function formatRegexpReplace(string|RawValue $value, string $pattern, string $replacement): string
    {
        $val = $this->resolveValue($value);
        $rep = str_replace("'", "''", $replacement);
        // Use registered user-defined function
        // Convert regex pattern to SQL Server pattern
        $sqlPattern = $this->convertRegexToSqlPattern($pattern);
        $sqlPatternEscaped = str_replace("'", "''", $sqlPattern);
        // For REPLACE, use exact pattern match (no % wildcards)
        return "dbo.regexp_replace($val, '{$sqlPatternEscaped}', '{$rep}')";
    }

    public function formatRegexpExtract(string|RawValue $value, string $pattern, ?int $groupIndex = null): string
    {
        $val = $this->resolveValue($value);
        $groupIdx = $groupIndex ?? 0;
        // Use registered user-defined function
        // Convert regex pattern to SQL Server pattern
        $sqlPattern = $this->convertRegexToSqlPattern($pattern);
        $sqlPatternEscaped = str_replace("'", "''", $sqlPattern);
        // PATINDEX searches anywhere, so add % around pattern
        return "dbo.regexp_extract($val, '%{$sqlPatternEscaped}%', $groupIdx)";
    }

    public function formatUnionSelect(string $selectSql, bool $isBaseQuery = false): string
    {
        // MSSQL: Remove TOP from union queries (TOP cannot be used in UNION)
        $result = preg_replace('/^SELECT\s+TOP\s+\(\d+\)\s+/i', 'SELECT ', $selectSql);
        return $result ?? $selectSql;
    }

    /**
     * {@inheritDoc}
     */
    public function formatMaterializedCte(string $cteSql, bool $isMaterialized): string
    {
        // MSSQL doesn't support materialized CTEs
        return $cteSql;
    }

    public function formatGreatest(array $values): string
    {
        $args = $this->resolveValues($values);
        // Apply normalizeRawValue to each argument to convert LENGTH() to LEN(), etc.
        $normalizedArgs = array_map(function ($arg) {
            return $this->normalizeRawValue((string)$arg);
        }, $args);
        // MSSQL GREATEST requires all arguments to be of compatible types
        // If any argument is a CAST/TRY_CAST expression, ensure all are cast to the same type
        $normalizedArgs = $this->normalizeGreatestLeastArgs($normalizedArgs);
        return 'GREATEST(' . implode(', ', $normalizedArgs) . ')';
    }

    public function formatLeast(array $values): string
    {
        $args = $this->resolveValues($values);
        // Apply normalizeRawValue to each argument to convert LENGTH() to LEN(), etc.
        $normalizedArgs = array_map(function ($arg) {
            return $this->normalizeRawValue((string)$arg);
        }, $args);
        // MSSQL LEAST requires all arguments to be of compatible types
        // If any argument is a CAST/TRY_CAST expression, ensure all are cast to the same type
        $normalizedArgs = $this->normalizeGreatestLeastArgs($normalizedArgs);
        return 'LEAST(' . implode(', ', $normalizedArgs) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeRawValue(string $sql): string
    {
        // Don't modify identifiers in square brackets (MSSQL quoted identifiers)
        // Split SQL into parts: identifiers in brackets and everything else
        $parts = preg_split('/(\[[^\]]+\])/', $sql, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            $parts = [];
        }
        $result = '';
        foreach ($parts as $part) {
            if (preg_match('/^\[[^\]]+\]$/', $part)) {
                // This is a quoted identifier, don't modify it
                $result .= $part;
            } else {
                // This is not a quoted identifier, apply normalization
                // Replace LENGTH( with LEN( but be careful not to replace in strings or identifiers
                $replaced = preg_replace('/\bLENGTH\s*\(/i', 'LEN(', $part);
                $part = $replaced !== null ? $replaced : $part;
                // Replace CEIL( with CEILING( (MSSQL uses CEILING instead of CEIL)
                $replaced = preg_replace('/\bCEIL\s*\(/i', 'CEILING(', $part);
                $part = $replaced !== null ? $replaced : $part;
                // MSSQL doesn't support TRUE/FALSE literals, use 1/0 for BIT type
                $replaced = preg_replace('/\bTRUE\b/i', '1', $part);
                $part = $replaced !== null ? $replaced : $part;
                $replaced = preg_replace('/\bFALSE\b/i', '0', $part);
                $part = $replaced !== null ? $replaced : $part;
                // Replace CAST( with TRY_CAST( for safe type conversion in MSSQL
                // This prevents errors when casting invalid values (e.g., 'abc' to INT)
                $replaced = preg_replace('/\bCAST\s*\(/i', 'TRY_CAST(', $part);
                $part = $replaced !== null ? $replaced : $part;
                // MSSQL doesn't support LN(), use LOG() for natural logarithm
                $replaced = preg_replace('/\bLN\s*\(/i', 'LOG(', $part);
                $part = $replaced !== null ? $replaced : $part;
                // MSSQL doesn't support LOG10(), use LOG(10, value) instead
                $replaced = preg_replace_callback('/\bLOG10\s*\(([^)]+)\)/i', function ($matches) {
                    return 'LOG(10, ' . trim($matches[1]) . ')';
                }, $part);
                $part = $replaced !== null ? $replaced : $part;
                $result .= $part;
            }
        }
        return $result;
    }

    /**
     * Register REGEXP functions for MSSQL using SQL Server user-defined functions.
     * Creates T-SQL functions that use PATINDEX and string manipulation for regex matching.
     *
     * Note: This is a basic implementation using PATINDEX. PATINDEX supports SQL Server
     * patterns (%, _, [], [^]), not full regex. For full regex support, SQL Server CLR
     * functions should be used.
     *
     * @param PDO $pdo The PDO instance
     * @param bool $force Force re-registration even if functions exist
     */
    public function registerRegexpFunctions(PDO $pdo, bool $force = false): void
    {
        // Check if REGEXP functions are already available (unless forced)
        if (!$force) {
            try {
                // Check if functions exist in sys.objects
                $stmt = $pdo->query("
                    SELECT COUNT(*) as cnt
                    FROM sys.objects
                    WHERE name IN ('regexp_match', 'regexp_replace', 'regexp_extract')
                    AND type = 'FN'
                    AND schema_id = SCHEMA_ID('dbo')
                ");
                $result = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
                if ($result && (int)$result['cnt'] === 3) {
                    // Functions are already available, skip registration
                    return;
                }
            } catch (\PDOException $e) {
                // Error checking, proceed with registration
            }
        }

        // Create user-defined functions using T-SQL
        // These functions use PATINDEX for basic pattern matching

        // Function for regexp_match (returns 1 if matches, 0 otherwise)
        // DROP and CREATE must be separate statements
        try {
            $pdo->exec("IF OBJECT_ID('dbo.regexp_match', 'FN') IS NOT NULL DROP FUNCTION dbo.regexp_match");
        } catch (\PDOException $e) {
            // Ignore errors if function doesn't exist
        }
        $pdo->exec('
            CREATE FUNCTION dbo.regexp_match(@subject NVARCHAR(MAX), @pattern NVARCHAR(MAX))
            RETURNS BIT
            WITH EXECUTE AS CALLER
            AS
            BEGIN
                -- Use PATINDEX for basic pattern matching
                -- Note: PATINDEX supports SQL Server patterns, not full regex
                -- For full regex support, SQL Server CLR functions should be used
                IF PATINDEX(@pattern, @subject) > 0
                    RETURN 1;
                RETURN 0;
            END
        ');

        // Function for regexp_replace
        try {
            $pdo->exec("IF OBJECT_ID('dbo.regexp_replace', 'FN') IS NOT NULL DROP FUNCTION dbo.regexp_replace");
        } catch (\PDOException $e) {
            // Ignore errors if function doesn't exist
        }
        $pdo->exec('
            CREATE FUNCTION dbo.regexp_replace(@subject NVARCHAR(MAX), @pattern NVARCHAR(MAX), @replacement NVARCHAR(MAX))
            RETURNS NVARCHAR(MAX)
            WITH EXECUTE AS CALLER
            AS
            BEGIN
                -- Basic replacement using REPLACE
                -- For complex regex, use CLR functions
                RETURN REPLACE(@subject, @pattern, @replacement);
            END
        ');

        // Function for regexp_extract
        try {
            $pdo->exec("IF OBJECT_ID('dbo.regexp_extract', 'FN') IS NOT NULL DROP FUNCTION dbo.regexp_extract");
        } catch (\PDOException $e) {
            // Ignore errors if function doesn't exist
        }
        $pdo->exec("
            CREATE FUNCTION dbo.regexp_extract(@subject NVARCHAR(MAX), @pattern NVARCHAR(MAX), @groupIndex INT = 0)
            RETURNS NVARCHAR(MAX)
            WITH EXECUTE AS CALLER
            AS
            BEGIN
                -- Basic extraction using PATINDEX and SUBSTRING
                DECLARE @pos INT = PATINDEX(@pattern, @subject);
                IF @pos > 0
                    RETURN SUBSTRING(@subject, @pos, LEN(@subject));
                RETURN '';
            END
        ");
    }

    /**
     * Convert basic regex patterns to SQL Server PATINDEX patterns.
     * This is a simplified conversion - full regex requires CLR functions.
     *
     * @param string $pattern Regex pattern
     *
     * @return string SQL Server pattern
     */
    protected function convertRegexToSqlPattern(string $pattern): string
    {
        // Basic conversions for common regex patterns
        // Note: This is limited - full regex support requires CLR
        $sqlPattern = $pattern;

        // Escape SQL Server special characters first
        $sqlPattern = str_replace('[', '[[]', $sqlPattern);
        $sqlPattern = str_replace(']', '[]]', $sqlPattern);
        $sqlPattern = str_replace('%', '[%]', $sqlPattern);
        $sqlPattern = str_replace('_', '[_]', $sqlPattern);

        // Convert regex patterns to SQL Server patterns
        // . -> _ (any single character)
        $sqlPattern = str_replace('.', '_', $sqlPattern);
        // * -> % (zero or more characters) - but only if not already escaped
        $replaced = preg_replace('/(?<!\[)(?<!\[\[)\*(?!\])/', '%', $sqlPattern);
        $sqlPattern = $replaced !== null ? $replaced : $sqlPattern;
        // + -> % (one or more) - simplified
        $replaced = preg_replace('/(?<!\[)(?<!\[\[)\+(?!\])/', '%', $sqlPattern);
        $sqlPattern = $replaced !== null ? $replaced : $sqlPattern;
        // ? -> _ (zero or one) - simplified
        $replaced = preg_replace('/(?<!\[)(?<!\[\[)\?(?!\])/', '_', $sqlPattern);
        $sqlPattern = $replaced !== null ? $replaced : $sqlPattern;

        // Remove regex anchors (^ and $) as PATINDEX searches anywhere
        $sqlPattern = str_replace('^', '', $sqlPattern);
        $sqlPattern = str_replace('$', '', $sqlPattern);

        return $sqlPattern;
    }

    /**
     * Normalize arguments for GREATEST/LEAST to ensure type compatibility in MSSQL.
     * If any argument is a CAST/TRY_CAST expression, cast all other arguments to DECIMAL(10,2).
     *
     * @param array<int|string, string|int|float> $args
     *
     * @return array<int, string>
     */
    protected function normalizeGreatestLeastArgs(array $args): array
    {
        $hasCast = false;
        $castType = 'DECIMAL(10,2)';

        // Check if any argument is a CAST/TRY_CAST expression
        foreach ($args as $arg) {
            $argStr = (string)$arg;
            if (preg_match('/\b(TRY_CAST|CAST)\s*\([^)]+\s+AS\s+([^)]+)\)/i', $argStr, $matches)) {
                $hasCast = true;
                // Extract the type from CAST expression
                $extractedType = trim($matches[2]);
                // Normalize common numeric types to DECIMAL(10,2)
                if (preg_match('/\b(INT|INTEGER|SIGNED|UNSIGNED|BIGINT|SMALLINT|TINYINT)\b/i', $extractedType)) {
                    $castType = 'DECIMAL(10,2)';
                } elseif (preg_match('/\b(REAL|FLOAT|DOUBLE|NUMERIC|DECIMAL)\b/i', $extractedType)) {
                    $castType = 'DECIMAL(10,2)';
                }
                break;
            }
        }

        if (!$hasCast) {
            // No CAST found, check if we have mixed types (strings and numbers)
            // If so, cast all to DECIMAL(10,2) for safety
            $hasNumeric = false;
            $hasString = false;
            foreach ($args as $arg) {
                $argStr = trim((string)$arg);
                // Check if it's a quoted string or column name (not a number)
                if (preg_match('/^[\'"`\[]/', $argStr) || preg_match('/^[a-zA-Z_]/', $argStr)) {
                    $hasString = true;
                } elseif (is_numeric($argStr)) {
                    $hasNumeric = true;
                }
            }
            if ($hasString && $hasNumeric) {
                $hasCast = true;
            }
        }

        if ($hasCast) {
            // Cast all arguments to the same type
            $normalized = [];
            foreach ($args as $arg) {
                $argStr = (string)$arg;
                // If already a CAST/TRY_CAST, extract the value and recast to target type
                if (preg_match('/\b(TRY_CAST|CAST)\s*\(([^)]+)\s+AS\s+[^)]+\)/i', $argStr, $matches)) {
                    $value = trim($matches[2]);
                    $normalized[] = "TRY_CAST({$value} AS {$castType})";
                } else {
                    // Cast to target type
                    $normalized[] = "TRY_CAST({$argStr} AS {$castType})";
                }
            }
            return $normalized;
        }

        // Convert all arguments to strings and ensure numeric keys
        return array_values(array_map(fn ($arg) => (string)$arg, $args));
    }
}
