<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\cli\DumpManager;

/**
 * Dump and restore on Oracle: quoting, NULLs, dates, key constraints and a full round trip.
 */
final class DumpManagerTests extends BaseOracleTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->dropTables();
        self::$db->rawQuery('CREATE TABLE dump_users (id NUMBER(10) PRIMARY KEY, name VARCHAR2(100) NOT NULL, email VARCHAR2(255), note VARCHAR2(200), joined DATE)');
        self::$db->rawQuery('CREATE TABLE dump_posts (id NUMBER(10) PRIMARY KEY, user_id NUMBER(10) NOT NULL, title VARCHAR2(255) NOT NULL, CONSTRAINT dump_posts_user_fk FOREIGN KEY (user_id) REFERENCES dump_users(id))');
        self::$db->rawQuery("INSERT INTO dump_users (id, name, email, note, joined) VALUES (1, 'John Doe', 'john@example.com', 'it''s fine', DATE '2026-01-15')");
        self::$db->rawQuery("INSERT INTO dump_users (id, name, email, note, joined) VALUES (2, 'Jane', NULL, NULL, NULL)");
        self::$db->rawQuery("INSERT INTO dump_posts (id, user_id, title) VALUES (1, 1, 'First Post')");
    }

    public function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    private function dropTables(): void
    {
        foreach (['dump_posts', 'dump_users'] as $table) {
            try {
                self::$db->rawQuery("DROP TABLE {$table} CASCADE CONSTRAINTS PURGE");
            } catch (\Throwable) {
                // not there yet
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function users(): array
    {
        return array_map(
            static fn (array $row): array => array_change_key_case($row, CASE_LOWER),
            self::$db->find()->from('dump_users')->orderBy('id')->get()
        );
    }

    public function testDumpContainsSchemaAndData(): void
    {
        $sql = DumpManager::dump(self::$db, 'dump_users');

        $this->assertStringContainsString('CREATE TABLE "DUMP_USERS"', $sql);
        $this->assertStringContainsString('PRIMARY KEY', $sql);
        $this->assertStringContainsString("'it''s fine'", $sql);
        $this->assertStringContainsString('NULL', $sql);
        $this->assertStringNotContainsString('dump_posts', strtolower($sql));
    }

    public function testSchemaOnlyHasNoRowsAndDataOnlyHasNoTables(): void
    {
        $schema = DumpManager::dump(self::$db, 'dump_users', true, false);
        $data = DumpManager::dump(self::$db, 'dump_users', false, true);

        $this->assertStringContainsString('CREATE TABLE', $schema);
        $this->assertStringNotContainsString('INSERT', $schema);
        $this->assertStringNotContainsString('CREATE TABLE', $data);
        $this->assertStringContainsString('INSERT', $data);
    }

    public function testRoundTripRestoresTheRows(): void
    {
        $before = $this->users();
        $file = sys_get_temp_dir() . '/oracle_dump_' . bin2hex(random_bytes(4)) . '.sql';

        try {
            file_put_contents($file, DumpManager::dump(self::$db, 'dump_users'));
            self::$db->rawQuery('DROP TABLE dump_posts PURGE');
            self::$db->rawQuery('DROP TABLE dump_users PURGE');

            DumpManager::restore(self::$db, $file);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $after = $this->users();
        $this->assertCount(2, $after);
        $this->assertSame($before[0]['name'], $after[0]['name']);
        $this->assertSame("it's fine", $after[0]['note']);
        $this->assertNull($after[1]['email']);
        $this->assertNull($after[1]['joined']);
        $this->assertStringStartsWith('2026-01-15', (string) $after[0]['joined']);
    }
}
