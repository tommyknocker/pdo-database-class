<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\seeds;

use PDOException;
use tommyknocker\pdodb\events\SeedCompletedEvent;
use tommyknocker\pdodb\events\SeedRolledBackEvent;
use tommyknocker\pdodb\events\SeedStartedEvent;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\runner\AbstractFileRunner;

/**
 * Seed runner for executing database seeds.
 *
 * This class manages seed execution, rollback, and history tracking.
 */
class SeedRunner extends AbstractFileRunner
{
    /** @var string Path to seed files directory */
    protected string $seedPath;

    /** @var string Seed table name */
    protected string $seedTable = '__seeds';

    /**
     * SeedRunner constructor.
     *
     * @param PdoDb $db Database instance
     * @param string $seedPath Path to seed files directory
     */
    public function __construct(PdoDb $db, string $seedPath)
    {
        parent::__construct($db);
        $this->seedPath = static::existingDirectory($seedPath, 'Seed');
    }

    /**
     * Ensure seed table exists.
     */
    protected function ensureSeedTable(): void
    {
        $schema = $this->db->schema();

        if (!$schema->tableExists($this->seedTable)) {
            $dialect = $this->db->connection->getDialect();
            $timestampDefault = $dialect->getTimestampDefaultExpression();
            $schema->createTable($this->seedTable, [
                'id' => $schema->primaryKey(),
                'seed' => $schema->string(255)->notNull(),
                'batch' => $schema->integer()->notNull(),
                'executed_at' => $schema->timestamp()->notNull()->defaultExpression($timestampDefault),
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
        if (!$this->historyTableAvailable()) {
            return [];
        }
        $rows = $this->db->find()
            ->from($this->seedTable)
            ->orderBy('executed_at', 'ASC')
            ->orderBy('seed', 'ASC')
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
        if (!$this->historyTableAvailable()) {
            return [];
        }
        $query = $this->db->find()
            ->from($this->seedTable)
            ->orderBy('executed_at', 'DESC')
            ->orderBy('seed', 'DESC');

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
        // Dry-run never executes seed PHP.
        if ($this->dryRun) {
            $batch = $this->getNextBatchNumber();
            $this->collectedQueries[] = "-- Seed: {$name}";
            $this->collectedQueries[] = '-- Dry-run: seed PHP is not executed.';
            $this->collectedQueries[] = '-- SQL from arbitrary PHP cannot be shown without side effects on some drivers.';
            $this->collectedQueries[] = "-- Would record seed in batch {$batch}";
            return;
        }

        try {
            // Dispatch seed started event
            $dispatcher = $this->getEventDispatcher();
            if ($dispatcher !== null) {
                $dispatcher->dispatch(new SeedStartedEvent(
                    $name,
                    $this->getDriverName()
                ));
            }

            $startTime = microtime(true);
            $this->db->startTransaction();
            $seed = $this->loadSeed($name);
            $seed->run();
            $batch = $this->getNextBatchNumber();
            $this->recordSeed($name, $batch);
            $this->db->commit();
            $duration = (microtime(true) - $startTime) * 1000; // milliseconds

            // Dispatch seed completed event
            if ($dispatcher !== null) {
                $dispatcher->dispatch(new SeedCompletedEvent(
                    $name,
                    $this->getDriverName(),
                    $duration
                ));
            }
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
            ->orderBy('seed', 'DESC')
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
            $this->collectedQueries[] = '-- Dry-run: seed PHP is not executed.';
            $this->collectedQueries[] = '-- Would remove seed record';
            return;
        }

        try {
            $this->db->startTransaction();
            $seed = $this->loadSeed($name);
            $seed->rollback();
            $this->removeSeedRecord($name);
            $this->db->commit();

            $this->getEventDispatcher()?->dispatch(new SeedRolledBackEvent($name, $this->getDriverName()));
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
        if (!$this->historyTableAvailable()) {
            return null;
        }
        // Use rawQueryOne to avoid QueryBuilder adding ORDER BY which causes issues
        // with aggregate functions in PostgreSQL
        // Table name must be quoted for Oracle compatibility
        $tableQuoted = $this->db->connection->getDialect()->quoteTable($this->seedTable);
        $sql = "SELECT MAX(batch) as max_batch FROM {$tableQuoted}";
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

    protected function historyTableName(): string
    {
        return $this->seedTable;
    }

    protected function ensureHistoryTable(): void
    {
        $this->ensureSeedTable();
    }
}
