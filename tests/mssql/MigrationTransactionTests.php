<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\tests\shared\MigrationTransactionSupport;

final class MigrationTransactionTests extends BaseMSSQLTestCase
{
    public function testFailedMigrationIsRolledBackIncludingItsDdl(): void
    {
        $this->assertTrue(self::$db->schema()->getDialect()->supportsTransactionalDdl());

        $result = MigrationTransactionSupport::runFailingMigration(self::$db);

        $this->assertStringContainsString('failed', $result['message']);
        $this->assertStringNotContainsString('ran without a transaction', $result['message']);
        $this->assertFalse($result['tableExists']);
        $this->assertFalse($result['recorded']);
    }
}
