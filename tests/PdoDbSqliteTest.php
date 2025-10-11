<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\PdoDb;

final class PdoDbSqliteTest extends TestCase
{
    private static ?PdoDb $db = null;

    public function setUp(): void
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:']);

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

    public function testSqliteMinimalParams(): void
    {
        $dsn = self::$db->buildDsn([
            'driver' => 'sqlite',
            'path' => ':memory:',
        ]);
        $this->assertEquals('sqlite::memory:', $dsn);
    }

    public function testSqliteAllParams(): void
    {
        $dsn = self::$db->buildDsn([
            'driver' => 'sqlite',
            'path' => '/tmp/test.sqlite',
            'mode' => 'rwc',
            'cache' => 'shared',
        ]);
        $this->assertStringContainsString('sqlite:/tmp/test.sqlite', $dsn);
        $this->assertStringContainsString('mode=rwc', $dsn);
        $this->assertStringContainsString('cache=shared', $dsn);
    }

    public function testSqliteMissingParamsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::$db->buildDsn(['driver' => 'sqlite']); // no path
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

    public function testPaginate(): void
    {
        // Заполняем таблицу 12 пользователями
        for ($i = 1; $i <= 12; $i++) {
            self::$db->insert('users', [
                'name' => "User{$i}",
                'status' => 'active'
            ]);
        }

        // Устанавливаем размер страницы = 5
        self::$db->setPageLimit(5);

        // Страница 1: первые 5 пользователей
        $page1 = self::$db->withTotalCount()->orderBy('id', 'ASC')
            ->paginate('users', 1, ['id', 'name']);
        $this->assertCount(5, $page1);
        $this->assertEquals('User1', $page1[0]['name']);
        $this->assertEquals('User5', $page1[4]['name']);
        $this->assertEquals(12, self::$db->totalCount());

        // Страница 2: следующие 5 (User6..User10)
        self::$db->orderBy('id', 'ASC')->setPageLimit(5);
        $page2 = self::$db->paginate('users', 2, ['id', 'name']);
        $this->assertCount(5, $page2);
        $this->assertEquals('User6', $page2[0]['name']);
        $this->assertEquals('User10', $page2[4]['name']);

        // Страница 3: оставшиеся 2 (User11..User12)
        self::$db->orderBy('id', 'ASC')->setPageLimit(5);
        $page3 = self::$db->paginate('users', 3, ['id', 'name']);
        $this->assertCount(2, $page3);
        $this->assertEquals('User11', $page3[0]['name']);
        $this->assertEquals('User12', $page3[1]['name']);

        // Страница 4: пусто
        self::$db->orderBy('id', 'ASC')->setPageLimit(5);
        $page4 = self::$db->paginate('users', 4, ['id', 'name']);
        $this->assertCount(0, $page4);
    }

    public function testGetValueWithAliasSqlite(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            self::$db->insert('users', [
                'name' => "User{$i}",
                'status' => 'active'
            ]);
        }

        $cnt = self::$db->getValue('users', 'COUNT(*) AS cnt');
        $this->assertEquals(3, $cnt);
    }

    public function testGetColumn(): void
    {
        $db = self::$db;
        self::$db->insert('users', ['name' => 'SubUser', 'status' => 'active']);
        self::$db->insert('users', ['name' => 'OtherUser', 'status' => 'active']);
        $names = $db->getColumn('users', 'name');
        $this->assertEquals(['SubUser','OtherUser'], $names);
    }

    public function testUpdateLimit(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            self::$db->insert('users', ['name' => "Cnt{$i}", 'status' => 'active']);
        }
        self::$db->update('users', ['status' => 'inactive'], 5);
        $this->assertCount(5, self::$db->where('status', 'inactive')->get('users'));
    }
}
