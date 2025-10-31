<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use RuntimeException;

/**
 * SQLite-specific tests for LATERAL JOIN functionality.
 */
final class LateralJoinTests extends BaseSqliteTestCase
{
    public function testSqliteDoesNotSupportLateralJoin(): void
    {
        $dialect = self::$db->find()->getConnection()->getDialect();
        $this->assertFalse($dialect->supportsLateralJoin());
        $this->assertEquals('sqlite', $dialect->getDriverName());
    }

    public function testLateralJoinThrowsExceptionOnSQLite(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LATERAL JOIN is not supported by sqlite dialect');

        self::$db->find()
            ->from('users AS u')
            ->lateralJoin(function ($q) {
                $q->from('orders')->where('user_id', 1)->limit(1);
            });
    }
}
