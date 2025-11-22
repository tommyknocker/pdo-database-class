<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use tommyknocker\pdodb\dialects\postgresql\PostgreSQLDmlBuilder;
use tommyknocker\pdodb\helpers\values\RawValue;

/**
 * Tests for PostgreSQLDmlBuilder class.
 */
final class PostgreSQLDmlBuilderTests extends BasePostgreSQLTestCase
{
    private PostgreSQLDmlBuilder $builder;

    public function setUp(): void
    {
        parent::setUp();
        $dialect = self::$db->connection->getDialect();
        $this->builder = new PostgreSQLDmlBuilder($dialect);
    }

    public function testBuildInsertSqlBasic(): void
    {
        $sql = $this->builder->buildInsertSql('users', ['name', 'email'], ['?', '?']);
        $this->assertStringContainsString('INSERT INTO users', $sql);
        $this->assertStringContainsString('"name"', $sql);
        $this->assertStringContainsString('"email"', $sql);
    }

    public function testBuildInsertSqlWithReturning(): void
    {
        $sql = $this->builder->buildInsertSql('users', ['name'], ['?'], ['RETURNING id']);
        $this->assertStringContainsString('RETURNING id', $sql);
    }

    public function testBuildInsertSqlWithOnly(): void
    {
        $sql = $this->builder->buildInsertSql('users', ['name'], ['?'], ['ONLY']);
        $this->assertStringContainsString('INSERT INTO ONLY users', $sql);
    }

    public function testBuildInsertSqlWithOverriding(): void
    {
        $sql = $this->builder->buildInsertSql('users', ['name'], ['?'], ['OVERRIDING SYSTEM VALUE']);
        $this->assertStringContainsString('OVERRIDING SYSTEM VALUE', $sql);
        $this->assertStringContainsString('VALUES', $sql);
    }

    public function testBuildInsertSqlWithOnConflict(): void
    {
        $sql = $this->builder->buildInsertSql('users', ['name'], ['?'], ['ON CONFLICT (id) DO NOTHING']);
        $this->assertStringContainsString('ON CONFLICT', $sql);
        $this->assertStringContainsString('DO NOTHING', $sql);
    }

    public function testBuildInsertSelectSqlBasic(): void
    {
        $sql = $this->builder->buildInsertSelectSql('users', ['name', 'email'], 'SELECT name, email FROM old_users');
        $this->assertStringContainsString('INSERT INTO users', $sql);
        $this->assertStringContainsString('SELECT name, email FROM old_users', $sql);
    }

    public function testBuildInsertSelectSqlWithReturning(): void
    {
        $sql = $this->builder->buildInsertSelectSql('users', ['name'], 'SELECT name FROM old_users', ['RETURNING id']);
        $this->assertStringContainsString('RETURNING id', $sql);
    }

    public function testBuildInsertSelectSqlWithOnly(): void
    {
        $sql = $this->builder->buildInsertSelectSql('users', ['name'], 'SELECT name FROM old_users', ['ONLY']);
        $this->assertStringContainsString('INSERT INTO ONLY', $sql);
    }

    public function testBuildInsertSelectSqlWithOverriding(): void
    {
        $sql = $this->builder->buildInsertSelectSql('users', ['name'], 'SELECT name FROM old_users', ['OVERRIDING SYSTEM VALUE']);
        $this->assertStringContainsString('OVERRIDING SYSTEM VALUE', $sql);
    }

    public function testBuildUpdateWithJoinSql(): void
    {
        $sql = $this->builder->buildUpdateWithJoinSql(
            'users',
            '"name" = ?',
            ['INNER JOIN profiles ON users.id = profiles.user_id'],
            'users.id = ?',
            null
        );
        $this->assertStringContainsString('UPDATE users SET', $sql);
        $this->assertStringContainsString('FROM profiles', $sql);
        $this->assertStringContainsString('WHERE', $sql);
    }

    public function testBuildUpdateWithJoinSqlWithLimit(): void
    {
        $sql = $this->builder->buildUpdateWithJoinSql(
            'users',
            '"name" = ?',
            ['INNER JOIN profiles ON users.id = profiles.user_id'],
            'users.id = ?',
            10
        );
        $this->assertStringContainsString('LIMIT 10', $sql);
    }

    public function testBuildUpdateWithJoinSqlWithLeftJoin(): void
    {
        $sql = $this->builder->buildUpdateWithJoinSql(
            'users',
            '"name" = ?',
            ['LEFT JOIN profiles ON users.id = profiles.user_id'],
            '',
            null
        );
        $this->assertStringContainsString('FROM profiles', $sql);
    }

    public function testBuildDeleteWithJoinSql(): void
    {
        $sql = $this->builder->buildDeleteWithJoinSql(
            'users',
            ['INNER JOIN profiles ON users.id = profiles.user_id'],
            'users.id = ?'
        );
        $this->assertStringContainsString('DELETE FROM users', $sql);
        $this->assertStringContainsString('USING profiles', $sql);
        $this->assertStringContainsString('WHERE', $sql);
    }

    public function testBuildDeleteWithJoinSqlWithLeftJoin(): void
    {
        $sql = $this->builder->buildDeleteWithJoinSql(
            'users',
            ['LEFT JOIN profiles ON users.id = profiles.user_id'],
            ''
        );
        $this->assertStringContainsString('USING profiles', $sql);
    }

    public function testBuildReplaceSqlBasic(): void
    {
        $sql = $this->builder->buildReplaceSql('users', ['name', 'email'], ['?', '?'], false);
        $this->assertStringContainsString('INSERT INTO', $sql);
        $this->assertStringContainsString('ON CONFLICT', $sql);
    }

    public function testBuildReplaceSqlWithId(): void
    {
        $sql = $this->builder->buildReplaceSql('users', ['id', 'name'], ['?', '?'], false);
        $this->assertStringContainsString('ON CONFLICT ("id")', $sql);
        $this->assertStringContainsString('DO UPDATE SET', $sql);
    }

    public function testBuildReplaceSqlMultiple(): void
    {
        $sql = $this->builder->buildReplaceSql('users', ['name'], [['?'], ['?']], true);
        $this->assertStringContainsString('VALUES', $sql);
        $this->assertStringContainsString('ON CONFLICT', $sql);
    }

    public function testBuildUpsertClause(): void
    {
        $sql = $this->builder->buildUpsertClause(['name', 'email'], 'id');
        $this->assertStringContainsString('ON CONFLICT ("id")', $sql);
        $this->assertStringContainsString('DO UPDATE SET', $sql);
        $this->assertStringContainsString('EXCLUDED', $sql);
    }

    public function testBuildUpsertClauseWithAssociativeArray(): void
    {
        $sql = $this->builder->buildUpsertClause(['name' => 'EXCLUDED."name"', 'email' => 'EXCLUDED."email"'], 'id');
        $this->assertStringContainsString('ON CONFLICT ("id")', $sql);
        $this->assertStringContainsString('DO UPDATE SET', $sql);
    }

    public function testBuildUpsertClauseEmpty(): void
    {
        $sql = $this->builder->buildUpsertClause([], 'id');
        $this->assertEquals('', $sql);
    }

    public function testBuildMergeSql(): void
    {
        $sql = $this->builder->buildMergeSql(
            'target_table',
            'SELECT * FROM source_table',
            'target.id = source.id',
            [
                'whenMatched' => '"name" = source."name"',
                'whenNotMatched' => '("name") VALUES (source."name")',
            ]
        );
        $this->assertStringContainsString('MERGE INTO', $sql);
        $this->assertStringContainsString('AS target', $sql);
        $this->assertStringContainsString('AS source', $sql);
        $this->assertStringContainsString('WHEN MATCHED', $sql);
        $this->assertStringContainsString('WHEN NOT MATCHED', $sql);
    }

    public function testBuildMergeSqlWithWhenMatchedCondition(): void
    {
        $sql = $this->builder->buildMergeSql(
            'target_table',
            'SELECT * FROM source_table',
            'target.id = source.id',
            [
                'whenMatched' => '"name" = source."name" AND target."status" = \'active\'',
            ]
        );
        $this->assertStringContainsString('WHEN MATCHED AND', $sql);
        $this->assertStringContainsString('target."status"', $sql);
    }

    public function testBuildMergeSqlWithWhenNotMatchedBySourceDelete(): void
    {
        $sql = $this->builder->buildMergeSql(
            'target_table',
            'SELECT * FROM source_table',
            'target.id = source.id',
            [
                'whenNotMatchedBySourceDelete' => true,
            ]
        );
        $this->assertStringContainsString('WHEN NOT MATCHED BY SOURCE', $sql);
        $this->assertStringContainsString('DELETE', $sql);
    }

    public function testNeedsColumnQualificationInUpdateSet(): void
    {
        $result = $this->builder->needsColumnQualificationInUpdateSet();
        $this->assertFalse($result);
    }

    public function testBuildIncrementExpression(): void
    {
        $reflection = new \ReflectionClass($this->builder);
        $method = $reflection->getMethod('buildIncrementExpression');
        $method->setAccessible(true);

        $result = $method->invoke($this->builder, '"count"', ['__op' => 'inc', 'val' => 5], 'users');
        $this->assertStringContainsString('"count" = "users"."count" + 5', $result);
    }

    public function testBuildIncrementExpressionDec(): void
    {
        $reflection = new \ReflectionClass($this->builder);
        $method = $reflection->getMethod('buildIncrementExpression');
        $method->setAccessible(true);

        $result = $method->invoke($this->builder, '"count"', ['__op' => 'dec', 'val' => 3], 'users');
        $this->assertStringContainsString('"count" = "users"."count" - 3', $result);
    }

    public function testBuildRawValueExpression(): void
    {
        $reflection = new \ReflectionClass($this->builder);
        $method = $reflection->getMethod('buildRawValueExpression');
        $method->setAccessible(true);

        $rawValue = new RawValue('NOW()');
        $result = $method->invoke($this->builder, '"updated_at"', $rawValue, 'users', 'updated_at');
        $this->assertStringContainsString('"updated_at" = NOW()', $result);
    }

    public function testBuildDefaultExpression(): void
    {
        $reflection = new \ReflectionClass($this->builder);
        $method = $reflection->getMethod('buildDefaultExpression');
        $method->setAccessible(true);

        $result = $method->invoke($this->builder, '"name"', 'EXCLUDED."name"', 'name');
        $this->assertStringContainsString('"name" = EXCLUDED."name"', $result);
    }

    public function testBuildDefaultExpressionWithColumnName(): void
    {
        $reflection = new \ReflectionClass($this->builder);
        $method = $reflection->getMethod('buildDefaultExpression');
        $method->setAccessible(true);

        $result = $method->invoke($this->builder, '"name"', 'name', 'name');
        $this->assertStringContainsString('EXCLUDED."name"', $result);
    }
}
