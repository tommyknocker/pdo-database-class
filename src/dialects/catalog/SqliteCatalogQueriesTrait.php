<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\catalog;

use tommyknocker\pdodb\dialects\DialectInterface;

trait SqliteCatalogQueriesTrait
{
    use CatalogQueriesTraitHelpers;

    abstract protected function catalogDialect(): DialectInterface;

    public function buildCurrentSchemaQuery(): ?array
    {
        return null;
    }

    public function buildPgResolveSchemaQuery(string $quotedTable): ?array
    {
        return null;
    }

    public function buildAiColumnsQuery(string $schema, string $table): array
    {
        return [
            'PRAGMA ' . $this->quoteCatalogIdentifier($schema) . '.table_xinfo(' . $this->quoteCatalogIdentifier($table) . ')',
            [],
        ];
    }

    public function aiColumnsQueryParameters(string $schema, string $table): array
    {
        return [];
    }

    public function usesSqlitePragmaColumns(): bool
    {
        return true;
    }

    public function buildAiForeignKeysQuery(string $schema, string $table): array
    {
        return [
            'PRAGMA ' . $this->quoteCatalogIdentifier($schema) . '.foreign_key_list(' . $this->quoteCatalogIdentifier($table) . ')',
            [],
        ];
    }

    public function aiForeignKeysQueryParameters(string $schema, string $table): array
    {
        return [];
    }

    public function buildAiStatisticsQuery(string $schema, string $table): ?array
    {
        return null;
    }

    public function aiStatisticsSourceLabel(): string
    {
        return 'unavailable';
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
        return true;
    }

    public function buildSqliteIndexListQuery(string $schema, string $table): array
    {
        return [
            'PRAGMA ' . $this->quoteCatalogIdentifier($schema) . '.index_list(' . $this->quoteCatalogIdentifier($table) . ')',
            [],
        ];
    }

    public function buildSqliteIndexXinfoQuery(string $schema, string $indexName): array
    {
        return [
            'PRAGMA ' . $this->quoteCatalogIdentifier($schema) . '.index_xinfo(' . $this->quoteCatalogIdentifier($indexName) . ')',
            [],
        ];
    }

    public function buildSqliteIndexDefinitionQuery(string $schema, string $indexName): array
    {
        return [
            'SELECT sql FROM ' . $this->quoteCatalogIdentifier($schema) . '.sqlite_master WHERE name = ? AND type = \'index\'',
            [$indexName],
        ];
    }

    public function buildAiIndexesQuery(string $schema, string $table): array
    {
        return $this->buildSqliteIndexListQuery($schema, $table);
    }

    public function aiIndexesQueryParameters(string $schema, string $table): array
    {
        return [];
    }

    public function usesMysqlShowIndexesFormat(): bool
    {
        return false;
    }

    public function buildMcpListTablesQuery(string $schema): array
    {
        return [
            'SELECT name FROM ' . $this->quoteCatalogIdentifier($schema) . ".sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            [],
        ];
    }

    public function mcpListTablesParameters(string $schema): array
    {
        return [];
    }

    public function buildMcpListSchemasQuery(): array
    {
        return ['PRAGMA database_list', []];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function mcpSchemaNameFromRow(array $row): string
    {
        return (string)($row['name'] ?? reset($row));
    }

    public function buildMcpColumnsQuery(string $schema, string $table): array
    {
        return [
            'PRAGMA ' . $this->quoteCatalogIdentifier($schema) . '.table_info(' . $this->quoteCatalogIdentifier($table) . ')',
            [],
        ];
    }

    public function mcpColumnsParameters(string $schema, string $table): array
    {
        return [];
    }

    public function buildMcpIndexesQuery(string $schema, string $table): array
    {
        return [
            'SELECT name, tbl_name AS table_name, sql FROM ' . $this->quoteCatalogIdentifier($schema) . ".sqlite_master WHERE type = 'index' AND tbl_name = ?",
            [$table],
        ];
    }

    public function mcpIndexesParameters(string $schema, string $table): array
    {
        return [$table];
    }

    public function buildMcpForeignKeysQuery(string $schema, string $table): array
    {
        return [
            'PRAGMA ' . $this->quoteCatalogIdentifier($schema) . '.foreign_key_list(' . $this->quoteCatalogIdentifier($table) . ')',
            [],
        ];
    }

    public function mcpForeignKeysParameters(string $schema, string $table): array
    {
        return [];
    }

    public function buildAiTableSizeStatsQuery(string $tableName): ?array
    {
        return null;
    }
}
