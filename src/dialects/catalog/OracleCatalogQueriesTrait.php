<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\catalog;

use tommyknocker\pdodb\dialects\DialectInterface;

trait OracleCatalogQueriesTrait
{
    use CatalogQueriesTraitHelpers;

    abstract protected function catalogDialect(): DialectInterface;

    public function buildCurrentSchemaQuery(): ?array
    {
        return ["SELECT SYS_CONTEXT('USERENV', 'CURRENT_SCHEMA') FROM DUAL", []];
    }

    public function buildPgResolveSchemaQuery(string $quotedTable): ?array
    {
        return null;
    }

    public function resolveOracleObjectName(
        callable $fetchRows,
        string $schema,
        string $table,
    ): string {
        $candidates = [];
        foreach ([$table, strtoupper($table), strtolower($table)] as $candidate) {
            if (!in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }
        $placeholders = implode(', ', array_fill(0, count($candidates), '?'));
        $rows = $fetchRows(
            'SELECT OBJECT_NAME AS table_name FROM ALL_OBJECTS WHERE OWNER = ? AND OBJECT_TYPE IN (\'TABLE\', \'VIEW\') AND OBJECT_NAME IN (' . $placeholders . ')',
            [$schema, ...$candidates],
            count($candidates)
        );
        $found = [];
        foreach ($rows as $row) {
            if (isset($row['table_name']) && is_string($row['table_name'])) {
                $found[] = $row['table_name'];
            }
        }
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $found, true)) {
                return $candidate;
            }
        }

        return $table;
    }

    public function buildAiColumnsQuery(string $schema, string $table): array
    {
        return [
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS type, NULLABLE AS nullable, DATA_DEFAULT AS default_value FROM ALL_TAB_COLUMNS WHERE OWNER = ? AND TABLE_NAME = ? ORDER BY COLUMN_ID',
            [$schema, $table],
        ];
    }

    public function aiColumnsQueryParameters(string $schema, string $table): array
    {
        return [$schema, $table];
    }

    public function usesSqlitePragmaColumns(): bool
    {
        return false;
    }

    public function buildAiForeignKeysQuery(string $schema, string $table): array
    {
        return [
            'SELECT c.CONSTRAINT_NAME AS name, col.COLUMN_NAME AS column_name, ref.OWNER AS referenced_schema, ref.TABLE_NAME AS referenced_table, refcol.COLUMN_NAME AS referenced_column FROM ALL_CONSTRAINTS c JOIN ALL_CONS_COLUMNS col ON col.OWNER = c.OWNER AND col.CONSTRAINT_NAME = c.CONSTRAINT_NAME JOIN ALL_CONSTRAINTS ref ON ref.OWNER = c.R_OWNER AND ref.CONSTRAINT_NAME = c.R_CONSTRAINT_NAME JOIN ALL_CONS_COLUMNS refcol ON refcol.OWNER = ref.OWNER AND refcol.CONSTRAINT_NAME = ref.CONSTRAINT_NAME AND refcol.POSITION = col.POSITION WHERE c.OWNER = ? AND c.TABLE_NAME = ? AND c.CONSTRAINT_TYPE = \'R\' ORDER BY c.CONSTRAINT_NAME, col.POSITION',
            [$schema, $table],
        ];
    }

    public function aiForeignKeysQueryParameters(string $schema, string $table): array
    {
        return [$schema, $table];
    }

    public function buildAiStatisticsQuery(string $schema, string $table): ?array
    {
        return [
            'SELECT NUM_ROWS AS row_count, LAST_ANALYZED AS last_analyzed FROM ALL_TABLES WHERE OWNER = ? AND TABLE_NAME = ?',
            [$schema, $table],
        ];
    }

    public function aiStatisticsSourceLabel(): string
    {
        return $this->catalogDialect()->getDriverName() . '_catalog';
    }

    public function normalizeAiIndexColumnRow(array $row): array
    {
        if (isset($row['column_expression'])) {
            $expression = trim((string)$row['column_expression']);
            if (preg_match('/^"((?:[^"]|"")+)"$/D', $expression, $column)) {
                $row['column_name'] = str_replace('""', '"', $column[1]);
            } elseif (str_starts_with((string)($row['column_name'] ?? ''), 'SYS_NC')) {
                $row['column_name'] = null;
            }
        }

        return $row;
    }

    public function usesSqlitePragmaIndexes(): bool
    {
        return false;
    }

    public function buildSqliteIndexListQuery(string $schema, string $table): array
    {
        return ['', []];
    }

    public function buildSqliteIndexXinfoQuery(string $schema, string $indexName): array
    {
        return ['', []];
    }

    public function buildSqliteIndexDefinitionQuery(string $schema, string $indexName): array
    {
        return ['', []];
    }

    public function buildAiIndexesQuery(string $schema, string $table): array
    {
        return [
            'SELECT i.INDEX_NAME AS name, i.UNIQUENESS AS uniqueness, c.COLUMN_NAME AS column_name, c.COLUMN_POSITION AS position, c.DESCEND AS direction, e.COLUMN_EXPRESSION AS column_expression FROM ALL_INDEXES i JOIN ALL_IND_COLUMNS c ON c.INDEX_OWNER = i.OWNER AND c.INDEX_NAME = i.INDEX_NAME LEFT JOIN ALL_IND_EXPRESSIONS e ON e.INDEX_OWNER = c.INDEX_OWNER AND e.INDEX_NAME = c.INDEX_NAME AND e.COLUMN_POSITION = c.COLUMN_POSITION WHERE i.TABLE_OWNER = ? AND i.TABLE_NAME = ? ORDER BY i.INDEX_NAME, c.COLUMN_POSITION',
            [$schema, $table],
        ];
    }

    public function aiIndexesQueryParameters(string $schema, string $table): array
    {
        return [$schema, $table];
    }

    public function usesMysqlShowIndexesFormat(): bool
    {
        return false;
    }

    public function buildMcpListTablesQuery(string $schema): array
    {
        return [
            "SELECT TABLE_NAME FROM ALL_TABLES WHERE OWNER = ? AND TABLE_NAME NOT LIKE 'BIN$%' ORDER BY TABLE_NAME",
            [$schema],
        ];
    }

    public function mcpListTablesParameters(string $schema): array
    {
        return [$schema];
    }

    public function buildMcpListSchemasQuery(): array
    {
        return [
            "SELECT DISTINCT OWNER FROM ALL_TABLES WHERE OWNER NOT IN ('SYS', 'SYSTEM', 'XDB', 'CTXSYS', 'MDSYS')",
            [],
        ];
    }

    public function mcpSchemaNameFromRow(array $row): string
    {
        return (string) reset($row);
    }

    public function buildMcpColumnsQuery(string $schema, string $table): array
    {
        return [
            'SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH, DATA_PRECISION, DATA_SCALE, NULLABLE, DATA_DEFAULT FROM ALL_TAB_COLUMNS WHERE OWNER = ? AND TABLE_NAME = ? ORDER BY COLUMN_ID',
            [$schema, $table],
        ];
    }

    public function mcpColumnsParameters(string $schema, string $table): array
    {
        return [$schema, $table];
    }

    public function buildMcpIndexesQuery(string $schema, string $table): array
    {
        return [
            'SELECT INDEX_NAME AS index_name, TABLE_NAME AS table_name, COLUMN_NAME AS column_name, COLUMN_POSITION AS column_position FROM ALL_IND_COLUMNS WHERE TABLE_OWNER = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, COLUMN_POSITION',
            [$schema, $table],
        ];
    }

    public function mcpIndexesParameters(string $schema, string $table): array
    {
        return [$schema, $table];
    }

    public function buildMcpForeignKeysQuery(string $schema, string $table): array
    {
        return [
            "SELECT c.CONSTRAINT_NAME, col.COLUMN_NAME, ref.OWNER AS REFERENCED_TABLE_SCHEMA, ref.TABLE_NAME AS REFERENCED_TABLE_NAME, refcol.COLUMN_NAME AS REFERENCED_COLUMN_NAME FROM ALL_CONSTRAINTS c JOIN ALL_CONS_COLUMNS col ON col.OWNER = c.OWNER AND col.CONSTRAINT_NAME = c.CONSTRAINT_NAME JOIN ALL_CONSTRAINTS ref ON ref.OWNER = c.R_OWNER AND ref.CONSTRAINT_NAME = c.R_CONSTRAINT_NAME JOIN ALL_CONS_COLUMNS refcol ON refcol.OWNER = ref.OWNER AND refcol.CONSTRAINT_NAME = ref.CONSTRAINT_NAME AND refcol.POSITION = col.POSITION WHERE c.OWNER = ? AND c.TABLE_NAME = ? AND c.CONSTRAINT_TYPE = 'R' ORDER BY c.CONSTRAINT_NAME, col.POSITION",
            [$schema, $table],
        ];
    }

    public function mcpForeignKeysParameters(string $schema, string $table): array
    {
        return [$schema, $table];
    }

    public function buildAiTableSizeStatsQuery(string $tableName): ?array
    {
        return null;
    }
}
