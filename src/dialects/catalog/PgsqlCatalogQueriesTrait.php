<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\catalog;

use tommyknocker\pdodb\dialects\DialectInterface;

trait PgsqlCatalogQueriesTrait
{
    use CatalogQueriesTraitHelpers;

    abstract protected function catalogDialect(): DialectInterface;

    public function buildCurrentSchemaQuery(): ?array
    {
        return ['SELECT current_schema()', []];
    }

    public function buildPgResolveSchemaQuery(string $quotedTable): ?array
    {
        return [
            'SELECT n.nspname AS schema_name FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.oid = to_regclass(?)',
            [$quotedTable],
        ];
    }

    public function buildAiColumnsQuery(string $schema, string $table): array
    {
        return [
            'SELECT column_name AS name, data_type AS type, is_nullable AS nullable, column_default AS default_value FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
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
            'SELECT con.conname AS name, a.attname AS column_name, refns.nspname AS referenced_schema, ref.relname AS referenced_table, ra.attname AS referenced_column FROM pg_constraint con JOIN pg_class tab ON tab.oid = con.conrelid JOIN pg_namespace ns ON ns.oid = tab.relnamespace JOIN pg_class ref ON ref.oid = con.confrelid JOIN pg_namespace refns ON refns.oid = ref.relnamespace JOIN LATERAL generate_subscripts(con.conkey, 1) AS pos(n) ON true JOIN pg_attribute a ON a.attrelid = tab.oid AND a.attnum = con.conkey[pos.n] JOIN pg_attribute ra ON ra.attrelid = ref.oid AND ra.attnum = con.confkey[pos.n] WHERE con.contype = \'f\' AND ns.nspname = ? AND tab.relname = ? ORDER BY con.conname, pos.n',
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
            'SELECT c.reltuples AS row_count, s.last_analyze, s.last_autoanalyze FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace LEFT JOIN pg_stat_all_tables s ON s.relid = c.oid WHERE n.nspname = ? AND c.relname = ?',
            [$schema, $table],
        ];
    }

    public function aiStatisticsSourceLabel(): string
    {
        return $this->catalogDialect()->getDriverName() . '_catalog';
    }

    public function normalizeAiIndexColumnRow(array $row): array
    {
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
            'SELECT ci.relname AS name, i.indisunique AS is_unique, i.indisprimary AS is_primary, k.pos AS position, a.attname AS column_name, pg_index_column_has_property(i.indexrelid, k.pos, \'desc\') AS descending_key, pg_get_indexdef(i.indexrelid, k.pos, true) AS column_expression, k.pos > i.indnkeyatts AS is_included, pg_get_indexdef(i.indexrelid) AS definition, pg_get_expr(i.indpred, i.indrelid) AS predicate FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid JOIN pg_namespace ns ON ns.oid = t.relnamespace JOIN pg_class ci ON ci.oid = i.indexrelid CROSS JOIN LATERAL generate_series(1, i.indnatts) AS k(pos) LEFT JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = i.indkey[k.pos - 1] WHERE ns.nspname = ? AND t.relname = ? ORDER BY ci.relname, k.pos',
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
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
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
            "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME NOT IN ('pg_catalog', 'information_schema', 'INFORMATION_SCHEMA', 'sys') AND SCHEMA_NAME NOT LIKE 'pg_toast%'",
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
            'SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
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
            'SELECT indexname AS index_name, tablename AS table_name, indexdef AS definition FROM pg_indexes WHERE schemaname = ? AND tablename = ? ORDER BY indexname',
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
            "SELECT con.conname AS constraint_name, a.attname AS column_name, refns.nspname AS referenced_table_schema, ref.relname AS referenced_table_name, ra.attname AS referenced_column_name FROM pg_catalog.pg_constraint con JOIN pg_catalog.pg_class tab ON tab.oid = con.conrelid JOIN pg_catalog.pg_namespace ns ON ns.oid = tab.relnamespace JOIN pg_catalog.pg_class ref ON ref.oid = con.confrelid JOIN pg_catalog.pg_namespace refns ON refns.oid = ref.relnamespace JOIN LATERAL generate_subscripts(con.conkey, 1) AS pos(n) ON true JOIN pg_attribute a ON a.attrelid = tab.oid AND a.attnum = con.conkey[pos.n] JOIN pg_attribute ra ON ra.attrelid = ref.oid AND ra.attnum = con.confkey[pos.n] WHERE con.contype = 'f' AND ns.nspname = ? AND tab.relname = ? ORDER BY con.conname, pos.n",
            [$schema, $table],
        ];
    }

    public function mcpForeignKeysParameters(string $schema, string $table): array
    {
        return [$schema, $table];
    }

    public function buildAiTableSizeStatsQuery(string $tableName): ?array
    {
        return [
            'SELECT pg_size_pretty(pg_total_relation_size(?)) as size',
            [$tableName],
        ];
    }
}
