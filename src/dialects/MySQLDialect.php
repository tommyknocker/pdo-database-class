<?php

namespace tommyknocker\pdodb\dialects;

use InvalidArgumentException;
use PDO;
use tommyknocker\pdodb\PdoDb;

class MySQLDialect implements DialectInterface
{
    public function getDriverName(): string
    {
        return 'mysql';
    }

    // Connection / identity
    public function buildDsn(array $params): string
    {
        foreach (['host', 'dbname', 'username', 'password'] as $requiredParam) {
            if (empty($params[$requiredParam])) {
                throw new InvalidArgumentException("Missing '$requiredParam' parameter");
            }
        }
        return "mysql:host={$params['host']};dbname={$params['dbname']}"
            . (!empty($params['port']) ? ";port={$params['port']}" : '')
            . (!empty($params['charset']) ? ";charset={$params['charset']}" : '')
            . (!empty($params['unix_socket']) ? ";unix_socket={$params['unix_socket']}" : '')
            . (!empty($params['sslca']) ? ";sslca={$params['sslca']}" : '')
            . (!empty($params['sslcert']) ? ";sslcert={$params['sslcert']}" : '')
            . (!empty($params['sslkey']) ? ";sslkey={$params['sslkey']}" : '')
            . (!empty($params['compress']) ? ";compress={$params['compress']}" : '');
    }

    public function defaultPdoOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_LOCAL_INFILE => true
        ];
    }

    public function quoteIdentifier(string $name): string
    {
        return "`{$name}`";
    }

    public function booleanLiteral(bool $value): string
    {
        return $value ? '1' : '0';
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
            array_map(fn($c) => "{$this->quoteIdentifier($c)} = VALUES({$this->quoteIdentifier($c)})", $updateColumns));
        return "ON DUPLICATE KEY UPDATE {$updates}";
    }

    public function buildReturningClause(?string $returning): string
    {
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

    public function isStandardUpdateLimit(string $table, int $limit, PdoDb $db): bool
    {
       return true;
    }


    public function buildUpdateStatement(string $fullTable, string $setSql, ?string $whereSql): string
    {
        $sql = "UPDATE {$this->quoteIdentifier($fullTable)} SET {$setSql}";
        if ($whereSql) {
            $sql .= " WHERE {$whereSql}";
        }
        return $sql;
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
        $func = $func ?: 'NOW()';
        return $diff ? "$func + INTERVAL $diff" : $func;
    }


    public function explainSql(string $query, bool $analyze = false): string
    {
        return "EXPLAIN " . $query;
    }

    public function tableExistsSql(string $table): string
    {
        return "SHOW TABLES LIKE '{$table}'";
    }

    public function describeTableSql(string $table): string
    {
        return "DESCRIBE {$table}";
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
        return true;
    }

    public function buildLoadDataSql(PDO $pdo, string $table, string $filePath, array $options): string
    {
        $defaults = [
            "fieldChar"     => ';',
            "fieldEnclosure"=> null,
            "fields"        => [],
            "lineChar"      => null,
            "linesToIgnore" => null,
            "lineStarting"  => null,
            "local"         => false,
        ];
        $options = array_merge($defaults, $options);

        $localPrefix = $options['local'] ? 'LOCAL ' : '';
        $quotedPath  = $pdo->quote($filePath);

        $sql = "LOAD DATA {$localPrefix}INFILE {$quotedPath} INTO TABLE {$table}";

        // FIELDS
        $sql .= sprintf(" FIELDS TERMINATED BY '%s'", $options["fieldChar"]);
        if ($options["fieldEnclosure"]) {
            $sql .= sprintf(" ENCLOSED BY '%s'", $options["fieldEnclosure"]);
        }

        // LINES
        if ($options['lineChar']) {
            $sql .= sprintf(" LINES TERMINATED BY '%s'", $options["lineChar"]);
        }
        if ($options["lineStarting"]) {
            $sql .= sprintf(" STARTING BY '%s'", $options["lineStarting"]);
        }

        // IGNORE LINES
        if ($options["linesToIgnore"]) {
            $sql .= sprintf(" IGNORE %d LINES", $options["linesToIgnore"]);
        }

        // FIELDS LIST (в конце!)
        if ($options['fields']) {
            $sql .= ' (' . implode(', ', $options['fields']) . ')';
        }

        return $sql;
    }

}
