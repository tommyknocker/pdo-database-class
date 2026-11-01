<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\tests\shared\MigrationTransactionSupport;

final class MigrationTransactionTests extends BaseOracleTestCase
{
    public function testFailedMigrationReportsThatItsDdlCannotBeRolledBack(): void
    {
        $this->assertFalse(self::$db->schema()->getDialect()->supportsTransactionalDdl());

        $result = MigrationTransactionSupport::runFailingMigration(self::$db);

        $this->assertStringContainsString('ran without a transaction', $result['message']);
        $this->assertTrue($result['tableExists'], 'DDL run before the failure stays applied');
        $this->assertFalse($result['recorded'], 'a failed migration is not recorded in the history');
    }
}
