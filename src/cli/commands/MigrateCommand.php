<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\MigrationGenerator;
use tommyknocker\pdodb\cli\traits\SubcommandSuggestionTrait;
use tommyknocker\pdodb\migrations\MigrationRunner;

/**
 * Migrate command for managing database migrations.
 */
class MigrateCommand extends Command
{
    use SubcommandSuggestionTrait;

    /**
     * Create migrate command.
     */
    public function __construct()
    {
        parent::__construct('migrate', 'Manage database migrations');
    }

    /**
     * Get available subcommands.
     *
     * @return array<string>
     */
    protected function getAvailableSubcommands(): array
    {
        return ['create', 'up', 'migrate', 'down', 'rollback', 'history', 'new', 'help'];
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
            default => $this->showSubcommandError($subcommand),
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
        $force = $this->getOption('force', false);

        if ($dryRun) {
            $runner->setDryRun(true);
        }
        if ($pretend) {
            $runner->setPretend(true);
        }

        $limit = (int)($this->getArgument(1) ?? 0);
        $newMigrations = $runner->getNewMigrations();

        // Ask for confirmation unless --force is used or no migrations to apply
        if (!empty($newMigrations) && !$force && !$dryRun && !$pretend) {
            $count = count($newMigrations);
            $migrationsToApply = $limit > 0 ? array_slice($newMigrations, 0, $limit) : $newMigrations;
            $limitText = $limit > 0 ? " {$limit}" : '';

            // Show migrations that will be applied
            $this->writeln("\nMigrations to be applied:");
            foreach ($migrationsToApply as $version) {
                $this->writeln("  - {$version}");
            }
            $this->writeln('');

            $confirmed = static::readConfirmation(
                "Are you sure you want to apply{$limitText} migration(s)? This will modify your database",
                true
            );

            if (!$confirmed) {
                static::info('Operation cancelled');
                return 0;
            }
        }

        $applied = $runner->migrate($limit);
        $count = count($applied);

        if ($count > 0) {
            if ($dryRun || $pretend) {
                $mode = $dryRun ? 'DRY-RUN' : 'PRETEND';
                static::info("{$mode} mode: Would apply {$count} migration(s)");
                foreach ($applied as $version) {
                    $this->writeln("  - {$version}");
                }

                // Show collected queries for dry-run
                if ($dryRun) {
                    $queries = $runner->getCollectedQueries();
                    if (!empty($queries)) {
                        $this->writeln("\nSQL that would be executed:");
                        foreach ($queries as $query) {
                            $this->writeln("  {$query}");
                        }
                    }
                }
            } else {
                static::success("Applied {$count} migration(s)");
                foreach ($applied as $version) {
                    $this->writeln("  - {$version}");
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
            // Get migrations that will be rolled back
            $history = $runner->getMigrationHistory($step);
            $migrationsToRollback = array_column($history, 'version');

            // Show migrations that will be rolled back
            if (!empty($migrationsToRollback)) {
                $this->writeln("\nMigrations to be rolled back:");
                foreach ($migrationsToRollback as $version) {
                    $this->writeln("  - {$version}");
                }
                $this->writeln('');
            }

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
                    $this->writeln("  - {$version}");
                }

                // Show collected queries for dry-run
                if ($dryRun) {
                    $queries = $runner->getCollectedQueries();
                    if (!empty($queries)) {
                        $this->writeln("\nSQL that would be executed:");
                        foreach ($queries as $query) {
                            $this->writeln("  {$query}");
                        }
                    }
                }
            } else {
                static::success("Rolled back {$count} migration(s)");
                foreach ($rolledBack as $version) {
                    $this->writeln("  - {$version}");
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

        $this->writeln("Migration History:\n");
        foreach ($history as $migration) {
            $this->writeln("  {$migration['version']} - {$migration['apply_time']}");
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

        $this->writeln("New Migrations:\n");
        foreach ($new as $migration) {
            $this->writeln("  {$migration}");
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
        $this->writeln("Migration Management\n");
        $this->writeln("Usage: pdodb migrate <subcommand> [arguments] [options]\n");
        $this->writeln('Subcommands:');
        $this->writeln('  create [name]     Create a new migration file');
        $this->writeln('  up, migrate       Apply pending migrations');
        $this->writeln('  down, rollback    Rollback the last migration');
        $this->writeln('  history           Show migration history');
        $this->writeln('  new               Show new migrations');
        $this->writeln("  help              Show this help message\n");
        $this->writeln('Options:');
        $this->writeln('  --dry-run         List operations without executing migration PHP');
        $this->writeln('  --force           Execute without confirmation');
        $this->writeln("  --pretend         Simulate execution\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb migrate up --dry-run');
        $this->writeln('  pdodb migrate up --force');
        $this->writeln('  pdodb migrate down 2 --force');
        $this->writeln('  pdodb migrate up --pretend');
        return 0;
    }
}
