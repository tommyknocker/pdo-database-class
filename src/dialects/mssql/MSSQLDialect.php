<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mssql;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use tommyknocker\pdodb\dialects\DialectAbstract;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\dialects\mssql\MSSQLFeatureSupport;
use tommyknocker\pdodb\dialects\traits\JsonPathBuilderTrait;
use tommyknocker\pdodb\dialects\traits\UpsertBuilderTrait;
use tommyknocker\pdodb\helpers\values\ConcatValue;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;
use tommyknocker\pdodb\query\schema\ColumnSchema;

class MSSQLDialect extends DialectAbstract
{
    use JsonPathBuilderTrait;
    use UpsertBuilderTrait;

    /** @var MSSQLFeatureSupport Feature support instance */
    private MSSQLFeatureSupport $featureSupport;

    public function __construct()
    {
        $this->featureSupport = new MSSQLFeatureSupport();
    }

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
        return $this->featureSupport->supportsLateralJoin();
    }

    /**
     * {@inheritDoc}
     */
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
     */
    public function supportsJoinInUpdateDelete(): bool
    {
        return $this->featureSupport->supportsJoinInUpdateDelete();
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
     * MSSQL: NTEXT/NVARCHAR(MAX) doesn't work with LOWER directly, need CAST to NVARCHAR(MAX).
     */
    public function ilike(string $column, string $pattern): RawValue
    {
        // Use CAST to NVARCHAR(MAX) to ensure compatibility with NTEXT, NVARCHAR(MAX), TEXT, etc.
        return new RawValue("LOWER(CAST($column AS NVARCHAR(MAX))) LIKE LOWER(CAST(:pattern AS NVARCHAR(MAX)))", ['pattern' => $pattern]);
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

        // MSSQL requires CTE to be before INSERT statement
        // If selectSql starts with WITH, we need to split CTE and SELECT parts
        // Pattern: WITH ... SELECT ... -> WITH ... INSERT INTO ... SELECT ...
        if (preg_match('/^(\s*WITH\s+.*?)(\s+SELECT\s+.*)$/is', $selectSql, $matches)) {
            $ctePart = $matches[1];
            $selectPart = $matches[2];
            return sprintf('%s %s%s%s', $ctePart, $prefix, $table, $colsSql) . $selectPart;
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
        return $this->featureSupport->supportsMerge();
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
        // For string source (table name), wrap in SELECT * FROM for MSSQL
        if (!str_starts_with(trim($sourceSql), '(') && !str_starts_with(trim($sourceSql), 'SELECT')) {
            $sourceSql = "SELECT * FROM {$sourceSql}";
        }
        $sql .= "USING ({$sourceSql}) AS source\n";
        $sql .= "ON {$onClause}\n";

        if (!empty($whenClauses['whenMatched'])) {
            $sql .= "WHEN MATCHED THEN\n";
            $sql .= "  UPDATE SET {$whenClauses['whenMatched']}\n";
        }

        if (!empty($whenClauses['whenNotMatched'])) {
            $insertClause = $whenClauses['whenNotMatched'];
            // MSSQL MERGE INSERT requires: INSERT (columns) VALUES (source.columns)
            // Replace MERGE_SOURCE_COLUMN_ markers with source.column for MSSQL
            $insertClause = preg_replace('/MERGE_SOURCE_COLUMN_(\w+)/', 'source.$1', $insertClause);

            // MSSQL MERGE INSERT format: (columns) VALUES (values)
            // Extract columns and values from the clause
            if ($insertClause !== null && preg_match('/^\(([^)]+)\)\s+VALUES\s+\(([^)]+)\)/', $insertClause, $matches)) {
                $columns = $matches[1];
                $values = $matches[2];
                $sql .= "WHEN NOT MATCHED THEN\n";
                $sql .= "  INSERT ({$columns}) VALUES ({$values})\n";
            } else {
                // Fallback: use as-is
                $sql .= "WHEN NOT MATCHED THEN\n";
                $sql .= "  INSERT {$insertClause}\n";
            }
        }

        if (!empty($whenClauses['whenNotMatchedBySourceDelete'])) {
            $sql .= "WHEN NOT MATCHED BY SOURCE THEN DELETE\n";
        }

        // MSSQL requires semicolon after MERGE statement
        $sql .= ';';

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
        /** @var array<string> $placeholders */
        $vals = implode(', ', $placeholders);
        return "DELETE FROM {$table}; INSERT INTO {$table} ({$cols}) VALUES ({$vals})";
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $expr
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
        return $this->featureSupport->supportsFilterClause();
    }

    /**
     * {@inheritDoc}
     */
    public function supportsDistinctOn(): bool
    {
        return $this->featureSupport->supportsDistinctOn();
    }

    /**
     * {@inheritDoc}
     */
    public function supportsMaterializedCte(): bool
    {
        return $this->featureSupport->supportsMaterializedCte();
    }

    /**
     * {@inheritDoc}
     */
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
     * {@inheritDoc}
     */
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

    /**
     * {@inheritDoc}
     */
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

    /**
     * {@inheritDoc}
     */
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
                // MSSQL requires explicit CAST for concatenating numbers with strings
                // Convert number to string using CAST
                $mapped[] = "CAST({$part} AS NVARCHAR)";
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
            // Simple words without spaces/special chars are treated as identifiers, not literals
            $isSimpleWord = preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $s) && !str_contains($s, '.');
            $isSqlKeyword = preg_match('/^(SELECT|FROM|WHERE|AND|OR|JOIN|NULL|TRUE|FALSE|AS|ON|IN|IS|LIKE|BETWEEN|EXISTS|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|TABLE|INDEX|PRIMARY|KEY|FOREIGN|CONSTRAINT|UNIQUE|CHECK|DEFAULT|NOT|GROUP|BY|ORDER|HAVING|LIMIT|OFFSET|DISTINCT|COUNT|SUM|AVG|MAX|MIN)$/i', $s);

            // Only quote as string literal if it contains spaces or special chars (not operators)
            // Simple words are treated as identifiers
            if (
                preg_match('/[\s:;!?@#\$&]/', $s) &&
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
            if ($asTimestamp) {
                // MSSQL doesn't have UNIX_TIMESTAMP, use DATEDIFF(SECOND, '1970-01-01', GETDATE())
                return "DATEDIFF(SECOND, '1970-01-01', GETDATE())";
            }
            return 'GETDATE()';
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

            $dateExpr = "DATEADD({$mssqlUnit}, {$sign}{$value}, GETDATE())";
            if ($asTimestamp) {
                // Convert to Unix timestamp
                return "DATEDIFF(SECOND, '1970-01-01', {$dateExpr})";
            }
            return $dateExpr;
        }

        if ($asTimestamp) {
            return "DATEDIFF(SECOND, '1970-01-01', GETDATE())";
        }
        return 'GETDATE()';
    }

    /**
     * {@inheritDoc}
     */
    public function buildExplainSql(string $query): string
    {
        // MSSQL uses SET SHOWPLAN_ALL ON
        // Note: SET SHOWPLAN statements must be the only statements in the batch
        // So we return just the query - the caller should handle SET SHOWPLAN separately
        return $query;
    }

    /**
     * {@inheritDoc}
     */
    public function buildExplainAnalyzeSql(string $query): string
    {
        // MSSQL uses SET STATISTICS XML ON for explain analyze
        // Return the query as-is; executeExplainAnalyze will handle SET STATISTICS XML ON/OFF
        return $query;
    }

    /**
     * {@inheritDoc}
     */
    public function executeExplain(\PDO $pdo, string $sql, array $params = []): array
    {
        // MSSQL SET SHOWPLAN_ALL ON doesn't work well with PDO prepared statements
        // Return a minimal execution plan structure to satisfy the API
        // In production, consider using sys.dm_exec_query_plan or SET STATISTICS XML ON
        return [
            [
                'StmtText' => $sql,
                'Rows' => 0,
                'EstimateRows' => 0,
                'TotalSubtreeCost' => 0,
                'StatementType' => 'SELECT',
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function executeExplainAnalyze(\PDO $pdo, string $sql, array $params = []): array
    {
        // For MSSQL, SET STATISTICS XML ON returns XML in messages, not in result set
        // Execute the query and return a minimal result to satisfy the API
        // Note: $sql here is the original query (buildExplainAnalyzeSql returns it as-is)
        $pdo->query('SET STATISTICS XML ON');

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $stmt->closeCursor();
            $pdo->query('SET STATISTICS XML OFF');
            // Return minimal result structure
            return [['Query' => $sql, 'Statistics' => 'XML output available']];
        } catch (\PDOException $e) {
            try {
                $pdo->query('SET STATISTICS XML OFF');
            } catch (\PDOException $ignored) {
                // Ignore errors when turning off STATISTICS XML
            }

            throw $e;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function formatUnionSelect(string $selectSql, bool $isBaseQuery = false): string
    {
        // MSSQL: Remove TOP from union queries (TOP cannot be used in UNION)
        $result = preg_replace('/^SELECT\s+TOP\s+\(\d+\)\s+/i', 'SELECT ', $selectSql);
        return $result ?? $selectSql;
    }

    /**
     * {@inheritDoc}
     */
    public function needsUnionParentheses(): bool
    {
        // MSSQL requires parentheses around each SELECT in UNION
        return true;
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
     * {@inheritDoc}
     */
    public function buildExistsExpression(string $subquery): string
    {
        // MSSQL doesn't support SELECT EXISTS(...), use CASE WHEN EXISTS(...) THEN 1 ELSE 0 END
        return 'SELECT CASE WHEN EXISTS(' . $subquery . ') THEN 1 ELSE 0 END';
    }

    /**
     * {@inheritDoc}
     */
    public function supportsLimitInExists(): bool
    {
        return $this->featureSupport->supportsLimitInExists();
    }

    /**
     * Build NOT EXISTS expression for MSSQL.
     *
     * @param string $subquery Subquery SQL
     *
     * @return string NOT EXISTS expression SQL
     */
    public function buildNotExistsExpression(string $subquery): string
    {
        // MSSQL doesn't support SELECT NOT EXISTS(...), use CASE WHEN NOT EXISTS(...) THEN 1 ELSE 0 END
        return 'SELECT CASE WHEN NOT EXISTS(' . $subquery . ') THEN 1 ELSE 0 END';
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
        // MSSQL: Get column information from INFORMATION_SCHEMA including IDENTITY flag
        $parts = explode('.', $table);
        if (count($parts) === 2) {
            $schema = $parts[0];
            $tableName = $parts[1];
            return "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMNPROPERTY(OBJECT_ID('{$schema}.{$tableName}'), COLUMN_NAME, 'IsIdentity') AS is_identity FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$schema}' AND TABLE_NAME = '{$tableName}' ORDER BY ORDINAL_POSITION";
        }
        return "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMNPROPERTY(OBJECT_ID('{$table}'), COLUMN_NAME, 'IsIdentity') AS is_identity FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '{$table}' ORDER BY ORDINAL_POSITION";
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
        // MSSQL doesn't support LOCK TABLES like MySQL
        // Table-level locking should be done via transaction isolation levels or table hints in queries
        throw new RuntimeException('Table locking is not supported by MSSQL. Use transaction isolation levels or table hints in queries instead.');
    }

    /**
     * {@inheritDoc}
     */
    public function buildUnlockSql(): string
    {
        // MSSQL doesn't support UNLOCK TABLES like MySQL
        throw new RuntimeException('Table unlocking is not supported by MSSQL.');
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

        // Add PRIMARY KEY constraint from options if specified
        if (!empty($options['primaryKey'])) {
            $pkColumns = is_array($options['primaryKey']) ? $options['primaryKey'] : [$options['primaryKey']];
            $pkQuoted = array_map([$this, 'quoteIdentifier'], $pkColumns);
            $columnDefs[] = 'PRIMARY KEY (' . implode(', ', $pkQuoted) . ')';
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

        // MSSQL requires dropping default constraints before dropping the column
        // Extract table name without schema for OBJECT_ID
        $tableParts = explode('.', $table);
        $tableNameOnly = end($tableParts);
        $tableNameOnly = trim($tableNameOnly, '[]');
        $columnNameOnly = trim($column, '[]');

        // Build SQL batch that drops default constraints first, then the column
        // MSSQL allows multiple statements separated by semicolons
        $dropConstraintsSql = "
            DECLARE @constraintName NVARCHAR(255);
            DECLARE constraint_cursor CURSOR FOR
                SELECT name FROM sys.default_constraints
                WHERE parent_object_id = OBJECT_ID('{$tableNameOnly}')
                AND parent_column_id = COLUMNPROPERTY(OBJECT_ID('{$tableNameOnly}'), '{$columnNameOnly}', 'ColumnId');
            OPEN constraint_cursor;
            FETCH NEXT FROM constraint_cursor INTO @constraintName;
            WHILE @@FETCH_STATUS = 0
            BEGIN
                DECLARE @sql NVARCHAR(MAX) = 'ALTER TABLE {$tableQuoted} DROP CONSTRAINT [' + @constraintName + ']';
                EXEC sp_executesql @sql;
                FETCH NEXT FROM constraint_cursor INTO @constraintName;
            END;
            CLOSE constraint_cursor;
            DEALLOCATE constraint_cursor;
        ";

        // Return SQL batch that drops constraints first, then the column
        return $dropConstraintsSql . ";\nALTER TABLE {$tableQuoted} DROP COLUMN {$columnQuoted}";
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
        // sp_rename requires unquoted names in string format: 'schema.table.column'
        // Remove brackets for all sp_rename parameters (like buildRenameTableSql)
        $tableUnquoted = str_replace(['[', ']'], '', $this->quoteTable($table));
        $oldUnquoted = str_replace(['[', ']'], '', $this->quoteIdentifier($oldName));
        $newNameClean = trim($newName, '[]');
        // MSSQL uses sp_rename stored procedure
        return "EXEC sp_rename '{$tableUnquoted}.{$oldUnquoted}', '{$newNameClean}', 'COLUMN'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateIndexSql(
        string $name,
        string $table,
        array $columns,
        bool $unique = false,
        ?string $where = null,
        ?array $includeColumns = null,
        array $options = []
    ): string {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $type = $unique ? 'UNIQUE INDEX' : 'INDEX';

        // Process columns with sorting support and RawValue support (functional indexes)
        $colsQuoted = [];
        foreach ($columns as $col) {
            if (is_array($col)) {
                // Array format: ['column' => 'ASC'/'DESC'] - associative array with column name as key
                foreach ($col as $colName => $direction) {
                    // $colName is always string (array key), $direction is the sort direction
                    $dir = strtoupper((string)$direction) === 'DESC' ? 'DESC' : 'ASC';
                    $colsQuoted[] = $this->quoteIdentifier((string)$colName) . ' ' . $dir;
                }
            } elseif ($col instanceof RawValue) {
                // RawValue expression (functional index)
                $colsQuoted[] = $col->getValue();
            } else {
                $colsQuoted[] = $this->quoteIdentifier((string)$col);
            }
        }
        $colsList = implode(', ', $colsQuoted);

        $sql = "CREATE {$type} {$nameQuoted} ON {$tableQuoted} ({$colsList})";

        // Add INCLUDE columns if provided
        if ($includeColumns !== null && !empty($includeColumns)) {
            $includeQuoted = array_map([$this, 'quoteIdentifier'], $includeColumns);
            $sql .= ' INCLUDE (' . implode(', ', $includeQuoted) . ')';
        }

        // Add WHERE clause for filtered indexes (MSSQL 2008+)
        if ($where !== null && $where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        // Add options (fillfactor, etc.)
        if (!empty($options['fillfactor'])) {
            $sql .= ' WITH (FILLFACTOR = ' . (int)$options['fillfactor'] . ')';
        }

        return $sql;
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
    public function buildCreateFulltextIndexSql(string $name, string $table, array $columns, ?string $parser = null): string
    {
        // MSSQL uses FULLTEXT CATALOG and FULLTEXT INDEX
        // This is a simplified version - full implementation would require catalog management
        throw new \RuntimeException(
            'MSSQL fulltext indexes require FULLTEXT CATALOG setup. ' .
            'Please use CREATE FULLTEXT INDEX directly or set up the catalog first.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateSpatialIndexSql(string $name, string $table, array $columns): string
    {
        // MSSQL supports spatial indexes via CREATE SPATIAL INDEX
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $colsList = implode(', ', $colsQuoted);
        return "CREATE SPATIAL INDEX {$nameQuoted} ON {$tableQuoted} ({$colsList})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameIndexSql(string $oldName, string $table, string $newName): string
    {
        // MSSQL uses sp_rename for renaming indexes
        $tableUnquoted = str_replace(['[', ']'], '', $this->quoteTable($table));
        $oldUnquoted = str_replace(['[', ']'], '', $this->quoteIdentifier($oldName));
        $newNameClean = trim($newName, '[]');
        return "EXEC sp_rename '{$tableUnquoted}.{$oldUnquoted}', '{$newNameClean}', 'INDEX'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameForeignKeySql(string $oldName, string $table, string $newName): string
    {
        // MSSQL uses sp_rename for renaming foreign keys
        $tableUnquoted = str_replace(['[', ']'], '', $this->quoteTable($table));
        $oldUnquoted = str_replace(['[', ']'], '', $this->quoteIdentifier($oldName));
        $newNameClean = trim($newName, '[]');
        return "EXEC sp_rename '{$tableUnquoted}.{$oldUnquoted}', '{$newNameClean}', 'OBJECT'";
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
    public function buildAddPrimaryKeySql(string $name, string $table, array $columns): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $colsList = implode(', ', $colsQuoted);
        return "ALTER TABLE {$tableQuoted} ADD CONSTRAINT {$nameQuoted} PRIMARY KEY ({$colsList})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropPrimaryKeySql(string $name, string $table): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        return "ALTER TABLE {$tableQuoted} DROP CONSTRAINT {$nameQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddUniqueSql(string $name, string $table, array $columns): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $colsList = implode(', ', $colsQuoted);
        return "ALTER TABLE {$tableQuoted} ADD CONSTRAINT {$nameQuoted} UNIQUE ({$colsList})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropUniqueSql(string $name, string $table): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        return "ALTER TABLE {$tableQuoted} DROP CONSTRAINT {$nameQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddCheckSql(string $name, string $table, string $expression): string
    {
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        return "ALTER TABLE {$tableQuoted} ADD CONSTRAINT {$nameQuoted} CHECK ({$expression})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropCheckSql(string $name, string $table): string
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
        // MSSQL sp_rename doesn't need brackets in the new name parameter
        // Remove brackets if present to avoid "already in use" errors
        $newNameClean = trim($newName, '[]');
        // Don't quote the new name - sp_rename expects unquoted name
        // MSSQL uses sp_rename stored procedure
        return "EXEC sp_rename '{$tableQuoted}', '{$newNameClean}'";
    }

    /**
     * {@inheritDoc}
     */
    public function formatColumnDefinition(string $name, ColumnSchema $schema): string
    {
        $quotedName = $this->quoteIdentifier($name);
        $type = $schema->getType();
        $length = $schema->getLength();
        $precision = $length; // In ColumnSchema, length is used for precision
        $scale = $schema->getScale();
        $null = $schema->isNotNull() ? 'NOT NULL' : 'NULL';
        $default = $schema->getDefaultValue();
        $autoIncrement = $schema->isAutoIncrement();

        // Build type with length/precision
        // BIT type in MSSQL doesn't accept length specification
        $typeDef = $type;
        if ($type === 'BIT') {
            // BIT doesn't accept length in MSSQL
            $typeDef = 'BIT';
        } elseif ($type === 'TEXT' || $type === 'LONGTEXT' || $type === 'MEDIUMTEXT' || $type === 'TINYTEXT') {
            // MSSQL doesn't have TEXT type - use NVARCHAR(MAX) instead
            $typeDef = 'NVARCHAR(MAX)';
        } elseif ($type === 'NVARCHAR' && $length === null) {
            // NVARCHAR without length defaults to NVARCHAR(MAX) in MSSQL
            $typeDef = 'NVARCHAR(MAX)';
        } elseif ($type === 'VARCHAR' && $length === null) {
            // VARCHAR without length defaults to VARCHAR(MAX) in MSSQL
            $typeDef = 'VARCHAR(MAX)';
        } elseif ($length !== null) {
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
            if ($schema->isDefaultExpression()) {
                // Handle default expressions (e.g., CURRENT_TIMESTAMP -> GETDATE() for MSSQL)
                $defaultExpr = (string)$default;
                if (strtoupper($defaultExpr) === 'CURRENT_TIMESTAMP') {
                    $sql .= ' DEFAULT GETDATE()';
                } else {
                    $sql .= " DEFAULT {$defaultExpr}";
                }
            } else {
                // BIT type in MSSQL uses 0/1 for boolean values
                if ($type === 'BIT') {
                    $bitValue = ($default === true || $default === 1 || $default === '1' || $default === 'true') ? 1 : 0;
                    $sql .= " DEFAULT {$bitValue}";
                } elseif (is_string($default)) {
                    $sql .= " DEFAULT '{$default}'";
                } else {
                    $sql .= " DEFAULT {$default}";
                }
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
        // Convert BOOLEAN to BIT for MSSQL
        if (strtoupper($type) === 'BOOLEAN') {
            $type = 'BIT';
        }
        $length = isset($def['length']) ? (int)$def['length'] : (isset($def['precision']) ? (int)$def['precision'] : null);
        $scale = isset($def['scale']) ? (int)$def['scale'] : null;
        // BIT doesn't accept length in MSSQL
        if ($type === 'BIT') {
            $length = null;
        }
        $schema = new ColumnSchema($type, $length, $scale);

        if (isset($def['null'])) {
            if ((bool)$def['null']) {
                $schema->null();
            } else {
                $schema->notNull();
            }
        }
        if (isset($def['default'])) {
            $schema->defaultValue($def['default']);
        }
        if (isset($def['auto_increment']) || isset($def['autoIncrement'])) {
            $schema->autoIncrement();
        }

        return $schema;
    }

    /**
     * {@inheritDoc}
     */
    public function getBooleanType(): array
    {
        return ['type' => 'BIT', 'length' => null];
    }

    /**
     * {@inheritDoc}
     */
    public function getTimestampType(): string
    {
        // MSSQL can only have one TIMESTAMP column per table, so use DATETIME instead
        return 'DATETIME';
    }

    /**
     * {@inheritDoc}
     */
    public function getDatetimeType(): string
    {
        return 'DATETIME';
    }

    /**
     * {@inheritDoc}
     */
    public function isNoFieldsError(\PDOException $e): bool
    {
        $errorMessage = $e->getMessage();
        // MSSQL throws "The active result for the query contains no fields" for DDL/DDL-like queries
        return str_contains($errorMessage, 'contains no fields') ||
               str_contains($errorMessage, 'IMSSP');
    }

    /**
     * {@inheritDoc}
     */
    public function appendLimitOffset(string $sql, int $limit, int $offset): string
    {
        // MSSQL uses OFFSET ... FETCH NEXT ... ROWS ONLY
        // Check if ORDER BY exists in SQL
        if (stripos($sql, 'ORDER BY') === false) {
            // MSSQL requires ORDER BY for OFFSET/FETCH
            // Use a simple ordering that works for any query
            return $sql . " ORDER BY (SELECT NULL) OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        } else {
            // ORDER BY exists, just add OFFSET/FETCH
            return $sql . " OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getPrimaryKeyType(): string
    {
        return 'INT';
    }

    /**
     * {@inheritDoc}
     */
    public function getBigPrimaryKeyType(): string
    {
        return 'BIGINT';
    }

    /**
     * {@inheritDoc}
     */
    public function getStringType(): string
    {
        // MSSQL uses NVARCHAR for Unicode strings
        return 'NVARCHAR';
    }

    /**
     * {@inheritDoc}
     */
    public function getTextType(): string
    {
        // MSSQL doesn't have TEXT type - use NVARCHAR(MAX)
        // Conversion to NVARCHAR(MAX) happens in formatColumnDefinition
        return 'TEXT';
    }

    /**
     * {@inheritDoc}
     */
    public function getCharType(): string
    {
        // MSSQL uses NCHAR for Unicode fixed-length strings
        return 'NCHAR';
    }

    /**
     * {@inheritDoc}
     */
    public function formatMaterializedCte(string $cteSql, bool $isMaterialized): string
    {
        // MSSQL doesn't support materialized CTEs
        return $cteSql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildMigrationTableSql(string $tableName): string
    {
        // MSSQL requires explicit length for VARCHAR/NVARCHAR in PRIMARY KEY
        $tableQuoted = $this->quoteTable($tableName);
        return "CREATE TABLE {$tableQuoted} (
            version NVARCHAR(255) PRIMARY KEY,
            apply_time DATETIME DEFAULT GETDATE(),
            batch INT NOT NULL
        )";
    }

    /**
     * {@inheritDoc}
     */
    public function getExplainParser(): ExplainParserInterface
    {
        return new \tommyknocker\pdodb\query\analysis\parsers\MSSQLExplainParser();
    }

    /**
     * {@inheritDoc}
     */
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

    /**
     * {@inheritDoc}
     */
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
     * Normalize arguments for GREATEST/LEAST to ensure type compatibility in MSSQL.
     * If any argument is a CAST/TRY_CAST expression, cast all other arguments to DECIMAL(10,2).
     *
     * @param array<int, string|int|float> $args
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

        // Convert all arguments to strings
        return array_map(fn ($arg) => (string)$arg, $args);
    }

    /**
     * {@inheritDoc}
     */
    public function getRecursiveCteKeyword(): string
    {
        // MSSQL uses just 'WITH' for recursive CTEs, not 'WITH RECURSIVE'
        return 'WITH';
    }
}
