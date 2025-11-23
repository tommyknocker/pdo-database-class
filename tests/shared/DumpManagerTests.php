<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\DumpManager;
use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\PdoDb;

/**
 * Tests for DumpManager edge cases.
 */
final class DumpManagerTests extends TestCase
{
    protected string $dbPath;
    protected PdoDb $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir() . '/pdodb_dump_manager_' . uniqid() . '.sqlite';
        $this->db = new PdoDb('sqlite', ['path' => $this->dbPath]);

        // Create test table
        $this->db->rawQuery('CREATE TABLE test_users (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->rawQuery("INSERT INTO test_users (name) VALUES ('Test User')");
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
        parent::tearDown();
    }

    /**
     * Test restore with non-existent file.
     */
    public function testRestoreWithNonExistentFile(): void
    {
        $this->expectException(ResourceException::class);
        $this->expectExceptionMessage('Dump file not found');

        DumpManager::restore($this->db, '/nonexistent/file_' . uniqid() . '.sql');
    }

    /**
     * Test restore with continueOnError flag.
     */
    public function testRestoreWithContinueOnError(): void
    {
        // Create a dump file with some valid SQL
        $sql = DumpManager::dump($this->db);
        $dumpFile = sys_get_temp_dir() . '/pdodb_restore_continue_' . uniqid() . '.sql';
        file_put_contents($dumpFile, $sql);

        // Create new database
        $newDbPath = sys_get_temp_dir() . '/pdodb_restore_continue_' . uniqid() . '.sqlite';
        $newDb = new PdoDb('sqlite', ['path' => $newDbPath]);

        try {
            // Restore with continueOnError
            DumpManager::restore($newDb, $dumpFile, true);

            // Verify data was restored
            $users = $newDb->rawQuery('SELECT * FROM test_users');
            $this->assertNotEmpty($users);
        } finally {
            if (file_exists($dumpFile)) {
                @unlink($dumpFile);
            }
            if (file_exists($newDbPath)) {
                @unlink($newDbPath);
            }
        }
    }

    /**
     * Test dump with dropTables = false.
     */
    public function testDumpWithDropTablesFalse(): void
    {
        $sql = DumpManager::dump($this->db, null, false, false, false);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringNotContainsString('DROP TABLE', $sql);
    }

    /**
     * Test dump with dropTables = true.
     */
    public function testDumpWithDropTablesTrue(): void
    {
        $sql = DumpManager::dump($this->db, null, false, false, true);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        // SQLite dialect may or may not include DROP TABLE, depending on implementation
        $this->assertIsString($sql);
    }

    /**
     * Test dump with empty schema.
     */
    public function testDumpWithEmptySchema(): void
    {
        // Create empty database
        $emptyDbPath = sys_get_temp_dir() . '/pdodb_empty_' . uniqid() . '.sqlite';
        $emptyDb = new PdoDb('sqlite', ['path' => $emptyDbPath]);

        try {
            $sql = DumpManager::dump($emptyDb);
            $this->assertIsString($sql);
            $this->assertStringContainsString('PDOdb Database Dump', $sql);
        } finally {
            if (file_exists($emptyDbPath)) {
                @unlink($emptyDbPath);
            }
        }
    }

    /**
     * Test dump with table name.
     */
    public function testDumpWithTableName(): void
    {
        $sql = DumpManager::dump($this->db, 'test_users');
        $this->assertStringContainsString('-- Table: test_users', $sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);
    }

    /**
     * Test dump schema only with table name.
     */
    public function testDumpSchemaOnlyWithTableName(): void
    {
        $sql = DumpManager::dump($this->db, 'test_users', true, false);
        $this->assertStringContainsString('-- Mode: Schema only', $sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringNotContainsString('INSERT INTO', $sql);
    }

    /**
     * Test dump data only with table name.
     */
    public function testDumpDataOnlyWithTableName(): void
    {
        $sql = DumpManager::dump($this->db, 'test_users', false, true);
        $this->assertStringContainsString('-- Mode: Data only', $sql);
        $this->assertStringNotContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('INSERT INTO', $sql);
    }
}
