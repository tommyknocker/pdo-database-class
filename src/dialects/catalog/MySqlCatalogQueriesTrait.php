<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\catalog;

use tommyknocker\pdodb\dialects\DialectInterface;

trait MySqlCatalogQueriesTrait
{
    use CatalogQueriesTraitHelpers;

    abstract protected function catalogDialect(): DialectInterface;

    public function buildCurrentSchemaQuery(): ?array
    {
        return ['SELECT DATABASE()', []];
    }

    public function buildPgResolveSchemaQuery(string $quotedTable): ?array
    {
        return null;
    }

    public function buildAiColumnsQuery(string $schema, string $table): array
    {
        return [
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_KEY AS column_key, COLUMN_DEFAULT AS default_value FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
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
            'SELECT CONSTRAINT_NAME AS name, COLUMN_NAME AS column_name, REFERENCED_TABLE_SCHEMA AS referenced_schema, REFERENCED_TABLE_NAME AS referenced_table, REFERENCED_COLUMN_NAME AS referenced_column FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION',
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
            'SELECT TABLE_ROWS AS row_count, DATA_LENGTH + INDEX_LENGTH AS size_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$schema, $table],
        ];
    }

    public function aiStatisticsSourceLabel(): string
    {
        return $this->catalogDialect()->getDriverName() . '_catalog';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
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
            'SHOW INDEXES FROM ' . $this->quoteCatalogIdentifier($schema) . '.' . $this->quoteCatalogIdentifier($table),
            [],
        ];
    }

    public function aiIndexesQueryParameters(string $schema, string $table): array
    {
        return [];
    }

    public function usesMysqlShowIndexesFormat(): bool
    {
        return true;
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
            "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME NOT IN ('mysql', 'information_schema', 'performance_schema', 'sys')",
            [],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function mcpSchemaNameFromRow(array $row): string
    {
        return (string) reset($row);
    }

    public function buildMcpColumnsQuery(string $schema, string $table): array
    {
        return [
            'SELECT COLUMN_NAME AS Field, COLUMN_TYPE AS Type, IS_NULLABLE AS `Null`, COLUMN_KEY AS `Key`, COLUMN_DEFAULT AS `Default`, EXTRA AS Extra FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
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
            'SHOW INDEXES FROM ' . $this->quoteCatalogIdentifier($schema) . '.' . $this->quoteCatalogIdentifier($table),
            [],
        ];
    }

    public function mcpIndexesParameters(string $schema, string $table): array
    {
        return [];
    }

    public function buildMcpForeignKeysQuery(string $schema, string $table): array
    {
        return [
            'SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
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
            'SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = ?',
            [$tableName],
        ];
    }
}
