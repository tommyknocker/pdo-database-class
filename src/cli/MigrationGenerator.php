<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\migrations\MigrationRunner;

/**
 * Interactive migration generator.
 *
 * Provides interactive prompts for creating migrations with helpful suggestions.
 */
class MigrationGenerator extends BaseCliCommand
{
    /**
     * Generate a migration interactively.
     *
     * @param string|null $name Migration name (optional, will prompt if not provided)
     * @param string|null $migrationPath Migration path (optional)
     *
     * @return string Path to created migration file
     */
    public static function generate(?string $name = null, ?string $migrationPath = null): string
    {
        $db = static::createDatabase();
        $driver = static::getDriverName($db);

        static::cliWriteln('PDOdb Migration Generator');
        static::cliWriteln("Database: {$driver}\n");

        // Get migration path
        if ($migrationPath === null) {
            $migrationPath = static::getMigrationPath();
        }

        static::cliWriteln("Migrations path: {$migrationPath}\n");

        // Get migration name
        if ($name === null) {
            $name = static::readInput('Enter migration name');
            if ($name === '') {
                static::error('Migration name is required');
            }
        }

        // Suggest migration type based on name
        $suggestions = static::suggestMigrationType($name);
        if (!empty($suggestions)) {
            static::cliWriteln("\nSuggested migration types:");
            foreach ($suggestions as $i => $suggestion) {
                static::cliWrite('  ' . ($i + 1) . ". {$suggestion}\n");
            }
            static::cliWriteln('  0. Custom (manual)');
            $choice = static::readInput("\nSelect migration type", '0');
            $selectedIndex = (int)$choice - 1;
            if ($selectedIndex >= 0 && $selectedIndex < count($suggestions)) {
                $migrationType = $suggestions[$selectedIndex];
                static::info("Selected: {$migrationType}");
            }
        }

        $runner = new MigrationRunner($db, $migrationPath);
        $filename = $runner->create($name);

        static::success('Migration file created: ' . basename($filename));
        static::cliWriteln("  Path: {$filename}");

        return $filename;
    }

    /**
     * Suggest migration type based on name.
     *
     * @param string $name Migration name
     *
     * @return array<int, string>
     */
    protected static function suggestMigrationType(string $name): array
    {
        $nameLower = strtolower($name);
        $suggestions = [];

        if (str_contains($nameLower, 'create') && str_contains($nameLower, 'table')) {
            $suggestions[] = 'create_table';
        } elseif (str_contains($nameLower, 'add') && str_contains($nameLower, 'column')) {
            $suggestions[] = 'add_column';
        } elseif (str_contains($nameLower, 'drop') && str_contains($nameLower, 'column')) {
            $suggestions[] = 'drop_column';
        } elseif (str_contains($nameLower, 'add') && str_contains($nameLower, 'index')) {
            $suggestions[] = 'add_index';
        } elseif (str_contains($nameLower, 'add') && str_contains($nameLower, 'foreign')) {
            $suggestions[] = 'add_foreign_key';
        }

        return $suggestions;
    }

    /**
     * Get migration path from environment or default locations.
     *
     * @return string
     */
    public static function getMigrationPath(): string
    {
        // Check environment variable first
        $path = getenv('PDODB_MIGRATION_PATH');
        if ($path && is_dir($path)) {
            return $path;
        }

        // Use current working directory (user's project root)
        $cwd = getcwd();
        if ($cwd === false) {
            $cwd = __DIR__ . '/../../..';
        }

        // Check common locations in user's project
        $possiblePaths = [
            $cwd . '/migrations',
            $cwd . '/database/migrations',
            // Fallback to library directory (for development)
            __DIR__ . '/../../migrations',
            __DIR__ . '/../../database/migrations',
        ];

        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        // Create default migrations directory in user's project
        $defaultPath = $cwd . '/migrations';
        if (!is_dir($defaultPath)) {
            mkdir($defaultPath, 0755, true);
        }

        return $defaultPath;
    }
}
