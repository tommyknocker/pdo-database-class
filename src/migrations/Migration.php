<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\migrations;

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\query\DdlQueryBuilder;
use tommyknocker\pdodb\query\QueryBuilder;

/**
 * Base class for database migrations.
 *
 * This class provides common methods for migrations, similar to Yii2's Migration class.
 * All migrations should extend this class and implement up() and down() methods.
 */
abstract class Migration implements MigrationInterface
{
    /** @var PdoDb Database instance */
    protected PdoDb $db;

    /**
     * Migration constructor.
     *
     * @param PdoDb $db Database instance
     */
    public function __construct(PdoDb $db)
    {
        $this->db = $db;
    }

    /**
     * Apply the migration.
     */
    abstract public function up(): void;

    /**
     * Revert the migration.
     */
    abstract public function down(): void;

    /**
     * Get DDL Query Builder for schema operations.
     *
     * @return DdlQueryBuilder
     */
    protected function schema(): DdlQueryBuilder
    {
        return $this->db->schema();
    }

    /**
     * Get Query Builder for data operations.
     *
     * @return QueryBuilder
     */
    protected function find(): QueryBuilder
    {
        return $this->db->find();
    }

    /**
     * Execute raw SQL.
     *
     * @param string $sql SQL statement
     *
     * @return array<int, array<string, mixed>>
     */
    protected function execute(string $sql): array
    {
        return $this->db->rawQuery($sql);
    }

    /**
     * Insert a single row.
     *
     * @param string $table Table name
     * @param array<string, mixed> $columns Column data
     *
     * @return int Inserted row ID
     */
    protected function insert(string $table, array $columns): int
    {
        return $this->db->find()->table($table)->insert($columns);
    }

    /**
     * Insert multiple rows.
     *
     * @param string $table Table name
     * @param array<int, string> $columns Column names
     * @param array<int, array<string, bool|float|int|string|\tommyknocker\pdodb\helpers\values\RawValue|null>> $rows Row data
     */
    protected function batchInsert(string $table, array $columns, array $rows): void
    {
        $this->db->find()->table($table)->insertMulti($rows);
    }

    /**
     * Safe version of up() that can be overridden.
     *
     * Override this method instead of up() for migrations that cannot be wrapped in transactions.
     */
    protected function safeUp(): void
    {
        $this->up();
    }

    /**
     * Safe version of down() that can be overridden.
     *
     * Override this method instead of down() for migrations that cannot be wrapped in transactions.
     */
    protected function safeDown(): void
    {
        $this->down();
    }
}
