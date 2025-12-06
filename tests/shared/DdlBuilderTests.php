<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\dialects\builders\DdlBuilderInterface;
use tommyknocker\pdodb\exceptions\QueryException;

/**
 * Shared tests for DDL Builder functionality.
 *
 * These tests verify that DDL builders generate correct SQL statements.
 */
class DdlBuilderTests extends BaseSharedTestCase
{
    /**
     * Get DDL builder instance.
     *
     * @return DdlBuilderInterface
     */
    protected function getDdlBuilder(): DdlBuilderInterface
    {
        $db = self::$db;
        $dialect = $db->schema()->getDialect();

        // Get ddlBuilder from dialect via reflection
        $reflection = new \ReflectionClass($dialect);
        $builderProperty = $reflection->getProperty('ddlBuilder');
        $builderProperty->setAccessible(true);
        return $builderProperty->getValue($dialect);
    }

    /**
     * Test buildCreateTableSql.
     */
    public function testBuildCreateTableSql(): void
    {
        $builder = $this->getDdlBuilder();
        $schema = self::$db->schema();

        $columns = [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100)->notNull(),
            'email' => $schema->string(255)->notNull(),
        ];

        $sql = $builder->buildCreateTableSql('test_table', $columns);

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('id', $sql);
        $this->assertStringContainsString('name', $sql);
        $this->assertStringContainsString('email', $sql);
    }

    /**
     * Test buildDropTableSql.
     */
    public function testBuildDropTableSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildDropTableSql('test_table');

        $this->assertIsString($sql);
        $this->assertStringContainsString('DROP TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
    }

    /**
     * Test buildDropTableIfExistsSql.
     */
    public function testBuildDropTableIfExistsSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildDropTableIfExistsSql('test_table');

        $this->assertIsString($sql);
        $this->assertStringContainsString('DROP TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        // Should contain IF EXISTS or equivalent
        $this->assertTrue(
            str_contains($sql, 'IF EXISTS') ||
            str_contains($sql, 'IF_EXISTS') ||
            str_contains($sql, 'EXISTS')
        );
    }

    /**
     * Test buildAddColumnSql.
     */
    public function testBuildAddColumnSql(): void
    {
        $builder = $this->getDdlBuilder();
        $schema = self::$db->schema();

        $columnSchema = $schema->string(100)->notNull();
        $sql = $builder->buildAddColumnSql('test_table', 'new_column', $columnSchema);

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('ADD', $sql);
        $this->assertStringContainsString('new_column', $sql);
    }

    /**
     * Test buildDropColumnSql.
     */
    public function testBuildDropColumnSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildDropColumnSql('test_table', 'old_column');

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('DROP', $sql);
        $this->assertStringContainsString('old_column', $sql);
    }

    /**
     * Test buildAlterColumnSql.
     */
    public function testBuildAlterColumnSql(): void
    {
        $builder = $this->getDdlBuilder();
        $schema = self::$db->schema();
        $driver = $schema->getDialect()->getDriverName();

        // SQLite doesn't support ALTER COLUMN
        if ($driver === 'sqlite') {
            $this->expectException(QueryException::class);
        }

        $columnSchema = $schema->string(200)->notNull();
        $sql = $builder->buildAlterColumnSql('test_table', 'existing_column', $columnSchema);

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('existing_column', $sql);
    }

    /**
     * Test buildRenameColumnSql.
     */
    public function testBuildRenameColumnSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildRenameColumnSql('test_table', 'old_name', 'new_name');

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('old_name', $sql);
        $this->assertStringContainsString('new_name', $sql);
    }

    /**
     * Test buildCreateIndexSql.
     */
    public function testBuildCreateIndexSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildCreateIndexSql('idx_test', 'test_table', ['column1', 'column2']);

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE INDEX', $sql);
        $this->assertStringContainsString('idx_test', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('column1', $sql);
        $this->assertStringContainsString('column2', $sql);
    }

    /**
     * Test buildCreateIndexSql with unique.
     */
    public function testBuildCreateIndexSqlUnique(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildCreateIndexSql('idx_test_unique', 'test_table', ['email'], true);

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE', $sql);
        $this->assertStringContainsString('UNIQUE', $sql);
        $this->assertStringContainsString('idx_test_unique', $sql);
        $this->assertStringContainsString('test_table', $sql);
    }

    /**
     * Test buildDropIndexSql.
     */
    public function testBuildDropIndexSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildDropIndexSql('idx_test', 'test_table');

        $this->assertIsString($sql);
        $this->assertStringContainsString('DROP', $sql);
        $this->assertStringContainsString('INDEX', $sql);
        $this->assertStringContainsString('idx_test', $sql);
        // SQLite doesn't include table name in DROP INDEX, others may
    }

    /**
     * Test buildRenameTableSql.
     */
    public function testBuildRenameTableSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildRenameTableSql('old_table', 'new_table');

        $this->assertIsString($sql);
        $this->assertStringContainsString('RENAME', $sql);
        $this->assertStringContainsString('old_table', $sql);
        $this->assertStringContainsString('new_table', $sql);
    }

    /**
     * Test buildAddForeignKeySql.
     */
    public function testBuildAddForeignKeySql(): void
    {
        $builder = $this->getDdlBuilder();
        $driver = self::$db->schema()->getDialect()->getDriverName();

        // SQLite doesn't support adding foreign keys via ALTER TABLE
        if ($driver === 'sqlite') {
            $this->expectException(QueryException::class);
        }

        $sql = $builder->buildAddForeignKeySql(
            'fk_test',
            'orders',
            ['user_id'],
            'users',
            ['id'],
            'CASCADE',
            'RESTRICT'
        );

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('orders', $sql);
        $this->assertStringContainsString('ADD', $sql);
        $this->assertStringContainsString('FOREIGN KEY', $sql);
        $this->assertStringContainsString('fk_test', $sql);
        $this->assertStringContainsString('user_id', $sql);
        $this->assertStringContainsString('users', $sql);
        $this->assertStringContainsString('id', $sql);
    }

    /**
     * Test buildDropForeignKeySql.
     */
    public function testBuildDropForeignKeySql(): void
    {
        $builder = $this->getDdlBuilder();
        $driver = self::$db->schema()->getDialect()->getDriverName();

        // SQLite doesn't support dropping foreign keys via ALTER TABLE
        if ($driver === 'sqlite') {
            $this->expectException(QueryException::class);
        }

        $sql = $builder->buildDropForeignKeySql('fk_test', 'test_table');

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('DROP', $sql);
        $this->assertStringContainsString('fk_test', $sql);
    }

    /**
     * Test buildAddPrimaryKeySql.
     */
    public function testBuildAddPrimaryKeySql(): void
    {
        $builder = $this->getDdlBuilder();
        $driver = self::$db->schema()->getDialect()->getDriverName();

        // SQLite doesn't support adding PRIMARY KEY via ALTER TABLE
        if ($driver === 'sqlite') {
            $this->expectException(QueryException::class);
        }

        $sql = $builder->buildAddPrimaryKeySql('pk_test', 'test_table', ['id']);

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('ADD', $sql);
        $this->assertStringContainsString('PRIMARY KEY', $sql);
        $this->assertStringContainsString('id', $sql);
    }

    /**
     * Test buildDropPrimaryKeySql.
     */
    public function testBuildDropPrimaryKeySql(): void
    {
        $builder = $this->getDdlBuilder();
        $driver = self::$db->schema()->getDialect()->getDriverName();

        // SQLite doesn't support dropping PRIMARY KEY via ALTER TABLE
        if ($driver === 'sqlite') {
            $this->expectException(QueryException::class);
        }

        $sql = $builder->buildDropPrimaryKeySql('pk_test', 'test_table');

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('DROP', $sql);
        $this->assertStringContainsString('PRIMARY KEY', $sql);
    }

    /**
     * Test buildAddUniqueSql.
     */
    public function testBuildAddUniqueSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildAddUniqueSql('uk_test', 'test_table', ['email']);

        $this->assertIsString($sql);
        // SQLite uses CREATE UNIQUE INDEX, others use ALTER TABLE ADD UNIQUE
        $this->assertTrue(
            str_contains($sql, 'ALTER TABLE') ||
            str_contains($sql, 'CREATE UNIQUE INDEX')
        );
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('UNIQUE', $sql);
        $this->assertStringContainsString('email', $sql);
    }

    /**
     * Test buildDropUniqueSql.
     */
    public function testBuildDropUniqueSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildDropUniqueSql('uk_test', 'test_table');

        $this->assertIsString($sql);
        // SQLite uses DROP INDEX for unique constraints, others use ALTER TABLE
        $this->assertTrue(
            str_contains($sql, 'ALTER TABLE') ||
            str_contains($sql, 'DROP INDEX')
        );
        $this->assertStringContainsString('DROP', $sql);
        $this->assertStringContainsString('uk_test', $sql);
    }

    /**
     * Test buildAddCheckSql.
     */
    public function testBuildAddCheckSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildAddCheckSql('ck_test', 'test_table', 'age > 0');

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('ADD', $sql);
        $this->assertStringContainsString('CHECK', $sql);
        $this->assertStringContainsString('age > 0', $sql);
    }

    /**
     * Test buildDropCheckSql.
     */
    public function testBuildDropCheckSql(): void
    {
        $builder = $this->getDdlBuilder();
        $driver = self::$db->schema()->getDialect()->getDriverName();

        // SQLite doesn't support dropping CHECK constraint via ALTER TABLE
        if ($driver === 'sqlite') {
            $this->expectException(QueryException::class);
        }

        $sql = $builder->buildDropCheckSql('ck_test', 'test_table');

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('DROP', $sql);
        $this->assertStringContainsString('ck_test', $sql);
    }

    /**
     * Test buildRenameIndexSql.
     */
    public function testBuildRenameIndexSql(): void
    {
        $builder = $this->getDdlBuilder();
        $driver = self::$db->schema()->getDialect()->getDriverName();

        // SQLite doesn't support renaming indexes directly
        if ($driver === 'sqlite') {
            $this->expectException(QueryException::class);
        }

        $sql = $builder->buildRenameIndexSql('old_idx', 'test_table', 'new_idx');

        $this->assertIsString($sql);
        $this->assertStringContainsString('RENAME', $sql);
        $this->assertStringContainsString('old_idx', $sql);
        $this->assertStringContainsString('new_idx', $sql);
        $this->assertStringContainsString('test_table', $sql);
    }

    /**
     * Test buildRenameForeignKeySql.
     */
    public function testBuildRenameForeignKeySql(): void
    {
        $builder = $this->getDdlBuilder();
        $driver = self::$db->schema()->getDialect()->getDriverName();

        // SQLite doesn't support renaming foreign keys
        if ($driver === 'sqlite') {
            $this->expectException(QueryException::class);
        }

        $sql = $builder->buildRenameForeignKeySql('old_fk', 'test_table', 'new_fk');

        $this->assertIsString($sql);
        $this->assertStringContainsString('RENAME', $sql);
        $this->assertStringContainsString('old_fk', $sql);
        $this->assertStringContainsString('new_fk', $sql);
        $this->assertStringContainsString('test_table', $sql);
    }

    /**
     * Test formatColumnDefinition.
     */
    public function testFormatColumnDefinition(): void
    {
        $builder = $this->getDdlBuilder();
        $schema = self::$db->schema();

        $columnSchema = $schema->string(100)->notNull()->defaultValue('test');
        $sql = $builder->formatColumnDefinition('test_column', $columnSchema);

        $this->assertIsString($sql);
        $this->assertStringContainsString('test_column', $sql);
        $this->assertTrue(
            str_contains($sql, 'VARCHAR') ||
            str_contains($sql, 'STRING') ||
            str_contains($sql, 'TEXT')
        );
    }

    /**
     * Test buildCreateIndexSql with WHERE clause (partial index).
     */
    public function testBuildCreateIndexSqlWithWhere(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildCreateIndexSql(
            'idx_partial',
            'test_table',
            ['status'],
            false,
            'status = \'active\''
        );

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE INDEX', $sql);
        $this->assertStringContainsString('idx_partial', $sql);
        $this->assertStringContainsString('status', $sql);
        // WHERE clause may be in different formats depending on dialect
        $this->assertTrue(
            str_contains($sql, 'WHERE') ||
            str_contains($sql, 'status =') ||
            str_contains($sql, 'active')
        );
    }

    /**
     * Test buildCreateFulltextIndexSql.
     */
    public function testBuildCreateFulltextIndexSql(): void
    {
        $builder = $this->getDdlBuilder();
        $driver = self::$db->schema()->getDialect()->getDriverName();

        // SQLite doesn't support FULLTEXT indexes
        if ($driver === 'sqlite') {
            $this->expectException(QueryException::class);
        }

        $sql = $builder->buildCreateFulltextIndexSql('ft_idx', 'test_table', ['title', 'content']);

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE', $sql);
        $this->assertStringContainsString('FULLTEXT', $sql);
        $this->assertStringContainsString('ft_idx', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('title', $sql);
        $this->assertStringContainsString('content', $sql);
    }

    /**
     * Test buildCreateSpatialIndexSql.
     */
    public function testBuildCreateSpatialIndexSql(): void
    {
        $builder = $this->getDdlBuilder();
        $driver = self::$db->schema()->getDialect()->getDriverName();

        // SQLite doesn't support SPATIAL indexes
        if ($driver === 'sqlite') {
            $this->expectException(QueryException::class);
        }

        $sql = $builder->buildCreateSpatialIndexSql('sp_idx', 'test_table', ['location']);

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE', $sql);
        $this->assertStringContainsString('SPATIAL', $sql);
        $this->assertStringContainsString('sp_idx', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('location', $sql);
    }
}
