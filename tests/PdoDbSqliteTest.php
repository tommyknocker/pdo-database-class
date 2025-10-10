<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\tests;

use PDO;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\PdoDb;

final class PdoDbSqliteTest extends TestCase
{
    private static ?PdoDb $db = null;

    public function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $db = new PdoDb('sqlite', ['pdo' => $pdo]);

        $db->rawQuery("CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                status TEXT
        )");

        $db->rawQuery("CREATE TABLE logs (
                user_id INTEGER,
                action TEXT
        )");

        self::$db = $db;
    }

    public function testBasicSqliteUsage(): void
    {
        $db = self::$db;

        $db->insert('users', ['name' => 'Alice', 'status' => 'active']);
        $db->insert('users', ['name' => 'Bob', 'status' => 'inactive']);

        $rows = $db->get('users');
        $this->assertCount(2, $rows);

        $db->where('name', 'Bob')->update('users', ['status' => 'active']);
        $status = $db->where('name', 'Bob')->getValue('users', 'status');
        $this->assertEquals('active', $status);

        $db->where('name', 'Alice')->delete('users');
        $remaining = $db->getValue('users', 'COUNT(*)');
        $this->assertEquals(1, $remaining);
    }

    public function testAdvancedSqliteViaPdoDb(): void
    {
        $db = self::$db;

        $db->insert('users', ['name' => 'Alice', 'status' => 'active']);
        $db->insert('users', ['name' => 'Bob', 'status' => 'inactive']);
        $db->insert('users', ['name' => 'Charlie', 'status' => 'active']);

        $db->insert('logs', ['user_id' => 1, 'action' => 'login']);
        $db->insert('logs', ['user_id' => 1, 'action' => 'logout']);
        $db->insert('logs', ['user_id' => 3, 'action' => 'login']);

        $sub = $db->subQuery();
        $sub->get('logs', null, ['user_id']);

        $db->where('id', $sub, 'IN')->update('users', ['status' => 'logged']);

        $status1 = $db->where('name', 'Alice')->getValue('users', 'status');
        $status3 = $db->where('name', 'Charlie')->getValue('users', 'status');
        $status2 = $db->where('name', 'Bob')->getValue('users', 'status');

        $this->assertEquals('logged', $status1);
        $this->assertEquals('logged', $status3);
        $this->assertEquals('inactive', $status2);

        $db->where('status', 'logged')->delete('users');

        $remaining = $db->get('users');
        $this->assertCount(1, $remaining);
        $this->assertEquals('Bob', $remaining[0]['name']);
    }


}
