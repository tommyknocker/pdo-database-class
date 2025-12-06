<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\SeedGenerator;
use tommyknocker\pdodb\cli\TableManager;
use tommyknocker\pdodb\cli\traits\SubcommandSuggestionTrait;
use tommyknocker\pdodb\helpers\values\RawValue;
use tommyknocker\pdodb\seeds\SeedDataGenerator;
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
        return ['create', 'run', 'list', 'rollback', 'generate', 'help'];
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

        $subcommandStr = is_string($subcommand) ? $subcommand : (string)$subcommand;

        return match ($subcommandStr) {
            'create' => $this->create(),
            'run' => $this->run(),
            'list' => $this->list(),
            'rollback' => $this->rollback(),
            'generate' => $this->generate(),
            '--help', 'help' => $this->showHelp(),
            default => $this->showSubcommandError($subcommandStr),
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
            $nameStr = $name !== null && is_string($name) ? $name : null;
            $filename = SeedGenerator::generate($nameStr);
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
                $seedNameStr = (string)$seedName;
                $newSeeds = [$seedNameStr];
                $executedSeeds = $runner->getExecutedSeeds();
                if (in_array($seedNameStr, $executedSeeds, true)) {
                    if (!$force && !$dryRun && !$pretend) {
                        $confirmed = static::readConfirmation(
                            "Seed '{$seedNameStr}' has already been executed. Re-run it?",
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

            $seedNameForRun = $seedName !== null ? (string)$seedName : null;
            $executed = $runner->run($seedNameForRun);

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
                        foreach ($executed as $executedSeedName) {
                            $executedSeedNameStr = is_string($executedSeedName) ? $executedSeedName : (string)$executedSeedName;
                            echo "  - {$executedSeedNameStr}\n";
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
                $seedNameStr = (string)$seedName;
                if (!in_array($seedNameStr, $executedSeeds, true)) {
                    $this->showError("Seed '{$seedNameStr}' has not been executed.");
                }

                if (!$force && !$dryRun && !$pretend) {
                    $confirmed = static::readConfirmation(
                        "Are you sure you want to rollback seed '{$seedNameStr}'? This will modify your database",
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

            $seedNameForRollback = $seedName !== null ? (string)$seedName : null;
            $rolledBack = $runner->rollback($seedNameForRollback);

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
                        foreach ($rolledBack as $rolledBackSeedName) {
                            $rolledBackSeedNameStr = is_string($rolledBackSeedName) ? $rolledBackSeedName : (string)$rolledBackSeedName;
                            echo "  - {$rolledBackSeedNameStr}\n";
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
     * Generate seed from existing database data.
     *
     * @return int Exit code
     */
    protected function generate(): int
    {
        $tableName = $this->getArgument(1);

        if ($tableName === null || $tableName === '' || !is_string($tableName)) {
            echo "Usage: pdodb seed generate <table_name> [options]\n";
            echo "Options:\n";
            echo "  --limit=N              Limit number of rows to export\n";
            echo "  --where=condition       WHERE condition (e.g., \"status='active'\")\n";
            echo "  --order-by=column      ORDER BY column(s)\n";
            echo "  --exclude=columns       Comma-separated columns to exclude\n";
            echo "  --include=columns       Comma-separated columns to include\n";
            echo "  --chunk-size=N          Chunk size for insertMulti (default: 1000)\n";
            echo "  --preserve-ids          Preserve IDs in generated seed (default: true)\n";
            echo "  --skip-timestamps       Skip timestamp columns (created_at, updated_at)\n";
            echo "  --format=pretty|compact Code format (default: pretty)\n";
            $this->showError('Table name is required');
        }

        $tableNameStr = (string)$tableName;

        try {
            $seedPath = SeedGenerator::getSeedPath();
            $runner = new SeedRunner($this->getDb(), $seedPath);

            // Parse options
            $limitOpt = $this->getOption('limit');
            $whereOpt = $this->getOption('where');
            $orderByOpt = $this->getOption('order-by');
            $excludeOpt = $this->getOption('exclude');
            $includeOpt = $this->getOption('include');
            $chunkSizeOpt = $this->getOption('chunk-size', 1000);
            $preserveIdsOpt = $this->getOption('preserve-ids', true);
            $skipTimestampsOpt = $this->getOption('skip-timestamps', false);
            $formatOpt = $this->getOption('format', 'pretty');

            $options = [
                'limit' => $limitOpt !== null && is_numeric($limitOpt) ? (int)$limitOpt : null,
                'where' => $whereOpt !== null && is_string($whereOpt) ? $whereOpt : null,
                'order_by' => $orderByOpt !== null && is_string($orderByOpt) ? $orderByOpt : null,
                'exclude' => $excludeOpt !== null && (is_string($excludeOpt) || is_array($excludeOpt)) ? $excludeOpt : null,
                'include' => $includeOpt !== null && (is_string($includeOpt) || is_array($includeOpt)) ? $includeOpt : null,
                'chunk_size' => is_numeric($chunkSizeOpt) ? (int)$chunkSizeOpt : 1000,
                'preserve_ids' => $preserveIdsOpt !== false,
                'skip_timestamps' => $skipTimestampsOpt !== false,
                'format' => is_string($formatOpt) ? $formatOpt : 'pretty',
            ];

            // Check if table exists
            $db = $this->getDb();
            if (!TableManager::tableExists($db, $tableNameStr)) {
                $this->showError("Table '{$tableNameStr}' does not exist");
            }

            // Check row count if no limit specified
            $limitValue = $options['limit'];
            if ($limitValue === null) {
                try {
                    $countQuery = $db->find()->from($tableNameStr)->select('COUNT(*)');
                    $whereValue = $options['where'];
                    if ($whereValue !== null) {
                        // Parse WHERE condition same way as SeedDataGenerator
                        if (preg_match('/^(\w+)\s*([=<>!]+)\s*(.+)$/', $whereValue, $matches)) {
                            $column = trim($matches[1]);
                            $operator = trim($matches[2]);
                            $value = trim($matches[3], " '\"");
                            if ($operator === '=') {
                                $countQuery->where($column, $value);
                            } else {
                                $quotedValue = $db->connection->quote($value);
                                $countQuery->where($column, new RawValue("{$operator} {$quotedValue}"));
                            }
                        } else {
                            // Use raw WHERE
                            $countQuery->where(new RawValue($whereValue));
                        }
                    }
                    $rowCount = (int)($countQuery->getValue() ?? 0);
                    if ($rowCount > 1000) {
                        $confirmed = static::readConfirmation(
                            "Table '{$tableNameStr}' has {$rowCount} rows. This may take a while. Continue?",
                            true
                        );
                        if (!$confirmed) {
                            static::info('Seed generation cancelled.');
                            return 0;
                        }
                    }
                } catch (\Exception $e) {
                    // Ignore count errors, will be caught later in generate()
                }
            }

            // Generate seed data
            $generator = new SeedDataGenerator($db);
            $result = $generator->generate($tableNameStr, $options);

            // Show warnings
            if (!empty($result['warnings'])) {
                foreach ($result['warnings'] as $warning) {
                    static::warning($warning);
                }
            }

            // Create seed file
            $seedName = $tableNameStr;
            $timestamp = date('YmdHis');
            $filename = "s{$timestamp}_{$seedName}.php";
            $filepath = $seedPath . '/' . $filename;

            // Check if file already exists
            if (file_exists($filepath)) {
                $confirmed = static::readConfirmation(
                    "Seed file '{$filename}' already exists. Overwrite?",
                    false
                );
                if (!$confirmed) {
                    static::info('Seed generation cancelled.');
                    return 0;
                }
            }

            // Write seed file
            file_put_contents($filepath, $result['content']);

            static::success("Seed file generated: {$filename}");
            echo "  Path: {$filepath}\n";
            echo "  Rows: {$result['rowCount']}\n";

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
        echo "  generate <table>       Generate seed file from existing database data\n";
        echo "  help                   Show this help message\n\n";
        echo "Options:\n";
        echo "  --dry-run              Show SQL queries without executing them\n";
        echo "  --pretend              Simulate execution without running queries\n";
        echo "  --force                Skip confirmation prompts\n";
        echo "  --connection=<name>    Use specific database connection\n\n";
        echo "Generate options:\n";
        echo "  --limit=N              Limit number of rows to export\n";
        echo "  --where=condition      WHERE condition (e.g., \"status='active'\")\n";
        echo "  --order-by=column      ORDER BY column(s)\n";
        echo "  --exclude=columns      Comma-separated columns to exclude\n";
        echo "  --include=columns      Comma-separated columns to include\n";
        echo "  --chunk-size=N         Chunk size for insertMulti (default: 1000)\n";
        echo "  --preserve-ids         Preserve IDs in generated seed (default: true)\n";
        echo "  --skip-timestamps      Skip timestamp columns\n";
        echo "  --format=pretty|compact Code format (default: pretty)\n\n";
        echo "Examples:\n";
        echo "  pdodb seed create users_table\n";
        echo "  pdodb seed run\n";
        echo "  pdodb seed run users_table\n";
        echo "  pdodb seed run --dry-run\n";
        echo "  pdodb seed list\n";
        echo "  pdodb seed rollback\n";
        echo "  pdodb seed rollback users_table\n";
        echo "  pdodb seed generate users --limit=100\n";
        echo "  pdodb seed generate users --where=\"status='active'\" --limit=50\n";
        echo "  pdodb seed generate products --exclude=image_data,binary_data\n";

        return 0;
    }
}
