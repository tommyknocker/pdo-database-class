<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

use InvalidArgumentException;
use PDO;
use tommyknocker\pdodb\dialects\sqlite\SqliteFeatureSupport;
use tommyknocker\pdodb\dialects\traits\JsonPathBuilderTrait;
use tommyknocker\pdodb\dialects\traits\UpsertBuilderTrait;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\helpers\values\ConfigValue;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\query\analysis\parsers\ExplainParserInterface;
use tommyknocker\pdodb\query\schema\ColumnSchema;

class SqliteDialect extends DialectAbstract
{
    use JsonPathBuilderTrait;
    use UpsertBuilderTrait;

    /** @var SqliteFeatureSupport Feature support instance */
    private SqliteFeatureSupport $featureSupport;

    public function __construct()
    {
        $this->featureSupport = new SqliteFeatureSupport();
    }
    /**
     * {@inheritDoc}
     */
    public function getDriverName(): string
    {
        return 'sqlite';
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
        // This method should not be called if supportsJoinInUpdateDelete() returns false
        // But we implement it for interface compliance
        throw new QueryException('JOIN in UPDATE statements is not supported by SQLite');
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
        // This method should not be called if supportsJoinInUpdateDelete() returns false
        // But we implement it for interface compliance
        throw new QueryException('JOIN in DELETE statements is not supported by SQLite');
    }

    /**
     * {@inheritDoc}
     */
    public function buildDsn(array $params): string
    {
        if (!isset($params['path'])) {
            throw new InvalidArgumentException("Missing 'path' parameter");
        }
        // SQLite cache parameter is a string (shared/private), not an array
        // Filter out cache config arrays used for query result caching
        $sqliteCache = null;
        if (isset($params['cache']) && is_string($params['cache'])) {
            $validCacheModes = ['shared', 'private'];
            if (!in_array(strtolower($params['cache']), $validCacheModes, true)) {
                throw new InvalidArgumentException(
                    'Invalid SQLite cache parameter. Must be one of: ' . implode(', ', $validCacheModes)
                );
            }
            $sqliteCache = $params['cache'];
        }

        $sqliteMode = null;
        if (!empty($params['mode']) && is_string($params['mode'])) {
            $validModes = ['ro', 'rw', 'rwc', 'memory'];
            if (!in_array(strtolower($params['mode']), $validModes, true)) {
                throw new InvalidArgumentException(
                    'Invalid SQLite mode parameter. Must be one of: ' . implode(', ', $validModes)
                );
            }
            $sqliteMode = $params['mode'];
        }

        return "sqlite:{$params['path']}"
            . ($sqliteMode !== null ? ";mode={$sqliteMode}" : '')
            . ($sqliteCache !== null ? ";cache={$sqliteCache}" : '');
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
     *
     * @param array<int|string, mixed> $flags
     */
    public function insertKeywords(array $flags): string
    {
        // SQLite does not support IGNORE directly, but supports INSERT OR IGNORE
        if (in_array('IGNORE', $flags, true)) {
            return 'INSERT OR IGNORE ';
        }
        return 'INSERT ';
    }

    /**
     * {@inheritDoc}
     */
    public function buildInsertSql(string $fullTable, array $columns, array $placeholders, array $options): string
    {
        $cols = implode(',', array_map([$this, 'quoteIdentifier'], $columns));
        $phs = implode(',', $placeholders);
        return $this->insertKeywords($options) . "INTO {$fullTable} ({$cols}) VALUES ({$phs})";
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
        $colsSql = '';
        if (!empty($columns)) {
            $colsSql = ' (' . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . ')';
        }
        return $this->insertKeywords($options) . "INTO {$table}{$colsSql} {$selectSql}";
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $options
     */
    public function formatSelectOptions(string $sql, array $options): string
    {
        $middle = [];
        $tail = [];
        foreach ($options as $opt) {
            $u = strtoupper(trim($opt));
            if (in_array($u, ['LOCK IN SHARE MODE', 'FOR UPDATE'])) {
                $tail[] = $opt;
            } else {
                $middle[] = $opt;
            }
        }
        if ($middle) {
            $result = preg_replace('/^SELECT\s+/i', 'SELECT ' . implode(',', $middle) . ' ', $sql, 1);
            $sql = $result !== null ? $result : $sql;
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
                $parts[] = "{$this->quoteIdentifier($c)} = excluded.{$this->quoteIdentifier($c)}";
            }
        }

        $target = $this->quoteIdentifier($defaultConflictTarget);
        return "ON CONFLICT ({$target}) DO UPDATE SET " . implode(', ', $parts);
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
        // SQLite emulation similar to MySQL
        // Extract key from ON clause
        preg_match('/target\.(\w+)\s*=\s*source\.(\w+)/i', $onClause, $matches);
        $keyColumn = $matches[1] ?? 'id';

        $target = $this->quoteTable($targetTable);

        if (empty($whenClauses['whenNotMatched'])) {
            throw new QueryException('SQLite MERGE requires WHEN NOT MATCHED clause');
        }

        // Parse INSERT clause
        preg_match('/\(([^)]+)\)\s+VALUES\s+\(([^)]+)\)/', $whenClauses['whenNotMatched'], $insertMatches);
        $columns = $insertMatches[1] ?? '';
        $values = $insertMatches[2] ?? '';

        // Build SELECT columns from VALUES - replace MERGE_SOURCE_COLUMN_ markers with source columns
        $selectColumns = [];
        $valueColumns = explode(',', $values);
        $colNames = explode(',', $columns);
        foreach ($valueColumns as $idx => $val) {
            $val = trim($val);
            $colName = trim($colNames[$idx] ?? '');
            // Remove quotes from column name if present
            $colName = trim($colName, '`"');
            // If it's a MERGE_SOURCE_COLUMN_ marker, use source column; otherwise it's raw SQL
            if (preg_match('/^MERGE_SOURCE_COLUMN_(\w+)$/', $val, $paramMatch)) {
                // Use unquoted column name in source reference for SQLite
                $selectColumns[] = 'source.' . $this->quoteIdentifier($colName);
            } else {
                $selectColumns[] = $val;
            }
        }

        // SQLite doesn't support ON CONFLICT with INSERT ... SELECT
        // Use INSERT OR REPLACE which replaces based on PRIMARY KEY or UNIQUE constraints
        // Remove "AS source" if present (we add it ourselves)
        $sourceSql = preg_replace('/\s+AS\s+source$/i', '', $sourceSql);
        $sql = "INSERT OR REPLACE INTO {$target} ({$columns})\n";
        $sql .= 'SELECT ' . implode(', ', $selectColumns) . " FROM {$sourceSql} AS source";

        // Note: INSERT OR REPLACE will replace existing rows based on PRIMARY KEY
        // The whenMatched clause behavior is handled by OR REPLACE

        return $sql;
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $expr
     */
    protected function buildIncrementExpression(string $colSql, array $expr, string $tableName): string
    {
        if (!isset($expr['__op']) || !isset($expr['val'])) {
            return "{$colSql} = excluded.{$colSql}";
        }

        $op = $expr['__op'];
        // SQLite uses column (unqualified) for old values
        return match ($op) {
            'inc' => "{$colSql} = {$colSql} + " . (int)$expr['val'],
            'dec' => "{$colSql} = {$colSql} - " . (int)$expr['val'],
            default => "{$colSql} = excluded.{$colSql}",
        };
    }

    /**
     * {@inheritDoc}
     */
    protected function buildRawValueExpression(string $colSql, RawValue $expr, string $tableName, string $col): string
    {
        // SQLite doesn't support DEFAULT keyword in UPDATE statements
        // Replace DEFAULT with NULL (closest equivalent behavior)
        $value = $expr->getValue();
        if (trim($value) === 'DEFAULT') {
            return "{$colSql} = NULL";
        }
        return "{$colSql} = {$value}";
    }

    /**
     * {@inheritDoc}
     */
    protected function buildDefaultExpression(string $colSql, mixed $expr, string $col): string
    {
        $exprStr = trim((string)$expr);

        // Simple name or EXCLUDED.name
        if (preg_match('/^(?:excluded\.)?[A-Za-z_][A-Za-z0-9_]*$/i', $exprStr)) {
            if (stripos($exprStr, 'excluded.') === 0) {
                return "{$colSql} = {$exprStr}";
            }
            return "{$colSql} = excluded.{$this->quoteIdentifier($exprStr)}";
        }

        // Auto-qualify for typical expressions: replace only "bare" occurrences of column name with excluded."col"
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
        $colsSql = implode(',', array_map([$this, 'quoteIdentifier'], $columns));

        if ($isMultiple) {
            $valsSql = implode(',', array_map(function ($p) {
                return is_array($p) ? '(' . implode(',', $p) . ')' : $p;
            }, $placeholders));
            return sprintf('REPLACE INTO %s (%s) VALUES %s', $table, $colsSql, $valsSql);
        }

        $stringPlaceholders = array_map(fn ($p) => is_array($p) ? implode(',', $p) : $p, $placeholders);
        $valsSql = implode(',', $stringPlaceholders);
        return sprintf('REPLACE INTO %s (%s) VALUES (%s)', $table, $colsSql, $valsSql);
    }

    /**
     * {@inheritDoc}
     */
    public function now(?string $diff = '', bool $asTimestamp = false): string
    {
        if ($asTimestamp) {
            return $diff ? "STRFTIME('%s','now','{$diff}')" : "STRFTIME('%s','now')";
        }
        return $diff ? "DATETIME('now','{$diff}')" : "DATETIME('now')";
    }

    /**
     * {@inheritDoc}
     */
    public function config(ConfigValue $value): RawValue
    {
        $sql = 'PRAGMA ' . strtoupper($value->getValue())
            . ($value->getUseEqualSign() ? ' = ' : ' ')
            . ($value->getQuoteValue() ? '\'' . $value->getParams()[0] . '\'' : $value->getParams()[0]);
        return new RawValue($sql);
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
        return 'EXPLAIN QUERY PLAN ' . $query;
    }

    /**
     * {@inheritDoc}
     */
    public function buildTableExistsSql(string $table): string
    {
        return "SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDescribeSql(string $table): string
    {
        return "PRAGMA table_info({$table})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildLockSql(array $tables, string $prefix, string $lockMethod): string
    {
        throw new QueryException('LOCK TABLES not supported');
    }

    /**
     * {@inheritDoc}
     */
    public function buildUnlockSql(): string
    {
        throw new QueryException('UNLOCK TABLES not supported');
    }

    /**
     * {@inheritDoc}
     */
    public function buildTruncateSql(string $table): string
    {
        $table = $this->quoteTable($table);
        $identifier = $this->quoteIdentifier($table);
        return "DELETE FROM {$table}; DELETE FROM sqlite_sequence WHERE name={$identifier}";
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
     * SQLite doesn't have GREATEST, use MAX.
     */
    public function formatGreatest(array $values): string
    {
        return 'MAX(' . implode(', ', $this->resolveValues($values)) . ')';
    }

    /**
     * {@inheritDoc}
     * SQLite doesn't have LEAST, use MIN.
     */
    public function formatLeast(array $values): string
    {
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
     * Emulated using recursive CTE for SQLite.
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
     * Emulated using recursive CTE for SQLite.
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
     * Emulated using SUBSTR and REPEAT emulation for SQLite.
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
     * SQLite doesn't have TRUNCATE function, use ROUND(value - 0.5, precision) for positive numbers.
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
     * SQLite uses INSTR(string, substring) instead of POSITION(substring IN string).
     */
    public function formatPosition(string|RawValue $substring, string|RawValue $value): string
    {
        $sub = $substring instanceof RawValue ? $substring->getValue() : "'" . addslashes((string)$substring) . "'";
        $val = $this->resolveValue($value);
        return "INSTR($val, $sub)";
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
     * Register REGEXP functions for SQLite using PHP's preg_* functions.
     * This method registers REGEXP, regexp_replace, and regexp_extract functions
     * if they are not already available.
     *
     * @param PDO $pdo The PDO instance
     * @param bool $force Force re-registration even if functions exist
     */
    public function registerRegexpFunctions(PDO $pdo, bool $force = false): void
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

    /**
     * {@inheritDoc}
     * SQLite doesn't have LEFT function, use SUBSTR(value, 1, length).
     */
    public function formatLeft(string|RawValue $value, int $length): string
    {
        $val = $this->resolveValue($value);
        return "SUBSTR($val, 1, $length)";
    }

    /**
     * {@inheritDoc}
     * SQLite doesn't have RIGHT function, use SUBSTR(value, -length) or SUBSTR(value, LENGTH(value) - length + 1).
     */
    public function formatRight(string|RawValue $value, int $length): string
    {
        $val = $this->resolveValue($value);
        return "SUBSTR($val, -$length)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatFulltextMatch(string|array $columns, string $searchTerm, ?string $mode = null, bool $withQueryExpansion = false): array|string
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $quotedCols = array_map([$this, 'quoteIdentifier'], $cols);
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
    public function buildShowIndexesSql(string $table): string
    {
        return "SELECT name, tbl_name as table_name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = '{$table}'";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowForeignKeysSql(string $table): string
    {
        return "PRAGMA foreign_key_list('{$table}')";
    }

    /**
     * {@inheritDoc}
     */
    public function buildShowConstraintsSql(string $table): string
    {
        return "SELECT sql, type FROM sqlite_master WHERE type IN ('table', 'index') AND tbl_name = '{$table}'";
    }

    /* ---------------- DDL Operations ---------------- */

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

        foreach ($columns as $name => $def) {
            if ($def instanceof ColumnSchema) {
                $columnDefs[] = $this->formatColumnDefinition($name, $def);
            } elseif (is_array($def)) {
                // Short syntax: ['type' => 'TEXT', 'null' => false]
                $schema = $this->parseColumnDefinition($def);
                $columnDefs[] = $this->formatColumnDefinition($name, $schema);
            } else {
                // String type: 'TEXT'
                $schema = new ColumnSchema((string)$def);
                $columnDefs[] = $this->formatColumnDefinition($name, $schema);
            }
        }

        // Add PRIMARY KEY constraint from options if specified
        if (!empty($options['primaryKey'])) {
            $pkColumns = is_array($options['primaryKey']) ? $options['primaryKey'] : [$options['primaryKey']];
            $pkQuoted = array_map([$this, 'quoteIdentifier'], $pkColumns);
            $columnDefs[] = 'PRIMARY KEY (' . implode(', ', $pkQuoted) . ')';
        }

        $sql = "CREATE TABLE {$tableQuoted} (\n    " . implode(",\n    ", $columnDefs) . "\n)";

        // SQLite doesn't support table options like MySQL/PostgreSQL
        // Options are silently ignored

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
        // SQLite doesn't support FIRST/AFTER in ALTER TABLE ADD COLUMN
        return "ALTER TABLE {$tableQuoted} ADD COLUMN {$columnDef}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropColumnSql(string $table, string $column): string
    {
        // SQLite 3.35.0+ supports DROP COLUMN
        // For older versions, we throw an exception
        $tableQuoted = $this->quoteTable($table);
        $columnQuoted = $this->quoteIdentifier($column);
        // Note: SQLite DROP COLUMN requires complex table recreation
        // This is a simplified version - in production, you'd need to:
        // 1. Create new table without column
        // 2. Copy data
        // 3. Drop old table
        // 4. Rename new table
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
        // SQLite doesn't support ALTER COLUMN to change type
        // This would require table recreation which is complex
        throw new QueryException(
            'SQLite does not support ALTER COLUMN to change column type. ' .
            'You must recreate the table. Use buildRenameColumnSql to rename columns.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameColumnSql(string $table, string $oldName, string $newName): string
    {
        // SQLite 3.25.0+ supports RENAME COLUMN
        $tableQuoted = $this->quoteTable($table);
        $oldQuoted = $this->quoteIdentifier($oldName);
        $newQuoted = $this->quoteIdentifier($newName);
        return "ALTER TABLE {$tableQuoted} RENAME COLUMN {$oldQuoted} TO {$newQuoted}";
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
        $uniqueClause = $unique ? 'UNIQUE ' : '';

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
            } elseif ($col instanceof \tommyknocker\pdodb\helpers\values\RawValue) {
                // RawValue expression (functional index)
                $colsQuoted[] = $col->getValue();
            } else {
                $colsQuoted[] = $this->quoteIdentifier((string)$col);
            }
        }
        $colsList = implode(', ', $colsQuoted);

        $sql = "CREATE {$uniqueClause}INDEX {$nameQuoted} ON {$tableQuoted} ({$colsList})";

        // SQLite supports WHERE clause for partial indexes
        if ($where !== null && $where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        // SQLite doesn't support INCLUDE columns
        if ($includeColumns !== null && !empty($includeColumns)) {
            throw new QueryException(
                'SQLite does not support INCLUDE columns in indexes. ' .
                'Include columns must be part of the main index columns list.'
            );
        }

        // SQLite doesn't support fillfactor or other index options

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropIndexSql(string $name, string $table): string
    {
        // SQLite: DROP INDEX name (table is not needed, but kept for compatibility)
        $nameQuoted = $this->quoteIdentifier($name);
        return "DROP INDEX {$nameQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateFulltextIndexSql(string $name, string $table, array $columns, ?string $parser = null): string
    {
        // SQLite doesn't have native fulltext indexes, but supports FTS (Full-Text Search) virtual tables
        throw new QueryException(
            'SQLite does not support FULLTEXT indexes. ' .
            'Use FTS (Full-Text Search) virtual tables instead: CREATE VIRTUAL TABLE ... USING FTS5(...)'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildCreateSpatialIndexSql(string $name, string $table, array $columns): string
    {
        // SQLite doesn't have native spatial indexes
        throw new QueryException(
            'SQLite does not support SPATIAL indexes. ' .
            'Use R-Tree virtual tables for spatial data: CREATE VIRTUAL TABLE ... USING rtree(...)'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameIndexSql(string $oldName, string $table, string $newName): string
    {
        // SQLite doesn't support RENAME INDEX directly
        // Need to drop and recreate
        throw new QueryException(
            'SQLite does not support renaming indexes directly. ' .
            'You must drop the index and create a new one with the desired name.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameForeignKeySql(string $oldName, string $table, string $newName): string
    {
        // SQLite doesn't support renaming foreign keys
        throw new QueryException(
            'SQLite does not support renaming foreign keys. ' .
            'You must recreate the table without the constraint and add a new one.'
        );
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
        // SQLite foreign keys must be defined during CREATE TABLE
        // Adding them via ALTER TABLE requires table recreation
        // This is a limitation - we throw exception for now
        // In production, you might want to implement table recreation logic
        throw new QueryException(
            'SQLite does not support adding foreign keys via ALTER TABLE. ' .
            'Foreign keys must be defined during CREATE TABLE. ' .
            'To add a foreign key to an existing table, you must recreate the table.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropForeignKeySql(string $name, string $table): string
    {
        // SQLite foreign keys can't be dropped directly
        // Requires table recreation
        throw new QueryException(
            'SQLite does not support dropping foreign keys via ALTER TABLE. ' .
            'To drop a foreign key, you must recreate the table without the constraint.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddPrimaryKeySql(string $name, string $table, array $columns): string
    {
        // SQLite doesn't support adding PRIMARY KEY via ALTER TABLE
        // Requires table recreation
        throw new QueryException(
            'SQLite does not support adding PRIMARY KEY via ALTER TABLE. ' .
            'To add a PRIMARY KEY, you must recreate the table with the constraint.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropPrimaryKeySql(string $name, string $table): string
    {
        // SQLite doesn't support dropping PRIMARY KEY via ALTER TABLE
        // Requires table recreation
        throw new QueryException(
            'SQLite does not support dropping PRIMARY KEY via ALTER TABLE. ' .
            'To drop a PRIMARY KEY, you must recreate the table without the constraint.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddUniqueSql(string $name, string $table, array $columns): string
    {
        // SQLite supports UNIQUE via CREATE UNIQUE INDEX
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        $colsQuoted = array_map([$this, 'quoteIdentifier'], $columns);
        $colsList = implode(', ', $colsQuoted);
        return "CREATE UNIQUE INDEX {$nameQuoted} ON {$tableQuoted} ({$colsList})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropUniqueSql(string $name, string $table): string
    {
        // SQLite UNIQUE constraints are implemented as indexes, so drop as index
        $nameQuoted = $this->quoteIdentifier($name);
        return "DROP INDEX {$nameQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function buildAddCheckSql(string $name, string $table, string $expression): string
    {
        // SQLite 3.37.0+ supports CHECK constraints via ALTER TABLE
        $tableQuoted = $this->quoteTable($table);
        $nameQuoted = $this->quoteIdentifier($name);
        return "ALTER TABLE {$tableQuoted} ADD CONSTRAINT {$nameQuoted} CHECK ({$expression})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildDropCheckSql(string $name, string $table): string
    {
        // SQLite doesn't support dropping CHECK constraints via ALTER TABLE
        // Requires table recreation
        throw new QueryException(
            'SQLite does not support dropping CHECK constraints via ALTER TABLE. ' .
            'To drop a CHECK constraint, you must recreate the table without the constraint.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function buildRenameTableSql(string $table, string $newName): string
    {
        $tableQuoted = $this->quoteTable($table);
        $newQuoted = $this->quoteTable($newName);
        return "ALTER TABLE {$tableQuoted} RENAME TO {$newQuoted}";
    }

    /**
     * {@inheritDoc}
     */
    public function formatColumnDefinition(string $name, ColumnSchema $schema): string
    {
        $nameQuoted = $this->quoteIdentifier($name);
        $type = $schema->getType();

        // SQLite type mapping (SQLite is flexible with types)
        // Map common types to SQLite equivalents
        $typeUpper = strtoupper($type);
        if ($typeUpper === 'INT' || $typeUpper === 'INTEGER' || $typeUpper === 'TINYINT' || $typeUpper === 'SMALLINT' || $typeUpper === 'MEDIUMINT') {
            $type = 'INTEGER';
        } elseif ($typeUpper === 'BIGINT') {
            $type = 'INTEGER'; // SQLite uses INTEGER for all integer sizes
        } elseif ($typeUpper === 'VARCHAR' || $typeUpper === 'CHAR' || $typeUpper === 'CHARACTER') {
            $type = 'TEXT';
        } elseif ($typeUpper === 'TEXT' || $typeUpper === 'LONGTEXT' || $typeUpper === 'MEDIUMTEXT') {
            $type = 'TEXT';
        } elseif ($typeUpper === 'DATETIME' || $typeUpper === 'TIMESTAMP') {
            $type = 'TEXT'; // SQLite stores dates as TEXT
        } elseif ($typeUpper === 'DATE') {
            $type = 'TEXT';
        } elseif ($typeUpper === 'TIME') {
            $type = 'TEXT';
        } elseif ($typeUpper === 'BOOLEAN' || $typeUpper === 'BOOL') {
            $type = 'INTEGER'; // SQLite uses INTEGER (0/1) for booleans
        } elseif ($typeUpper === 'DECIMAL' || $typeUpper === 'NUMERIC' || $typeUpper === 'FLOAT' || $typeUpper === 'DOUBLE' || $typeUpper === 'REAL') {
            // Keep as-is, SQLite supports REAL, NUMERIC, etc.
        }

        // Build type with length/scale (SQLite ignores length for most types, but we include it for compatibility)
        $typeDef = $type;
        if ($schema->getLength() !== null) {
            if ($schema->getScale() !== null) {
                // For DECIMAL/NUMERIC
                $typeDef .= '(' . $schema->getLength() . ',' . $schema->getScale() . ')';
            }
            // Note: SQLite ignores length for TEXT/VARCHAR, but we keep it for SQL compatibility
        }

        $parts = [$nameQuoted, $typeDef];

        // PRIMARY KEY (for INTEGER PRIMARY KEY AUTOINCREMENT)
        if ($schema->isAutoIncrement()) {
            // SQLite requires INTEGER PRIMARY KEY for AUTOINCREMENT
            if ($type === 'INTEGER') {
                $parts[1] = 'INTEGER PRIMARY KEY AUTOINCREMENT';
            } else {
                // For other types, we can't use AUTOINCREMENT, but can mark as PRIMARY KEY
                $parts[] = 'PRIMARY KEY';
            }
        }

        // NOT NULL / NULL
        if ($schema->isNotNull()) {
            $parts[] = 'NOT NULL';
        }

        // DEFAULT
        if ($schema->getDefaultValue() !== null) {
            if ($schema->isDefaultExpression()) {
                $parts[] = 'DEFAULT ' . $schema->getDefaultValue();
            } else {
                $default = $this->formatDefaultValue($schema->getDefaultValue());
                $parts[] = 'DEFAULT ' . $default;
            }
        }

        // UNIQUE is handled separately (not in column definition)
        // It's created via CREATE INDEX or table constraint

        // SQLite doesn't support UNSIGNED, FIRST, AFTER
        // These are silently ignored

        return implode(' ', $parts);
    }

    /**
     * Parse column definition from array.
     *
     * @param array<string, mixed> $def Definition array
     *
     * @return ColumnSchema
     */
    protected function parseColumnDefinition(array $def): ColumnSchema
    {
        $type = $def['type'] ?? 'TEXT';
        $length = $def['length'] ?? $def['size'] ?? null;
        $scale = $def['scale'] ?? null;

        $schema = new ColumnSchema((string)$type, $length, $scale);

        if (isset($def['null']) && $def['null'] === false) {
            $schema->notNull();
        }
        if (isset($def['default'])) {
            if (isset($def['defaultExpression']) && $def['defaultExpression']) {
                $schema->defaultExpression((string)$def['default']);
            } else {
                $schema->defaultValue($def['default']);
            }
        }
        if (isset($def['autoIncrement']) && $def['autoIncrement']) {
            $schema->autoIncrement();
        }
        if (isset($def['unique']) && $def['unique']) {
            $schema->unique();
        }

        // SQLite doesn't support UNSIGNED, FIRST, AFTER, COMMENT
        // These are silently ignored

        return $schema;
    }

    /**
     * Format default value for SQL.
     *
     * @param mixed $value Default value
     *
     * @return string SQL formatted value
     */
    protected function formatDefaultValue(mixed $value): string
    {
        if ($value instanceof RawValue) {
            return $value->getValue();
        }
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_numeric($value)) {
            return (string)$value;
        }
        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }
        return "'" . addslashes((string)$value) . "'";
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
     * {@inheritDoc}
     */
    public function buildExistsExpression(string $subquery): string
    {
        return 'SELECT EXISTS(' . $subquery . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function supportsLimitInExists(): bool
    {
        return $this->featureSupport->supportsLimitInExists();
    }

    /**
     * {@inheritDoc}
     */
    public function getStringType(): string
    {
        // SQLite uses TEXT for strings
        return 'TEXT';
    }

    /**
     * {@inheritDoc}
     */
    public function getTextType(): string
    {
        // SQLite uses TEXT for all text types
        return 'TEXT';
    }

    /**
     * {@inheritDoc}
     */
    public function getCharType(): string
    {
        // SQLite doesn't distinguish CHAR/VARCHAR/TEXT - all are TEXT
        return 'TEXT';
    }

    /**
     * {@inheritDoc}
     */
    public function formatMaterializedCte(string $cteSql, bool $isMaterialized): string
    {
        // SQLite doesn't support materialized CTEs
        return $cteSql;
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeDefaultValue(string $value): string
    {
        // SQLite doesn't support DEFAULT keyword in UPDATE statements
        // Replace DEFAULT with NULL (closest equivalent behavior)
        if (trim($value) === 'DEFAULT') {
            return 'NULL';
        }
        return $value;
    }

    /**
     * {@inheritDoc}
     */
    public function buildMigrationTableSql(string $tableName): string
    {
        $tableQuoted = $this->quoteTable($tableName);
        return "CREATE TABLE {$tableQuoted} (
            version TEXT PRIMARY KEY,
            apply_time TEXT DEFAULT CURRENT_TIMESTAMP,
            batch INTEGER NOT NULL
        )";
    }

    /**
     * {@inheritDoc}
     */
    public function getExplainParser(): ExplainParserInterface
    {
        return new \tommyknocker\pdodb\query\analysis\parsers\SqliteExplainParser();
    }
}
