<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\catalog;

use tommyknocker\pdodb\dialects\DialectInterface;

trait MssqlCatalogQueriesTrait
{
    use CatalogQueriesTraitHelpers;

    abstract protected function catalogDialect(): DialectInterface;

    public function buildCurrentSchemaQuery(): ?array
    {
        return ['SELECT SCHEMA_NAME()', []];
    }

    public function buildPgResolveSchemaQuery(string $quotedTable): ?array
    {
        return null;
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
            'SELECT fk.name, c.name AS column_name, OBJECT_SCHEMA_NAME(fk.referenced_object_id) AS referenced_schema, OBJECT_NAME(fk.referenced_object_id) AS referenced_table, rc.name AS referenced_column FROM sys.foreign_keys fk JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id JOIN sys.columns c ON fkc.parent_object_id = c.object_id AND fkc.parent_column_id = c.column_id JOIN sys.columns rc ON fkc.referenced_object_id = rc.object_id AND fkc.referenced_column_id = rc.column_id WHERE OBJECT_SCHEMA_NAME(fk.parent_object_id) = ? AND OBJECT_NAME(fk.parent_object_id) = ? ORDER BY fk.name, fkc.constraint_column_id',
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
            'SELECT SUM(p.rows) AS row_count FROM sys.partitions p JOIN sys.tables t ON p.object_id = t.object_id JOIN sys.schemas s ON s.schema_id = t.schema_id WHERE s.name = ? AND t.name = ? AND p.index_id IN (0, 1)',
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
            'SELECT i.name, i.is_unique, i.is_primary_key AS is_primary, ic.key_ordinal AS position, c.name AS column_name, ic.is_descending_key AS descending_key, ic.is_included_column AS is_included, i.filter_definition AS predicate FROM sys.indexes i JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id WHERE OBJECT_SCHEMA_NAME(i.object_id) = ? AND OBJECT_NAME(i.object_id) = ? ORDER BY i.name, ic.key_ordinal, ic.index_column_id',
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
            "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMNPROPERTY(OBJECT_ID(QUOTENAME(TABLE_SCHEMA) + '.' + QUOTENAME(TABLE_NAME)), COLUMN_NAME, 'IsIdentity') AS is_identity FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION",
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
            'SELECT i.name AS IndexName, i.type_desc AS IndexType, ic.key_ordinal AS KeyOrdinal, c.name AS ColumnName FROM sys.indexes i JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id WHERE OBJECT_SCHEMA_NAME(i.object_id) = ? AND OBJECT_NAME(i.object_id) = ? ORDER BY i.name, ic.key_ordinal',
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
            'SELECT fk.name AS CONSTRAINT_NAME, c.name AS COLUMN_NAME, OBJECT_SCHEMA_NAME(fk.referenced_object_id) AS REFERENCED_TABLE_SCHEMA, OBJECT_NAME(fk.referenced_object_id) AS REFERENCED_TABLE_NAME, rc.name AS REFERENCED_COLUMN_NAME FROM sys.foreign_keys fk JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id JOIN sys.columns c ON fkc.parent_object_id = c.object_id AND fkc.parent_column_id = c.column_id JOIN sys.columns rc ON fkc.referenced_object_id = rc.object_id AND fkc.referenced_column_id = rc.column_id WHERE OBJECT_SCHEMA_NAME(fk.parent_object_id) = ? AND OBJECT_NAME(fk.parent_object_id) = ?',
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
