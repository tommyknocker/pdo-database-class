<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\seeds;

/**
 * Interface for database seeds.
 *
 * All seed classes must implement this interface.
 */
interface SeedInterface
{
    /**
     * Run the seed.
     *
     * This method should contain the code to populate the database with data.
     */
    public function run(): void;

    /**
     * Rollback the seed.
     *
     * This method should contain the code to remove the seeded data.
     */
    public function rollback(): void;
}
