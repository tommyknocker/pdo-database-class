<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\catalog;

/**
 * Dialect-specific SQL for AI metadata and MCP catalog introspection.
 *
 * @phpstan-type CatalogQuery array{0: string, 1: array<int|string, mixed>}
 */
interface CatalogQueriesInterface
{
    /**
     * @return CatalogQuery|null null = use literal schema name "main" (SQLite)
     */
    public function buildCurrentSchemaQuery(): ?array;

    /**
     * @return CatalogQuery|null PostgreSQL only
     */
    public function buildPgResolveSchemaQuery(string $quotedTable): ?array;

    public function resolveOracleObjectName(
        callable $fetchRows,
        string $schema,
        string $table,
    ): string;

    /**
     * @return CatalogQuery
     */
    public function buildAiColumnsQuery(string $schema, string $table): array;

    /**
     * @return list<mixed>
     */
    public function aiColumnsQueryParameters(string $schema, string $table): array;

    public function usesSqlitePragmaColumns(): bool;

    /**
     * @return CatalogQuery
     */
    public function buildAiForeignKeysQuery(string $schema, string $table): array;

    /**
     * @return list<mixed>
     */
    public function aiForeignKeysQueryParameters(string $schema, string $table): array;

    /**
     * @return CatalogQuery|null null when statistics are unavailable without extra pragmas
     */
    public function buildAiStatisticsQuery(string $schema, string $table): ?array;

    public function aiStatisticsSourceLabel(): string;

    public function normalizeAiIndexColumnRow(array $row): array;

    public function usesSqlitePragmaIndexes(): bool;

    /**
     * @return CatalogQuery
     */
    public function buildSqliteIndexListQuery(string $schema, string $table): array;

    /**
     * @return CatalogQuery
     */
    public function buildSqliteIndexXinfoQuery(string $schema, string $indexName): array;

    /**
     * @return CatalogQuery
     */
    public function buildSqliteIndexDefinitionQuery(string $schema, string $indexName): array;

    /**
     * @return CatalogQuery
     */
    public function buildAiIndexesQuery(string $schema, string $table): array;

    /**
     * @return list<mixed>
     */
    public function aiIndexesQueryParameters(string $schema, string $table): array;

    public function usesMysqlShowIndexesFormat(): bool;

    /**
     * @return CatalogQuery
     */
    public function buildMcpListTablesQuery(string $schema): array;

    /**
     * @return list<mixed>
     */
    public function mcpListTablesParameters(string $schema): array;

    /**
     * @return CatalogQuery
     */
    public function buildMcpListSchemasQuery(): array;

    public function mcpSchemaNameFromRow(array $row): string;

    /**
     * @return CatalogQuery
     */
    public function buildMcpColumnsQuery(string $schema, string $table): array;

    /**
     * @return list<mixed>
     */
    public function mcpColumnsParameters(string $schema, string $table): array;

    /**
     * @return CatalogQuery
     */
    public function buildMcpIndexesQuery(string $schema, string $table): array;

    /**
     * @return list<mixed>
     */
    public function mcpIndexesParameters(string $schema, string $table): array;

    /**
     * @return CatalogQuery
     */
    public function buildMcpForeignKeysQuery(string $schema, string $table): array;

    /**
     * @return list<mixed>
     */
    public function mcpForeignKeysParameters(string $schema, string $table): array;

    /**
     * Optional query for AI schema analysis table size (not row counts).
     *
     * @return CatalogQuery|null
     */
    public function buildAiTableSizeStatsQuery(string $tableName): ?array;
}
