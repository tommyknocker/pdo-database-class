<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mysql;

use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * MySQL-specific DDL Builder tests.
 *
 * These tests verify MySQL-specific DDL builder methods and SQL generation.
 */
final class MySQLDdlBuilderTests extends BaseMySQLTestCase
{
    /**
     * Get MySQL DDL builder instance.
     *
     * @return \tommyknocker\pdodb\dialects\mysql\MySQLDdlBuilder
     */
    protected function getDdlBuilder(): \tommyknocker\pdodb\dialects\mysql\MySQLDdlBuilder
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
     * Test buildCreateTableSql with MySQL-specific options (ENGINE, CHARSET, COLLATE).
     */
    public function testBuildCreateTableSqlWithEngineAndCharset(): void
    {
        $builder = $this->getDdlBuilder();
        $schema = self::$db->schema();

        $columns = [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100)->notNull(),
        ];

        $options = [
            'engine' => 'InnoDB',
            'charset' => 'utf8mb4',
            'collate' => 'utf8mb4_unicode_ci',
        ];

        $sql = $builder->buildCreateTableSql('test_table', $columns, $options);

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('ENGINE=InnoDB', $sql);
        $this->assertStringContainsString('DEFAULT CHARSET=utf8mb4', $sql);
        $this->assertStringContainsString('COLLATE=utf8mb4_unicode_ci', $sql);
    }

    /**
     * Test buildCreateTableSql with comment option.
     */
    public function testBuildCreateTableSqlWithComment(): void
    {
        $builder = $this->getDdlBuilder();
        $schema = self::$db->schema();

        $columns = [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100),
        ];

        $options = [
            'comment' => 'Test table comment',
        ];

        $sql = $builder->buildCreateTableSql('test_table', $columns, $options);

        $this->assertIsString($sql);
        $this->assertStringContainsString('COMMENT=', $sql);
        $this->assertStringContainsString('Test table comment', $sql);
    }

    /**
     * Test buildCreateFulltextIndexSql.
     */
    public function testBuildCreateFulltextIndexSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildCreateFulltextIndexSql('ft_idx', 'test_table', ['title', 'content']);

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE FULLTEXT INDEX', $sql);
        $this->assertStringContainsString('ft_idx', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('title', $sql);
        $this->assertStringContainsString('content', $sql);
    }

    /**
     * Test buildCreateFulltextIndexSql with parser.
     */
    public function testBuildCreateFulltextIndexSqlWithParser(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildCreateFulltextIndexSql('ft_idx', 'test_table', ['title'], 'ngram');

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE FULLTEXT INDEX', $sql);
        $this->assertStringContainsString('WITH PARSER ngram', $sql);
    }

    /**
     * Test buildCreateSpatialIndexSql.
     */
    public function testBuildCreateSpatialIndexSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildCreateSpatialIndexSql('spatial_idx', 'test_table', ['location']);

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE SPATIAL INDEX', $sql);
        $this->assertStringContainsString('spatial_idx', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('location', $sql);
    }

    /**
     * Test formatColumnDefinition with MySQL-specific types.
     */
    public function testFormatColumnDefinition(): void
    {
        $builder = $this->getDdlBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('formatColumnDefinition');
        $method->setAccessible(true);

        // Test BOOLEAN type (should be converted to TINYINT(1))
        $column = new ColumnSchema('BOOLEAN');
        $result = $method->invoke($builder, 'test_col', $column);
        $this->assertIsString($result);
        $this->assertStringContainsString('test_col', $result);
        // BOOLEAN may be converted to TINYINT(1) or kept as BOOLEAN depending on implementation
        $this->assertTrue(
            strpos($result, 'TINYINT') !== false || strpos($result, 'BOOLEAN') !== false
        );

        // Test TEXT type
        $column = new ColumnSchema('TEXT');
        $result = $method->invoke($builder, 'text_col', $column);
        $this->assertIsString($result);
        $this->assertStringContainsString('TEXT', $result);

        // Test with AUTO_INCREMENT
        $column = new ColumnSchema('INT');
        $column->autoIncrement(true);
        $result = $method->invoke($builder, 'id', $column);
        $this->assertStringContainsString('AUTO_INCREMENT', $result);
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
        $this->assertStringContainsString('MODIFY COLUMN', $sql);
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
        // MySQL 8.0.3+ uses RENAME COLUMN, older versions use CHANGE COLUMN
        $this->assertTrue(
            strpos($sql, 'RENAME COLUMN') !== false || strpos($sql, 'CHANGE COLUMN') !== false
        );
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
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('RENAME INDEX', $sql);
        $this->assertStringContainsString('old_idx', $sql);
        $this->assertStringContainsString('new_idx', $sql);
    }

    /**
     * Test buildRenameForeignKeySql.
     */
    public function testBuildRenameForeignKeySql(): void
    {
        $builder = $this->getDdlBuilder();

        // MySQL does not support renaming foreign keys directly
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MySQL does not support renaming foreign keys');

        $builder->buildRenameForeignKeySql('old_fk', 'test_table', 'new_fk');
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
        $this->assertStringContainsString('DROP FOREIGN KEY', $sql);
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

        // MySQL buildDropPrimaryKeySql takes name and table, but name is ignored
        $sql = $builder->buildDropPrimaryKeySql('pk_test', 'test_table');

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('test_table', $sql);
        $this->assertStringContainsString('DROP PRIMARY KEY', $sql);
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
        $this->assertStringContainsString('DROP INDEX', $sql);
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
        // MySQL uses DROP CHECK, not DROP CONSTRAINT
        $this->assertStringContainsString('DROP CHECK', $sql);
        $this->assertStringContainsString('chk_test', $sql);
    }
}
