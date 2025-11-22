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

    /**
     * Test buildDescribeSql generates INFORMATION_SCHEMA query.
     */
    public function testBuildDescribeSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildDescribeSql('[dbo].[test_table]');

        $this->assertIsString($sql);
        $this->assertStringContainsString('INFORMATION_SCHEMA.COLUMNS', $sql);
        $this->assertStringContainsString('TABLE_NAME', $sql);
    }

    /**
     * Test buildShowIndexesSql generates sys.indexes query.
     */
    public function testBuildShowIndexesSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildShowIndexesSql('[dbo].[test_table]');

        $this->assertIsString($sql);
        $this->assertStringContainsString('sys.indexes', $sql);
        $this->assertStringContainsString('OBJECT_SCHEMA_NAME', $sql);
        $this->assertStringContainsString('OBJECT_NAME', $sql);
    }

    /**
     * Test buildShowForeignKeysSql generates sys.foreign_keys query.
     */
    public function testBuildShowForeignKeysSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildShowForeignKeysSql('[dbo].[test_table]');

        $this->assertIsString($sql);
        $this->assertStringContainsString('sys.foreign_keys', $sql);
        $this->assertStringContainsString('OBJECT_SCHEMA_NAME', $sql);
        $this->assertStringContainsString('OBJECT_NAME', $sql);
    }

    /**
     * Test buildShowConstraintsSql generates INFORMATION_SCHEMA query.
     */
    public function testBuildShowConstraintsSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildShowConstraintsSql('[dbo].[test_table]');

        $this->assertIsString($sql);
        $this->assertStringContainsString('INFORMATION_SCHEMA.TABLE_CONSTRAINTS', $sql);
        $this->assertStringContainsString('CONSTRAINT_NAME', $sql);
    }

    /**
     * Test buildLockSql throws exception.
     */
    public function testBuildLockSql(): void
    {
        $builder = $this->getDdlBuilder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Table locking is not supported by MSSQL');
        $builder->buildLockSql(['[dbo].[test_table]'], 'WITH (', 'TABLOCKX');
    }

    /**
     * Test buildUnlockSql throws exception.
     */
    public function testBuildUnlockSql(): void
    {
        $builder = $this->getDdlBuilder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Table unlocking is not supported by MSSQL');
        $builder->buildUnlockSql();
    }

    /**
     * Test buildTruncateSql generates TRUNCATE TABLE with schema.
     */
    public function testBuildTruncateSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildTruncateSql('[dbo].[test_table]');

        $this->assertIsString($sql);
        $this->assertStringContainsString('TRUNCATE TABLE', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
    }

    /**
     * Test buildDropTableSql generates DROP TABLE.
     */
    public function testBuildDropTableSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildDropTableSql('[dbo].[test_table]');

        $this->assertIsString($sql);
        $this->assertStringContainsString('DROP TABLE', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
    }

    /**
     * Test buildDropTableIfExistsSql generates IF EXISTS check.
     */
    public function testBuildDropTableIfExistsSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildDropTableIfExistsSql('[dbo].[test_table]');

        $this->assertIsString($sql);
        $this->assertStringContainsString('DROP TABLE IF EXISTS', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
    }

    /**
     * Test buildAddColumnSql generates ALTER TABLE ADD COLUMN.
     */
    public function testBuildAddColumnSql(): void
    {
        $builder = $this->getDdlBuilder();
        $schema = self::$db->schema();

        $columnSchema = $schema->string(100)->notNull();
        $sql = $builder->buildAddColumnSql('[dbo].[test_table]', 'new_column', $columnSchema);

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
        $this->assertStringContainsString('ADD', $sql);
        $this->assertStringContainsString('[new_column]', $sql);
    }

    /**
     * Test buildCreateIndexSql generates CREATE INDEX.
     */
    public function testBuildCreateIndexSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildCreateIndexSql(
            'idx_test',
            '[dbo].[test_table]',
            ['name', 'email'],
            false
        );

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE INDEX', $sql);
        $this->assertStringContainsString('[idx_test]', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
        $this->assertStringContainsString('[name]', $sql);
        $this->assertStringContainsString('[email]', $sql);
    }

    /**
     * Test buildCreateIndexSql with unique index.
     */
    public function testBuildCreateIndexSqlWithUnique(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildCreateIndexSql(
            'idx_unique_test',
            '[dbo].[test_table]',
            ['email'],
            true
        );

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE UNIQUE INDEX', $sql);
        $this->assertStringContainsString('[idx_unique_test]', $sql);
    }

    /**
     * Test buildDropIndexSql generates DROP INDEX.
     */
    public function testBuildDropIndexSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildDropIndexSql('idx_test', '[dbo].[test_table]');

        $this->assertIsString($sql);
        $this->assertStringContainsString('DROP INDEX', $sql);
        $this->assertStringContainsString('[idx_test]', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
    }

    /**
     * Test buildCreateFulltextIndexSql throws RuntimeException.
     */
    public function testBuildCreateFulltextIndexSql(): void
    {
        $builder = $this->getDdlBuilder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MSSQL fulltext indexes require FULLTEXT CATALOG setup');

        $builder->buildCreateFulltextIndexSql(
            'ft_idx_test',
            '[dbo].[test_table]',
            ['content'],
            null
        );
    }

    /**
     * Test buildCreateFulltextIndexSql with parser throws exception.
     */
    public function testBuildCreateFulltextIndexSqlWithParser(): void
    {
        $builder = $this->getDdlBuilder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MSSQL fulltext indexes require FULLTEXT CATALOG setup');
        $builder->buildCreateFulltextIndexSql(
            'ft_idx_test',
            '[dbo].[test_table]',
            ['content'],
            'WORD'
        );
    }

    /**
     * Test buildCreateSpatialIndexSql generates CREATE SPATIAL INDEX.
     */
    public function testBuildCreateSpatialIndexSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildCreateSpatialIndexSql(
            'sp_idx_test',
            '[dbo].[test_table]',
            ['location']
        );

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE SPATIAL INDEX', $sql);
        $this->assertStringContainsString('[sp_idx_test]', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
    }

    /**
     * Test buildAddForeignKeySql generates ALTER TABLE ADD CONSTRAINT.
     */
    public function testBuildAddForeignKeySql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildAddForeignKeySql(
            'fk_test',
            '[dbo].[test_table]',
            ['user_id'],
            '[dbo].[users]',
            ['id']
        );

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
        $this->assertStringContainsString('ADD CONSTRAINT', $sql);
        $this->assertStringContainsString('[fk_test]', $sql);
        $this->assertStringContainsString('FOREIGN KEY', $sql);
        $this->assertStringContainsString('[user_id]', $sql);
        $this->assertStringContainsString('REFERENCES', $sql);
        $this->assertStringContainsString('[users]', $sql);
        $this->assertStringContainsString('[id]', $sql);
    }

    /**
     * Test buildAddForeignKeySql with ON DELETE and ON UPDATE actions.
     */
    public function testBuildAddForeignKeySqlWithActions(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildAddForeignKeySql(
            'fk_test',
            '[dbo].[test_table]',
            ['user_id'],
            '[dbo].[users]',
            ['id'],
            'CASCADE',
            'SET NULL'
        );

        $this->assertIsString($sql);
        $this->assertStringContainsString('ON DELETE CASCADE', $sql);
        $this->assertStringContainsString('ON UPDATE SET NULL', $sql);
    }

    /**
     * Test buildDropForeignKeySql generates ALTER TABLE DROP CONSTRAINT.
     */
    public function testBuildDropForeignKeySql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildDropForeignKeySql('fk_test', '[dbo].[test_table]');

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
        $this->assertStringContainsString('DROP CONSTRAINT', $sql);
        $this->assertStringContainsString('[fk_test]', $sql);
    }

    /**
     * Test buildAddPrimaryKeySql generates ALTER TABLE ADD CONSTRAINT PRIMARY KEY.
     */
    public function testBuildAddPrimaryKeySql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildAddPrimaryKeySql('pk_test', '[dbo].[test_table]', ['id', 'version']);

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
        $this->assertStringContainsString('ADD CONSTRAINT', $sql);
        $this->assertStringContainsString('[pk_test]', $sql);
        $this->assertStringContainsString('PRIMARY KEY', $sql);
        $this->assertStringContainsString('[id]', $sql);
        $this->assertStringContainsString('[version]', $sql);
    }

    /**
     * Test buildDropPrimaryKeySql generates ALTER TABLE DROP CONSTRAINT.
     */
    public function testBuildDropPrimaryKeySql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildDropPrimaryKeySql('pk_test', '[dbo].[test_table]');

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
        $this->assertStringContainsString('DROP CONSTRAINT', $sql);
        $this->assertStringContainsString('[pk_test]', $sql);
    }

    /**
     * Test buildAddUniqueSql generates ALTER TABLE ADD CONSTRAINT UNIQUE.
     */
    public function testBuildAddUniqueSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildAddUniqueSql('uk_test', '[dbo].[test_table]', ['email']);

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
        $this->assertStringContainsString('ADD CONSTRAINT', $sql);
        $this->assertStringContainsString('[uk_test]', $sql);
        $this->assertStringContainsString('UNIQUE', $sql);
        $this->assertStringContainsString('[email]', $sql);
    }

    /**
     * Test buildDropUniqueSql generates ALTER TABLE DROP CONSTRAINT.
     */
    public function testBuildDropUniqueSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildDropUniqueSql('uk_test', '[dbo].[test_table]');

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
        $this->assertStringContainsString('DROP CONSTRAINT', $sql);
        $this->assertStringContainsString('[uk_test]', $sql);
    }

    /**
     * Test buildAddCheckSql generates ALTER TABLE ADD CONSTRAINT CHECK.
     */
    public function testBuildAddCheckSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildAddCheckSql('chk_test', '[dbo].[test_table]', 'age > 0');

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
        $this->assertStringContainsString('ADD CONSTRAINT', $sql);
        $this->assertStringContainsString('[chk_test]', $sql);
        $this->assertStringContainsString('CHECK', $sql);
        $this->assertStringContainsString('age > 0', $sql);
    }

    /**
     * Test buildDropCheckSql generates ALTER TABLE DROP CONSTRAINT.
     */
    public function testBuildDropCheckSql(): void
    {
        $builder = $this->getDdlBuilder();

        $sql = $builder->buildDropCheckSql('chk_test', '[dbo].[test_table]');

        $this->assertIsString($sql);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
        $this->assertStringContainsString('DROP CONSTRAINT', $sql);
        $this->assertStringContainsString('[chk_test]', $sql);
    }

    /**
     * Test formatColumnDefinition with IDENTITY.
     */
    public function testFormatColumnDefinitionWithIdentity(): void
    {
        $builder = $this->getDdlBuilder();
        $schema = self::$db->schema();

        $columnSchema = $schema->primaryKey();
        $sql = $builder->formatColumnDefinition('id', $columnSchema);

        $this->assertIsString($sql);
        $this->assertStringContainsString('[id]', $sql);
        // Should contain IDENTITY for primary key
        $this->assertStringContainsString('IDENTITY', $sql);
    }

    /**
     * Test formatColumnDefinition with default value.
     */
    public function testFormatColumnDefinitionWithDefault(): void
    {
        $builder = $this->getDdlBuilder();
        $schema = self::$db->schema();

        $columnSchema = $schema->string(100)->defaultValue('test');
        $sql = $builder->formatColumnDefinition('name', $columnSchema);

        $this->assertIsString($sql);
        $this->assertStringContainsString('[name]', $sql);
        $this->assertStringContainsString('DEFAULT', $sql);
        $this->assertStringContainsString("'test'", $sql);
    }

    /**
     * Test formatColumnDefinition with NOT NULL.
     */
    public function testFormatColumnDefinitionWithNotNull(): void
    {
        $builder = $this->getDdlBuilder();
        $schema = self::$db->schema();

        $columnSchema = $schema->string(100)->notNull();
        $sql = $builder->formatColumnDefinition('name', $columnSchema);

        $this->assertIsString($sql);
        $this->assertStringContainsString('[name]', $sql);
        $this->assertStringContainsString('NOT NULL', $sql);
    }

    /**
     * Test formatColumnDefinition with NULL.
     */
    public function testFormatColumnDefinitionWithNull(): void
    {
        $builder = $this->getDdlBuilder();
        $schema = self::$db->schema();

        $columnSchema = $schema->string(100)->null();
        $sql = $builder->formatColumnDefinition('name', $columnSchema);

        $this->assertIsString($sql);
        $this->assertStringContainsString('[name]', $sql);
        $this->assertStringContainsString('NULL', $sql);
    }

    /**
     * Test formatDefaultValue with string.
     */
    public function testFormatDefaultValueWithString(): void
    {
        $reflection = new \ReflectionClass($this->getDdlBuilder());
        $method = $reflection->getMethod('formatDefaultValue');
        $method->setAccessible(true);

        $builder = $this->getDdlBuilder();
        $result = $method->invoke($builder, 'test value');

        $this->assertIsString($result);
        $this->assertStringStartsWith("'", $result);
        $this->assertStringEndsWith("'", $result);
        $this->assertStringContainsString('test value', $result);
    }

    /**
     * Test formatDefaultValue with integer.
     */
    public function testFormatDefaultValueWithInteger(): void
    {
        $reflection = new \ReflectionClass($this->getDdlBuilder());
        $method = $reflection->getMethod('formatDefaultValue');
        $method->setAccessible(true);

        $builder = $this->getDdlBuilder();
        $result = $method->invoke($builder, 123);

        $this->assertIsString($result);
        $this->assertEquals('123', $result);
    }

    /**
     * Test formatDefaultValue with boolean true.
     */
    public function testFormatDefaultValueWithBooleanTrue(): void
    {
        $reflection = new \ReflectionClass($this->getDdlBuilder());
        $method = $reflection->getMethod('formatDefaultValue');
        $method->setAccessible(true);

        $builder = $this->getDdlBuilder();
        $result = $method->invoke($builder, true);

        $this->assertIsString($result);
        $this->assertEquals('1', $result);
    }

    /**
     * Test formatDefaultValue with boolean false.
     */
    public function testFormatDefaultValueWithBooleanFalse(): void
    {
        $reflection = new \ReflectionClass($this->getDdlBuilder());
        $method = $reflection->getMethod('formatDefaultValue');
        $method->setAccessible(true);

        $builder = $this->getDdlBuilder();
        $result = $method->invoke($builder, false);

        $this->assertIsString($result);
        $this->assertEquals('0', $result);
    }

    /**
     * Test formatDefaultValue with null.
     */
    public function testFormatDefaultValueWithNull(): void
    {
        $reflection = new \ReflectionClass($this->getDdlBuilder());
        $method = $reflection->getMethod('formatDefaultValue');
        $method->setAccessible(true);

        $builder = $this->getDdlBuilder();
        $result = $method->invoke($builder, null);

        $this->assertIsString($result);
        $this->assertEquals('NULL', $result);
    }

    /**
     * Test formatDefaultValue with RawValue.
     */
    public function testFormatDefaultValueWithRawValue(): void
    {
        $reflection = new \ReflectionClass($this->getDdlBuilder());
        $method = $reflection->getMethod('formatDefaultValue');
        $method->setAccessible(true);

        $builder = $this->getDdlBuilder();
        $rawValue = new \tommyknocker\pdodb\helpers\values\RawValue('GETDATE()');
        $result = $method->invoke($builder, $rawValue);

        $this->assertIsString($result);
        $this->assertEquals('GETDATE()', $result);
    }

    /**
     * Test buildCreateTableSql with multiple columns.
     */
    public function testBuildCreateTableSqlWithMultipleColumns(): void
    {
        $builder = $this->getDdlBuilder();
        $schema = self::$db->schema();

        $columns = [
            'id' => $schema->primaryKey(),
            'name' => $schema->string(100)->notNull(),
            'email' => $schema->string(255)->null(),
            'age' => $schema->integer()->defaultValue(0),
        ];

        $sql = $builder->buildCreateTableSql('[dbo].[test_table]', $columns);

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('[test_table]', $sql);
        $this->assertStringContainsString('[id]', $sql);
        $this->assertStringContainsString('[name]', $sql);
        $this->assertStringContainsString('[email]', $sql);
        $this->assertStringContainsString('[age]', $sql);
    }

    /**
     * Test buildCreateTableSql with primary key constraint.
     */
    public function testBuildCreateTableSqlWithPrimaryKey(): void
    {
        $builder = $this->getDdlBuilder();
        $schema = self::$db->schema();

        $columns = [
            'id' => $schema->integer()->notNull(),
            'name' => $schema->string(100),
        ];

        $options = ['primaryKey' => ['id']];
        $sql = $builder->buildCreateTableSql('[dbo].[test_table]', $columns, $options);

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('PRIMARY KEY', $sql);
        $this->assertStringContainsString('[id]', $sql);
    }
}
