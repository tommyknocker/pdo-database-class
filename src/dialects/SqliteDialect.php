<?php

namespace tommyknocker\pdodb\dialects;

use InvalidArgumentException;
use PDO;
use tommyknocker\pdodb\PdoDb;

class SqliteDialect implements DialectInterface
{
    public function getDriverName(): string
    {
        return 'sqlite';
    }

    // Connection / identity
    public function buildDsn(array $params): string
    {
        if (!isset($params['path'])) {
            throw new InvalidArgumentException("Missing 'path' parameter");
        }
        return "sqlite:{$params['path']}"
            . (!empty($params['mode']) ? ";mode={$params['mode']}" : '')       // ex. ro/rw/rwc/memory
            . (!empty($params['cache']) ? ";cache={$params['cache']}" : '');    // shared/private
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
        return $value ? '1' : '0';
    }

    public function insertKeywords(array $flags): string
    {
        // SQLite does not support IGNORE directly, but supports INSERT OR IGNORE
        if (in_array('IGNORE', $flags, true)) {
            return 'INSERT OR IGNORE ';
        }
        return 'INSERT ';
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
        // In SQLite, INSERT OR REPLACE is more commonly used, so there is no separate clause
        return '';
    }

    public function buildReturningClause(?string $returning): string
    {
        // SQLite 3.35+ supports RETURNING, but for compatibility we leave it empty
        return '';
    }

    public function supportsReturning(): bool
    {
        return false;
    }

    public function lastInsertId(PDO $pdo, ?string $table = null, ?string $column = null): string
    {
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
        return true;
    }


    public function limitOffsetClause(?int $limit, ?int $offset): string
    {
        if ($limit === null) {
            return '';
        }
        return $offset ? " LIMIT {$limit} OFFSET {$offset}" : " LIMIT {$limit}";
    }

    public function now(?string $diff = '', ?string $func = null): string
    {
        $func = $func ?: 'CURRENT_TIMESTAMP';
        if (!$diff) {
            return $func;
        }
        return "DATETIME('now','{$diff}')";
    }


    public function explainSql(string $query, bool $analyze = false): string
    {
        if ($analyze) {
            return "EXPLAIN QUERY PLAN " . $query;
        }
        return "EXPLAIN " . $query;
    }

    public function tableExistsSql(string $table): string
    {
        return "SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'";
    }

    public function describeTableSql(string $table): string
    {
        return "PRAGMA table_info({$table})";
    }

    public function buildLockSql(array $tables, string $prefix, string $lockMethod): string
    {
        $parts = [];
        foreach ($tables as $t) {
            $parts[] = $this->quoteIdentifier($prefix . $t) . " {$lockMethod}";
        }
        return "LOCK TABLES " . implode(', ', $parts);
    }

    public function buildUnlockSql(): string
    {
        return "UNLOCK TABLES";
    }

    public function canLoadXml(): bool
    {
        return true;
    }

    public function canLoadData(): bool
    {
        return false;
    }

    public function buildLoadDataSql(PDO $pdo, string $table, string $filePath, array $options): string
    {
        return '';
    }
}
