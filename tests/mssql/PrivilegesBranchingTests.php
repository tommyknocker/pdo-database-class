<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\dialects\mssql\MSSQLDialect;

final class PrivilegesBranchingTests extends BaseMSSQLTestCase
{
    public function testGrantSchemaLevelPrivileges(): void
    {
        $dialect = new MSSQLDialect();

        try {
            $result = $dialect->grantPrivileges('testuser', 'SELECT', 'testdb', null, null, static::$db);
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
        $dialect = new MSSQLDialect();

        try {
            $result = $dialect->grantPrivileges('testuser', 'SELECT', 'testdb', 'users', null, static::$db);
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
