<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\dialects\oracle\OracleDdlBuilder;
use tommyknocker\pdodb\query\schema\ColumnSchema;

/**
 * Tests for OracleDdlBuilder class.
 */
final class OracleDdlBuilderTests extends BaseOracleTestCase
{
    protected function createBuilder(): OracleDdlBuilder
    {
        return new OracleDdlBuilder(self::$db->connection->getDialect());
    }

    public function testBuildCreateTableSql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildCreateTableSql('test_table', [
            'id' => new ColumnSchema('NUMBER', 10),
            'name' => new ColumnSchema('VARCHAR2', 255),
        ]);

        $this->assertStringContainsString('CREATE TABLE', $sql);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('TEST_TABLE', $sql);
        $this->assertStringContainsString('ID', $sql);
        $this->assertStringContainsString('NAME', $sql);
    }

    public function testBuildCreateTableSqlWithPrimaryKey(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildCreateTableSql('test_table', [
            'id' => new ColumnSchema('NUMBER', 10),
            'name' => new ColumnSchema('VARCHAR2', 255),
        ], ['primaryKey' => ['id']]);

        $this->assertStringContainsString('PRIMARY KEY', $sql);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('ID', $sql);
    }

    public function testBuildCreateTableSqlWithAutoIncrement(): void
    {
        $builder = $this->createBuilder();
        $column = new ColumnSchema('NUMBER', 10);
        $column->autoIncrement();
        $sql = $builder->buildCreateTableSql('test_table', [
            'id' => $column,
        ]);

        $this->assertStringContainsString('CREATE SEQUENCE', $sql);
        $this->assertStringContainsString('PRIMARY KEY', $sql);
    }

    public function testBuildCreateTableSqlWithForeignKey(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildCreateTableSql('test_table', [
            'id' => new ColumnSchema('NUMBER', 10),
            'user_id' => new ColumnSchema('NUMBER', 10),
        ], [
            'foreignKeys' => [
                [
                    'columns' => 'user_id',
                    'refTable' => 'users',
                    'refColumns' => 'id',
                ],
            ],
        ]);

        $this->assertStringContainsString('FOREIGN KEY', $sql);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('USERS', $sql);
    }

    public function testBuildCreateTableSqlWithForeignKeyOnDelete(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildCreateTableSql('test_table', [
            'id' => new ColumnSchema('NUMBER', 10),
            'user_id' => new ColumnSchema('NUMBER', 10),
        ], [
            'foreignKeys' => [
                [
                    'columns' => 'user_id',
                    'refTable' => 'users',
                    'refColumns' => 'id',
                    'onDelete' => 'CASCADE',
                ],
            ],
        ]);

        $this->assertStringContainsString('ON DELETE CASCADE', $sql);
    }

    public function testBuildCreateTableSqlWithTablespace(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildCreateTableSql('test_table', [
            'id' => new ColumnSchema('NUMBER', 10),
        ], ['tablespace' => 'USERS']);

        $this->assertStringContainsString('TABLESPACE', $sql);
        $this->assertStringContainsString('USERS', $sql);
    }

    public function testBuildDropTableSql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildDropTableSql('test_table');
        $this->assertStringContainsString('DROP TABLE', $sql);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('TEST_TABLE', $sql);
    }

    public function testBuildDropTableIfExistsSql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildDropTableIfExistsSql('test_table');
        $this->assertStringContainsString('DROP TABLE', $sql);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('TEST_TABLE', $sql);
    }

    public function testBuildAddColumnSql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildAddColumnSql('test_table', 'new_column', new ColumnSchema('VARCHAR2', 255));
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('ADD', $sql);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('NEW_COLUMN', $sql);
    }

    public function testBuildDropColumnSql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildDropColumnSql('test_table', 'old_column');
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('DROP COLUMN', $sql);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('OLD_COLUMN', $sql);
    }

    public function testBuildAlterColumnSql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildAlterColumnSql('test_table', 'column_name', new ColumnSchema('VARCHAR2', 500));
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('MODIFY', $sql);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('COLUMN_NAME', $sql);
    }

    public function testBuildRenameColumnSql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildRenameColumnSql('test_table', 'old_name', 'new_name');
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('RENAME COLUMN', $sql);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('OLD_NAME', $sql);
        $this->assertStringContainsString('NEW_NAME', $sql);
    }

    public function testBuildCreateIndexSql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildCreateIndexSql('idx_test', 'test_table', ['column1', 'column2']);
        $this->assertStringContainsString('CREATE INDEX', $sql);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('IDX_TEST', $sql);
        $this->assertStringContainsString('TEST_TABLE', $sql);
    }

    public function testBuildCreateIndexSqlWithUnique(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildCreateIndexSql('idx_test', 'test_table', ['column1'], true);
        $this->assertStringContainsString('CREATE UNIQUE INDEX', $sql);
        // Oracle converts identifiers to uppercase
    }

    public function testBuildDropIndexSql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildDropIndexSql('idx_test', 'test_table');
        $this->assertStringContainsString('DROP INDEX', $sql);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('IDX_TEST', $sql);
    }

    public function testBuildCreateFulltextIndexSql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildCreateFulltextIndexSql('idx_ft', 'test_table', ['content']);
        // Oracle doesn't support fulltext indexes like MySQL, should return empty or throw
        $this->assertIsString($sql);
    }

    public function testBuildCreateSpatialIndexSql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildCreateSpatialIndexSql('idx_spatial', 'test_table', ['location']);
        // Oracle doesn't support spatial indexes like MySQL, should return empty or throw
        $this->assertIsString($sql);
    }

    public function testBuildRenameIndexSql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildRenameIndexSql('old_idx', 'test_table', 'new_idx');
        $this->assertStringContainsString('ALTER INDEX', $sql);
        $this->assertStringContainsString('RENAME', $sql);
        // Oracle converts identifiers to uppercase
    }

    public function testBuildRenameForeignKeySql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildRenameForeignKeySql('old_fk', 'test_table', 'new_fk');
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('RENAME CONSTRAINT', $sql);
        // Oracle converts identifiers to uppercase
    }

    public function testBuildAddForeignKeySql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildAddForeignKeySql('fk_test', 'test_table', ['user_id'], 'users', ['id']);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('ADD CONSTRAINT', $sql);
        $this->assertStringContainsString('FOREIGN KEY', $sql);
        // Oracle converts identifiers to uppercase
    }

    public function testBuildAddForeignKeySqlWithOnDelete(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildAddForeignKeySql('fk_test', 'test_table', ['user_id'], 'users', ['id'], 'CASCADE');
        $this->assertStringContainsString('ON DELETE CASCADE', $sql);
    }

    public function testBuildDropForeignKeySql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildDropForeignKeySql('fk_test', 'test_table');
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('DROP CONSTRAINT', $sql);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('FK_TEST', $sql);
    }

    public function testBuildAddPrimaryKeySql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildAddPrimaryKeySql('pk_test', 'test_table', ['id']);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('ADD CONSTRAINT', $sql);
        $this->assertStringContainsString('PRIMARY KEY', $sql);
        // Oracle converts identifiers to uppercase
    }

    public function testBuildDropPrimaryKeySql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildDropPrimaryKeySql('pk_test', 'test_table');
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('DROP CONSTRAINT', $sql);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('PK_TEST', $sql);
    }

    public function testBuildAddUniqueSql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildAddUniqueSql('uk_test', 'test_table', ['email']);
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('ADD CONSTRAINT', $sql);
        $this->assertStringContainsString('UNIQUE', $sql);
        // Oracle converts identifiers to uppercase
    }

    public function testBuildDropUniqueSql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildDropUniqueSql('uk_test', 'test_table');
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('DROP CONSTRAINT', $sql);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('UK_TEST', $sql);
    }

    public function testBuildAddCheckSql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildAddCheckSql('ck_test', 'test_table', 'age > 0');
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('ADD CONSTRAINT', $sql);
        $this->assertStringContainsString('CHECK', $sql);
        // Oracle converts identifiers to uppercase
    }

    public function testBuildDropCheckSql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildDropCheckSql('ck_test', 'test_table');
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('DROP CONSTRAINT', $sql);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('CK_TEST', $sql);
    }

    public function testBuildRenameTableSql(): void
    {
        $builder = $this->createBuilder();
        $sql = $builder->buildRenameTableSql('old_table', 'new_table');
        $this->assertStringContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('RENAME TO', $sql);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('NEW_TABLE', $sql);
    }

    public function testFormatColumnDefinition(): void
    {
        $builder = $this->createBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('formatColumnDefinition');
        $method->setAccessible(true);

        $column = new ColumnSchema('VARCHAR2', 255);
        $result = $method->invoke($builder, 'test_column', $column);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('TEST_COLUMN', $result);
        $this->assertStringContainsString('VARCHAR2', $result);
    }

    public function testFormatColumnDefinitionWithNotNull(): void
    {
        $builder = $this->createBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('formatColumnDefinition');
        $method->setAccessible(true);

        $column = new ColumnSchema('VARCHAR2', 255);
        $column->notNull();
        $result = $method->invoke($builder, 'test_column', $column);
        $this->assertStringContainsString('NOT NULL', $result);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('TEST_COLUMN', $result);
    }

    public function testFormatColumnDefinitionWithDefaultValue(): void
    {
        $builder = $this->createBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('formatColumnDefinition');
        $method->setAccessible(true);

        $column = new ColumnSchema('VARCHAR2', 255);
        $column->defaultValue('default');
        $result = $method->invoke($builder, 'test_column', $column);
        $this->assertStringContainsString('DEFAULT', $result);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('TEST_COLUMN', $result);
    }

    public function testFormatColumnDefinitionWithDefaultExpression(): void
    {
        $builder = $this->createBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('formatColumnDefinition');
        $method->setAccessible(true);

        $column = new ColumnSchema('TIMESTAMP');
        $column->defaultExpression('CURRENT_TIMESTAMP');
        $result = $method->invoke($builder, 'created_at', $column);
        $this->assertStringContainsString('DEFAULT', $result);
        // Oracle uses SYSTIMESTAMP instead of CURRENT_TIMESTAMP
        $this->assertStringContainsString('SYSTIMESTAMP', $result);
        // Oracle converts identifiers to uppercase
        $this->assertStringContainsString('CREATED_AT', $result);
    }

    public function testGenerateSequenceName(): void
    {
        $builder = $this->createBuilder();
        $reflection = new \ReflectionClass($builder);
        $method = $reflection->getMethod('generateSequenceName');
        $method->setAccessible(true);

        $result = $method->invoke($builder, 'users', 'id');
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }
}
