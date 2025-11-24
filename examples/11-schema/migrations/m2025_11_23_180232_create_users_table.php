<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\migrations;

/**
 * Migration: create_users_table
 *
 * Created: 2025_11_23_180232_create_users_table
 */
class m20251123180232CreateUsersTable extends Migration
{
    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $this->schema()->createTable('test_users', [
        'id' => $this->schema()->primaryKey(),
        'username' => $this->schema()->string(100)->notNull(),
        'email' => $this->schema()->string(255)->notNull(),
    ]);
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $this->schema()->dropTable('test_users');
    }
}
