<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\PdoDb;

/**
 * Facade for table operations using DDL builders and dialect helpers.
 */
class TableManager
{
    /**
     * List tables (optionally by schema if supported).
     *
     * @return array<int, string>
     */
    public static function listTables(PdoDb $db, ?string $schema = null): array
    {
        // Use schema inspection through SHOW TABLES/PRAGMA or information schema abstraction via dialect
        // Fallback: use builder's dialect SQL for listing tables where available
        $dialect = $db->schema()->getDialect();
        $driver = $dialect->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $db->rawQuery("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
            /** @var array<int, string> $names */
            $names = array_map(static fn (array $r): string => (string)$r['name'], $rows);
            return $names;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = $db->rawQuery('SHOW FULL TABLES WHERE Table_Type = "BASE TABLE"');
            /** @var array<int, string> $names */
            $names = array_values(array_filter(array_map(
                static function (array $r): string {
                    $vals = array_values($r);
                    return isset($vals[0]) && is_string($vals[0]) ? $vals[0] : '';
                },
                $rows
            ), static fn(string $s): bool => $s !== ''));
            sort($names);
            return $names;
        }

        if ($driver === 'pgsql') {
            $schemaName = $schema ?? 'public';
            /** @var array<int, array<string, mixed>> $rows */
            $rows = $db->rawQuery(
                'SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = :schema ORDER BY tablename',
                [':schema' => $schemaName]
            );
            /** @var array<int, string> $names */
            $names = array_map(static fn (array $r): string => (string)$r['tablename'], $rows);
            return $names;
        }

        if ($driver === 'sqlsrv') {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = $db->rawQuery("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
            /** @var array<int, string> $names */
            $names = array_map(static fn (array $r): string => (string)$r['TABLE_NAME'], $rows);
            return $names;
        }

        return [];
    }

    public static function tableExists(PdoDb $db, string $table): bool
    {
        $dialect = $db->schema()->getDialect();
        $sql = $dialect->buildTableExistsSql($table);
        $val = $db->rawQueryValue($sql);
        return !empty($val);
    }

    /**
     * Describe table columns (basic).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function describe(PdoDb $db, string $table): array
    {
        $dialect = $db->schema()->getDialect();
        $sql = $dialect->buildDescribeSql($table);
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $db->rawQuery($sql);
        return $rows;
    }

    /**
     * Get table info summary: columns, primary key, indexes, foreign keys (best effort).
     *
     * @return array<string, mixed>
     */
    public static function info(PdoDb $db, string $table): array
    {
        $columns = static::describe($db, $table);
        $dialect = $db->schema()->getDialect();
        $idxSql = $dialect->buildShowIndexesSql($table);
        $indexes = $db->rawQuery($idxSql);

        return [
            'table' => $table,
            'columns' => $columns,
            'indexes' => $indexes,
        ];
    }

    /**
     * @param array<string, array<string, mixed>|string> $columns
     * @param array<string, string> $options
     */
    public static function create(PdoDb $db, string $table, array $columns, array $options = [], bool $ifNotExists = false): void
    {
        $builder = $db->schema();
        if ($ifNotExists) {
            $builder->createTableIfNotExists($table, $columns, $options);
            return;
        }
        $builder->createTable($table, $columns, $options);
    }

    public static function drop(PdoDb $db, string $table, bool $ifExists = false): void
    {
        $builder = $db->schema();
        if ($ifExists) {
            $builder->dropTableIfExists($table);
            return;
        }
        $builder->dropTable($table);
    }

    public static function rename(PdoDb $db, string $old, string $new): void
    {
        $db->schema()->renameTable($old, $new);
    }

    public static function truncate(PdoDb $db, string $table): void
    {
        $db->schema()->truncateTable($table);
    }

    /**
     * @param array<string, mixed>|string $type
     */
    public static function addColumn(PdoDb $db, string $table, string $name, array|string $type): void
    {
        $db->schema()->addColumn($table, $name, $type);
    }

    /**
     * @param array<string, mixed>|string $type
     */
    public static function alterColumn(PdoDb $db, string $table, string $name, array|string $type): void
    {
        $db->schema()->alterColumn($table, $name, $type);
    }

    public static function dropColumn(PdoDb $db, string $table, string $name): void
    {
        $db->schema()->dropColumn($table, $name);
    }

    /**
     * @param string|array<int, string|array<string, string>> $columns
     */
    public static function createIndex(PdoDb $db, string $name, string $table, string|array $columns, bool $unique = false): void
    {
        $db->schema()->createIndex($name, $table, $columns, $unique);
    }

    public static function dropIndex(PdoDb $db, string $name, string $table): void
    {
        $db->schema()->dropIndex($name, $table);
    }
}
