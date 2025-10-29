<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use tommyknocker\pdodb\dialects\traits\JsonPathBuilderTrait;
use tommyknocker\pdodb\dialects\traits\UpsertBuilderTrait;
use tommyknocker\pdodb\helpers\values\RawValue;

class PostgreSQLDialect extends DialectAbstract
{
    use JsonPathBuilderTrait;
    use UpsertBuilderTrait;
    /**
     * {@inheritDoc}
     */
    public function getDriverName(): string
    {
        return 'pgsql';
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
        return "pgsql:host={$params['host']};dbname={$params['dbname']}"
            . (!empty($params['hostaddr']) ? ";hostaddr={$params['hostaddr']}" : '')
            . (!empty($params['service']) ? ";service={$params['service']}" : '')
            . (!empty($params['port']) ? ";port={$params['port']}" : '')
            . (!empty($params['options']) ? ";options={$params['options']}" : '')
            . (!empty($params['sslmode']) ? ";sslmode={$params['sslmode']}" : '')
            . (!empty($params['sslkey']) ? ";sslkey={$params['sslkey']}" : '')
            . (!empty($params['sslcert']) ? ";sslcert={$params['sslcert']}" : '')
            . (!empty($params['sslrootcert']) ? ";sslrootcert={$params['sslrootcert']}" : '')
            . (!empty($params['application_name']) ? ";application_name={$params['application_name']}" : '')
            . (!empty($params['connect_timeout']) ? ";connect_timeout={$params['connect_timeout']}" : '')
            . (!empty($params['target_session_attrs']) ? ";target_session_attrs={$params['target_session_attrs']}" : '');
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
    public function quoteIdentifier(string $name): string
    {
        return "\"{$name}\"";
    }

    /**
     * {@inheritDoc}
     */
    public function quoteTable(mixed $table): string
    {
        return $this->quoteTableWithAlias($table);
    }

    /**
     * {@inheritDoc}
     */
    public function buildInsertSql(string $table, array $columns, array $placeholders, array $options = []): string
    {
        $cols = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
        $vals = implode(', ', $placeholders);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, $cols, $vals);

        $tail = [];
        $beforeValues = []; // e.g., OVERRIDING ...
        foreach ($options as $opt) {
            $u = strtoupper(trim($opt));
            if (str_starts_with($u, 'RETURNING')) {
                $tail[] = $opt;
            } elseif (str_starts_with($u, 'ONLY')) {
                $result = preg_replace(
                    '/^INSERT INTO\s+' . preg_quote($table, '/') . '/i',
                    'INSERT INTO ONLY ' . $table,
                    $sql,
                    1
                );
                $sql = $result ?? $sql;
            } elseif (str_starts_with($u, 'OVERRIDING')) {
                // insert before VALUES
                $beforeValues[] = $opt;
            } elseif (str_starts_with($u, 'ON CONFLICT')) {
                // ON CONFLICT goes after VALUES and before RETURNING
                $tail[] = $opt;
            } else {
                // move unknown option to tail by default
                $tail[] = $opt;
            }
        }

        if (!empty($beforeValues)) {
            $result = preg_replace('/\)\s+VALUES\s+/i', ') ' . implode(' ', $beforeValues) . ' VALUES ', $sql, 1);
            $sql = $result ?? $sql;
        }

        if (!empty($tail)) {
            $sql .= ' ' . implode(' ', $tail);
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $options
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
    public function buildUpsertClause(array $updateColumns, string $defaultConflictTarget = 'id', string $tableName = ''): string
    {
        if (!$updateColumns) {
            return '';
        }

        $isAssoc = $this->isAssociativeArray($updateColumns);

        if ($isAssoc) {
            $parts = $this->buildUpsertExpressions($updateColumns, $tableName);
        } else {
            $parts = [];
            foreach ($updateColumns as $c) {
                $parts[] = "{$this->quoteIdentifier($c)} = EXCLUDED.{$this->quoteIdentifier($c)}";
            }
        }

        return "ON CONFLICT ({$this->quoteIdentifier($defaultConflictTarget)}) DO UPDATE SET " . implode(', ', $parts);
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $expr
     */
    protected function buildIncrementExpression(string $colSql, array $expr, string $tableName): string
    {
        if (!isset($expr['__op']) || !isset($expr['val'])) {
            return "{$colSql} = EXCLUDED.{$colSql}";
        }

        $op = $expr['__op'];
        // For inc/dec we reference the old table value
        $tableRef = $tableName ? $this->quoteTable($tableName) . '.' : '';
        return match ($op) {
            'inc' => "{$colSql} = {$tableRef}{$colSql} + " . (int)$expr['val'],
            'dec' => "{$colSql} = {$tableRef}{$colSql} - " . (int)$expr['val'],
            default => "{$colSql} = EXCLUDED.{$colSql}",
        };
    }

    /**
     * {@inheritDoc}
     */
    protected function buildRawValueExpression(string $colSql, RawValue $expr, string $tableName, string $col): string
    {
        // For RawValue with column references, replace with table.column
        $exprStr = $expr->getValue();
        if ($tableName) {
            $quotedCol = $this->quoteIdentifier($col);
            $tableRef = $this->quoteTable($tableName);
            $replacement = $tableRef . '.' . $quotedCol;

            $safeExpr = $this->replaceColumnReferences($exprStr, $col, $replacement);
            return "{$colSql} = {$safeExpr}";
        }
        return "{$colSql} = {$exprStr}";
    }

    /**
     * {@inheritDoc}
     */
    protected function buildDefaultExpression(string $colSql, mixed $expr, string $col): string
    {
        $exprStr = trim((string)$expr);

        if (preg_match('/^(?:EXCLUDED\.)?[A-Za-z_][A-Za-z0-9_]*$/i', $exprStr)) {
            if (stripos($exprStr, 'EXCLUDED.') === 0) {
                return "{$colSql} = {$exprStr}";
            }
            return "{$colSql} = EXCLUDED.{$this->quoteIdentifier($exprStr)}";
        }

        $quotedCol = $this->quoteIdentifier($col);
        $replacement = 'excluded.' . $quotedCol;

        $safeExpr = $this->replaceColumnReferences($exprStr, $col, $replacement);
        return "{$colSql} = {$safeExpr}";
    }

    /**
     * {@inheritDoc}
     *
     * @param array<int, string> $columns
     * @param array<int, string|array<int, string>> $placeholders
     */
    public function buildReplaceSql(
        string $table,
        array $columns,
        array $placeholders,
        bool $isMultiple = false
    ): string {
        $tableSql = $this->quoteTable($table);
        $colsSql = implode(',', array_map([$this, 'quoteIdentifier'], $columns));

        if ($isMultiple) {
            // $placeholders is expected to be an array of strings where each string
            // is a list of placeholders without outer parentheses or already wrapped.
            // Normalize to: VALUES (row1),(row2),...
            $rows = [];
            foreach ($placeholders as $ph) {
                // if the element already has outer parentheses — keep it, otherwise wrap it
                $phStr = is_array($ph) ? implode(',', $ph) : $ph;
                $phTrim = trim($phStr);
                if (str_starts_with($phTrim, '(') && str_ends_with($phTrim, ')')) {
                    $rows[] = $phTrim;
                } else {
                    $rows[] = '(' . $phTrim . ')';
                }
            }
            $valsSql = implode(',', $rows);
        } else {
            // single insert: ensure parentheses around the list
            $stringPlaceholders = array_map(static fn ($p) => is_array($p) ? implode(',', $p) : $p, $placeholders);
            $phList = implode(',', $stringPlaceholders);
            $valsSql = '(' . $phList . ')';
        }

        if (in_array('id', $columns, true)) {
            $updates = [];
            foreach ($columns as $col) {
                if ($col === 'id') {
                    continue;
                }
                $quoted = $this->quoteIdentifier($col);
                $updates[] = sprintf('%s = EXCLUDED.%s', $quoted, $quoted);
            }
            $updateSql = empty($updates) ? 'DO NOTHING' : 'DO UPDATE SET ' . implode(', ', $updates);
            return sprintf(
                'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) %s',
                $tableSql,
                $colsSql,
                $valsSql,
                $this->quoteIdentifier('id'),
                $updateSql
            );
        }

        return sprintf('INSERT INTO %s (%s) VALUES %s ON CONFLICT DO NOTHING', $tableSql, $colsSql, $valsSql);
    }

    /**
     * {@inheritDoc}
     */
    public function now(?string $diff = '', bool $asTimestamp = false): string
    {
        if (!$diff) {
            return $asTimestamp ? 'EXTRACT(EPOCH FROM NOW())' : 'NOW()';
        }

        $trimmedDif = trim($diff);
        if (preg_match('/^([+-]?)(\d+)\s+([A-Za-z]+)$/', $trimmedDif, $matches)) {
            $sign = $matches[1] === '-' ? '-' : '+';
            $value = $matches[2];
            $unit = strtolower($matches[3]);

            if ($asTimestamp) {
                return "EXTRACT(EPOCH FROM (NOW() {$sign} INTERVAL '{$value} {$unit}'))";
            }

            return "NOW() {$sign} INTERVAL '{$value} {$unit}'";
        }

        if ($asTimestamp) {
            return "EXTRACT(EPOCH FROM (NOW() + INTERVAL '{$trimmedDif}'))";
        }

        return "NOW() + INTERVAL '{$trimmedDif}'";
    }

    /**
     * {@inheritDoc}
     */
    public function ilike(string $column, string $pattern): RawValue
    {
        return new RawValue("$column ILIKE :pattern", ['pattern' => $pattern]);
    }

    /**
     * {@inheritDoc}
     */
    public function buildExplainSql(string $query): string
    {
        return 'EXPLAIN ' . $query;
    }

    /**
     * {@inheritDoc}
     */
    public function buildExplainAnalyzeSql(string $query): string
    {
        return 'EXPLAIN ANALYZE ' . $query;
    }

    /**
     * {@inheritDoc}
     */
    public function buildTableExistsSql(string $table): string
    {
        return "SELECT to_regclass('{$table}') IS NOT NULL AS exists";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDescribeSql(string $table): string
    {
        return "SELECT column_name, data_type, is_nullable, column_default
                FROM information_schema.columns
                WHERE table_name = '{$table}'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildLockSql(array $tables, string $prefix, string $lockMethod): string
    {
        $list = implode(', ', array_map(fn ($t) => $this->quoteIdentifier($prefix . $t), $tables));
        return "LOCK TABLE {$list} IN ACCESS EXCLUSIVE MODE";
    }

    /**
     * {@inheritDoc}
     */
    public function buildUnlockSql(): string
    {
        // PostgreSQL does not need unlock
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
     * {@inheritDoc}
     */
    public function buildLoadCsvSql(string $table, string $filePath, array $options = []): string
    {
        if ($this->pdo === null) {
            throw new RuntimeException('PDO instance not set. Call setPdo() first.');
        }
        $pdo = $this->pdo;

        $tableSql = $this->quoteIdentifier($table);
        $quotedPath = $pdo->quote($filePath);

        $delimiter = $options['fieldChar'] ?? ',';
        $quoteChar = $options['fieldEnclosure'] ?? '"';
        $header = isset($options['header']) && (bool)$options['header'];

        $columnsSql = '';
        if (!empty($options['fields']) && is_array($options['fields'])) {
            $columnsSql = ' (' . implode(', ', array_map([$this, 'quoteIdentifier'], $options['fields'])) . ')';
        }

        $del = $this->pdo->quote($delimiter);
        $quo = $this->pdo->quote($quoteChar);

        return sprintf(
            'COPY %s%s FROM %s WITH (FORMAT csv, HEADER %s, DELIMITER %s, QUOTE %s)',
            $tableSql,
            $columnsSql,
            $quotedPath,
            $header ? 'true' : 'false',
            $del,
            $quo
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildLoadCsvSqlGenerator(string $table, string $filePath, array $options = []): \Generator
    {
        // PostgreSQL uses native COPY which loads entire file at once
        yield $this->buildLoadCsvSql($table, $filePath, $options);
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
     *
     * @throws \JsonException
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
     *
     * @throws \JsonException
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
    public function formatFulltextMatch(string|array $columns, string $searchTerm, ?string $mode = null, bool $withQueryExpansion = false): array|string
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $quotedCols = array_map([$this, 'quoteIdentifier'], $cols);
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
    public function buildShowIndexesSql(string $table): string
    {
        return "SELECT
            indexname as index_name,
            tablename as table_name,
            indexdef as definition
            FROM pg_indexes
            WHERE tablename = '{$table}'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowForeignKeysSql(string $table): string
    {
        return "SELECT
            tc.constraint_name,
            kcu.column_name,
            ccu.table_name AS referenced_table_name,
            ccu.column_name AS referenced_column_name
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.key_column_usage AS kcu
                ON tc.constraint_name = kcu.constraint_name
            JOIN information_schema.constraint_column_usage AS ccu
                ON ccu.constraint_name = tc.constraint_name
            WHERE tc.constraint_type = 'FOREIGN KEY'
            AND tc.table_name = '{$table}'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowConstraintsSql(string $table): string
    {
        return "SELECT
            tc.constraint_name,
            tc.constraint_type,
            tc.table_name,
            kcu.column_name
            FROM information_schema.table_constraints tc
            LEFT JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
            WHERE tc.table_name = '{$table}'";
    }
}
