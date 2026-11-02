<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\dialects\mssql\MSSQLDialect;
use tommyknocker\pdodb\dialects\mysql\MySQLDialect;
use tommyknocker\pdodb\dialects\oracle\OracleDialect;
use tommyknocker\pdodb\dialects\postgresql\PostgreSQLDialect;
use tommyknocker\pdodb\dialects\sqlite\SqliteDialect;
use tommyknocker\pdodb\events\QueryExecutedEvent;
use tommyknocker\pdodb\exceptions\UnsupportedOperationException;

/**
 * Two things code that runs on several databases or logs queries needs to ask or rely on.
 */
final class LogSafetyAndUpsertCapabilityTests extends TestCase
{
    public function testTheSafeFormOfAQueryEventHoldsNoValues(): void
    {
        $event = new QueryExecutedEvent(
            "SELECT * FROM users WHERE token = :t AND email = 'ada@example.test' AND id = 42",
            [':t' => 'secret-token-123'],
            12.3456,
            1,
            'pgsql'
        );

        $safe = $event->toSafeArray();

        $this->assertSame(1, $safe['param_count']);
        $this->assertSame(12.35, $safe['execution_time_ms']);
        $this->assertSame('pgsql', $safe['driver']);
        $this->assertFalse($safe['from_cache']);
        $encoded = (string)json_encode($safe);
        $this->assertStringNotContainsString('secret-token-123', $encoded);
        $this->assertStringNotContainsString('ada@example.test', $encoded, 'literals written into the statement are removed too');
        $this->assertStringContainsString('SELECT * FROM users WHERE token = :t', $safe['sql'], 'the shape of the query stays readable');
    }

    public function testDoubleQuotedTextIsRedactedWhereTheDatabaseTreatsItAsAString(): void
    {
        $sql = 'SELECT * FROM users WHERE name = "Ada Lovelace"';

        $this->assertStringNotContainsString('Ada Lovelace', (new QueryExecutedEvent($sql, [], 1.0, 0, 'mysql', false, true))->toSafeArray()['sql']);
        // in PostgreSQL the same quotes name an identifier, which is not secret and is kept
        $this->assertStringContainsString('"Ada Lovelace"', (new QueryExecutedEvent($sql, [], 1.0, 0, 'pgsql', false, false))->toSafeArray()['sql']);
    }

    public function testDialectsSayWhetherInsertOnDuplicateIsAvailable(): void
    {
        $this->assertTrue((new MySQLDialect())->supportsUpsertClause());
        $this->assertTrue((new PostgreSQLDialect())->supportsUpsertClause());
        $this->assertTrue((new SqliteDialect())->supportsUpsertClause());
        $this->assertFalse((new MSSQLDialect())->supportsUpsertClause());
        $this->assertFalse((new OracleDialect())->supportsUpsertClause());
    }

    public function testWhereThereIsNoUpsertClauseBuildingOneFailsWithAnUnsupportedOperation(): void
    {
        foreach ([new MSSQLDialect(), new OracleDialect()] as $dialect) {
            $this->assertFalse($dialect->supportsUpsertClause());
            $this->assertTrue($dialect->supportsMerge(), 'they upsert with MERGE');

            try {
                $dialect->buildUpsertClause(['age'], 'id', 'users');
                $this->fail($dialect->getDriverName() . ' has no ON DUPLICATE clause');
            } catch (UnsupportedOperationException $e) {
                $this->assertStringContainsString('merge()', $e->getMessage());
            }
        }
    }
}
