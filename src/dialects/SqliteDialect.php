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

                // RawValue вставляем как есть
                if ($expr instanceof RawValue) {
                    $parts[] = "{$colSql} = {$expr->getValue()}";
                    continue;
                }

                $exprStr = trim((string)$expr);

                // Простое имя или EXCLUDED.name
                if (preg_match('/^(?:excluded\.)?[A-Za-z_][A-Za-z0-9_]*$/i', $exprStr)) {
                    if (stripos($exprStr, 'excluded.') === 0) {
                        $parts[] = "{$colSql} = {$exprStr}";
                    } else {
                        $parts[] = "{$colSql} = excluded.{$this->quoteIdentifier($exprStr)}";
                    }
                    continue;
                }

                // Автоквалификация для типичных выражений: заменяем только "голые" вхождения имени колонки на excluded."col"
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
        $jsonPath = '$' . (empty($parts) ? '' : '.' . implode('.', array_map(function($p){
                    return preg_match('/^\d+$/', $p) ? $p : $p;
                }, $parts)));

        $colQuoted = $this->quoteIdentifier($col);
        $base = "json_extract({$colQuoted}, '{$jsonPath}')";

        // If caller requests "text" form we still try to return a scalar-friendly expression.
        // Use json_type() to detect numeric types and coerce them to SQL numbers via +0.
        // For other types return the raw json_extract result.
        //
        // Explanation:
        // - For JSON numbers json_type(...) returns 'integer' or 'real'. Adding +0 coerces the JSON literal to SQL numeric.
        // - For strings/objects/arrays we return json_extract(...) unchanged so JSON strings remain comparable to string params.
        //
        // Resulting SQL example:
        // CASE json_type(json_extract("meta",'$.a.b'))
        //   WHEN 'integer' THEN json_extract("meta",'$.a.b') + 0
        //   WHEN 'real' THEN json_extract("meta",'$.a.b') + 0
        //   ELSE json_extract("meta",'$.a.b')
        // END
        $expr = "CASE json_type({$base}) WHEN 'integer' THEN ({$base} + 0) WHEN 'real' THEN ({$base} + 0) ELSE {$base} END";

        // If caller explicitly asked for "text" and you want always text, you can wrap in CAST(... AS TEXT)
        if ($asText) {
            return "CAST({$expr} AS TEXT)";
        }

        return $expr;
    }


    /**
     * {@inheritDoc}
     */
    public function formatJsonContains(string $col, mixed $value, array|string|null $path = null): array|string
    {
        $parts = $this->normalizeJsonPath($path ?? []);
        $jsonPath = '$' . (empty($parts) ? '' : '.' . implode('.', $parts));
        $param = ':jsonc';
        $quotedCol = $this->quoteIdentifier($col);

        // SQLite doesn't support JSON_CONTAINS; emulate via json_each
        $sql = "EXISTS (SELECT 1 FROM json_each({$quotedCol}, '{$jsonPath}') WHERE json_each.value = {$param})";
        return [$sql, [$param => $value]];
    }


    /**
     * {@inheritDoc}
     */
    public function formatJsonSet(string $col, array|string $path, mixed $value): array
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = '$' . (empty($parts) ? '' : '.' . implode('.', array_map(function($p){
                    return preg_match('/^\d+$/', $p) ? $p : $p;
                }, $parts)));
        $param = ':jsonset';
        $expr = "JSON_SET(" . $this->quoteIdentifier($col) . ", '{$jsonPath}', {$param})";
        return [$expr, [$param => json_encode($value, JSON_UNESCAPED_UNICODE)]];
    }

    /**
     * {@inheritDoc}
     */
    public function formatJsonRemove(string $col, array|string $path): string
    {
        $parts = $this->normalizeJsonPath($path);
        $jsonPath = '$' . (empty($parts) ? '' : '.' . implode('.', array_map(function($p){
                    return preg_match('/^\d+$/', $p) ? $p : $p;
                }, $parts)));
        return "JSON_REMOVE(" . $this->quoteIdentifier($col) . ", '{$jsonPath}')";
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
        return $this->formatJsonGet($col, $path, true);
    }
}
