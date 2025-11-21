<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\seeds;

use PDOException;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\migrations\SqlQueryCollector;
use tommyknocker\pdodb\PdoDb;

/**
 * Seed runner for executing database seeds.
 *
 * This class manages seed execution, rollback, and history tracking.
 */
class SeedRunner
{
    /** @var PdoDb Database instance */
    protected PdoDb $db;

    /** @var string Path to seed files directory */
    protected string $seedPath;

    /** @var string Seed table name */
    protected string $seedTable = '__seeds';

    /** @var bool Dry-run mode (show SQL without executing) */
    protected bool $dryRun = false;

    /** @var bool Pretend mode (simulate execution) */
    protected bool $pretend = false;

    /** @var array<int, string> Collected SQL queries in dry-run/pretend mode */
    protected array $collectedQueries = [];

    /**
     * SeedRunner constructor.
     *
     * @param PdoDb $db Database instance
     * @param string $seedPath Path to seed files directory
     */
    public function __construct(PdoDb $db, string $seedPath)
    {
        $this->db = $db;
        $this->seedPath = rtrim($seedPath, '/\\');

        if (!is_dir($this->seedPath)) {
            throw new QueryException("Seed path does not exist: {$this->seedPath}");
        }

        $this->ensureSeedTable();
    }

    /**
     * Enable dry-run mode (show SQL without executing).
     *
     * @param bool $enabled Whether to enable dry-run mode
     *
     * @return static
     */
    public function setDryRun(bool $enabled): static
    {
        $this->dryRun = $enabled;
        return $this;
    }

    /**
     * Enable pretend mode (simulate execution).
     *
     * @param bool $enabled Whether to enable pretend mode
     *
     * @return static
     */
    public function setPretend(bool $enabled): static
    {
        $this->pretend = $enabled;
        return $this;
    }

    /**
     * Get collected SQL queries (for dry-run/pretend mode).
     *
     * @return array<int, string>
     */
    public function getCollectedQueries(): array
    {
        return $this->collectedQueries;
    }

    /**
     * Clear collected SQL queries.
     */
    public function clearCollectedQueries(): void
    {
        $this->collectedQueries = [];
    }

    /**
     * Ensure seed table exists.
     */
    protected function ensureSeedTable(): void
    {
        $schema = $this->db->schema();

        if (!$schema->tableExists($this->seedTable)) {
            $schema->createTable($this->seedTable, [
                'id' => $schema->primaryKey(),
                'seed' => $schema->string(255)->notNull(),
                'batch' => $schema->integer()->notNull(),
                'executed_at' => $schema->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            ]);
        }
    }

    /**
     * Create a new seed file.
     *
     * @param string $name Seed name
     *
     * @return string Path to created seed file
     */
    public function create(string $name): string
    {
        $className = $this->generateClassName($name);
        $filename = $this->generateFilename($name);
        $filepath = $this->seedPath . '/' . $filename;

        if (file_exists($filepath)) {
            throw new QueryException("Seed file already exists: {$filepath}");
        }

        $template = $this->getSeedTemplate($className);
        file_put_contents($filepath, $template);

        return $filepath;
    }

    /**
     * Get all seed files.
     *
     * @return array<int, string> Array of seed filenames
     */
    public function getAllSeeds(): array
    {
        $files = glob($this->seedPath . '/s*.php');
        if ($files === false) {
            return [];
        }

        $seeds = [];
        foreach ($files as $file) {
            $seeds[] = basename($file, '.php');
        }

        sort($seeds);
        return $seeds;
    }

    /**
     * Get executed seeds.
     *
     * @return array<int, string> Array of executed seed names
     */
    public function getExecutedSeeds(): array
    {
        $rows = $this->db->find()
            ->from($this->seedTable)
            ->orderBy('executed_at', 'ASC')
            ->get();

        return array_column($rows, 'seed');
    }

    /**
     * Get new (unexecuted) seeds.
     *
     * @return array<int, string> Array of new seed names
     */
    public function getNewSeeds(): array
    {
        $allSeeds = $this->getAllSeeds();
        $executedSeeds = $this->getExecutedSeeds();

        return array_diff($allSeeds, $executedSeeds);
    }

    /**
     * Get seed history.
     *
     * @param int|null $limit Maximum number of records to return
     *
     * @return array<int, array<string, mixed>> Seed history
     */
    public function getSeedHistory(?int $limit = null): array
    {
        $query = $this->db->find()
            ->from($this->seedTable)
            ->orderBy('executed_at', 'DESC');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_values($query->get());
    }

    /**
     * Run seeds.
     *
     * @param string|null $name Specific seed name (null = run all new seeds)
     *
     * @return array<int, string> Array of executed seed names
     */
    public function run(?string $name = null): array
    {
        if ($name !== null) {
            // Run specific seed
            return $this->runSpecificSeed($name);
        }

        // Run all new seeds
        $newSeeds = $this->getNewSeeds();
        if (empty($newSeeds)) {
            return [];
        }

        $executed = [];
        foreach ($newSeeds as $seedName) {
            $this->runSeed($seedName);
            $executed[] = $seedName;
        }

        return $executed;
    }

    /**
     * Rollback seeds.
     *
     * @param string|null $name Specific seed name (null = rollback last batch)
     *
     * @return array<int, string> Array of rolled back seed names
     */
    public function rollback(?string $name = null): array
    {
        if ($name !== null) {
            // Rollback specific seed
            return $this->rollbackSpecificSeed($name);
        }

        // Rollback last batch
        return $this->rollbackLastBatch();
    }

    /**
     * Run specific seed.
     *
     * @param string $name Seed name
     *
     * @return array<int, string>
     */
    protected function runSpecificSeed(string $name): array
    {
        $executedSeeds = $this->getExecutedSeeds();
        if (in_array($name, $executedSeeds, true)) {
            return []; // Already executed
        }

        $this->runSeed($name);
        return [$name];
    }

    /**
     * Run single seed.
     *
     * @param string $name Seed name
     */
    protected function runSeed(string $name): void
    {
        if ($this->dryRun) {
            $this->collectedQueries[] = "-- Seed: {$name}";
            $this->collectedQueries[] = '';

            try {
                $this->db->startTransaction();
                $seed = $this->loadSeed($name);
                $this->collectSeedSql($seed, $name, 'run');
                $this->db->rollback(); // Always rollback in dry-run mode
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollback();
                }
                $this->collectedQueries[] = '-- Error: ' . $e->getMessage();
            }
            return;
        }

        if ($this->pretend) {
            $batch = $this->getNextBatchNumber();
            $this->collectedQueries[] = "-- Seed: {$name}";
            $this->collectedQueries[] = '-- Would execute seed.run()';
            $this->collectedQueries[] = "-- Would record seed in batch {$batch}";
            return;
        }

        try {
            $this->db->startTransaction();
            $seed = $this->loadSeed($name);
            $seed->run();
            $batch = $this->getNextBatchNumber();
            $this->recordSeed($name, $batch);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            $previous = $e instanceof PDOException ? $e : null;

            throw new QueryException(
                "Seed {$name} failed: " . $e->getMessage(),
                0,
                $previous
            );
        }
    }

    /**
     * Rollback specific seed.
     *
     * @param string $name Seed name
     *
     * @return array<int, string>
     */
    protected function rollbackSpecificSeed(string $name): array
    {
        $executedSeeds = $this->getExecutedSeeds();
        if (!in_array($name, $executedSeeds, true)) {
            return []; // Not executed
        }

        $this->rollbackSeed($name);
        return [$name];
    }

    /**
     * Rollback last batch.
     *
     * @return array<int, string>
     */
    protected function rollbackLastBatch(): array
    {
        $lastBatch = $this->getLastBatchNumber();
        if ($lastBatch === null) {
            return [];
        }

        $seeds = $this->db->find()
            ->from($this->seedTable)
            ->where('batch', $lastBatch)
            ->orderBy('executed_at', 'DESC')
            ->get();

        $rolledBack = [];
        foreach ($seeds as $seedRow) {
            $seedName = (string)$seedRow['seed'];
            $this->rollbackSeed($seedName);
            $rolledBack[] = $seedName;
        }

        return $rolledBack;
    }

    /**
     * Rollback single seed.
     *
     * @param string $name Seed name
     */
    protected function rollbackSeed(string $name): void
    {
        if ($this->dryRun) {
            $this->collectedQueries[] = "-- Rollback Seed: {$name}";
            $this->collectedQueries[] = '';

            try {
                $this->db->startTransaction();
                $seed = $this->loadSeed($name);
                $this->collectSeedSql($seed, $name, 'rollback');
                $this->db->rollback(); // Always rollback in dry-run mode
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollback();
                }
                $this->collectedQueries[] = '-- Error: ' . $e->getMessage();
            }
            return;
        }

        if ($this->pretend) {
            $this->collectedQueries[] = "-- Rollback Seed: {$name}";
            $this->collectedQueries[] = '-- Would execute seed.rollback()';
            $this->collectedQueries[] = '-- Would remove seed record';
            return;
        }

        try {
            $this->db->startTransaction();
            $seed = $this->loadSeed($name);
            $seed->rollback();
            $this->removeSeedRecord($name);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            $previous = $e instanceof PDOException ? $e : null;

            throw new QueryException(
                "Seed rollback {$name} failed: " . $e->getMessage(),
                0,
                $previous
            );
        }
    }

    /**
     * Load seed instance.
     *
     * @param string $name Seed name
     *
     * @return SeedInterface
     */
    protected function loadSeed(string $name): SeedInterface
    {
        $filepath = $this->seedPath . '/' . $name . '.php';
        if (!file_exists($filepath)) {
            throw new QueryException("Seed file not found: {$filepath}");
        }

        require_once $filepath;

        $className = $this->getClassNameFromFile($name);
        if (!class_exists($className)) {
            throw new QueryException("Seed class not found: {$className}");
        }

        $seed = new $className($this->db);
        if (!$seed instanceof SeedInterface) {
            throw new QueryException("Seed class must implement SeedInterface: {$className}");
        }

        return $seed;
    }

    /**
     * Collect SQL from seed execution.
     *
     * @param SeedInterface $seed Seed instance
     * @param string $name Seed name
     * @param string $method Method to call ('run' or 'rollback')
     */
    protected function collectSeedSql(SeedInterface $seed, string $name, string $method): void
    {
        // Create SQL collector
        $sqlCollector = new SqlQueryCollector();

        // Set up event dispatcher with SQL collector if available
        $originalDispatcher = $this->db->getEventDispatcher();
        $collectorDispatcher = null;
        $connection = $this->db->getConnection('write');
        $originalConnectionDispatcher = $connection->getEventDispatcher();

        if (interface_exists(\Psr\EventDispatcher\EventDispatcherInterface::class)) {
            // Create a simple event dispatcher that collects SQL queries
            $collectorDispatcher = new class ($sqlCollector) implements \Psr\EventDispatcher\EventDispatcherInterface {
                public function __construct(
                    private SqlQueryCollector $collector
                ) {
                }

                public function dispatch(object $event): object
                {
                    if ($event instanceof \tommyknocker\pdodb\events\QueryExecutedEvent) {
                        $this->collector->handleQueryExecuted($event);
                    }
                    return $event;
                }
            };

            // Temporarily set the collector dispatcher
            $this->db->setEventDispatcher($collectorDispatcher);
            $connection->setEventDispatcher($collectorDispatcher);
        }

        try {
            // Execute seed method - SQL will be collected via event dispatcher
            if ($method === 'run') {
                $seed->run();
            } else {
                $seed->rollback();
            }

            // Collect SQL from the collector
            $collectedSql = $sqlCollector->getQueries();
            if (!empty($collectedSql)) {
                $this->collectedQueries = array_merge($this->collectedQueries, $collectedSql);
            } else {
                // Fallback: try to get SQL from connection's lastQuery
                $lastQuery = $this->db->lastQuery;
                if ($lastQuery !== null && $lastQuery !== '') {
                    $this->collectedQueries[] = $lastQuery;
                }
            }
        } catch (\Throwable $e) {
            // Seed failed, but we still want to show what SQL was attempted
            $this->collectedQueries[] = '-- Error during seed execution: ' . $e->getMessage();

            // Still try to collect any SQL that was executed before the error
            $collectedSql = $sqlCollector->getQueries();
            if (!empty($collectedSql)) {
                $this->collectedQueries = array_merge($this->collectedQueries, $collectedSql);
            }
        } finally {
            // Restore original event dispatcher
            if ($collectorDispatcher !== null) {
                $this->db->setEventDispatcher($originalDispatcher);
                $connection->setEventDispatcher($originalConnectionDispatcher);
            }
        }

        // Add seed record SQL
        if ($method === 'run') {
            $batch = $this->getNextBatchNumber();
            $this->collectedQueries[] = '';
            $this->collectedQueries[] = "-- Would record seed in batch {$batch}";
            $this->collectedQueries[] = "INSERT INTO {$this->seedTable} (seed, batch) VALUES ('{$name}', {$batch})";
        } else {
            $this->collectedQueries[] = '';
            $this->collectedQueries[] = '-- Would remove seed record';
            $this->collectedQueries[] = "DELETE FROM {$this->seedTable} WHERE seed = '{$name}'";
        }
    }

    /**
     * Record seed execution.
     *
     * @param string $name Seed name
     * @param int $batch Batch number
     */
    protected function recordSeed(string $name, int $batch): void
    {
        $this->db->find()->table($this->seedTable)->insert([
            'seed' => $name,
            'batch' => $batch,
        ]);
    }

    /**
     * Remove seed record.
     *
     * @param string $name Seed name
     */
    protected function removeSeedRecord(string $name): void
    {
        $this->db->find()->table($this->seedTable)->where('seed', $name)->delete();
    }

    /**
     * Get next batch number.
     *
     * @return int
     */
    protected function getNextBatchNumber(): int
    {
        $lastBatch = $this->getLastBatchNumber();
        return ($lastBatch ?? 0) + 1;
    }

    /**
     * Get last batch number.
     *
     * @return int|null
     */
    public function getLastBatchNumber(): ?int
    {
        // Use rawQueryOne to avoid QueryBuilder adding ORDER BY which causes issues
        // with aggregate functions in PostgreSQL
        // Table name is controlled by us and safe to use directly
        $sql = "SELECT MAX(batch) as max_batch FROM {$this->seedTable}";
        $result = $this->db->rawQueryOne($sql);

        $maxBatch = $result['max_batch'] ?? null;
        return $maxBatch !== null ? (int)$maxBatch : null;
    }

    /**
     * Generate class name from seed name.
     *
     * @param string $name Seed name
     *
     * @return string
     */
    protected function generateClassName(string $name): string
    {
        // Convert snake_case to PascalCase
        $className = str_replace('_', '', ucwords($name, '_'));
        return $className . 'Seed';
    }

    /**
     * Generate filename from seed name.
     *
     * @param string $name Seed name
     *
     * @return string
     */
    protected function generateFilename(string $name): string
    {
        $timestamp = date('YmdHis');
        return "s{$timestamp}_{$name}.php";
    }

    /**
     * Get class name from filename.
     *
     * @param string $filename Filename without extension
     *
     * @return string
     */
    protected function getClassNameFromFile(string $filename): string
    {
        // Extract name part from s20231121120000_create_users format
        if (preg_match('/^s\d+_(.+)$/', $filename, $matches)) {
            return $this->generateClassName($matches[1]);
        }

        return $this->generateClassName($filename);
    }

    /**
     * Get seed template.
     *
     * @param string $className Class name
     *
     * @return string
     */
    protected function getSeedTemplate(string $className): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use tommyknocker\pdodb\seeds\Seed;

/**
 * {$className} seed.
 */
class {$className} extends Seed
{
    /**
     * Run the seed.
     */
    public function run(): void
    {
        // Add your seed data here
        // Example:
        // \$this->insert('users', [
        //     'name' => 'John Doe',
        //     'email' => 'john@example.com',
        //     'created_at' => date('Y-m-d H:i:s'),
        // ]);
    }

    /**
     * Rollback the seed.
     */
    public function rollback(): void
    {
        // Remove seeded data here
        // Example:
        // \$this->delete('users', ['email' => 'john@example.com']);
    }
}
PHP;
    }
}
