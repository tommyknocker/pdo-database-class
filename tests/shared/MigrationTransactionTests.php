<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

final class MigrationTransactionTests extends BaseSharedTestCase
{
    public function testSqliteRollsBackDdlOfAFailedMigration(): void
    {
        $this->assertTrue(self::$db->schema()->getDialect()->supportsTransactionalDdl());

        $result = MigrationTransactionSupport::runFailingMigration(self::$db);

        $this->assertStringContainsString('failed', $result['message']);
        $this->assertStringNotContainsString('ran without a transaction', $result['message']);
        $this->assertFalse($result['tableExists']);
        $this->assertFalse($result['recorded']);
    }

    public function testMigrationCanOptOutOfTheTransaction(): void
    {
        $result = MigrationTransactionSupport::runFailingMigration(self::$db, 'false');

        $this->assertStringContainsString('ran without a transaction', $result['message']);
        $this->assertTrue($result['tableExists']);
        $this->assertFalse($result['recorded']);
    }

    public function testMigrationCanForceATransaction(): void
    {
        $result = MigrationTransactionSupport::runFailingMigration(self::$db, 'true');

        $this->assertFalse($result['tableExists']);
        $this->assertFalse($result['recorded']);
    }
}
