<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use RuntimeException;

/**
 * SQLite-specific tests for UPDATE/DELETE with JOIN functionality.
 */
final class UpdateDeleteJoinTests extends BaseSqliteTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Create tables for UPDATE/DELETE with JOIN tests
        self::$db->rawQuery('DROP TABLE IF EXISTS update_delete_join_orders');
        self::$db->rawQuery('DROP TABLE IF EXISTS update_delete_join_users');

        self::$db->rawQuery('
            CREATE TABLE update_delete_join_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                status TEXT,
                balance NUMERIC(10,2) DEFAULT 0
            )
        ');

        self::$db->rawQuery('
            CREATE TABLE update_delete_join_orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                amount NUMERIC(10,2),
                status TEXT
            )
        ');
    }

    public function testUpdateWithJoinThrowsException(): void
    {
        // SQLite doesn't support JOIN in UPDATE statements
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JOIN in UPDATE statements is not supported by sqlite dialect');

        self::$db->find()
            ->table('update_delete_join_users')
            ->join('update_delete_join_orders', 'update_delete_join_orders.user_id = update_delete_join_users.id')
            ->update(['status' => 'updated']);
    }

    public function testDeleteWithJoinThrowsException(): void
    {
        // SQLite doesn't support JOIN in DELETE statements
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JOIN in DELETE statements is not supported by sqlite dialect');

        self::$db->find()
            ->table('update_delete_join_users')
            ->join('update_delete_join_orders', 'update_delete_join_orders.user_id = update_delete_join_users.id')
            ->delete();
    }
}
