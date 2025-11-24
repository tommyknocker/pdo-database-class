<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\migrations;

/**
 * Migration: add_posts_table
 *
 * Created: 2025_11_23_180232_add_posts_table
 */
class m20251123180232AddPostsTable extends Migration
{
    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $this->schema()->createTable('test_posts', [
        'id' => $this->schema()->primaryKey(),
        'user_id' => $this->schema()->integer()->notNull(),
        'title' => $this->schema()->string(255)->notNull(),
        'content' => $this->schema()->text(),
    ]);
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        $this->schema()->dropTable('test_posts');
    }
}
