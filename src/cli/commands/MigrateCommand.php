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
        ob_start();

        try {
            MigrationGenerator::generate($name);
            ob_end_clean();
            return 0;
        } catch (\Exception $e) {
            ob_end_clean();
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
        $limit = (int)($this->getArgument(1) ?? 0);
        $applied = $runner->migrate($limit);
        $count = count($applied);
        if ($count > 0) {
            static::success("Applied {$count} migration(s)");
            foreach ($applied as $version) {
                echo "  - {$version}\n";
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
        $step = (int)($this->getArgument(1) ?? 1);
        $rolledBack = $runner->migrateDown($step);
        $count = count($rolledBack);
        static::success("Rolled back {$count} migration(s)");
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
        echo "  help              Show this help message\n";
        return 0;
    }
}
