<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\migrations;

/**
 * Interface for database migrations.
 *
 * All migration classes must implement this interface.
 */
interface MigrationInterface
{
    /**
     * Apply the migration.
     *
     * This method should contain the code to apply the migration.
     */
    public function up(): void;

    /**
     * Revert the migration.
     *
     * This method should contain the code to revert the migration.
     */
    public function down(): void;
}
