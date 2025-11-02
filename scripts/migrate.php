#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Migration CLI Tool.
 *
 * Usage:
 *   php scripts/migrate.php create <name>
 *   php scripts/migrate.php migrate [limit]
 *   php scripts/migrate.php migrate/up <version>
 *   php scripts/migrate.php migrate/down <version>
 *   php scripts/migrate.php rollback [limit]
 *   php scripts/migrate.php to <version>
 *   php scripts/migrate.php history [limit]
 *   php scripts/migrate.php new
 */

require_once __DIR__ . '/../vendor/autoload.php';

use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\migrations\MigrationRunner;
use tommyknocker\pdodb\PdoDb;

/**
 * Load database configuration from config files or environment.
 *
 * @return array<string, mixed>
 */
function loadDatabaseConfig(): array
{
    $driver = mb_strtolower(getenv('PDODB_DRIVER') ?: 'sqlite', 'UTF-8');
    $configFile = __DIR__ . "/../examples/config.{$driver}.php";

    // Try examples config first
    if (file_exists($configFile)) {
        return require $configFile;
    }

    // Try root config
    $rootConfig = __DIR__ . '/../config.php';
    if (file_exists($rootConfig)) {
        $config = require $rootConfig;
        return $config[$driver] ?? $config['sqlite'] ?? ['driver' => 'sqlite', 'path' => ':memory:'];
    }

    // Fallback to SQLite
    return ['driver' => 'sqlite', 'path' => ':memory:'];
}

/**
 * Get migration path from environment or default locations.
 *
 * @return string
 */
function getMigrationPath(): string
{
    // Check environment variable first
    $path = getenv('PDODB_MIGRATION_PATH');
    if ($path && is_dir($path)) {
        return $path;
    }

    // Check common locations
    $possiblePaths = [
        __DIR__ . '/../migrations',
        __DIR__ . '/../database/migrations',
        getcwd() . '/migrations',
        getcwd() . '/database/migrations',
    ];

    foreach ($possiblePaths as $path) {
        if (is_dir($path)) {
            return $path;
        }
    }

    // Create default migrations directory
    $defaultPath = __DIR__ . '/../migrations';
    if (!is_dir($defaultPath)) {
        mkdir($defaultPath, 0755, true);
    }

    return $defaultPath;
}

// Parse command line arguments
$command = $argv[1] ?? null;
$arg1 = $argv[2] ?? null;
$arg2 = $argv[3] ?? null;

if ($command === null) {
    echo "PDOdb Migration Tool\n\n";
    echo "Usage:\n";
    echo "  php scripts/migrate.php create <name>              Create a new migration\n";
    echo "  php scripts/migrate.php migrate [limit]             Apply new migrations\n";
    echo "  php scripts/migrate.php migrate/up <version>         Apply specific migration\n";
    echo "  php scripts/migrate.php migrate/down <version>      Rollback specific migration\n";
    echo "  php scripts/migrate.php rollback [limit]            Rollback last batch (default: 1)\n";
    echo "  php scripts/migrate.php to <version>                Migrate to specific version (or '0' for all)\n";
    echo "  php scripts/migrate.php history [limit]             Show migration history\n";
    echo "  php scripts/migrate.php new                          Show new (not applied) migrations\n";
    echo "\n";
    echo "Environment variables:\n";
    echo "  PDODB_DRIVER         Database driver (mysql, mariadb, pgsql, sqlite)\n";
    echo "  PDODB_MIGRATION_PATH Path to migration files directory\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php scripts/migrate.php create create_users_table\n";
    echo "  php scripts/migrate.php migrate\n";
    echo "  php scripts/migrate.php rollback 2\n";
    exit(1);
}

try {
    // Load database configuration
    $config = loadDatabaseConfig();
    $driver = $config['driver'];
    unset($config['driver']);

    $db = new PdoDb($driver, $config);
    $migrationPath = getMigrationPath();

    echo "Database: {$driver}\n";
    echo "Migrations path: {$migrationPath}\n\n";

    $runner = new MigrationRunner($db, $migrationPath);

    switch ($command) {
        case 'create':
            if (!$arg1) {
                echo "Error: Migration name is required\n";
                echo "Usage: php scripts/migrate.php create <name>\n";
                exit(1);
            }

            $filename = $runner->create($arg1);
            echo '✓ Migration file created: ' . basename($filename) . "\n";
            echo "  Path: {$filename}\n";
            break;

        case 'migrate':
            $limit = $arg1 ? (int)$arg1 : 0;
            echo "Applying migrations...\n";
            $applied = $runner->migrate($limit);

            if (empty($applied)) {
                echo "No new migrations to apply.\n";
            } else {
                echo '✓ Applied ' . count($applied) . " migration(s):\n";
                foreach ($applied as $version) {
                    echo "  - {$version}\n";
                }
            }
            break;

        case 'migrate/up':
            if (!$arg1) {
                echo "Error: Migration version is required\n";
                echo "Usage: php scripts/migrate.php migrate/up <version>\n";
                exit(1);
            }

            echo "Applying migration: {$arg1}\n";
            $runner->migrateUp($arg1);
            echo "✓ Migration applied successfully\n";
            break;

        case 'migrate/down':
            if (!$arg1) {
                echo "Error: Migration version is required\n";
                echo "Usage: php scripts/migrate.php migrate/down <version>\n";
                exit(1);
            }

            echo "Rolling back migration: {$arg1}\n";
            $runner->migrateDown($arg1);
            echo "✓ Migration rolled back successfully\n";
            break;

        case 'rollback':
            $limit = $arg1 ? (int)$arg1 : 1;
            echo "Rolling back last {$limit} migration(s)...\n";
            $rolledBack = $runner->rollback($limit);

            if (empty($rolledBack)) {
                echo "No migrations to rollback.\n";
            } else {
                echo '✓ Rolled back ' . count($rolledBack) . " migration(s):\n";
                foreach ($rolledBack as $version) {
                    echo "  - {$version}\n";
                }
            }
            break;

        case 'to':
            if (!$arg1) {
                echo "Error: Target version is required\n";
                echo "Usage: php scripts/migrate.php to <version>\n";
                echo "       Use '0' to rollback all migrations\n";
                exit(1);
            }

            echo "Migrating to version: {$arg1}\n";
            $runner->to($arg1);
            echo "✓ Migration completed\n";
            break;

        case 'history':
            $limit = $arg1 ? (int)$arg1 : null;
            $history = $runner->getMigrationHistory($limit);

            if (empty($history)) {
                echo "No migration history found.\n";
            } else {
                echo "Migration history:\n";
                echo str_repeat('-', 80) . "\n";
                printf("%-30s %-20s %s\n", 'Version', 'Applied', 'Batch');
                echo str_repeat('-', 80) . "\n";
                foreach ($history as $record) {
                    printf(
                        "%-30s %-20s %d\n",
                        $record['version'],
                        $record['apply_time'],
                        $record['batch']
                    );
                }
            }
            break;

        case 'new':
            $newMigrations = $runner->getNewMigrations();

            if (empty($newMigrations)) {
                echo "No new migrations found.\n";
            } else {
                echo 'Found ' . count($newMigrations) . " new migration(s):\n";
                foreach ($newMigrations as $version) {
                    echo "  - {$version}\n";
                }
            }
            break;

        default:
            echo "Unknown command: {$command}\n";
            echo "Run 'php scripts/migrate.php' for usage information.\n";
            exit(1);
    }
} catch (QueryException $e) {
    echo "Migration error: {$e->getMessage()}\n";
    if (getenv('DEBUG')) {
        echo "\nStack trace:\n{$e->getTraceAsString()}\n";
    }
    exit(1);
} catch (\Throwable $e) {
    echo "Error: {$e->getMessage()}\n";
    if (getenv('DEBUG')) {
        echo "\nStack trace:\n{$e->getTraceAsString()}\n";
    }
    exit(1);
}
