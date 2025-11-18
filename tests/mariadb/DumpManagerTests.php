<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

use tommyknocker\pdodb\cli\DumpManager;
use tommyknocker\pdodb\PdoDb;

/**
 * DumpManager tests for MariaDB.
 */
final class DumpManagerTests extends BaseMariaDBTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Create test tables with data
        static::$db->rawQuery('DROP TABLE IF EXISTS dump_test_posts');
        static::$db->rawQuery('DROP TABLE IF EXISTS dump_test_users');

        static::$db->rawQuery('CREATE TABLE dump_test_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(255) UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB');

        static::$db->rawQuery('CREATE TABLE dump_test_posts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            content TEXT,
            FOREIGN KEY (user_id) REFERENCES dump_test_users(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id),
            INDEX idx_title (title)
        ) ENGINE=InnoDB');

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
        $dumpFile = sys_get_temp_dir() . '/pdodb_mariadb_restore_' . uniqid() . '.sql';
        file_put_contents($dumpFile, $sql);

        // Create new database for restore
        $newDb = new PdoDb(
            'mariadb',
            [
                'host' => self::DB_HOST,
                'port' => self::DB_PORT,
                'username' => self::DB_USER,
                'password' => self::DB_PASSWORD,
                'dbname' => self::DB_NAME,
                'charset' => self::DB_CHARSET,
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
        // MariaDB's SHOW CREATE TABLE includes indexes in the CREATE TABLE statement
        $this->assertStringContainsString('dump_test_posts', $sql);
        $this->assertStringContainsString('dump_test_users', $sql);
        // Check that indexes are present (either in CREATE TABLE or as separate statements)
        $this->assertTrue(
            str_contains($sql, 'idx_user_id') || str_contains($sql, 'KEY `idx_user_id`') || str_contains($sql, 'INDEX `idx_user_id`'),
            'Index idx_user_id should be present in dump'
        );
    }
}
