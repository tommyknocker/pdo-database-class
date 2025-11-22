<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * MSSQL-specific DDL Builder tests.
 *
 * These tests verify MSSQL-specific DDL builder methods and SQL generation.
 */
final class MSSQLDdlBuilderTests extends BaseMSSQLTestCase
{
    /**
     * Get MSSQL DDL builder instance.
     *
     * @return \tommyknocker\pdodb\dialects\mssql\MSSQLDdlBuilder
     */
    protected function getDdlBuilder(): \tommyknocker\pdodb\dialects\mssql\MSSQLDdlBuilder
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
     * Test buildDropColumnSql generates SQL with default constraint removal.
     */
    public function testBuildDropColumnSqlWithDefaultConstraints(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildDropColumnSql('[dbo].[test_table]', 'test_column');

        $this->assertIsString($sql);
        $this->assertStringContainsString('DECLARE @constraintName', $sql);
        $this->assertStringContainsString('sys.default_constraints', $sql);
        $this->assertStringContainsString('OBJECT_ID', $sql);
        $this->assertStringContainsString('COLUMNPROPERTY', $sql);
        $this->assertStringContainsString('DROP CONSTRAINT', $sql);
        $this->assertStringContainsString('DROP COLUMN', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
        $this->assertStringContainsString('[test_column]', $sql);
    }

    /**
     * Test buildRenameColumnSql uses sp_rename.
     */
    public function testBuildRenameColumnSqlUsesSpRename(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildRenameColumnSql('[dbo].[test_table]', 'old_column', 'new_column');

        $this->assertIsString($sql);
        $this->assertStringContainsString('EXEC sp_rename', $sql);
        $this->assertStringContainsString("'COLUMN'", $sql);
        $this->assertStringContainsString('old_column', $sql);
        $this->assertStringContainsString('new_column', $sql);
        // sp_rename requires unquoted names
        $this->assertStringNotContainsString('[old_column]', $sql);
        $this->assertStringNotContainsString('[new_column]', $sql);
    }

    /**
     * Test buildRenameIndexSql uses sp_rename.
     */
    public function testBuildRenameIndexSqlUsesSpRename(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildRenameIndexSql('old_idx', '[dbo].[test_table]', 'new_idx');

        $this->assertIsString($sql);
        $this->assertStringContainsString('EXEC sp_rename', $sql);
        $this->assertStringContainsString("'INDEX'", $sql);
        $this->assertStringContainsString('old_idx', $sql);
        $this->assertStringContainsString('new_idx', $sql);
        // sp_rename requires unquoted names
        $this->assertStringNotContainsString('[old_idx]', $sql);
        $this->assertStringNotContainsString('[new_idx]', $sql);
    }

    /**
     * Test buildRenameForeignKeySql uses sp_rename.
     */
    public function testBuildRenameForeignKeySqlUsesSpRename(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildRenameForeignKeySql('old_fk', '[dbo].[test_table]', 'new_fk');

        $this->assertIsString($sql);
        $this->assertStringContainsString('EXEC sp_rename', $sql);
        $this->assertStringContainsString("'OBJECT'", $sql);
        $this->assertStringContainsString('old_fk', $sql);
        $this->assertStringContainsString('new_fk', $sql);
        // sp_rename requires unquoted names
        $this->assertStringNotContainsString('[old_fk]', $sql);
        $this->assertStringNotContainsString('[new_fk]', $sql);
    }

    /**
     * Test buildRenameTableSql uses sp_rename.
     */
    public function testBuildRenameTableSqlUsesSpRename(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildRenameTableSql('[dbo].[old_table]', 'new_table');

        $this->assertIsString($sql);
        $this->assertStringContainsString('EXEC sp_rename', $sql);
        $this->assertStringContainsString('old_table', $sql);
        $this->assertStringContainsString('new_table', $sql);
        // sp_rename doesn't need brackets in new name
        $this->assertStringNotContainsString('[new_table]', $sql);
    }

    /**
     * Test buildCreateTableSql with ON clause (filegroup).
     */
    public function testBuildCreateTableSqlWithOnClause(): void
    {
        $builder = $this->getDdlBuilder();
        $schema = self::$db->schema();

        $columns = [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100)->notNull(),
        ];

        $options = ['on' => '[PRIMARY]'];
        $sql = $builder->buildCreateTableSql('[dbo].[test_table]', $columns, $options);

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
        $this->assertStringContainsString('ON [PRIMARY]', $sql);
    }

    /**
     * Test formatColumnDefinition converts TEXT to NVARCHAR(MAX).
     */
    public function testFormatColumnDefinitionTextToNvarcharMax(): void
    {
        $builder = $this->getDdlBuilder();
        $schema = self::$db->schema();

        $columnSchema = $schema->text();
        $sql = $builder->formatColumnDefinition('test_column', $columnSchema);

        $this->assertIsString($sql);
        $this->assertStringContainsString('test_column', $sql);
        $this->assertStringContainsString('NVARCHAR(MAX)', $sql);
        $this->assertStringNotContainsString('TEXT', $sql);
    }

    /**
     * Test formatColumnDefinition converts BOOLEAN to BIT.
     */
    public function testFormatColumnDefinitionBooleanToBit(): void
    {
        $builder = $this->getDdlBuilder();

        // parseColumnDefinition converts BOOLEAN to BIT
        $reflection = new \ReflectionClass($builder);
        $parseMethod = $reflection->getMethod('parseColumnDefinition');
        $parseMethod->setAccessible(true);

        $columnSchema = $parseMethod->invoke($builder, ['type' => 'BOOLEAN']);
        $sql = $builder->formatColumnDefinition('test_column', $columnSchema);

        $this->assertIsString($sql);
        $this->assertStringContainsString('test_column', $sql);
        $this->assertStringContainsString('BIT', $sql);
        $this->assertStringNotContainsString('BOOLEAN', $sql);
    }

    /**
     * Test formatColumnDefinition uses NVARCHAR for string types.
     */
    public function testFormatColumnDefinitionUsesNvarchar(): void
    {
        $builder = $this->getDdlBuilder();
        $schema = self::$db->schema();

        $columnSchema = $schema->string(100);
        $sql = $builder->formatColumnDefinition('test_column', $columnSchema);

        $this->assertIsString($sql);
        $this->assertStringContainsString('test_column', $sql);
        $this->assertStringContainsString('NVARCHAR', $sql);
        $this->assertStringContainsString('100', $sql);
    }

    /**
     * Test formatColumnDefinition handles NVARCHAR without length as NVARCHAR(MAX).
     */
    public function testFormatColumnDefinitionNvarcharWithoutLength(): void
    {
        $builder = $this->getDdlBuilder();

        // Create column schema with NVARCHAR without length
        $columnSchema = new ColumnSchema('NVARCHAR');
        $sql = $builder->formatColumnDefinition('test_column', $columnSchema);

        $this->assertIsString($sql);
        $this->assertStringContainsString('test_column', $sql);
        $this->assertStringContainsString('NVARCHAR(MAX)', $sql);
    }

    /**
     * Test formatColumnDefinition handles VARCHAR without length as VARCHAR(MAX).
     */
    public function testFormatColumnDefinitionVarcharWithoutLength(): void
    {
        $builder = $this->getDdlBuilder();

        // Create column schema with VARCHAR without length
        $columnSchema = new ColumnSchema('VARCHAR');
        $sql = $builder->formatColumnDefinition('test_column', $columnSchema);

        $this->assertIsString($sql);
        $this->assertStringContainsString('test_column', $sql);
        $this->assertStringContainsString('VARCHAR(MAX)', $sql);
    }

    /**
     * Test buildCreateIndexSql with INCLUDE columns.
     */
    public function testBuildCreateIndexSqlWithInclude(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildCreateIndexSql(
            'idx_test',
            '[dbo].[test_table]',
            ['name'],
            false,
            null,
            ['price', 'stock']
        );

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE INDEX', $sql);
        $this->assertStringContainsString('[idx_test]', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
        $this->assertStringContainsString('INCLUDE', $sql);
        $this->assertStringContainsString('[price]', $sql);
        $this->assertStringContainsString('[stock]', $sql);
    }

    /**
     * Test buildCreateIndexSql with WHERE clause (filtered index).
     */
    public function testBuildCreateIndexSqlWithWhereClause(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildCreateIndexSql(
            'idx_test',
            '[dbo].[test_table]',
            ['status'],
            false,
            'status = 1'
        );

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE INDEX', $sql);
        $this->assertStringContainsString('[idx_test]', $sql);
        $this->assertStringContainsString('WHERE', $sql);
        $this->assertStringContainsString('status = 1', $sql);
    }

    /**
     * Test buildCreateIndexSql with both WHERE and INCLUDE.
     */
    public function testBuildCreateIndexSqlWithWhereAndInclude(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildCreateIndexSql(
            'idx_test',
            '[dbo].[test_table]',
            ['status'],
            false,
            'status = 1',
            ['name', 'value']
        );

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE INDEX', $sql);
        $this->assertStringContainsString('WHERE', $sql);
        $this->assertStringContainsString('status = 1', $sql);
        $this->assertStringContainsString('INCLUDE', $sql);
        $this->assertStringContainsString('[name]', $sql);
        $this->assertStringContainsString('[value]', $sql);
    }

    /**
     * Test buildTableExistsSql with schema-qualified table name.
     */
    public function testBuildTableExistsSqlWithSchema(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildTableExistsSql('dbo.test_table');

        $this->assertIsString($sql);
        $this->assertStringContainsString('INFORMATION_SCHEMA.TABLES', $sql);
        $this->assertStringContainsString('TABLE_SCHEMA', $sql);
        $this->assertStringContainsString('TABLE_NAME', $sql);
        $this->assertStringContainsString('dbo', $sql);
        $this->assertStringContainsString('test_table', $sql);
    }

    /**
     * Test buildTableExistsSql without schema.
     */
    public function testBuildTableExistsSqlWithoutSchema(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildTableExistsSql('test_table');

        $this->assertIsString($sql);
        $this->assertStringContainsString('INFORMATION_SCHEMA.TABLES', $sql);
        $this->assertStringContainsString('TABLE_NAME', $sql);
        $this->assertStringContainsString('test_table', $sql);
        // Should not check TABLE_SCHEMA when schema is not provided
        $this->assertStringNotContainsString('TABLE_SCHEMA', $sql);
    }

    /**
     * Test buildAlterColumnSql uses ALTER COLUMN syntax.
     */
    public function testBuildAlterColumnSqlUsesAlterColumn(): void
    {
        $builder = $this->getDdlBuilder();
        $schema = self::$db->schema();

        $columnSchema = $schema->string(200)->notNull();
        $sql = $builder->buildAlterColumnSql('[dbo].[test_table]', 'test_column', $columnSchema);

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
        $this->assertStringContainsString('ALTER COLUMN', $sql);
        $this->assertStringContainsString('[test_column]', $sql);
    }
}
