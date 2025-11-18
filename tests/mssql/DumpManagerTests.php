<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\cli\DumpManager;
use tommyknocker\pdodb\PdoDb;

/**
 * DumpManager tests for MSSQL.
 */
final class DumpManagerTests extends BaseMSSQLTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Create test tables with data
        static::$db->rawQuery('DROP TABLE IF EXISTS dump_test_posts');
        static::$db->rawQuery('DROP TABLE IF EXISTS dump_test_users');

        static::$db->rawQuery('CREATE TABLE dump_test_users (
            id INT IDENTITY(1,1) PRIMARY KEY,
            name NVARCHAR(100) NOT NULL,
            email NVARCHAR(255) UNIQUE,
            created_at DATETIME DEFAULT GETDATE()
        )');

        static::$db->rawQuery('CREATE TABLE dump_test_posts (
            id INT IDENTITY(1,1) PRIMARY KEY,
            user_id INT NOT NULL,
            title NVARCHAR(255) NOT NULL,
            content NTEXT,
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
        // Create dump: schema first, then data (for both tables)
        $schemaUsers = DumpManager::dump(static::$db, 'dump_test_users', true, false);
        $schemaPosts = DumpManager::dump(static::$db, 'dump_test_posts', true, false);
        $dataUsers = DumpManager::dump(static::$db, 'dump_test_users', false, true);
        $dataPosts = DumpManager::dump(static::$db, 'dump_test_posts', false, true);

        // Combine: all schemas first, then all data
        $sql = $schemaUsers . "\n" . $schemaPosts . "\n" . $dataUsers . "\n" . $dataPosts;
        $dumpFile = sys_get_temp_dir() . '/pdodb_mssql_restore_' . uniqid() . '.sql';
        file_put_contents($dumpFile, $sql);

        // Create new database for restore
        $newDb = new PdoDb(
            'sqlsrv',
            [
                'host' => self::DB_HOST,
                'port' => self::DB_PORT,
                'username' => self::DB_USER,
                'password' => self::DB_PASSWORD,
                'dbname' => self::DB_NAME,
            ]
        );

        // Drop tables if exist
        $newDb->rawQuery('DROP TABLE IF EXISTS dump_test_posts');
        $newDb->rawQuery('DROP TABLE IF EXISTS dump_test_users');

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
        $newDb->rawQuery('DROP TABLE IF EXISTS dump_test_posts');
        $newDb->rawQuery('DROP TABLE IF EXISTS dump_test_users');
        unlink($dumpFile);
    }

    public function testDumpIncludesIndexes(): void
    {
        $sql = DumpManager::dump(static::$db, null, true, false);
        $this->assertStringContainsString('CREATE INDEX', $sql);
        $this->assertStringContainsString('idx_user_id', $sql);
        $this->assertStringContainsString('idx_title', $sql);
    }

    public function testRestoreWithDefaultExpression(): void
    {
        // Create table with defaultExpression (like in examples)
        static::$db->rawQuery('DROP TABLE IF EXISTS dump_test_default');
        static::$db->rawQuery('CREATE TABLE dump_test_default (
            id INT IDENTITY(1,1) PRIMARY KEY,
            name NVARCHAR(100) NOT NULL,
            created_at DATETIME DEFAULT (getdate())
        )');

        // Dump using DumpManager (adds headers)
        $dump = DumpManager::dump(static::$db, 'dump_test_default');

        // Drop and restore using restoreFromSql directly
        static::$db->rawQuery('DROP TABLE IF EXISTS dump_test_default');
        static::$db->schema()->getDialect()->restoreFromSql(static::$db, $dump, false);

        // Verify table was restored
        $this->assertTrue(static::$db->schema()->tableExists('dump_test_default'));

        // Cleanup
        static::$db->rawQuery('DROP TABLE IF EXISTS dump_test_default');
    }

    public function testRestoreCombinedDumpsLikeExample(): void
    {
        // Simulate example: dump to files, then combine like in 04-dump-restore.php
        $usersDumpFile = sys_get_temp_dir() . '/users_dump_' . uniqid() . '.sql';
        $postsDumpFile = sys_get_temp_dir() . '/posts_dump_' . uniqid() . '.sql';

        // Dump to files (full dump with data, like in example)
        file_put_contents($usersDumpFile, DumpManager::dump(static::$db, 'dump_test_users'));
        file_put_contents($postsDumpFile, DumpManager::dump(static::$db, 'dump_test_posts'));

        // Combine like in example: file_get_contents() . "\n" . file_get_contents()
        $fullDump = file_get_contents($usersDumpFile) . "\n" . file_get_contents($postsDumpFile);

        // Create new database for restore
        $newDb = new PdoDb(
            'sqlsrv',
            [
                'host' => self::DB_HOST,
                'port' => self::DB_PORT,
                'username' => self::DB_USER,
                'password' => self::DB_PASSWORD,
                'dbname' => self::DB_NAME,
            ]
        );

        // Drop tables if exist
        $newDb->rawQuery('DROP TABLE IF EXISTS dump_test_posts');
        $newDb->rawQuery('DROP TABLE IF EXISTS dump_test_users');

        // Restore combined dump
        $newDb->schema()->getDialect()->restoreFromSql($newDb, $fullDump, false);

        // Verify tables were restored
        $this->assertTrue($newDb->schema()->tableExists('dump_test_users'));
        $this->assertTrue($newDb->schema()->tableExists('dump_test_posts'));

        // Verify data was restored
        $users = $newDb->rawQuery('SELECT * FROM dump_test_users ORDER BY id');
        $this->assertCount(2, $users);

        // Cleanup
        $newDb->rawQuery('DROP TABLE IF EXISTS dump_test_posts');
        $newDb->rawQuery('DROP TABLE IF EXISTS dump_test_users');
        unlink($usersDumpFile);
        unlink($postsDumpFile);
    }
}
