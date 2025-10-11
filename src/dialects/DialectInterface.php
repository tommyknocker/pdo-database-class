<?php

namespace tommyknocker\pdodb\dialects;

use PDO;
use tommyknocker\pdodb\PdoDb;

interface DialectInterface
{
    public function getDriverName(): string;

    // Connection / identity
    public function buildDsn(array $params): string;

    public function defaultPdoOptions(): array;

    // Identifiers
    public function quoteIdentifier(string $name): string;


    // INSERT builders (exactly reflect current PdoDb semantics)
    public function insertKeywords(array $flags): string;

    // e.g. returns "INSERT IGNORE " or "INSERT " (flags are placed after INSERT, before INTO)

    public function buildInsertValues(
        string $fullTable,
        array $columns,
        array $placeholders,
        array $flags
    ): string;

    // -> "INSERT [flags]INTO <table> (<quoted cols>) VALUES (<placeholders>)"

    public function buildInsertSubquery(
        string $fullTable,
        ?array $columns,          // null/[] = no column list (subquery defines it)
        string $subquerySql,
        array $flags
    ): string;

    // -> "INSERT [flags]INTO <table> (<quoted cols>?) <subquery>"

    public function buildUpsertClause(array $updateColumns, string $defaultConflictTarget = 'id'): string;
    // MySQL: "ON DUPLICATE KEY UPDATE col=VALUES(col), ..."
    // Postgres: "ON CONFLICT (id) DO UPDATE SET col=EXCLUDED.col, ..."
    // SQLite: usually empty (INSERT OR REPLACE is a different statement-level mode)

    public function buildReturningClause(?string $returning): string;

    public function supportsReturning(): bool;

    public function lastInsertId(\PDO $pdo, ?string $table = null, ?string $column = null): string;

    // UPDATE builders
    public function buildUpdateSet(array $data): array;
    // returns [sql => "SET \"col\"=:col, ...", params => [":col" => val, ...]]
    // position of identifier quoting and named params must match current PdoDb

    public function buildUpdateStatement(
        string $fullTable,
        string $setSql,
        ?string $whereSql
    ): string;
    // -> "UPDATE <table> SET ... WHERE ..."

    public function isStandardUpdateLimit(string $table, int $limit, PdoDb $db): bool;

    // WHERE / fragments (used across builders)
    public function limitOffsetClause(?int $limit, ?int $offset): string;

    // Functions and literals used by PdoDb
    public function now(?string $diff = '', ?string $func = null): string;

    public function booleanLiteral(bool $value): string;

    // Introspection / debug (used elsewhere in PdoDb)
    public function explainSql(string $query, bool $analyze = false): string;

    public function tableExistsSql(string $table): string;

    public function describeTableSql(string $table): string;

    public function buildLockSql(array $tables, string $prefix, string $lockMethod): string;

    public function buildUnlockSql(): string;

    public function canLoadXml(): bool;

    public function canLoadData(): bool;

    public function buildLoadDataSql(PDO $pdo, string $table, string $filePath, array $options): string;
}