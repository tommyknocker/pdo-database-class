<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\migrations;

use tommyknocker\pdodb\helpers\values\RawValue;
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
     * Whether the migration runs inside a transaction.
     *
     * Return null (default) to follow the database: a transaction where DDL can be rolled back (PostgreSQL,
     * SQLite, SQL Server), none where DDL commits implicitly (MySQL, MariaDB, Oracle). Return true to force a
     * transaction, for example for a migration that only changes data on MySQL; return false to opt out, for
     * example for PostgreSQL's CREATE INDEX CONCURRENTLY.
     */
    public function transactional(): ?bool
    {
        return null;
    }

    /**
     * Get DDL Query Builder for schema operations.
     *
     * Provides IDE autocompletion for schema operations.
     * All methods from DdlQueryBuilder are available through this method.
     *
     * @return DdlQueryBuilder Returns DdlQueryBuilder instance with all schema methods available.
     *                         IDE will provide autocompletion for methods like:
     *                         - createTable(), dropTable(), renameTable()
     *                         - addColumn(), dropColumn(), alterColumn()
     *                         - createIndex(), dropIndex(), createFulltextIndex()
     *                         - addForeignKey(), dropForeignKey()
     *                         - primaryKey(), string(), integer(), text(), etc.
     *
     * @example
     * // Create a table
     * $this->schema()->createTable('users', [
     *     'id' => $this->schema()->primaryKey(),
     *     'name' => $this->schema()->string(255)->notNull(),
     *     'email' => $this->schema()->string(255)->notNull()->unique(),
     * ]);
     * @example
     * // Add a column
     * $this->schema()->addColumn('users', 'status', $this->schema()->string(20)->defaultValue('active'));
     * @example
     * // Create an index
     * $this->schema()->createIndex('idx_email', 'users', 'email');
     * @example
     * // Add foreign key
     * $this->schema()->addForeignKey('fk_user_profile', 'profiles', 'user_id', 'users', 'id', 'CASCADE', 'CASCADE');
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
     * @param array<int, array<string, bool|float|int|string|RawValue|null>> $rows Row data
     */
    protected function batchInsert(string $table, array $columns, array $rows): void
    {
        $this->db->find()->table($table)->insertMulti($rows);
    }
}
