<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\dialects;

use InvalidArgumentException;
use PDO;
use RuntimeException;
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
    public function now(?string $diff = ''): string
    {
        return $diff ? "DATETIME('now','{$diff}')" : 'CURRENT_TIMESTAMP';
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
    public function buildExistsSql(string $table): string
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
}
