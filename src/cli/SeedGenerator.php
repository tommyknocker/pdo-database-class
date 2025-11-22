<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

use tommyknocker\pdodb\seeds\SeedRunner;

/**
 * Interactive seed generator.
 *
 * Provides interactive prompts for creating seeds with helpful suggestions.
 */
class SeedGenerator extends BaseCliCommand
{
    /**
     * Generate a seed interactively.
     *
     * @param string|null $name Seed name (optional, will prompt if not provided)
     * @param string|null $seedPath Seed path (optional)
     *
     * @return string Path to created seed file
     */
    public static function generate(?string $name = null, ?string $seedPath = null): string
    {
        $db = static::createDatabase();
        $driver = static::getDriverName($db);

        echo "PDOdb Seed Generator\n";
        echo "Database: {$driver}\n\n";

        // Get seed path
        if ($seedPath === null) {
            $seedPath = static::getSeedPath();
        }

        echo "Seeds path: {$seedPath}\n\n";

        // Get seed name
        if ($name === null) {
            $name = static::readInput('Enter seed name');
            if ($name === '') {
                static::error('Seed name is required');
            }
        }

        // Suggest seed type based on name (skip in non-interactive mode)
        $nonInteractive = getenv('PDODB_NON_INTERACTIVE') !== false
            || getenv('PHPUNIT') !== false
            || !stream_isatty(STDIN);

        if (!$nonInteractive) {
            $suggestions = static::suggestSeedType($name);
            if (!empty($suggestions)) {
                echo "\nSuggested seed types:\n";
                foreach ($suggestions as $i => $suggestion) {
                    echo '  ' . ($i + 1) . ". {$suggestion}\n";
                }
                echo "  0. Custom (manual)\n";
                $choice = static::readInput("\nSelect seed type", '0');
                $selectedIndex = (int)$choice - 1;
                if ($selectedIndex >= 0 && $selectedIndex < count($suggestions)) {
                    $seedType = $suggestions[$selectedIndex];
                    static::info("Selected: {$seedType}");
                }
            }
        }

        $runner = new SeedRunner($db, $seedPath);
        $filename = $runner->create($name);

        static::success('Seed file created: ' . basename($filename));
        echo "  Path: {$filename}\n";

        return $filename;
    }

    /**
     * Suggest seed type based on name.
     *
     * @param string $name Seed name
     *
     * @return array<int, string>
     */
    protected static function suggestSeedType(string $name): array
    {
        $nameLower = strtolower($name);
        $suggestions = [];

        if (str_contains($nameLower, 'user')) {
            $suggestions[] = 'users_table';
        } elseif (str_contains($nameLower, 'admin')) {
            $suggestions[] = 'admin_users';
        } elseif (str_contains($nameLower, 'category') || str_contains($nameLower, 'categories')) {
            $suggestions[] = 'categories_table';
        } elseif (str_contains($nameLower, 'product')) {
            $suggestions[] = 'products_table';
        } elseif (str_contains($nameLower, 'role')) {
            $suggestions[] = 'roles_and_permissions';
        } elseif (str_contains($nameLower, 'setting')) {
            $suggestions[] = 'application_settings';
        } elseif (str_contains($nameLower, 'test') || str_contains($nameLower, 'demo')) {
            $suggestions[] = 'test_data';
        }

        return $suggestions;
    }

    /**
     * Get seed path from environment or default locations.
     *
     * @return string
     */
    public static function getSeedPath(): string
    {
        // Check environment variable first
        $path = getenv('PDODB_SEED_PATH');
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
            $cwd . '/seeds',
            $cwd . '/database/seeds',
            $cwd . '/database/seeders',
            // Fallback to library directory (for development)
            __DIR__ . '/../../seeds',
            __DIR__ . '/../../database/seeds',
        ];

        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        // Create default seeds directory in user's project
        $defaultPath = $cwd . '/seeds';
        if (!is_dir($defaultPath)) {
            mkdir($defaultPath, 0755, true);
        }

        return $defaultPath;
    }
}
