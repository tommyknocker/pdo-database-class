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
        $dialect = $db->schema()->getDialect();
        return $dialect->listTables($db, $schema);
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
