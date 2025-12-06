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
 *
 * @mixin DdlQueryBuilder
 *
 * @method DdlQueryBuilder createTable(string $table, array $columns, array $options = [])
 * @method DdlQueryBuilder createTableIfNotExists(string $table, array $columns, array $options = [])
 * @method DdlQueryBuilder dropTable(string $table)
 * @method DdlQueryBuilder dropTableIfExists(string $table)
 * @method DdlQueryBuilder renameTable(string $table, string $newName)
 * @method DdlQueryBuilder truncateTable(string $table)
 * @method DdlQueryBuilder addColumn(string $table, string $column, \tommyknocker\pdodb\query\schema\ColumnSchema|array|string $type)
 * @method DdlQueryBuilder dropColumn(string $table, string $column)
 * @method DdlQueryBuilder alterColumn(string $table, string $column, \tommyknocker\pdodb\query\schema\ColumnSchema|array|string $type)
 * @method DdlQueryBuilder renameColumn(string $table, string $oldName, string $newName)
 * @method DdlQueryBuilder createIndex(string $name, string $table, string|array $columns, bool $unique = false, ?string $where = null, ?array $includeColumns = null, array $options = [])
 * @method DdlQueryBuilder dropIndex(string $name, string $table)
 * @method DdlQueryBuilder createFulltextIndex(string $name, string $table, string|array $columns, ?string $parser = null)
 * @method DdlQueryBuilder createSpatialIndex(string $name, string $table, string|array $columns)
 * @method DdlQueryBuilder renameIndex(string $oldName, string $table, string $newName)
 * @method DdlQueryBuilder renameForeignKey(string $oldName, string $table, string $newName)
 * @method DdlQueryBuilder addForeignKey(string $name, string $table, string|array $columns, string $refTable, string|array $refColumns, ?string $delete = null, ?string $update = null)
 * @method DdlQueryBuilder dropForeignKey(string $name, string $table)
 * @method DdlQueryBuilder addPrimaryKey(string $name, string $table, string|array $columns)
 * @method DdlQueryBuilder dropPrimaryKey(string $name, string $table)
 * @method DdlQueryBuilder addUnique(string $name, string $table, string|array $columns)
 * @method DdlQueryBuilder dropUnique(string $name, string $table)
 * @method DdlQueryBuilder addCheck(string $name, string $table, string $expression)
 * @method DdlQueryBuilder dropCheck(string $name, string $table)
 * @method \tommyknocker\pdodb\query\schema\ColumnSchema primaryKey(?int $length = null)
 * @method \tommyknocker\pdodb\query\schema\ColumnSchema bigPrimaryKey()
 * @method \tommyknocker\pdodb\query\schema\ColumnSchema string(?int $length = null)
 * @method \tommyknocker\pdodb\query\schema\ColumnSchema text()
 * @method \tommyknocker\pdodb\query\schema\ColumnSchema char(?int $length = null)
 * @method \tommyknocker\pdodb\query\schema\ColumnSchema integer(?int $length = null)
 * @method \tommyknocker\pdodb\query\schema\ColumnSchema bigInteger()
 * @method \tommyknocker\pdodb\query\schema\ColumnSchema smallInteger(?int $length = null)
 * @method \tommyknocker\pdodb\query\schema\ColumnSchema boolean()
 * @method \tommyknocker\pdodb\query\schema\ColumnSchema float(?int $precision = null, ?int $scale = null)
 * @method \tommyknocker\pdodb\query\schema\ColumnSchema decimal(int $precision = 10, int $scale = 2)
 * @method \tommyknocker\pdodb\query\schema\ColumnSchema date()
 * @method \tommyknocker\pdodb\query\schema\ColumnSchema time()
 * @method \tommyknocker\pdodb\query\schema\ColumnSchema datetime()
 * @method \tommyknocker\pdodb\query\schema\ColumnSchema timestamp()
 * @method \tommyknocker\pdodb\query\schema\ColumnSchema json()
 * @method bool tableExists(string $table)
 * @method bool indexExists(string $name, string $table)
 * @method bool foreignKeyExists(string $name, string $table)
 * @method bool checkExists(string $name, string $table)
 * @method bool uniqueExists(string $name, string $table)
 * @method array getIndexes(string $table)
 * @method array getForeignKeys(string $table)
 * @method array getCheckConstraints(string $table)
 * @method array getUniqueConstraints(string $table)
 * @method \tommyknocker\pdodb\dialects\DialectInterface getDialect()
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
     * Provides IDE autocompletion for schema operations.
     * All methods from DdlQueryBuilder are available through this method.
     *
     * @return DdlQueryBuilder
     *
     * @example
     * // Create a table
     * $this->schema()->createTable('users', [
     *     'id' => $this->schema()->primaryKey(),
     *     'name' => $this->schema()->string(255)->notNull(),
     *     'email' => $this->schema()->string(255)->notNull()->unique(),
     * ]);
     *
     * @example
     * // Add a column
     * $this->schema()->addColumn('users', 'status', $this->schema()->string(20)->defaultValue('active'));
     *
     * @example
     * // Create an index
     * $this->schema()->createIndex('idx_email', 'users', 'email');
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
