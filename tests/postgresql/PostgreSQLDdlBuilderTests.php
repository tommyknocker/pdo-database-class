<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * PostgreSQL-specific DDL Builder tests.
 *
 * These tests verify PostgreSQL-specific DDL builder methods and SQL generation.
 */
final class PostgreSQLDdlBuilderTests extends BasePostgreSQLTestCase
{
    /**
     * Get PostgreSQL DDL builder instance.
     *
     * @return \tommyknocker\pdodb\dialects\postgresql\PostgreSQLDdlBuilder
     */
    protected function getDdlBuilder(): \tommyknocker\pdodb\dialects\postgresql\PostgreSQLDdlBuilder
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
     * Test buildCreateTableSql with PostgreSQL-specific options.
     */
    public function testBuildCreateTableSql(): void
    {
        $builder = $this->getDdlBuilder();
        $schema = self::$db->schema();

        $columns = [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100)->notNull(),
        ];

        $sql = $builder->buildCreateTableSql('test_table', $columns);

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('id', $sql);
        $this->assertStringContainsString('name', $sql);
    }

    /**
     * Test buildCreateIndexSql with INCLUDE columns (PostgreSQL-specific).
     */
    public function testBuildCreateIndexSqlWithInclude(): void
    {
        $builder = $this->getDdlBuilder();
        $reflection = new \ReflectionClass($builder);

        // Check if buildCreateIndexSql supports include option
        $method = $reflection->getMethod('buildCreateIndexSql');
        $method->setAccessible(true);

        $includeColumns = ['email', 'created_at'];
        $sql = $method->invoke($builder, 'idx_test', 'test_table', ['name'], false, null, $includeColumns);

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE INDEX', $sql);
        $this->assertStringContainsString('idx_test', $sql);
        $this->assertStringContainsString('INCLUDE', $sql);
        $this->assertStringContainsString('email', $sql);
        $this->assertStringContainsString('created_at', $sql);
    }

    /**
     * Test buildCreateIndexSql with WHERE clause (partial index, PostgreSQL-specific).
     */
    public function testBuildCreateIndexSqlWithWhere(): void
    {
        $builder = $this->getDdlBuilder();
        $reflection = new \ReflectionClass($builder);

        $method = $reflection->getMethod('buildCreateIndexSql');
        $method->setAccessible(true);

        $where = 'status = \'active\'';
        $sql = $method->invoke($builder, 'idx_active', 'test_table', ['name'], false, $where);

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE INDEX', $sql);
        $this->assertStringContainsString('idx_active', $sql);
        $this->assertStringContainsString('WHERE', $sql);
        $this->assertStringContainsString('status', $sql);
    }

    /**
     * Test buildCreateFulltextIndexSql (PostgreSQL uses GIN indexes).
     */
    public function testBuildCreateFulltextIndexSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildCreateFulltextIndexSql('ft_idx', 'test_table', ['title', 'content']);

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE INDEX', $sql);
        $this->assertStringContainsString('ft_idx', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('GIN', $sql);
        $this->assertStringContainsString('to_tsvector', $sql);
    }

    /**
     * Test buildCreateSpatialIndexSql (PostgreSQL uses GiST indexes).
     */
    public function testBuildCreateSpatialIndexSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildCreateSpatialIndexSql('spatial_idx', 'test_table', ['location']);

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE INDEX', $sql);
        $this->assertStringContainsString('spatial_idx', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('USING GIST', $sql);
        $this->assertStringContainsString('location', $sql);
    }

    /**
     * Test formatColumnDefinition with PostgreSQL-specific types.
     */
    public function testFormatColumnDefinition(): void
    {
        $builder = $this->getDdlBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('formatColumnDefinition');
        $method->setAccessible(true);

        // Test BOOLEAN type (PostgreSQL uses BOOLEAN, not TINYINT)
        $column = new ColumnSchema('BOOLEAN');
        $result = $method->invoke($builder, 'test_col', $column);
        $this->assertIsString($result);
        $this->assertStringContainsString('test_col', $result);
        $this->assertStringContainsString('BOOLEAN', $result);

        // Test TEXT type
        $column = new ColumnSchema('TEXT');
        $result = $method->invoke($builder, 'text_col', $column);
        $this->assertIsString($result);
        $this->assertStringContainsString('TEXT', $result);

        // Test with SERIAL (auto-increment)
        $column = new ColumnSchema('SERIAL');
        $result = $method->invoke($builder, 'id', $column);
        $this->assertStringContainsString('SERIAL', $result);
    }

    /**
     * Test buildAlterColumnSql.
     */
    public function testBuildAlterColumnSql(): void
    {
        $builder = $this->getDdlBuilder();
        $schema = self::$db->schema();

        $columnSchema = $schema->string(200);
        $sql = $builder->buildAlterColumnSql('test_table', 'test_column', $columnSchema);

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('ALTER COLUMN', $sql);
        $this->assertStringContainsString('test_column', $sql);
    }

    /**
     * Test buildRenameColumnSql.
     */
    public function testBuildRenameColumnSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildRenameColumnSql('test_table', 'old_column', 'new_column');

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('RENAME COLUMN', $sql);
        $this->assertStringContainsString('old_column', $sql);
        $this->assertStringContainsString('new_column', $sql);
    }

    /**
     * Test buildRenameIndexSql.
     */
    public function testBuildRenameIndexSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildRenameIndexSql('old_idx', 'test_table', 'new_idx');

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER INDEX', $sql);
        $this->assertStringContainsString('old_idx', $sql);
        $this->assertStringContainsString('RENAME TO', $sql);
        $this->assertStringContainsString('new_idx', $sql);
    }

    /**
     * Test buildRenameForeignKeySql.
     */
    public function testBuildRenameForeignKeySql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildRenameForeignKeySql('old_fk', 'test_table', 'new_fk');

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('RENAME CONSTRAINT', $sql);
        $this->assertStringContainsString('old_fk', $sql);
        $this->assertStringContainsString('new_fk', $sql);
    }

    /**
     * Test buildAddForeignKeySql with ON DELETE and ON UPDATE actions.
     */
    public function testBuildAddForeignKeySqlWithActions(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildAddForeignKeySql(
            'fk_test',
            'child_table',
            ['parent_id'],
            'parent_table',
            ['id'],
            'CASCADE',
            'SET NULL'
        );

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('child_table', $sql);
        $this->assertStringContainsString('ADD CONSTRAINT', $sql);
        $this->assertStringContainsString('fk_test', $sql);
        $this->assertStringContainsString('FOREIGN KEY', $sql);
        $this->assertStringContainsString('ON DELETE CASCADE', $sql);
        $this->assertStringContainsString('ON UPDATE SET NULL', $sql);
    }

    /**
     * Test buildDropForeignKeySql.
     */
    public function testBuildDropForeignKeySql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildDropForeignKeySql('fk_test', 'test_table');

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('DROP CONSTRAINT', $sql);
        $this->assertStringContainsString('fk_test', $sql);
    }

    /**
     * Test buildAddPrimaryKeySql.
     */
    public function testBuildAddPrimaryKeySql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildAddPrimaryKeySql('pk_test', 'test_table', ['id', 'code']);

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('ADD CONSTRAINT', $sql);
        $this->assertStringContainsString('pk_test', $sql);
        $this->assertStringContainsString('PRIMARY KEY', $sql);
        $this->assertStringContainsString('id', $sql);
        $this->assertStringContainsString('code', $sql);
    }

    /**
     * Test buildDropPrimaryKeySql.
     */
    public function testBuildDropPrimaryKeySql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildDropPrimaryKeySql('pk_test', 'test_table');

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('DROP CONSTRAINT', $sql);
        $this->assertStringContainsString('pk_test', $sql);
    }

    /**
     * Test buildAddUniqueSql.
     */
    public function testBuildAddUniqueSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildAddUniqueSql('uk_test', 'test_table', ['email']);

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('ADD CONSTRAINT', $sql);
        $this->assertStringContainsString('uk_test', $sql);
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
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('DROP CONSTRAINT', $sql);
        $this->assertStringContainsString('uk_test', $sql);
    }

    /**
     * Test buildAddCheckSql.
     */
    public function testBuildAddCheckSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildAddCheckSql('chk_test', 'test_table', 'age > 0');

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('ADD CONSTRAINT', $sql);
        $this->assertStringContainsString('chk_test', $sql);
        $this->assertStringContainsString('CHECK', $sql);
        $this->assertStringContainsString('age > 0', $sql);
    }

    /**
     * Test buildDropCheckSql.
     */
    public function testBuildDropCheckSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildDropCheckSql('chk_test', 'test_table');

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('DROP CONSTRAINT', $sql);
        $this->assertStringContainsString('chk_test', $sql);
    }
}
