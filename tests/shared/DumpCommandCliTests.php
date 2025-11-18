<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\Application;
use tommyknocker\pdodb\cli\DumpManager;
use tommyknocker\pdodb\PdoDb;

/**
 * Tests for DumpCommand CLI.
 */
class DumpCommandCliTests extends TestCase
{
    private static ?PdoDb $db = null;
    private static ?string $dbPath = null;

    public static function setUpBeforeClass(): void
    {
        $dbPath = sys_get_temp_dir() . '/pdodb_dump_test_' . uniqid() . '.sqlite';
        self::$dbPath = $dbPath;

        $db = new PdoDb('sqlite', ['path' => $dbPath]);

        // Create test tables with data
        $db->rawQuery('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT)');
        $db->rawQuery('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, content TEXT)');
        $db->rawQuery('CREATE INDEX idx_user_id ON posts(user_id)');

        $db->rawQuery("INSERT INTO users (name, email) VALUES ('John', 'john@example.com')");
        $db->rawQuery("INSERT INTO users (name, email) VALUES ('Jane', 'jane@example.com')");
        $db->rawQuery("INSERT INTO posts (user_id, title, content) VALUES (1, 'Post 1', 'Content 1')");
        $db->rawQuery("INSERT INTO posts (user_id, title, content) VALUES (1, 'Post 2', 'Content 2')");
        $db->rawQuery("INSERT INTO posts (user_id, title, content) VALUES (2, 'Post 3', 'Content 3')");

        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$dbPath !== null && file_exists(self::$dbPath)) {
            unlink(self::$dbPath);
        }
    }

    public function testDumpFullDatabase(): void
    {
        $sql = DumpManager::dump(self::$db);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('users', $sql);
        $this->assertStringContainsString('posts', $sql);
        $this->assertStringContainsString('INSERT INTO', $sql);
        $this->assertStringContainsString('John', $sql);
        $this->assertStringContainsString('Post 1', $sql);
    }

    public function testDumpSchemaOnly(): void
    {
        $sql = DumpManager::dump(self::$db, null, true, false);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringNotContainsString('INSERT INTO', $sql);
    }

    public function testDumpDataOnly(): void
    {
        $sql = DumpManager::dump(self::$db, null, false, true);
        $this->assertStringNotContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('INSERT INTO', $sql);
        $this->assertStringContainsString('John', $sql);
    }

    public function testDumpSingleTable(): void
    {
        $sql = DumpManager::dump(self::$db, 'users');
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('users', $sql);
        $this->assertStringNotContainsString('posts', $sql);
        $this->assertStringContainsString('INSERT INTO', $sql);
        $this->assertStringContainsString('John', $sql);
    }

    public function testRestoreFromDump(): void
    {
        // Create dump
        $sql = DumpManager::dump(self::$db);
        $dumpFile = sys_get_temp_dir() . '/pdodb_restore_test_' . uniqid() . '.sql';
        file_put_contents($dumpFile, $sql);

        // Create new database
        $newDbPath = sys_get_temp_dir() . '/pdodb_restore_test_' . uniqid() . '.sqlite';
        $newDb = new PdoDb('sqlite', ['path' => $newDbPath]);

        // Restore
        DumpManager::restore($newDb, $dumpFile);

        // Verify data
        $users = $newDb->rawQuery('SELECT * FROM users ORDER BY id');
        $this->assertCount(2, $users);
        $this->assertEquals('John', $users[0]['name']);
        $this->assertEquals('Jane', $users[1]['name']);

        $posts = $newDb->rawQuery('SELECT * FROM posts ORDER BY id');
        $this->assertCount(3, $posts);

        // Cleanup
        unlink($dumpFile);
        unlink($newDbPath);
    }

    public function testRestoreFromFile(): void
    {
        // Create dump file
        $sql = DumpManager::dump(self::$db);
        $dumpFile = sys_get_temp_dir() . '/pdodb_dump_file_' . uniqid() . '.sql';
        file_put_contents($dumpFile, $sql);

        // Create new database
        $newDbPath = sys_get_temp_dir() . '/pdodb_restore_file_test_' . uniqid() . '.sqlite';
        $newDb = new PdoDb('sqlite', ['path' => $newDbPath]);

        // Restore from file
        DumpManager::restore($newDb, $dumpFile);

        // Verify
        $users = $newDb->rawQuery('SELECT * FROM users ORDER BY id');
        $this->assertCount(2, $users);

        // Cleanup
        unlink($dumpFile);
        unlink($newDbPath);
    }

    public function testDumpCommandHelp(): void
    {
        $app = new Application();
        ob_start();
        $exitCode = $app->run(['pdodb', 'dump', 'help']);
        $output = ob_get_clean();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Database Dump and Restore', $output);
        $this->assertStringContainsString('Usage:', $output);
    }
}
