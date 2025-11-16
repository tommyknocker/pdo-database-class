<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use tommyknocker\pdodb\dialects\postgresql\PostgreSQLDialect;

final class PrivilegesBranchingTests extends BasePostgreSQLTestCase
{
    public function testGrantDatabaseLevelPrivileges(): void
    {
        $dialect = new PostgreSQLDialect();

        try {
            $result = $dialect->grantPrivileges('testuser', 'CONNECT,CREATE,TEMPORARY', 'testdb', null, null, static::$db);
            $this->assertTrue($result);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'permission') !== false || stripos($msg, 'denied') !== false) {
                $this->markTestSkipped('Insufficient privileges: ' . $msg);
            }

            throw $e;
        }
    }

    public function testGrantTableLevelPrivileges(): void
    {
        $dialect = new PostgreSQLDialect();

        try {
            $result = $dialect->grantPrivileges('testuser', 'SELECT,INSERT', 'testdb', null, null, static::$db);
            $this->assertTrue($result);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'permission') !== false || stripos($msg, 'denied') !== false) {
                $this->markTestSkipped('Insufficient privileges: ' . $msg);
            }

            throw $e;
        }
    }
}
