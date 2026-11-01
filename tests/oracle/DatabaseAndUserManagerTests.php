<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use InvalidArgumentException;
use tommyknocker\pdodb\cli\DatabaseManager;
use tommyknocker\pdodb\cli\UserManager;

/**
 * Oracle "databases" are schemas (users). Creating one needs CREATE USER, so those tests skip without it.
 */
final class DatabaseAndUserManagerTests extends BaseOracleTestCase
{
    private const SCHEMA = 'PDODB_TMP_SCHEMA';
    private const USER = 'PDODB_TMP_USER';

    public function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    public function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        foreach ([self::SCHEMA, self::USER] as $name) {
            try {
                if (UserManager::exists($name, null, self::$db)) {
                    UserManager::drop($name, null, self::$db);
                }
            } catch (\Throwable) {
                // no privilege to look, nothing to clean
            }
        }
    }

    private function requireCreateUser(): void
    {
        $granted = self::$db->rawQueryValue("SELECT COUNT(*) FROM SESSION_PRIVS WHERE PRIVILEGE IN ('CREATE USER', 'DROP USER')");
        if ((int) $granted < 2) {
            $this->markTestSkipped('The test account lacks CREATE USER / DROP USER.');
        }
    }

    public function testDatabaseInfoNamesTheCurrentUserAndVersion(): void
    {
        $info = DatabaseManager::getInfo(self::$db);

        $this->assertSame('oci', $info['driver']);
        $this->assertNotEmpty($info['current_user']);
        $this->assertStringContainsString('Oracle', (string) $info['version']);
    }

    public function testListedDatabasesIncludeTheCurrentSchema(): void
    {
        $current = (string) DatabaseManager::getInfo(self::$db)['current_user'];

        $this->assertContains($current, DatabaseManager::list(self::$db));
        $this->assertTrue(DatabaseManager::exists($current, self::$db));
        $this->assertFalse(DatabaseManager::exists('PDODB_NO_SUCH_SCHEMA', self::$db));
    }

    public function testCreateDatabaseSqlSetsNoPassword(): void
    {
        $dialect = self::$db->schema()->getDialect();
        $sql = (new \ReflectionMethod($dialect, 'buildCreateDatabaseSql'))->invoke($dialect, 'APP_SCHEMA');

        $this->assertSame('CREATE USER "APP_SCHEMA" NO AUTHENTICATION', $sql);
    }

    public function testCreatedDatabaseIsASchemaNobodyCanLogInTo(): void
    {
        $this->requireCreateUser();

        $this->assertTrue(DatabaseManager::create(self::SCHEMA, self::$db));
        $this->assertTrue(DatabaseManager::exists(self::SCHEMA, self::$db));
        $this->assertSame(
            'NONE',
            self::$db->rawQueryValue('SELECT AUTHENTICATION_TYPE FROM DBA_USERS WHERE USERNAME = ?', [self::SCHEMA])
        );
        $this->assertTrue(DatabaseManager::drop(self::SCHEMA, self::$db));
        $this->assertFalse(DatabaseManager::exists(self::SCHEMA, self::$db));
    }

    public function testUserLifecycle(): void
    {
        $this->requireCreateUser();

        $this->assertTrue(UserManager::create(self::USER, 'Str0ng_Passw0rd_1', null, self::$db));
        $this->assertTrue(UserManager::exists(self::USER, null, self::$db));
        $this->assertSame(self::USER, UserManager::getInfo(self::USER, null, self::$db)['username']);
        $this->assertContains(self::USER, array_column(UserManager::list(self::$db), 'username'));
        $this->assertTrue(UserManager::drop(self::USER, null, self::$db));
        $this->assertFalse(UserManager::exists(self::USER, null, self::$db));
    }

    public function testPasswordWithADoubleQuoteIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UserManager::create(self::USER, 'bad"password', null, self::$db);
    }
}
