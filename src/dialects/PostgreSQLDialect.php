<?php

namespace tommyknocker\pdodb\dialects;

use InvalidArgumentException;
use PDO;
use tommyknocker\pdodb\PdoDb;

class PostgreSQLDialect implements DialectInterface
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

    public function booleanLiteral(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }

    public function insertKeywords(array $flags): string
    {
        return 'INSERT ' . ($flags ? implode(' ', $flags) . ' ' : '');
    }

    public function buildInsertValues(string $fullTable, array $columns, array $placeholders, array $flags): string
    {
        $cols = implode(',', array_map([$this, 'quoteIdentifier'], $columns));
        $phs = implode(',', $placeholders);
        return $this->insertKeywords($flags) . "INTO {$this->quoteIdentifier($fullTable)} ({$cols}) VALUES ({$phs})";
    }

    public function buildInsertSubquery(string $fullTable, ?array $columns, string $subquerySql, array $flags): string
    {
        $cols = $columns ? '(' . implode(',', array_map([$this, 'quoteIdentifier'], $columns)) . ')' : '';
        return $this->insertKeywords($flags) . "INTO {$this->quoteIdentifier($fullTable)} {$cols} {$subquerySql}";
    }

    public function buildUpsertClause(array $updateColumns, string $defaultConflictTarget = 'id'): string
    {
        if (!$updateColumns) {
            return '';
        }
        $updates = implode(',',
            array_map(fn($c) => "{$this->quoteIdentifier($c)}=EXCLUDED.{$this->quoteIdentifier($c)}", $updateColumns));
        return "ON CONFLICT ({$this->quoteIdentifier($defaultConflictTarget)}) DO UPDATE SET {$updates}";
    }

    public function buildReturningClause(?string $returning): string
    {
        return $returning ? " RETURNING {$this->quoteIdentifier($returning)}" : '';
    }

    public function supportsReturning(): bool
    {
        return true;
    }

    public function lastInsertId(PDO $pdo, ?string $table = null, ?string $column = null): string
    {
        if ($table && $column) {
            return $pdo->lastInsertId("{$table}_{$column}_seq");
        }
        return $pdo->lastInsertId();
    }

    public function buildUpdateSet(array $data): array
    {
        $set = [];
        $params = [];
        foreach ($data as $col => $val) {
            $set[] = $this->quoteIdentifier($col) . " = :$col";
            $params[":$col"] = $val;
        }
        return ['sql' => implode(', ', $set), 'params' => $params];
    }

    public function buildUpdateStatement(string $fullTable, string $setSql, ?string $whereSql): string
    {
        $sql = "UPDATE {$this->quoteIdentifier($fullTable)} SET {$setSql}";
        if ($whereSql) {
            $sql .= " WHERE {$whereSql}";
        }
        return $sql;
    }

    public function isStandardUpdateLimit(string $table, int $limit, PdoDb $db): bool
    {
        // UPDATE emulation ... LIMIT through ctid
        $subQuery = $db->subQuery();
        $result = $subQuery->getColumn($table, 'ctid', $limit);
        $db->where('ctid', $result, 'IN');
        return false;
    }


    public function limitOffsetClause(?int $limit, ?int $offset): string
    {
        $sql = '';
        if ($limit !== null) {
            $sql .= " LIMIT {$limit}";
        }
        if ($offset !== null) {
            $sql .= " OFFSET {$offset}";
        }
        return $sql;
    }

    public function now(?string $diff = '', ?string $func = null): string
    {
        $func = $func ?: 'NOW()';
        return $diff ? "$func + INTERVAL '{$diff}'" : $func;
    }

    public function explainSql(string $query, bool $analyze = false): string
    {
        return "EXPLAIN " . ($analyze ? "ANALYZE " : "") . $query;
    }

    public function tableExistsSql(string $table): string
    {
        return "SELECT to_regclass('{$table}') IS NOT NULL AS exists";
    }

    public function describeTableSql(string $table): string
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

    public function canLoadXml(): bool
    {
        // PostgreSQL can't load XML
        return false;
    }

    public function canLoadData(): bool
    {
        return true;
    }

    public function buildLoadDataSql(PDO $pdo, string $table, string $filePath, array $options): string
    {
        $quotedPath = $this->pdo->quote($filePath);

        $delimiter = $options['fieldChar'] ?? ',';
        $header    = $options['header'] ?? true;
        $quoteChar = $options['fieldEnclosure'] ?? '"';

        return sprintf(
            'COPY %s FROM %s WITH (FORMAT csv, HEADER %s, DELIMITER %s, QUOTE %s)',
            $table,
            $quotedPath,
            $header ? 'true' : 'false',
            $this->pdo->quote($delimiter),
            $this->pdo->quote($quoteChar)
        );
    }

}