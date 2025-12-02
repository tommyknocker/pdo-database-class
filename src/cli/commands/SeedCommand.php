<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\SeedGenerator;
use tommyknocker\pdodb\cli\traits\SubcommandSuggestionTrait;
use tommyknocker\pdodb\seeds\SeedRunner;

/**
 * Seed command for managing database seeds.
 */
class SeedCommand extends Command
{
    use SubcommandSuggestionTrait;

    /**
     * Create seed command.
     */
    public function __construct()
    {
        parent::__construct('seed', 'Manage database seeds');
    }

    /**
     * Get available subcommands.
     *
     * @return array<string>
     */
    protected function getAvailableSubcommands(): array
    {
        return ['create', 'run', 'list', 'rollback', 'help'];
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
            'run' => $this->run(),
            'list' => $this->list(),
            'rollback' => $this->rollback(),
            '--help', 'help' => $this->showHelp(),
            default => $this->showSubcommandError($subcommand),
        };
    }

    /**
     * Create new seed.
     *
     * @return int Exit code
     */
    protected function create(): int
    {
        $name = $this->getArgument(1);

        try {
            $filename = SeedGenerator::generate($name);
            // Output is already displayed by SeedGenerator::generate()
            return 0;
        } catch (\Exception $e) {
            $this->showError($e->getMessage());
        }
    }

    /**
     * Run seeds.
     *
     * @return int Exit code
     */
    protected function run(): int
    {
        $seedPath = SeedGenerator::getSeedPath();
        $runner = new SeedRunner($this->getDb(), $seedPath);

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

        $seedName = $this->getArgument(1);

        try {
            if ($seedName !== null) {
                // Run specific seed
                $newSeeds = [$seedName];
                $executedSeeds = $runner->getExecutedSeeds();
                if (in_array($seedName, $executedSeeds, true)) {
                    if (!$force && !$dryRun && !$pretend) {
                        $confirmed = static::readConfirmation(
                            "Seed '{$seedName}' has already been executed. Re-run it?",
                            false
                        );
                        if (!$confirmed) {
                            static::info('Seed execution cancelled.');
                            return 0;
                        }
                    }
                }
            } else {
                // Run all new seeds
                $newSeeds = $runner->getNewSeeds();
                if (empty($newSeeds)) {
                    static::info('No new seeds to run.');
                    return 0;
                }

                // Ask for confirmation unless --force is used or in dry-run/pretend mode
                if (!$force && !$dryRun && !$pretend) {
                    $count = count($newSeeds);
                    $confirmed = static::readConfirmation(
                        "Are you sure you want to run {$count} seed(s)? This will modify your database",
                        true
                    );
                    if (!$confirmed) {
                        static::info('Seed execution cancelled.');
                        return 0;
                    }
                }
            }

            $executed = $runner->run($seedName);

            if ($dryRun || $pretend) {
                $queries = $runner->getCollectedQueries();
                if (!empty($queries)) {
                    echo "\n" . implode("\n", $queries) . "\n";
                }
                $mode = $dryRun ? 'dry-run' : 'pretend';
                static::info("Seed execution completed ({$mode} mode).");
            } else {
                $count = count($executed);
                if ($count > 0) {
                    static::success("Successfully executed {$count} seed(s):");
                    if (getenv('PHPUNIT') === false) {
                        foreach ($executed as $seedName) {
                            echo "  - {$seedName}\n";
                        }
                    }
                } else {
                    static::info('No seeds were executed.');
                }
            }

            return 0;
        } catch (\Exception $e) {
            $this->showError($e->getMessage());
        }
    }

    /**
     * List seeds.
     *
     * @return int Exit code
     */
    protected function list(): int
    {
        $seedPath = SeedGenerator::getSeedPath();
        $runner = new SeedRunner($this->getDb(), $seedPath);

        try {
            $allSeeds = $runner->getAllSeeds();
            $executedSeeds = $runner->getExecutedSeeds();
            $newSeeds = $runner->getNewSeeds();

            echo "Seeds Status:\n";
            echo "=============\n\n";

            if (empty($allSeeds)) {
                echo "No seed files found in: {$seedPath}\n";
                return 0;
            }

            echo "Path: {$seedPath}\n\n";

            foreach ($allSeeds as $seed) {
                $status = in_array($seed, $executedSeeds, true) ? '[EXECUTED]' : '[PENDING]';
                $color = in_array($seed, $executedSeeds, true) ? 'green' : 'yellow';
                echo "{$status} {$seed}\n";
            }

            echo "\nSummary:\n";
            echo '  Total seeds: ' . count($allSeeds) . "\n";
            echo '  Executed: ' . count($executedSeeds) . "\n";
            echo '  Pending: ' . count($newSeeds) . "\n";

            return 0;
        } catch (\Exception $e) {
            $this->showError($e->getMessage());
        }
    }

    /**
     * Rollback seeds.
     *
     * @return int Exit code
     */
    protected function rollback(): int
    {
        $seedPath = SeedGenerator::getSeedPath();
        $runner = new SeedRunner($this->getDb(), $seedPath);

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

        $seedName = $this->getArgument(1);

        try {
            if ($seedName !== null) {
                // Rollback specific seed
                $executedSeeds = $runner->getExecutedSeeds();
                if (!in_array($seedName, $executedSeeds, true)) {
                    $this->showError("Seed '{$seedName}' has not been executed.");
                }

                if (!$force && !$dryRun && !$pretend) {
                    $confirmed = static::readConfirmation(
                        "Are you sure you want to rollback seed '{$seedName}'? This will modify your database",
                        false
                    );
                    if (!$confirmed) {
                        static::info('Seed rollback cancelled.');
                        return 0;
                    }
                }
            } else {
                // Rollback last batch
                $lastBatch = $runner->getLastBatchNumber();
                if ($lastBatch === null) {
                    static::info('No seeds to rollback.');
                    return 0;
                }

                if (!$force && !$dryRun && !$pretend) {
                    $confirmed = static::readConfirmation(
                        'Are you sure you want to rollback the last batch of seeds? This will modify your database',
                        false
                    );
                    if (!$confirmed) {
                        static::info('Seed rollback cancelled.');
                        return 0;
                    }
                }
            }

            $rolledBack = $runner->rollback($seedName);

            if ($dryRun || $pretend) {
                $queries = $runner->getCollectedQueries();
                if (!empty($queries)) {
                    echo "\n" . implode("\n", $queries) . "\n";
                }
                $mode = $dryRun ? 'dry-run' : 'pretend';
                static::info("Seed rollback completed ({$mode} mode).");
            } else {
                $count = count($rolledBack);
                if ($count > 0) {
                    static::success("Successfully rolled back {$count} seed(s):");
                    if (getenv('PHPUNIT') === false) {
                        foreach ($rolledBack as $seedName) {
                            echo "  - {$seedName}\n";
                        }
                    }
                } else {
                    static::info('No seeds were rolled back.');
                }
            }

            return 0;
        } catch (\Exception $e) {
            $this->showError($e->getMessage());
        }
    }

    /**
     * Show command help.
     *
     * @return int Exit code
     */
    protected function showHelp(): int
    {
        echo "Usage: pdodb seed <subcommand> [options] [arguments]\n\n";
        echo "Subcommands:\n";
        echo "  create <name>          Create a new seed file\n";
        echo "  run [<name>]           Run all new seeds or specific seed\n";
        echo "  list                   List all seeds with their status\n";
        echo "  rollback [<name>]      Rollback last batch or specific seed\n";
        echo "  help                   Show this help message\n\n";
        echo "Options:\n";
        echo "  --dry-run              Show SQL queries without executing them\n";
        echo "  --pretend              Simulate execution without running queries\n";
        echo "  --force                Skip confirmation prompts\n";
        echo "  --connection=<name>    Use specific database connection\n\n";
        echo "Examples:\n";
        echo "  pdodb seed create users_table\n";
        echo "  pdodb seed run\n";
        echo "  pdodb seed run users_table\n";
        echo "  pdodb seed run --dry-run\n";
        echo "  pdodb seed list\n";
        echo "  pdodb seed rollback\n";
        echo "  pdodb seed rollback users_table\n";

        return 0;
    }
}
