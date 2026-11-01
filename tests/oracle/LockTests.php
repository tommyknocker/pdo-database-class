<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use InvalidArgumentException;

final class LockTests extends BaseOracleTestCase
{
    private const TABLE = 'lock_probe';

    public function setUp(): void
    {
        parent::setUp();
        $this->dropTable();
        self::$db->rawQuery('CREATE TABLE ' . self::TABLE . ' (id NUMBER(10) PRIMARY KEY)');
    }

    public function tearDown(): void
    {
        $this->dropTable();
        parent::tearDown();
    }

    private function dropTable(): void
    {
        try {
            self::$db->rawQuery('DROP TABLE ' . self::TABLE . ' PURGE');
        } catch (\Throwable) {
            // not there yet
        }
    }

    public function testWriteLockUsesExclusiveMode(): void
    {
        $db = self::$db;
        $db->startTransaction();

        try {
            $this->assertTrue($db->lock(self::TABLE));
            $this->assertSame('LOCK TABLE "' . strtoupper(self::TABLE) . '" IN EXCLUSIVE MODE', $db->lastQuery);
            $this->assertTrue($db->unlock());
        } finally {
            $db->rollback();
        }
    }

    public function testReadLockUsesShareMode(): void
    {
        $db = self::$db;
        $db->startTransaction();

        try {
            $this->assertTrue($db->setLockMethod('READ')->lock(self::TABLE));
            $this->assertSame('LOCK TABLE "' . strtoupper(self::TABLE) . '" IN SHARE MODE', $db->lastQuery);
        } finally {
            $db->setLockMethod('WRITE');
            $db->rollback();
        }
    }

    public function testSeveralTablesAreLockedByOneStatement(): void
    {
        $dialect = self::$db->connection?->getDialect();
        $this->assertNotNull($dialect);

        $this->assertSame('LOCK TABLE "A", "B" IN EXCLUSIVE MODE', $dialect->buildLockSql(['a', 'b'], '', 'WRITE'));
        $this->assertSame('LOCK TABLE "P_A" IN ROW SHARE MODE', $dialect->buildLockSql(['a'], 'p_', 'row share'));
    }

    public function testUnknownModeIsRejected(): void
    {
        $dialect = self::$db->connection?->getDialect();
        $this->assertNotNull($dialect);

        $this->expectException(InvalidArgumentException::class);
        $dialect->buildLockSql(['a'], '', 'EXCLUSIVE MODE; DROP TABLE x --');
    }
}
