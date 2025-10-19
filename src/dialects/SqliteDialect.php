<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use tommyknocker\pdodb\helpers\ConfigValue;
use tommyknocker\pdodb\helpers\RawValue;

class SqliteDialect extends DialectAbstract implements DialectInterface
{
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
    public function buildDsn(array $params): string
    {
        if (!isset($params['path'])) {
            throw new InvalidArgumentException("Missing 'path' parameter");
        }
        return "sqlite:{$params['path']}"
            . (!empty($params['mode']) ? ";mode={$params['mode']}" : '')       // ex. ro/rw/rwc/memory
            . (!empty($params['cache']) ? ";cache={$params['cache']}" : '');    // shared/private
    }

    /**
     * {@inheritDoc}
     */
    public function defaultPdoOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
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
            $sql = preg_replace('/^SELECT\s+/i', 'SELECT ' . implode(',', $middle) . ' ', $sql, 1);
        }
        if ($tail) {
            $sql .= ' ' . implode(' ', $tail);
        }
        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function buildUpsertClause(array $updateColumns, string $defaultConflictTarget = 'id'): string
    {
        if (!$updateColumns) {
            return '';
        }

        $parts = [];
        $isAssoc = array_keys($updateColumns) !== range(0, count($updateColumns) - 1);

        if ($isAssoc) {
            foreach ($updateColumns as $col => $expr) {
                $colSql = $this->quoteIdentifier($col);

                // RawValue is inserted as-is
                if ($expr instanceof RawValue) {
                    $parts[] = "{$colSql} = {$expr->getValue()}";
                    continue;
                }

                $exprStr = trim((string)$expr);

                // Simple name or EXCLUDED.name
                if (preg_match('/^(?:excluded\.)?[A-Za-z_][A-Za-z0-9_]*$/i', $exprStr)) {
                    if (stripos($exprStr, 'excluded.') === 0) {
                        $parts[] = "{$colSql} = {$exprStr}";
                    } else {
                        $parts[] = "{$colSql} = excluded.{$this->quoteIdentifier($exprStr)}";
                    }
                    continue;
                }

                // Auto-qualify for typical expressions: replace only "bare" occurrences of column name with excluded."col"
                $quotedCol = $this->quoteIdentifier($col);
                $replacement = 'excluded.' . $quotedCol;

                $safeExpr = preg_replace_callback(
                    '/\b' . preg_quote($col, '/') . '\b/i',
                    static function ($m) use ($exprStr, $replacement) {
                        $pos = strpos($exprStr, $m[0]);
                        if ($pos === false) {
                            return $m[0];
                        }
                        $left = $pos > 0 ? substr($exprStr, max(0, $pos - 9), 9) : '';
                        if (strpos($left, '.') !== false || stripos($left, 'excluded') !== false) {
                            return $m[0];
                        }
                        return $replacement;
                    },
                    $exprStr
                );

                $parts[] = "{$colSql} = {$safeExpr}";
            }
        } else {
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
    public function buildReplaceSql(
        string $table,
        array $columns,
        array $placeholders,
        bool $isMultiple = false
    ): string {
        $colsSql = implode(',', array_map([$this, 'quoteIdentifier'], $columns));
        $valsSql = implode(',', $placeholders);
        if ($isMultiple) {
            return sprintf('REPLACE INTO %s (%s) VALUES %s', $table, $colsSql, $valsSql);
        }
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
        $sql = "PRAGMA " . strtoupper($value->getValue())
            . ($value->getUseEqualSign() ? ' = ' : ' ')
            . ($value->getQuoteValue() ? '\'' . $value->getParams()[0] . '\'' : $value->getParams()[0]);
        return new RawValue($sql);
    }

    /**
     * {@inheritDoc}
     */
    public function buildExplainSql(string $query, bool $analyze = false): string
    {
        if ($analyze) {
            return "EXPLAIN QUERY PLAN " . $query;
        }
        return "EXPLAIN " . $query;
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
    public function buildDescribeTableSql(string $table): string
    {
        return "PRAGMA table_info({$table})";
    }

    /**
     * {@inheritDoc}
     */
    public function buildLockSql(array $tables, string $prefix, string $lockMethod): string
    {
        throw new RuntimeException('LOCK TABLES not supported');
    }

    /**
     * {@inheritDoc}
     */
    public function buildUnlockSql(): string
    {
        throw new RuntimeException('UNLOCK TABLES not supported');
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
        $jsonPath = '$';
        foreach ($parts as $p) {
            if (preg_match('/^\d+$/', (string)$p)) {
                // Numeric index -> [N]
                $jsonPath .= '[' . $p . ']';
            } else {
                // Object key -> .key
                $jsonPath .= '.' . $p;
            }
        }
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
        $jsonPath = '$';
        foreach ($parts as $p) {
            if (preg_match('/^\d+$/', (string)$p)) {
                // Numeric index -> [N]
                $jsonPath .= '[' . $p . ']';
            } else {
                // Object key -> .key
                $jsonPath .= '.' . $p;
            }
        }
        $quotedCol = $this->quoteIdentifier($col);

        // If value is an array, check that all elements are present in the JSON array
        if (is_array($value)) {
            $conditions = [];
            $params = [];

            foreach ($value as $idx => $item) {
                $param = ':jsonc_' . $idx;
                $conditions[] = "EXISTS (SELECT 1 FROM json_each({$quotedCol}, '{$jsonPath}') WHERE json_each.value = {$param})";
                $params[$param] = is_string($item) ? $item : json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }

            $sql = '(' . implode(' AND ', $conditions) . ')';
            return [$sql, $params];
        }

        // For scalar values, use simple json_each check
        $param = ':jsonc';
        $sql = "EXISTS (SELECT 1 FROM json_each({$quotedCol}, '{$jsonPath}') WHERE json_each.value = {$param})";

        // Encode value as JSON if not a simple scalar
        $paramValue = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return [$sql, [$param => $paramValue]];
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonSet(string $col, array|string $path, mixed $value): array
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = '$';
        foreach ($parts as $p) {
            if (preg_match('/^\d+$/', (string)$p)) {
                // Numeric index -> [N]
                $jsonPath .= '[' . $p . ']';
            } else {
                // Object key -> .key
                $jsonPath .= '.' . $p;
            }
        }
        $param = ':jsonset';
        // Use json() to parse the JSON-encoded value properly
        $expr = "JSON_SET(" . $this->quoteIdentifier($col) . ", '{$jsonPath}', json({$param}))";
        return [$expr, [$param => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]];
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonRemove(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = '$';
        foreach ($parts as $p) {
            if (preg_match('/^\d+$/', (string)$p)) {
                // Numeric index -> [N]
                $jsonPath .= '[' . $p . ']';
            } else {
                // Object key -> .key
                $jsonPath .= '.' . $p;
            }
        }

        $colQuoted = $this->quoteIdentifier($col);

        // If last segment is numeric, replace array element with JSON null to preserve indices
        $last = end($parts);
        if (preg_match('/^\d+$/', (string)$last)) {
            // Use JSON_SET to set the element to JSON null (using json('null'))
            return "JSON_SET({$colQuoted}, '{$jsonPath}', json('null'))";
        }

        // Otherwise remove object key
        return "JSON_REMOVE({$colQuoted}, '{$jsonPath}')";
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
        $jsonPath = '$';
        foreach ($parts as $p) {
            if (preg_match('/^\d+$/', (string)$p)) {
                $jsonPath .= '[' . $p . ']';
            } else {
                $jsonPath .= '.' . $p;
            }
        }

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
            // Return '["key1","key2",...]' - JSON array of keys
            // Note: SQLite doesn't have a simple function for this, so we return a descriptive placeholder
            return "'[keys]'"; // Simplified - in real usage would need subquery
        }

        $parts = $this->normalizeJsonPath($path);
        $jsonPath = '$';
        foreach ($parts as $p) {
            if (preg_match('/^\d+$/', (string)$p)) {
                $jsonPath .= '[' . $p . ']';
            } else {
                $jsonPath .= '.' . $p;
            }
        }

        return "'[keys]'"; // Simplified - in real usage would need subquery
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
        $jsonPath = '$';
        foreach ($parts as $p) {
            if (preg_match('/^\d+$/', (string)$p)) {
                $jsonPath .= '[' . $p . ']';
            } else {
                $jsonPath .= '.' . $p;
            }
        }
        
        return "json_type(json_extract({$colQuoted}, '{$jsonPath}'))";
    }

    /**
     * {@inheritDoc}
     */
    public function formatIfNull(string $expr, mixed $default): string
    {
        $defaultStr = is_string($default) ? "'{$default}'" : $default;
        return "IFNULL($expr, $defaultStr)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatGreatest(array $values): string
    {
        // SQLite doesn't have GREATEST, use MAX
        $args = array_map(fn($v) => $v instanceof RawValue ? $v->getValue() : $v, $values);
        return 'MAX(' . implode(', ', $args) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function formatLeast(array $values): string
    {
        // SQLite doesn't have LEAST, use MIN
        $args = array_map(fn($v) => $v instanceof RawValue ? $v->getValue() : $v, $values);
        return 'MIN(' . implode(', ', $args) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function formatSubstring(string|RawValue $source, int $start, ?int $length): string
    {
        $src = $source instanceof RawValue ? $source->getValue() : $source;
        if ($length === null) {
            return "SUBSTR($src, $start)";
        }
        return "SUBSTR($src, $start, $length)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatMod(string|RawValue $dividend, string|RawValue $divisor): string
    {
        // SQLite doesn't have MOD function, use % operator
        $d1 = $dividend instanceof RawValue ? $dividend->getValue() : $dividend;
        $d2 = $divisor instanceof RawValue ? $divisor->getValue() : $divisor;
        return "($d1 % $d2)";
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
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return "CAST(STRFTIME('%Y', $val) AS INTEGER)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatMonth(string|RawValue $value): string
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return "CAST(STRFTIME('%m', $val) AS INTEGER)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatDay(string|RawValue $value): string
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return "CAST(STRFTIME('%d', $val) AS INTEGER)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatHour(string|RawValue $value): string
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return "CAST(STRFTIME('%H', $val) AS INTEGER)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatMinute(string|RawValue $value): string
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return "CAST(STRFTIME('%M', $val) AS INTEGER)";
    }

    /**
     * {@inheritDoc}
     */
    public function formatSecond(string|RawValue $value): string
    {
        $val = $value instanceof RawValue ? $value->getValue() : $value;
        return "CAST(STRFTIME('%S', $val) AS INTEGER)";
    }
}

