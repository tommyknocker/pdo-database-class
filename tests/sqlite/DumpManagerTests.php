<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\cli\DumpManager;
use tommyknocker\pdodb\PdoDb;

/**
 * DumpManager tests for SQLite.
 */
final class DumpManagerTests extends BaseSqliteTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Create test tables with data
        static::$db->rawQuery('DROP TABLE IF EXISTS dump_test_posts');
        static::$db->rawQuery('DROP TABLE IF EXISTS dump_test_users');

        static::$db->rawQuery('CREATE TABLE dump_test_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )');

        static::$db->rawQuery('CREATE TABLE dump_test_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            content TEXT,
            FOREIGN KEY (user_id) REFERENCES dump_test_users(id) ON DELETE CASCADE
        )');

        static::$db->rawQuery('CREATE INDEX idx_user_id ON dump_test_posts(user_id)');
        static::$db->rawQuery('CREATE INDEX idx_title ON dump_test_posts(title)');

        static::$db->rawQuery("INSERT INTO dump_test_users (name, email) VALUES ('John Doe', 'john@example.com')");
        static::$db->rawQuery("INSERT INTO dump_test_users (name, email) VALUES ('Jane Smith', 'jane@example.com')");
        static::$db->rawQuery("INSERT INTO dump_test_posts (user_id, title, content) VALUES (1, 'First Post', 'Content 1')");
        static::$db->rawQuery("INSERT INTO dump_test_posts (user_id, title, content) VALUES (1, 'Second Post', 'Content 2')");
        static::$db->rawQuery("INSERT INTO dump_test_posts (user_id, title, content) VALUES (2, 'Third Post', 'Content 3')");
    }

    public function tearDown(): void
    {
        static::$db->rawQuery('DROP TABLE IF EXISTS dump_test_posts');
        static::$db->rawQuery('DROP TABLE IF EXISTS dump_test_users');
        parent::tearDown();
    }

    public function testDumpFullDatabase(): void
    {
        $sql = DumpManager::dump(static::$db);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('dump_test_users', $sql);
        $this->assertStringContainsString('dump_test_posts', $sql);
        $this->assertStringContainsString('INSERT INTO', $sql);
        $this->assertStringContainsString('John Doe', $sql);
    }

    public function testDumpSchemaOnly(): void
    {
        $sql = DumpManager::dump(static::$db, null, true, false);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringNotContainsString('INSERT INTO', $sql);
        $this->assertStringContainsString('dump_test_users', $sql);
        $this->assertStringContainsString('dump_test_posts', $sql);
    }

    public function testDumpDataOnly(): void
    {
        $sql = DumpManager::dump(static::$db, null, false, true);
        $this->assertStringNotContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('INSERT INTO', $sql);
        $this->assertStringContainsString('John Doe', $sql);
    }

    public function testDumpSingleTable(): void
    {
        $sql = DumpManager::dump(static::$db, 'dump_test_users');
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('dump_test_users', $sql);
        $this->assertStringNotContainsString('dump_test_posts', $sql);
        $this->assertStringContainsString('INSERT INTO', $sql);
        $this->assertStringContainsString('John Doe', $sql);
    }

    public function testRestoreFromDump(): void
    {
        // Create dump
        $sql = DumpManager::dump(static::$db);
        $dumpFile = sys_get_temp_dir() . '/pdodb_sqlite_restore_' . uniqid() . '.sql';
        file_put_contents($dumpFile, $sql);

        // Create new database for restore
        $newDbPath = sys_get_temp_dir() . '/pdodb_sqlite_restore_' . uniqid() . '.sqlite';
        $newDb = new PdoDb('sqlite', ['path' => $newDbPath]);

        // Restore
        DumpManager::restore($newDb, $dumpFile);

        // Verify data
        $users = $newDb->rawQuery('SELECT * FROM dump_test_users ORDER BY id');
        $this->assertCount(2, $users);
        $this->assertEquals('John Doe', $users[0]['name']);
        $this->assertEquals('Jane Smith', $users[1]['name']);

        $posts = $newDb->rawQuery('SELECT * FROM dump_test_posts ORDER BY id');
        $this->assertCount(3, $posts);

        // Cleanup
        unlink($dumpFile);
        if (file_exists($newDbPath)) {
            unlink($newDbPath);
        }
    }

    public function testDumpIncludesIndexes(): void
    {
        $sql = DumpManager::dump(static::$db, null, true, false);
        $this->assertStringContainsString('CREATE INDEX', $sql);
        $this->assertStringContainsString('idx_user_id', $sql);
        $this->assertStringContainsString('idx_title', $sql);
    }
}
