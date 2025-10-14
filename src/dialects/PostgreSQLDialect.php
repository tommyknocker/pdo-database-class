<?php

namespace tommyknocker\pdodb\dialects;

use InvalidArgumentException;
use PDO;
use tommyknocker\pdodb\helpers\RawValue;

class PostgreSQLDialect extends DialectAbstract implements DialectInterface
{
    public function getDriverName(): string
    {
        return 'pgsql';
    }

    // Connection / identity
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

    public function defaultPdoOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];
    }

    public function quoteIdentifier(string $name): string
    {
        return "\"{$name}\"";
    }

    public function quoteTable(mixed $table): string
    {
        return $this->quoteTableWithAlias($table);
    }

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
                $sql = preg_replace(
                    '/^INSERT INTO\s+' . preg_quote($table, '/') . '/i',
                    'INSERT INTO ONLY ' . $table,
                    $sql,
                    1
                );
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
            $sql = preg_replace('/\)\s+VALUES\s+/i', ') ' . implode(' ', $beforeValues) . ' VALUES ', $sql, 1);
        }

        if (!empty($tail)) {
            $sql .= ' ' . implode(' ', $tail);
        }

        return $sql;
    }


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

                if ($expr instanceof RawValue) {
                    $parts[] = "{$colSql} = {$expr->getValue()}";
                    continue;
                }

                $exprStr = trim((string)$expr);

                if (preg_match('/^(?:EXCLUDED\.)?[A-Za-z_][A-Za-z0-9_]*$/i', $exprStr)) {
                    if (stripos($exprStr, 'EXCLUDED.') === 0) {
                        $parts[] = "{$colSql} = {$exprStr}";
                    } else {
                        $parts[] = "{$colSql} = EXCLUDED.{$this->quoteIdentifier($exprStr)}";
                    }
                    continue;
                }

                $quotedCol = $this->quoteIdentifier($col); // e.g. "age"
                $replacement = 'EXCLUDED.' . $quotedCol;   // EXCLUDED."age"

                $safeExpr = preg_replace_callback(
                    '/\b' . preg_quote($col, '/') . '\b/i',
                    function ($m) use ($exprStr, $replacement) {
                        $pos = strpos($exprStr, $m[0]);
                        if ($pos === false) {
                            return $m[0];
                        }
                        $left = $pos > 0 ? substr($exprStr, max(0, $pos - 9), 9) : '';
                        if (strpos($left, '.') !== false || stripos($left, 'EXCLUDED') !== false) {
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
                $parts[] = "{$this->quoteIdentifier($c)} = EXCLUDED.{$this->quoteIdentifier($c)}";
            }
        }

        return "ON CONFLICT ({$this->quoteIdentifier($defaultConflictTarget)}) DO UPDATE SET " . implode(', ', $parts);
    }


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
                $phTrim = trim($ph);
                if (str_starts_with($phTrim, '(') && str_ends_with($phTrim, ')')) {
                    $rows[] = $phTrim;
                } else {
                    $rows[] = '(' . $phTrim . ')';
                }
            }
            $valsSql = implode(',', $rows);
        } else {
            // single insert: ensure parentheses around the list
            $phList = implode(',', $placeholders);
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

    public function now(?string $diff = ''): RawValue
    {
        $func = 'NOW()';
        return new RawValue($diff ? "$func + INTERVAL '{$diff}'" : $func);
    }

    public function buildExplainSql(string $query, bool $analyze = false): string
    {
        return "EXPLAIN " . ($analyze ? "ANALYZE " : "") . $query;
    }

    public function buildExistsSql(string $table): string
    {
        return "SELECT to_regclass('{$table}') IS NOT NULL AS exists";
    }

    public function buildDescribeTableSql(string $table): string
    {
        return "SELECT column_name, data_type, is_nullable, column_default
                FROM information_schema.columns
                WHERE table_name = '{$table}'";
    }

    public function buildLockSql(array $tables, string $prefix, string $lockMethod): string
    {
        $list = implode(', ', array_map(fn($t) => $this->quoteIdentifier($prefix . $t), $tables));
        return "LOCK TABLE {$list} IN ACCESS EXCLUSIVE MODE";
    }

    public function buildUnlockSql(): string
    {
        // PostgreSQL does not need unlock
        return '';
    }

    public function buildTruncateSql(string $table): string
    {
        return 'TRUNCATE TABLE ' . $this->quoteTable($table);
    }

    public function buildLoadDataSql(PDO $pdo, string $table, string $filePath, array $options = []): string
    {
        $tableSql = $this->quoteIdentifier($table);

        $quotedPath = $pdo->quote($filePath);

        $delimiter = $options['fieldChar'] ?? ',';
        $quoteChar = $options['fieldEnclosure'] ?? '"';
        $header = isset($options['header']) && (bool)$options['header'];

        $columnsSql = '';
        if (!empty($options['fields']) && is_array($options['fields'])) {
            $columnsSql = ' (' . implode(', ', array_map([$this, 'quoteIdentifier'], $options['fields'])) . ')';
        }

        $del = $pdo->quote($delimiter);
        $quo = $pdo->quote($quoteChar);

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
}