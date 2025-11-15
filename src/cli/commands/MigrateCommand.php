<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\MigrationGenerator;
use tommyknocker\pdodb\migrations\MigrationRunner;

/**
 * Migrate command for managing database migrations.
 */
class MigrateCommand extends Command
{
    /**
     * Create migrate command.
     */
    public function __construct()
    {
        parent::__construct('migrate', 'Manage database migrations');
    }

    /**
     * Execute command.
     *
     * @return int Exit code
     */
    public function execute(): int
    {
        $subcommand = $this->getArgument(0);

        if ($subcommand === null) {
            $this->showHelp();
            return 0;
        }

        return match ($subcommand) {
            'create' => $this->create(),
            'up', 'migrate' => $this->up(),
            'down', 'rollback' => $this->down(),
            'history' => $this->history(),
            'new' => $this->new(),
            '--help', 'help' => $this->showHelp(),
            default => $this->showError("Unknown subcommand: {$subcommand}"),
        };
    }

    /**
     * Create new migration.
     *
     * @return int Exit code
     */
    protected function create(): int
    {
        $name = $this->getArgument(1);

        try {
            $filename = MigrationGenerator::generate($name);
            // Output is already displayed by MigrationGenerator::generate()
            return 0;
        } catch (\Exception $e) {
            $this->showError($e->getMessage());
            // @phpstan-ignore-next-line
            return 1;
        }
    }

    /**
     * Run migrations.
     *
     * @return int Exit code
     */
    protected function up(): int
    {
        $migrationPath = MigrationGenerator::getMigrationPath();
        $runner = new MigrationRunner($this->getDb(), $migrationPath);

        // Handle options
        $dryRun = $this->getOption('dry-run', false);
        $pretend = $this->getOption('pretend', false);

        if ($dryRun) {
            $runner->setDryRun(true);
        }
        if ($pretend) {
            $runner->setPretend(true);
        }

        $limit = (int)($this->getArgument(1) ?? 0);
        $applied = $runner->migrate($limit);
        $count = count($applied);

        if ($count > 0) {
            if ($dryRun || $pretend) {
                $mode = $dryRun ? 'DRY-RUN' : 'PRETEND';
                static::info("{$mode} mode: Would apply {$count} migration(s)");
                foreach ($applied as $version) {
                    echo "  - {$version}\n";
                }

                // Show collected queries for dry-run
                if ($dryRun) {
                    $queries = $runner->getCollectedQueries();
                    if (!empty($queries)) {
                        echo "\nSQL that would be executed:\n";
                        foreach ($queries as $query) {
                            echo "  {$query}\n";
                        }
                    }
                }
            } else {
                static::success("Applied {$count} migration(s)");
                foreach ($applied as $version) {
                    echo "  - {$version}\n";
                }
            }
        } else {
            static::info('No new migrations to apply');
        }
        return 0;
    }

    /**
     * Rollback migrations.
     *
     * @return int Exit code
     */
    protected function down(): int
    {
        $migrationPath = MigrationGenerator::getMigrationPath();
        $runner = new MigrationRunner($this->getDb(), $migrationPath);

        // Handle options
        $dryRun = $this->getOption('dry-run', false);
        $pretend = $this->getOption('pretend', false);
        $force = $this->getOption('force', false);

        if ($dryRun) {
            $runner->setDryRun(true);
        }
        if ($pretend) {
            $runner->setPretend(true);
        }

        $step = (int)($this->getArgument(1) ?? 1);

        // Ask for confirmation unless --force is used
        if (!$force) {
            $confirmed = static::readConfirmation(
                "Are you sure you want to rollback {$step} migration(s)? This action cannot be undone",
                false
            );

            if (!$confirmed) {
                static::info('Operation cancelled');
                return 0;
            }
        }

        $rolledBack = $runner->migrateDown($step);
        $count = count($rolledBack);

        if ($count > 0) {
            if ($dryRun || $pretend) {
                $mode = $dryRun ? 'DRY-RUN' : 'PRETEND';
                static::info("{$mode} mode: Would rollback {$count} migration(s)");
                foreach ($rolledBack as $version) {
                    echo "  - {$version}\n";
                }

                // Show collected queries for dry-run
                if ($dryRun) {
                    $queries = $runner->getCollectedQueries();
                    if (!empty($queries)) {
                        echo "\nSQL that would be executed:\n";
                        foreach ($queries as $query) {
                            echo "  {$query}\n";
                        }
                    }
                }
            } else {
                static::success("Rolled back {$count} migration(s)");
                foreach ($rolledBack as $version) {
                    echo "  - {$version}\n";
                }
            }
        } else {
            static::info('No migrations to rollback');
        }

        return 0;
    }

    /**
     * Show migration history.
     *
     * @return int Exit code
     */
    protected function history(): int
    {
        $migrationPath = MigrationGenerator::getMigrationPath();
        $runner = new MigrationRunner($this->getDb(), $migrationPath);
        $limit = $this->getArgument(1) !== null ? (int)$this->getArgument(1) : null;
        $history = $runner->getMigrationHistory($limit);

        if (empty($history)) {
            static::info('No migrations found');
            return 0;
        }

        echo "Migration History:\n\n";
        foreach ($history as $migration) {
            echo "  {$migration['version']} - {$migration['apply_time']}\n";
        }

        return 0;
    }

    /**
     * Show new migrations.
     *
     * @return int Exit code
     */
    protected function new(): int
    {
        $migrationPath = MigrationGenerator::getMigrationPath();
        $runner = new MigrationRunner($this->getDb(), $migrationPath);
        $new = $runner->getNewMigrations();

        if (empty($new)) {
            static::info('No new migrations found');
            return 0;
        }

        echo "New Migrations:\n\n";
        foreach ($new as $migration) {
            echo "  {$migration}\n";
        }

        return 0;
    }

    /**
     * Show help message.
     *
     * @return int Exit code
     */
    protected function showHelp(): int
    {
        echo "Migration Management\n\n";
        echo "Usage: pdodb migrate <subcommand> [arguments] [options]\n\n";
        echo "Subcommands:\n";
        echo "  create [name]     Create a new migration file\n";
        echo "  up, migrate       Apply pending migrations\n";
        echo "  down, rollback    Rollback the last migration\n";
        echo "  history           Show migration history\n";
        echo "  new               Show new migrations\n";
        echo "  help              Show this help message\n\n";
        echo "Options:\n";
        echo "  --dry-run         Show SQL without executing\n";
        echo "  --force           Execute without confirmation (for rollback)\n";
        echo "  --pretend         Simulate execution\n\n";
        echo "Examples:\n";
        echo "  pdodb migrate up --dry-run\n";
        echo "  pdodb migrate down 2 --force\n";
        echo "  pdodb migrate up --pretend\n";
        return 0;
    }
}
