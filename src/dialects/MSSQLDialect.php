<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use tommyknocker\pdodb\dialects\traits\JsonPathBuilderTrait;
use tommyknocker\pdodb\dialects\traits\UpsertBuilderTrait;
use tommyknocker\pdodb\helpers\values\ConcatValue;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\schema\ColumnSchema;

class MSSQLDialect extends DialectAbstract
{
    use JsonPathBuilderTrait;
    use UpsertBuilderTrait;

    /**
     * {@inheritDoc}
     */
    public function getDriverName(): string
    {
        return 'sqlsrv';
    }

    /**
     * {@inheritDoc}
     */
    public function supportsLateralJoin(): bool
    {
        // MSSQL supports CROSS APPLY / OUTER APPLY (equivalent to LATERAL JOIN)
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsJoinInUpdateDelete(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function buildUpdateWithJoinSql(
        string $table,
        string $setClause,
        array $joins,
        string $whereClause,
        ?int $limit = null,
        string $options = ''
    ): string {
        // MSSQL UPDATE with JOIN syntax: UPDATE t SET ... FROM t JOIN ...
        // MSSQL doesn't support LIMIT in UPDATE, use TOP instead
        $topClause = $limit !== null ? "TOP ({$limit}) " : '';
        $sql = "UPDATE {$topClause}{$options}{$table} SET {$setClause}";
        if (!empty($joins)) {
            $sql .= ' FROM ' . $table . ' ' . implode(' ', $joins);
        }
        $sql .= $whereClause;
        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDeleteWithJoinSql(
        string $table,
        array $joins,
        string $whereClause,
        string $options = ''
    ): string {
        // MSSQL DELETE with JOIN syntax: DELETE t FROM t JOIN ...
        $sql = "DELETE {$options}{$table} FROM {$table}";
        if (!empty($joins)) {
            $sql .= ' ' . implode(' ', $joins);
        }
        $sql .= $whereClause;
        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDsn(array $params): string
    {
        foreach (['host', 'dbname', 'username', 'password'] as $requiredParam) {
            if (empty($params[$requiredParam])) {
                throw new InvalidArgumentException("Missing '$requiredParam' parameter");
            }
        }
        $port = $params['port'] ?? 1433;
        $dsn = "sqlsrv:Server={$params['host']},{$port};Database={$params['dbname']}";
        
        // Add SSL options (default to TrustServerCertificate=yes for self-signed certs)
        $trustCert = $params['trust_server_certificate'] ?? true;
        $encrypt = $params['encrypt'] ?? true;
        $dsn .= ';TrustServerCertificate=' . ($trustCert ? 'yes' : 'no');
        $dsn .= ';Encrypt=' . ($encrypt ? 'yes' : 'no');
        
        return $dsn;
    }

    /**
     * {@inheritDoc}
     */
    public function defaultPdoOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function quoteIdentifier(mixed $name): string
    {
        return $name instanceof RawValue ? $name->getValue() : "[{$name}]";
    }

    /**
     * {@inheritDoc}
     */
    public function quoteTable(mixed $table): string
    {
        // Support for schema.table and alias: [schema].[table] AS [t]
        $parts = explode(' ', $table, 3);
        $name = $parts[0];
        $alias = $parts[1] ?? null;

        $segments = explode('.', $name);
        $quotedSegments = array_map(static fn ($s) => '[' . str_replace(']', ']]', $s) . ']', $segments);
        $quoted = implode('.', $quotedSegments);

        if ($alias) {
            return $quoted . ' ' . implode(' ', array_slice($parts, 1));
        }
        return $quoted;
    }

    /**
     * {@inheritDoc}
     */
    public function buildInsertSql(string $table, array $columns, array $placeholders, array $options = []): string
    {
        $cols = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
        $vals = implode(', ', $placeholders);
        $prefix = 'INSERT' . ($options ? ' ' . implode(' ', $options) : '') . ' INTO';
        return sprintf('%s %s (%s) VALUES (%s)', $prefix, $table, $cols, $vals);
    }

    /**
     * {@inheritDoc}
     */
    public function buildInsertSelectSql(
        string $table,
        array $columns,
        string $selectSql,
        array $options = []
    ): string {
        $prefix = 'INSERT' . ($options ? ' ' . implode(' ', $options) : '') . ' INTO';
        $colsSql = '';
        if (!empty($columns)) {
            $colsSql = ' (' . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . ')';
        }
        return sprintf('%s %s%s %s', $prefix, $table, $colsSql, $selectSql);
    }

    /**
     * {@inheritDoc}
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

    /**
     * {@inheritDoc}
     */
    public function buildUpsertClause(array $updateColumns, string $defaultConflictTarget = 'id', string $tableName = ''): string
    {
        if (!$updateColumns) {
            return '';
        }

        // MSSQL doesn't support ON CONFLICT or ON DUPLICATE KEY UPDATE
        // Use MERGE statement instead, but for compatibility with INSERT ... ON DUPLICATE pattern,
        // we'll throw an exception suggesting to use MERGE directly
        // Alternatively, we could emulate using a subquery, but that's complex
        throw new RuntimeException(
            'MSSQL does not support UPSERT via INSERT ... ON DUPLICATE KEY UPDATE. ' .
            'Please use QueryBuilder::merge() method instead for UPSERT operations.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function supportsMerge(): bool
    {
        return true; // MSSQL has native MERGE support
    }

    /**
     * {@inheritDoc}
     */
    public function buildMergeSql(
        string $targetTable,
        string $sourceSql,
        string $onClause,
        array $whenClauses
    ): string {
        $target = $this->quoteTable($targetTable);
        $sql = "MERGE {$target} AS target\n";
        $sql .= "USING ({$sourceSql}) AS source\n";
        $sql .= "ON {$onClause}\n";

        if (!empty($whenClauses['whenMatched'])) {
            $sql .= "WHEN MATCHED THEN\n";
            $sql .= "  UPDATE SET {$whenClauses['whenMatched']}\n";
        }

        if (!empty($whenClauses['whenNotMatched'])) {
            $sql .= "WHEN NOT MATCHED THEN\n";
            $sql .= "  INSERT {$whenClauses['whenNotMatched']}\n";
        }

        if (!empty($whenClauses['whenNotMatchedBySourceDelete']) && $whenClauses['whenNotMatchedBySourceDelete']) {
            $sql .= "WHEN NOT MATCHED BY SOURCE THEN DELETE\n";
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildReplaceSql(string $table, array $columns, array $placeholders, bool $isMultiple = false): string
    {
        // MSSQL doesn't have REPLACE, use MERGE or DELETE + INSERT
        // For simplicity, use DELETE + INSERT pattern
        $cols = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
        $vals = implode(', ', $placeholders);
        return "DELETE FROM {$table}; INSERT INTO {$table} ({$cols}) VALUES ({$vals})";
    }

    /**
     * {@inheritDoc}
     */
    protected function buildIncrementExpression(string $colSql, array $expr, string $tableName): string
    {
        if (!isset($expr['__op']) || !isset($expr['val'])) {
            return "{$colSql} = INSERTED.{$colSql}";
        }
        $op = $expr['__op'];
        $val = $expr['val'];
        if ($op === 'inc') {
            return "{$colSql} = INSERTED.{$colSql} + {$val}";
        }
        if ($op === 'dec') {
            return "{$colSql} = INSERTED.{$colSql} - {$val}";
        }
        return "{$colSql} = INSERTED.{$colSql}";
    }

    /**
     * {@inheritDoc}
     */
    protected function buildRawValueExpression(string $colSql, RawValue $expr, string $tableName, string $col): string
    {
        $value = $expr->getValue();
        // Replace INSERTED.column references if needed
        $value = str_replace("VALUES({$col})", "INSERTED.{$col}", $value);
        return "{$colSql} = {$value}";
    }

    /**
     * {@inheritDoc}
     */
    protected function buildDefaultExpression(string $colSql, mixed $expr, string $col): string
    {
        if ($expr instanceof RawValue) {
            return $this->buildRawValueExpression($colSql, $expr, '', $col);
        }
        if (is_string($expr)) {
            return "{$colSql} = '{$expr}'";
        }
        return "{$colSql} = {$expr}";
    }

    /**
     * {@inheritDoc}
     */
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

    /**
     * {@inheritDoc}
     */
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

    /**
     * {@inheritDoc}
     */
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

    /**
     * {@inheritDoc}
     */
    public function formatJsonRemove(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        $colQuoted = $this->quoteIdentifier($col);
        
        // MSSQL JSON_MODIFY with NULL removes the path
        return "JSON_MODIFY({$colQuoted}, '{$jsonPath}', NULL)";
    }

    /**
     * {@inheritDoc}
     */
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

    /**
     * {@inheritDoc}
     */
    public function formatJsonExists(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        $colQuoted = $this->quoteIdentifier($col);
        
        // MSSQL: Check if JSON path exists using ISJSON or JSON_VALUE IS NOT NULL
        return "(ISJSON(JSON_QUERY({$colQuoted}, '{$jsonPath}')) = 1 OR JSON_VALUE({$colQuoted}, '{$jsonPath}') IS NOT NULL)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonOrderExpr(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = $this->buildJsonPathString($parts);
        $colQuoted = $this->quoteIdentifier($col);
        
        // Use JSON_VALUE for ordering (returns text, can be cast to numeric)
        $value = "JSON_VALUE({$colQuoted}, '{$jsonPath}')";
        return "CAST({$value} AS FLOAT)";
    }

    /**
     * {@inheritDoc}
     */
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

    /**
     * {@inheritDoc}
     */
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

    /**
     * {@inheritDoc}
     */
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

    /**
     * {@inheritDoc}
     */
    public function formatIfNull(string $expr, mixed $default): string
    {
        return "ISNULL($expr, {$this->formatDefaultValue($default)})";
    }

    /**
     * {@inheritDoc}
     */
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

    /**
     * {@inheritDoc}
     */
    public function formatMod(string|RawValue $dividend, string|RawValue $divisor): string
    {
        $d1 = $this->resolveValue($dividend);
        $d2 = $this->resolveValue($divisor);
        return "($d1 % $d2)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatCurDate(): string
    {
        return 'CAST(GETDATE() AS DATE)';
    }

    /**
     * {@inheritDoc}
     */
    public function formatCurTime(): string
    {
        return 'CAST(GETDATE() AS TIME)';
    }

    /**
     * {@inheritDoc}
     */
    public function formatYear(string|RawValue $value): string
    {
        return "YEAR({$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatMonth(string|RawValue $value): string
    {
        return "MONTH({$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatDay(string|RawValue $value): string
    {
        return "DAY({$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatHour(string|RawValue $value): string
    {
        return "DATEPART(HOUR, {$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatMinute(string|RawValue $value): string
    {
        return "DATEPART(MINUTE, {$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatSecond(string|RawValue $value): string
    {
        return "DATEPART(SECOND, {$this->resolveValue($value)})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatDateOnly(string|RawValue $value): string
    {
        return 'CAST(' . $this->resolveValue($value) . ' AS DATE)';
    }

    /**
     * {@inheritDoc}
     */
    public function formatTimeOnly(string|RawValue $value): string
    {
        return 'CAST(' . $this->resolveValue($value) . ' AS TIME)';
    }

    /**
     * {@inheritDoc}
     */
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

    /**
     * {@inheritDoc}
     */
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
    public function supportsFilterClause(): bool
    {
        return false; // MSSQL does not support FILTER clause
    }

    /**
     * {@inheritDoc}
     */
    public function supportsDistinctOn(): bool
    {
        return false; // MSSQL does not support DISTINCT ON
    }

    /**
     * {@inheritDoc}
     */
    public function supportsMaterializedCte(): bool
    {
        return false; // MSSQL does not support MATERIALIZED CTE
    }

    /**
     * {@inheritDoc}
     */
    public function formatGroupConcat(string|RawValue $column, string $separator, bool $distinct): string
    {
        $col = $this->resolveValue($column);
        $sep = str_replace("'", "''", $separator);
        $dist = $distinct ? 'DISTINCT ' : '';
        // MSSQL uses STRING_AGG with WITHIN GROUP (ORDER BY ...)
        // For ordering, use the column itself
        return "STRING_AGG({$dist}{$col}, '{$sep}') WITHIN GROUP (ORDER BY {$col})";
    }

    /**
     * {@inheritDoc}
     */
    public function formatTruncate(string|RawValue $value, int $precision): string
    {
        $val = $this->resolveValue($value);
        // MSSQL uses ROUND with third parameter for truncation
        return "ROUND($val, $precision, 1)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatPosition(string|RawValue $substring, string|RawValue $value): string
    {
        $sub = $substring instanceof RawValue ? $substring->getValue() : "'" . str_replace("'", "''", (string)$substring) . "'";
        $val = $this->resolveValue($value);
        // MSSQL uses CHARINDEX: CHARINDEX(substring, string, start)
        return "CHARINDEX($sub, $val)";
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
        $val = $value instanceof RawValue ? $value->getValue() : "'" . str_replace("'", "''", (string)$value) . "'";
        // MSSQL uses REPLICATE
        return "REPLICATE($val, $count)";
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
        $pad = str_replace("'", "''", $padString);
        // MSSQL doesn't have LPAD/RPAD, use REPLICATE + concatenation
        if ($isLeft) {
            return "RIGHT(REPLICATE('{$pad}', $length) + $val, $length)";
        }
        return "LEFT($val + REPLICATE('{$pad}', $length), $length)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpMatch(string|RawValue $value, string $pattern): string
    {
        $val = $this->resolveValue($value);
        $pat = str_replace("'", "''", $pattern);
        // MSSQL uses LIKE with wildcards or PATINDEX for regex-like matching
        // For basic regex, use PATINDEX (returns position, 0 if not found)
        return "(PATINDEX('%{$pat}%', $val) > 0)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpReplace(string|RawValue $value, string $pattern, string $replacement): string
    {
        $val = $this->resolveValue($value);
        $pat = str_replace("'", "''", $pattern);
        $rep = str_replace("'", "''", $replacement);
        // MSSQL doesn't have native regex replace, use REPLACE for simple patterns
        // For complex regex, would need CLR functions or manual string manipulation
        return "REPLACE($val, '{$pat}', '{$rep}')";
    }

    /**
     * {@inheritDoc}
     */
    public function formatRegexpExtract(string|RawValue $value, string $pattern, ?int $groupIndex = null): string
    {
        $val = $this->resolveValue($value);
        $pat = str_replace("'", "''", $pattern);
        // MSSQL doesn't have native regex extract
        // Use SUBSTRING with PATINDEX for basic extraction
        $start = "PATINDEX('%{$pat}%', $val)";
        return "SUBSTRING($val, $start, LEN($val))";
    }

    /**
     * {@inheritDoc}
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

            $s = (string)$part;

            // already quoted literal?
            if (preg_match("/^'.*'\$/s", $s) || preg_match('/^".*"\$/s', $s)) {
                $mapped[] = $s;
                continue;
            }

            // Check if it's a simple string literal:
            // - Contains spaces OR special chars like : ; ! ? etc. (but not SQL operators)
            // - Does NOT contain SQL operators or parentheses
            // - Does NOT look like SQL keywords
            // - OR is a simple word (starts with letter, contains only letters/numbers, no dots)
            //   that is not a known SQL keyword
            $isSimpleWord = preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $s) && !str_contains($s, '.');
            $isSqlKeyword = preg_match('/^(SELECT|FROM|WHERE|AND|OR|JOIN|NULL|TRUE|FALSE|AS|ON|IN|IS|LIKE|BETWEEN|EXISTS|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|TABLE|INDEX|PRIMARY|KEY|FOREIGN|CONSTRAINT|UNIQUE|CHECK|DEFAULT|NOT|GROUP|BY|ORDER|HAVING|LIMIT|OFFSET|DISTINCT|COUNT|SUM|AVG|MAX|MIN)$/i', $s);
            
            if (
                (
                    preg_match('/[\s:;!?@#\$&]/', $s) ||
                    ($isSimpleWord && !$isSqlKeyword)
                ) &&
                !preg_match('/[()%<>=+*\/]/', $s)
            ) {
                // Quote as string literal
                $mapped[] = "'" . str_replace("'", "''", $s) . "'";
                continue;
            }

            // contains parentheses, math/comparison operators — treat as raw SQL
            if (preg_match('/[()%<>=]/', $s)) {
                $mapped[] = $s;
                continue;
            }

            // simple identifier — quote with square brackets
            $pieces = explode('.', $s);
            foreach ($pieces as &$p) {
                $p = '[' . str_replace(']', ']]', $p) . ']';
            }
            $mapped[] = implode('.', $pieces);
        }

        // MSSQL uses + operator for concatenation
        $sql = implode(' + ', $mapped);

        return new RawValue($sql);
    }

    /**
     * {@inheritDoc}
     */
    public function now(?string $diff = '', bool $asTimestamp = false): string
    {
        if ($diff === '' || $diff === null) {
            return $asTimestamp ? 'GETDATE()' : 'GETDATE()';
        }
        
        // Parse diff like "+1 DAY" or "-2 MONTH"
        preg_match('/^([+-]?)\s*(\d+)\s+(\w+)$/i', $diff, $matches);
        if (count($matches) === 4) {
            $sign = $matches[1] === '-' ? '-' : '';
            $value = $matches[2];
            $unit = strtoupper($matches[3]);
            
            $mssqlUnit = match ($unit) {
                'DAY' => 'day',
                'MONTH' => 'month',
                'YEAR' => 'year',
                'HOUR' => 'hour',
                'MINUTE' => 'minute',
                'SECOND' => 'second',
                'WEEK' => 'week',
                default => 'day',
            };
            
            return "DATEADD({$mssqlUnit}, {$sign}{$value}, GETDATE())";
        }
        
        return 'GETDATE()';
    }

    /**
     * {@inheritDoc}
     */
    public function buildExplainSql(string $query): string
    {
        // MSSQL uses SET SHOWPLAN_ALL ON or SET STATISTICS IO ON
        // For compatibility, return the query wrapped in SHOWPLAN
        return "SET SHOWPLAN_ALL ON; {$query}; SET SHOWPLAN_ALL OFF";
    }

    /**
     * {@inheritDoc}
     */
    public function buildExplainAnalyzeSql(string $query): string
    {
        // MSSQL uses SET STATISTICS IO ON and SET STATISTICS TIME ON
        return "SET STATISTICS IO ON; SET STATISTICS TIME ON; {$query}; SET STATISTICS IO OFF; SET STATISTICS TIME OFF";
    }

    /**
     * {@inheritDoc}
     */
    public function buildTableExistsSql(string $table): string
    {
        // MSSQL: Check if table exists in INFORMATION_SCHEMA
        $parts = explode('.', $table);
        if (count($parts) === 2) {
            $schema = $parts[0];
            $tableName = $parts[1];
            return "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$schema}' AND TABLE_NAME = '{$tableName}'";
        }
        return "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = '{$table}'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDescribeSql(string $table): string
    {
        // MSSQL: Get column information from INFORMATION_SCHEMA
        $parts = explode('.', $table);
        if (count($parts) === 2) {
            $schema = $parts[0];
            $tableName = $parts[1];
            return "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$schema}' AND TABLE_NAME = '{$tableName}' ORDER BY ORDINAL_POSITION";
        }
        return "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '{$table}' ORDER BY ORDINAL_POSITION";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowIndexesSql(string $table): string
    {
        $parts = explode('.', $table);
        if (count($parts) === 2) {
            $schema = $parts[0];
            $tableName = $parts[1];
            return "SELECT i.name AS IndexName, i.type_desc AS IndexType, ic.key_ordinal AS KeyOrdinal, c.name AS ColumnName FROM sys.indexes i INNER JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id INNER JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id WHERE OBJECT_SCHEMA_NAME(i.object_id) = '{$schema}' AND OBJECT_NAME(i.object_id) = '{$tableName}' ORDER BY i.name, ic.key_ordinal";
        }
        return "SELECT i.name AS IndexName, i.type_desc AS IndexType, ic.key_ordinal AS KeyOrdinal, c.name AS ColumnName FROM sys.indexes i INNER JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id INNER JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id WHERE OBJECT_NAME(i.object_id) = '{$table}' ORDER BY i.name, ic.key_ordinal";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowForeignKeysSql(string $table): string
    {
        $parts = explode('.', $table);
        if (count($parts) === 2) {
            $schema = $parts[0];
            $tableName = $parts[1];
            return "SELECT fk.name AS CONSTRAINT_NAME, c.name AS COLUMN_NAME, OBJECT_SCHEMA_NAME(fk.referenced_object_id) AS REFERENCED_TABLE_SCHEMA, OBJECT_NAME(fk.referenced_object_id) AS REFERENCED_TABLE_NAME, rc.name AS REFERENCED_COLUMN_NAME FROM sys.foreign_keys fk INNER JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id INNER JOIN sys.columns c ON fkc.parent_object_id = c.object_id AND fkc.parent_column_id = c.column_id INNER JOIN sys.columns rc ON fkc.referenced_object_id = rc.object_id AND fkc.referenced_column_id = rc.column_id WHERE OBJECT_SCHEMA_NAME(fk.parent_object_id) = '{$schema}' AND OBJECT_NAME(fk.parent_object_id) = '{$tableName}'";
        }
        return "SELECT fk.name AS CONSTRAINT_NAME, c.name AS COLUMN_NAME, OBJECT_SCHEMA_NAME(fk.referenced_object_id) AS REFERENCED_TABLE_SCHEMA, OBJECT_NAME(fk.referenced_object_id) AS REFERENCED_TABLE_NAME, rc.name AS REFERENCED_COLUMN_NAME FROM sys.foreign_keys fk INNER JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id INNER JOIN sys.columns c ON fkc.parent_object_id = c.object_id AND fkc.parent_column_id = c.column_id INNER JOIN sys.columns rc ON fkc.referenced_object_id = rc.object_id AND fkc.referenced_column_id = rc.column_id WHERE OBJECT_NAME(fk.parent_object_id) = '{$table}'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowConstraintsSql(string $table): string
    {
        $parts = explode('.', $table);
        if (count($parts) === 2) {
            $schema = $parts[0];
            $tableName = $parts[1];
            return "SELECT tc.CONSTRAINT_NAME, tc.CONSTRAINT_TYPE, tc.TABLE_NAME, kcu.COLUMN_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA AND tc.TABLE_NAME = kcu.TABLE_NAME WHERE tc.TABLE_SCHEMA = '{$schema}' AND tc.TABLE_NAME = '{$tableName}'";
        }
        return "SELECT tc.CONSTRAINT_NAME, tc.CONSTRAINT_TYPE, tc.TABLE_NAME, kcu.COLUMN_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA AND tc.TABLE_NAME = kcu.TABLE_NAME WHERE tc.TABLE_NAME = '{$table}'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildLockSql(array $tables, string $prefix, string $lockMethod): string
    {
        // MSSQL uses table hints: WITH (TABLOCKX) for exclusive lock
        $hints = [];
        foreach ($tables as $table) {
            $tableQuoted = $this->quoteTable($table);
            if ($lockMethod === 'WRITE' || $lockMethod === 'EXCLUSIVE') {
                $hints[] = "{$tableQuoted} WITH (TABLOCKX)";
            } else {
                $hints[] = "{$tableQuoted} WITH (TABLOCK)";
            }
        }
        return implode(', ', $hints);
    }

    /**
     * {@inheritDoc}
     */
    public function buildUnlockSql(): string
    {
        // MSSQL doesn't have explicit UNLOCK, locks are released on transaction commit/rollback
        return '';
    }

    /**
     * {@inheritDoc}
     */
    public function buildTruncateSql(string $table): string
    {
        return 'TRUNCATE TABLE ' . $this->quoteTable($table);
    }

    /**
     * Get current database name.
     *
     * @return string
     */
    protected function getCurrentDatabase(): string
    {
        if ($this->pdo === null) {
            return '';
        }
        $stmt = $this->pdo->query('SELECT DB_NAME()');
        if ($stmt === false) {
            return '';
        }
        $result = $stmt->fetchColumn();
        return (string)($result ?: '');
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateTableSql(
        string $table,
        array $columns,
        array $options = []
    ): string {
        $tableQuoted = $this->quoteTable($table);
        $columnDefs = [];
        $primaryKeyColumn = null;

        foreach ($columns as $name => $def) {
            if ($def instanceof ColumnSchema) {
                $columnDefs[] = $this->formatColumnDefinition($name, $def);
                if ($def->isAutoIncrement() && $primaryKeyColumn === null) {
                    $primaryKeyColumn = $name;
                }
            } elseif (is_array($def)) {
                $schema = $this->parseColumnDefinition($def);
                $columnDefs[] = $this->formatColumnDefinition($name, $schema);
                if ($schema->isAutoIncrement() && $primaryKeyColumn === null) {
                    $primaryKeyColumn = $name;
                }
            } else {
                $schema = new ColumnSchema((string)$def);
                $columnDefs[] = $this->formatColumnDefinition($name, $schema);
                if ($schema->isAutoIncrement() && $primaryKeyColumn === null) {
                    $primaryKeyColumn = $name;
                }
            }
        }

        // Add PRIMARY KEY if AUTO_INCREMENT column exists
        if ($primaryKeyColumn !== null) {
            $columnDefs[] = 'PRIMARY KEY (' . $this->quoteIdentifier($primaryKeyColumn) . ')';
        }

        $sql = "CREATE TABLE {$tableQuoted} (\n    " . implode(",\n    ", $columnDefs) . "\n)";

        // MSSQL table options
        if (!empty($options['on'])) {
            $sql .= ' ON ' . $options['on'];
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropTableSql(string $table): string
    {
        return 'DROP TABLE ' . $this->quoteTable($table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropTableIfExistsSql(string $table): string
    {
        // MSSQL 2016+ supports IF EXISTS
        return 'DROP TABLE IF EXISTS ' . $this->quoteTable($table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddColumnSql(
        string $table,
        string $column,
        ColumnSchema $schema
    ): string {
        $tableQuoted = $this->quoteTable($table);
        $columnDef = $this->formatColumnDefinition($column, $schema);
        return "ALTER TABLE {$tableQuoted} ADD {$columnDef}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropColumnSql(string $table, string $column): string
    {
        $tableQuoted = $this->quoteTable($table);
        $columnQuoted = $this->quoteIdentifier($column);
        return "ALTER TABLE {$tableQuoted} DROP COLUMN {$columnQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildAlterColumnSql(
        string $table,
        string $column,
        ColumnSchema $schema
    ): string {
        $tableQuoted = $this->quoteTable($table);
        $columnQuoted = $this->quoteIdentifier($column);
        $columnDef = $this->formatColumnDefinition($column, $schema);
        // MSSQL uses ALTER COLUMN
        return "ALTER TABLE {$tableQuoted} ALTER COLUMN {$columnDef}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameColumnSql(string $table, string $oldName, string $newName): string
    {
        $tableQuoted = $this->quoteTable($table);
        $oldQuoted = $this->quoteIdentifier($oldName);
        $newQuoted = $this->quoteIdentifier($newName);
        // MSSQL uses sp_rename stored procedure
        return "EXEC sp_rename '{$tableQuoted}.{$oldQuoted}', '{$newQuoted}', 'COLUMN'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateIndexSql(string $name, string $table, array $columns, bool $unique = false): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $type = $unique ? 'UNIQUE INDEX' : 'INDEX';
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $colsList = implode(', ', $colsQuoted);
        return "CREATE {$type} {$nameQuoted} ON {$tableQuoted} ({$colsList})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropIndexSql(string $name, string $table): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        return "DROP INDEX {$nameQuoted} ON {$tableQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddForeignKeySql(
        string $name,
        string $table,
        array $columns,
        string $refTable,
        array $refColumns,
        ?string $delete = null,
        ?string $update = null
    ): string {
        $tableQuoted = $this->quoteTable($table);
        $refTableQuoted = $this->quoteTable($refTable);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $refColsQuoted = array_map([$this, 'quoteIdentifier'], $refColumns);
        $colsList = implode(', ', $colsQuoted);
        $refColsList = implode(', ', $refColsQuoted);

        $sql = "ALTER TABLE {$tableQuoted} ADD CONSTRAINT {$nameQuoted}";
        $sql .= " FOREIGN KEY ({$colsList}) REFERENCES {$refTableQuoted} ({$refColsList})";

        if ($delete !== null) {
            $sql .= ' ON DELETE ' . strtoupper($delete);
        }
        if ($update !== null) {
            $sql .= ' ON UPDATE ' . strtoupper($update);
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropForeignKeySql(string $name, string $table): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        return "ALTER TABLE {$tableQuoted} DROP CONSTRAINT {$nameQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameTableSql(string $table, string $newName): string
    {
        $tableQuoted = $this->quoteTable($table);
        $newQuoted = $this->quoteIdentifier($newName);
        // MSSQL uses sp_rename stored procedure
        return "EXEC sp_rename '{$tableQuoted}', '{$newQuoted}'";
    }

    /**
     * {@inheritDoc}
     */
    public function formatColumnDefinition(string $name, ColumnSchema $schema): string
    {
        $quotedName = $this->quoteIdentifier($name);
        $type = $schema->getType();
        $length = $schema->getLength();
        $precision = $schema->getPrecision();
        $scale = $schema->getScale();
        $null = $schema->isNullable() ? 'NULL' : 'NOT NULL';
        $default = $schema->getDefault();
        $autoIncrement = $schema->isAutoIncrement();

        // Build type with length/precision
        $typeDef = $type;
        if ($length !== null) {
            $typeDef .= "({$length})";
        } elseif ($precision !== null && $scale !== null) {
            $typeDef .= "({$precision},{$scale})";
        } elseif ($precision !== null) {
            $typeDef .= "({$precision})";
        }

        // Handle AUTO_INCREMENT -> IDENTITY
        if ($autoIncrement) {
            $typeDef .= ' IDENTITY(1,1)';
        }

        $sql = "{$quotedName} {$typeDef} {$null}";

        if ($default !== null && !$autoIncrement) {
            if (is_string($default)) {
                $sql .= " DEFAULT '{$default}'";
            } else {
                $sql .= " DEFAULT {$default}";
            }
        }

        return $sql;
    }

    /**
     * Parse column definition array to ColumnSchema.
     *
     * @param array<string, mixed> $def
     */
    protected function parseColumnDefinition(array $def): ColumnSchema
    {
        $type = $def['type'] ?? 'NVARCHAR(255)';
        $schema = new ColumnSchema($type);
        
        if (isset($def['null'])) {
            $schema->setNullable((bool)$def['null']);
        }
        if (isset($def['default'])) {
            $schema->setDefault($def['default']);
        }
        if (isset($def['auto_increment']) || isset($def['autoIncrement'])) {
            $schema->setAutoIncrement(true);
        }
        if (isset($def['length'])) {
            $schema->setLength((int)$def['length']);
        }
        if (isset($def['precision'])) {
            $schema->setPrecision((int)$def['precision']);
        }
        if (isset($def['scale'])) {
            $schema->setScale((int)$def['scale']);
        }
        
        return $schema;
    }
}

