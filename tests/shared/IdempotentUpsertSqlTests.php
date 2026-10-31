<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\dialects\mariadb\MariaDBDialect;
use tommyknocker\pdodb\dialects\mssql\MSSQLDialect;
use tommyknocker\pdodb\dialects\mysql\MySQLDialect;
use tommyknocker\pdodb\dialects\oracle\OracleDialect;
use tommyknocker\pdodb\dialects\postgresql\PostgreSQLDialect;
use tommyknocker\pdodb\dialects\sqlite\SqliteDialect;

final class IdempotentUpsertSqlTests extends TestCase
{
    public function testConflictDialectsUseInsertOnConflict(): void
    {
        foreach ([new SqliteDialect(), new PostgreSQLDialect()] as $dialect) {
            [$sql, $params] = $dialect->buildIdempotentUpsertSql(
                'articles',
                ['slug' => 'hello', 'title' => 'Hello'],
                ['slug'],
                ['title']
            );
            $this->assertStringContainsString('ON CONFLICT', $sql);
            $this->assertStringContainsString('EXCLUDED', $sql);
            $this->assertSame('hello', $params['upsert_slug']);
        }
    }

    public function testMysqlUsesOnDuplicateKey(): void
    {
        foreach ([new MySQLDialect(), new MariaDBDialect()] as $dialect) {
            [$sql] = $dialect->buildIdempotentUpsertSql(
                'articles',
                ['slug' => 'hello', 'title' => 'Hello'],
                ['slug'],
                []
            );
            $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        }
    }

    public function testMergeDialectsUseMerge(): void
    {
        [$mssql] = (new MSSQLDialect())->buildIdempotentUpsertSql(
            'articles',
            ['slug' => 'hello', 'title' => 'Hello'],
            ['slug'],
            ['title']
        );
        $this->assertStringContainsString('MERGE', $mssql);
        $this->assertStringContainsString('WHEN MATCHED', $mssql);

        [$oracle] = (new OracleDialect())->buildIdempotentUpsertSql(
            'articles',
            ['slug' => 'hello'],
            ['slug'],
            []
        );
        $this->assertStringContainsString('MERGE INTO', $oracle);
        $this->assertStringContainsString('FROM dual', $oracle);
        $this->assertStringNotContainsString('WHEN MATCHED', $oracle);
    }

    public function testInboxSchemaIsDialectSpecific(): void
    {
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS', (new SqliteDialect())->buildInboxSchemaSql('pdodb_inbox')[0]);
        $this->assertStringContainsString('OBJECT_ID', (new MSSQLDialect())->buildInboxSchemaSql('pdodb_inbox')[0]);
        $this->assertStringContainsString('EXECUTE IMMEDIATE', (new OracleDialect())->buildInboxSchemaSql('pdodb_inbox')[0]);
    }
}
