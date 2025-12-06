<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\migrations;

use PDOException;
use Psr\EventDispatcher\EventDispatcherInterface;
use tommyknocker\pdodb\events\MigrationCompletedEvent;
use tommyknocker\pdodb\events\MigrationRolledBackEvent;
use tommyknocker\pdodb\events\MigrationStartedEvent;
use tommyknocker\pdodb\events\QueryExecutedEvent;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\PdoDb;

/**
 * Migration runner for executing database migrations.
 *
 * This class manages migration execution, rollback, and history tracking.
 */
class MigrationRunner
{
    /** @var PdoDb Database instance */
    protected PdoDb $db;

    /** @var string Path to migration files directory */
    protected string $migrationPath;

    /** @var string Migration table name */
    protected string $migrationTable = '__migrations';

    /** @var bool Dry-run mode (show SQL without executing) */
    protected bool $dryRun = false;

    /** @var bool Pretend mode (simulate execution) */
    protected bool $pretend = false;

    /** @var array<int, string> Collected SQL queries in dry-run/pretend mode */
    protected array $collectedQueries = [];

    /**
     * MigrationRunner constructor.
     *
     * @param PdoDb $db Database instance
     * @param string $migrationPath Path to migration files directory
     */
    public function __construct(PdoDb $db, string $migrationPath)
    {
        $this->db = $db;
        $this->migrationPath = rtrim($migrationPath, '/\\');

        if (!is_dir($this->migrationPath)) {
            throw new QueryException("Migration path does not exist: {$this->migrationPath}");
        }

        $this->ensureMigrationTable();
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
     * Ensure migration table exists.
     */
    protected function ensureMigrationTable(): void
    {
        $schema = $this->db->schema();

        // Check if table exists
        $exists = $schema->tableExists($this->migrationTable);

        if (!$exists) {
            $dialect = $schema->getDialect();
            $sql = $dialect->buildMigrationTableSql($this->migrationTable);
            $this->db->rawQuery($sql);
        }
    }

    /**
     * Get new (not yet applied) migrations.
     *
     * @return array<int, string> Array of migration version strings
     */
    public function getNewMigrations(): array
    {
        $applied = $this->getMigrationHistory();
        $appliedVersions = array_column($applied, 'version');
        $allMigrations = $this->getAllMigrationFiles();

        return array_filter($allMigrations, function ($version) use ($appliedVersions) {
            return !in_array($version, $appliedVersions, true);
        });
    }

    /**
     * Get all migration files from migration path.
     *
     * @return array<int, string> Array of migration version strings
     */
    protected function getAllMigrationFiles(): array
    {
        $files = glob($this->migrationPath . '/m*.php');
        if ($files === false) {
            return [];
        }

        $versions = [];
        foreach ($files as $file) {
            $basename = basename($file, '.php');
            // Extract version from filename (format: mYYYY_MM_DD_HHMMSS_description or mYYYYMMDDHHMMSS_description)
            // Remove 'm' prefix and extract version part
            if (str_starts_with($basename, 'm')) {
                $versionPart = substr($basename, 1);
                // Validate version format: either YYYY_MM_DD_HHMMSS_description or YYYYMMDDHHMMSS_description
                if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6}|\d{14})_[a-z0-9_]+$/i', $versionPart) === 1) {
                    $versions[] = $versionPart;
                }
            }
        }

        sort($versions);

        return $versions;
    }

    /**
     * Get migration history.
     *
     * @param int|null $limit Maximum number of records to return
     *
     * @return array<int, array<string, mixed>> Migration history
     */
    public function getMigrationHistory(?int $limit = null): array
    {
        $query = $this->db->find()
            ->from($this->migrationTable)
            ->orderBy('apply_time', 'DESC');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_values($query->get());
    }

    /**
     * Get current migration version.
     *
     * @return string|null Current version or null if no migrations applied
     */
    public function getMigrationVersion(): ?string
    {
        $history = $this->getMigrationHistory(1);
        if (empty($history)) {
            return null;
        }

        return $history[0]['version'] ?? null;
    }

    /**
     * Execute new migrations.
     *
     * @param int $limit Maximum number of migrations to execute (0 = all)
     *
     * @return array<int, string> Array of applied migration versions
     */
    public function migrate(int $limit = 0): array
    {
        $newMigrations = $this->getNewMigrations();

        if (empty($newMigrations)) {
            return [];
        }

        if ($limit > 0) {
            $newMigrations = array_slice($newMigrations, 0, $limit);
        }

        // In dry-run mode, execute migrations in transaction to collect SQL
        if ($this->dryRun) {
            $this->clearCollectedQueries();
            foreach ($newMigrations as $version) {
                $this->migrateUp($version);
            }
            return $newMigrations;
        }

        // In pretend mode, just return the list without executing
        if ($this->pretend) {
            $this->clearCollectedQueries();
            $batch = $this->getNextBatchNumber();

            foreach ($newMigrations as $version) {
                $this->collectedQueries[] = "-- Migration: {$version}";
                $this->collectedQueries[] = '-- Would execute migration.up()';
                $this->collectedQueries[] = "-- Would record migration in batch {$batch}";
            }

            return $newMigrations;
        }

        $applied = [];
        $batch = $this->getNextBatchNumber();

        foreach ($newMigrations as $version) {
            try {
                // Dispatch migration started event
                $dispatcher = $this->getEventDispatcher();
                if ($dispatcher !== null) {
                    $dispatcher->dispatch(new MigrationStartedEvent(
                        $version,
                        $this->getDriverName()
                    ));
                }

                $startTime = microtime(true);
                $this->db->startTransaction();
                $migration = $this->loadMigration($version);
                $migration->up();
                $this->recordMigration($version, $batch);
                $this->db->commit();
                $duration = (microtime(true) - $startTime) * 1000; // milliseconds

                // Dispatch migration completed event
                if ($dispatcher !== null) {
                    $dispatcher->dispatch(new MigrationCompletedEvent(
                        $version,
                        $this->getDriverName(),
                        $duration
                    ));
                }

                $applied[] = $version;
            } catch (\Throwable $e) {
                $this->db->rollback();
                $previous = $e instanceof PDOException ? $e : null;

                throw new QueryException(
                    "Migration {$version} failed: " . $e->getMessage(),
                    0,
                    $previous
                );
            }
        }

        return $applied;
    }

    /**
     * Get event dispatcher from database connection.
     *
     * @return EventDispatcherInterface|null
     */
    protected function getEventDispatcher(): ?EventDispatcherInterface
    {
        return $this->db->connection->getEventDispatcher();
    }

    /**
     * Get driver name from database connection.
     *
     * @return string
     */
    protected function getDriverName(): string
    {
        return $this->db->connection->getDriverName();
    }

    /**
     * Rollback migrations.
     *
     * @param int $step Number of migrations to rollback
     *
     * @return array<int, string> Array of rolled back migration versions
     */
    public function migrateDown(int $step = 1): array
    {
        $history = $this->getMigrationHistory($step);
        if (empty($history)) {
            return [];
        }

        // In dry-run or pretend mode, just return the list without executing
        if ($this->dryRun || $this->pretend) {
            $this->clearCollectedQueries();
            $rolledBack = [];

            foreach ($history as $record) {
                $version = $record['version'];
                $this->collectedQueries[] = "-- Rollback Migration: {$version}";
                $this->collectedQueries[] = '-- Would execute migration.down()';
                $this->collectedQueries[] = '-- Would remove migration record';
                $rolledBack[] = $version;
            }

            return $rolledBack;
        }

        $rolledBack = [];

        foreach ($history as $record) {
            $version = $record['version'];

            try {
                $this->db->startTransaction();
                $migration = $this->loadMigration($version);
                $migration->down();
                $this->removeMigrationRecord($version);
                $this->db->commit();
                $rolledBack[] = $version;
            } catch (\Throwable $e) {
                $this->db->rollback();
                $previous = $e instanceof PDOException ? $e : null;

                throw new QueryException(
                    "Rollback of migration {$version} failed: " . $e->getMessage(),
                    0,
                    $previous
                );
            }
        }

        return $rolledBack;
    }

    /**
     * Rollback a specific migration by version.
     *
     * @param string $version Migration version to rollback
     */
    protected function migrateDownByVersion(string $version): void
    {
        // In dry-run or pretend mode, just collect info without executing
        if ($this->dryRun || $this->pretend) {
            $this->collectedQueries[] = "-- Rollback Migration: {$version}";
            $this->collectedQueries[] = '-- Would execute migration.down()';
            $this->collectedQueries[] = '-- Would remove migration record';
            return;
        }

        try {
            $this->db->startTransaction();
            $migration = $this->loadMigration($version);
            $migration->down();
            $this->removeMigrationRecord($version);
            $this->db->commit();

            // Dispatch migration rolled back event
            $dispatcher = $this->getEventDispatcher();
            if ($dispatcher !== null) {
                $dispatcher->dispatch(new MigrationRolledBackEvent(
                    $version,
                    $this->getDriverName()
                ));
            }
        } catch (\Throwable $e) {
            $this->db->rollback();
            $previous = $e instanceof PDOException ? $e : null;

            throw new QueryException(
                "Rollback of migration {$version} failed: " . $e->getMessage(),
                0,
                $previous
            );
        }
    }

    /**
     * Migrate to a specific version.
     *
     * @param string $version Target version
     */
    public function migrateTo(string $version): void
    {
        $allMigrations = $this->getAllMigrationFiles();
        $targetIndex = array_search($version, $allMigrations, true);

        if ($targetIndex === false) {
            throw new QueryException("Migration version {$version} not found");
        }

        $history = $this->getMigrationHistory();
        $appliedVersions = array_column($history, 'version');

        // Find the highest applied migration version (by order in allMigrations, not by apply_time)
        $currentIndex = -1;
        foreach ($appliedVersions as $appliedVersion) {
            $index = array_search($appliedVersion, $allMigrations, true);
            if ($index !== false && $index > $currentIndex) {
                $currentIndex = $index;
            }
        }

        if ($currentIndex === -1) {
            // No migrations applied, apply all up to target version
            $migrationsToApply = array_slice($allMigrations, 0, $targetIndex + 1);
            foreach ($migrationsToApply as $migrationVersion) {
                $this->migrateUp($migrationVersion);
            }
        } elseif ($currentIndex === $targetIndex) {
            // Already at target version
            return;
        } elseif ($targetIndex < $currentIndex) {
            // Rollback migrations in reverse order (newest first)
            // We need to rollback from currentIndex down to targetIndex+1
            $migrationsToRollback = array_slice($allMigrations, $targetIndex + 1, $currentIndex - $targetIndex);

            // Rollback each migration that needs to be rolled back, in reverse order
            foreach (array_reverse($migrationsToRollback) as $migrationVersion) {
                // Only rollback if migration is actually applied
                if (in_array($migrationVersion, $appliedVersions, true)) {
                    $this->migrateDownByVersion($migrationVersion);
                }
            }
        } else {
            // Apply
            $migrationsToApply = array_slice($allMigrations, $currentIndex + 1, $targetIndex - $currentIndex);
            foreach ($migrationsToApply as $migrationVersion) {
                $this->migrateUp($migrationVersion);
            }
        }
    }

    /**
     * Execute a specific migration.
     *
     * @param string $version Migration version
     */
    public function migrateUp(string $version): void
    {
        $history = $this->getMigrationHistory();
        $appliedVersions = array_column($history, 'version');

        if (in_array($version, $appliedVersions, true)) {
            return; // Already applied
        }

        // In dry-run mode, execute migration in transaction and collect SQL
        if ($this->dryRun) {
            $this->collectedQueries[] = "-- Migration: {$version}";
            $this->collectedQueries[] = '';

            try {
                $this->db->startTransaction();
                $migration = $this->loadMigration($version);

                // Collect SQL queries during migration execution
                $this->collectMigrationSql($migration, $version);

                $this->db->rollback(); // Always rollback in dry-run mode
            } catch (\Throwable $e) {
                // Rollback if transaction is still active
                if ($this->db->connection->inTransaction()) {
                    $this->db->rollback();
                }
                // Still collect the error as a comment
                $this->collectedQueries[] = '-- Error: ' . $e->getMessage();
            }
            return;
        }

        // In pretend mode, just collect info without executing
        if ($this->pretend) {
            $batch = $this->getNextBatchNumber();
            $this->collectedQueries[] = "-- Migration: {$version}";
            $this->collectedQueries[] = '-- Would execute migration.up()';
            $this->collectedQueries[] = "-- Would record migration in batch {$batch}";
            return;
        }

        try {
            $this->db->startTransaction();
            $migration = $this->loadMigration($version);
            $migration->up();
            $batch = $this->getNextBatchNumber();
            $this->recordMigration($version, $batch);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            $previous = $e instanceof PDOException ? $e : null;

            throw new QueryException(
                "Migration {$version} failed: " . $e->getMessage(),
                0,
                $previous
            );
        }
    }

    /**
     * Collect SQL queries from migration execution.
     *
     * @param Migration $migration Migration instance
     * @param string $version Migration version
     */
    protected function collectMigrationSql(Migration $migration, string $version): void
    {
        // Create SQL collector
        $sqlCollector = new SqlQueryCollector();

        // Set up event dispatcher with SQL collector if available
        $originalDispatcher = $this->db->getEventDispatcher();
        $collectorDispatcher = null;
        $connection = $this->db->connection;
        $originalConnectionDispatcher = $connection->getEventDispatcher();

        if (interface_exists(EventDispatcherInterface::class)) {
            // Create a simple event dispatcher that collects SQL queries
            $collectorDispatcher = new class ($sqlCollector) implements EventDispatcherInterface {
                public function __construct(
                    private SqlQueryCollector $collector
                ) {
                }

                public function dispatch(object $event): object
                {
                    if ($event instanceof QueryExecutedEvent) {
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
            // Execute migration - SQL will be collected via event dispatcher
            $migration->up();

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
            // Migration failed, but we still want to show what SQL was attempted
            $this->collectedQueries[] = '-- Error during migration execution: ' . $e->getMessage();

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

        // Add migration record SQL
        $batch = $this->getNextBatchNumber();
        $schema = $this->db->schema();
        $dialect = $schema->getDialect();
        [$insertSql, $insertParams] = $dialect->buildMigrationInsertSql($this->migrationTable, $version, $batch);

        // Format SQL with parameters
        $formattedSql = $this->formatSqlWithParams($insertSql, $insertParams);
        $this->collectedQueries[] = '';
        $this->collectedQueries[] = "-- Would record migration in batch {$batch}";
        $this->collectedQueries[] = $formattedSql;
    }

    /**
     * Format SQL query with parameters.
     *
     * @param string $sql SQL query
     * @param array<int|string, mixed> $params Query parameters
     *
     * @return string Formatted SQL
     */
    protected function formatSqlWithParams(string $sql, array $params): string
    {
        if (empty($params)) {
            return $sql;
        }

        // Simple parameter replacement for display
        $formatted = $sql;
        foreach ($params as $key => $value) {
            $placeholder = is_int($key) ? '?' : ':' . $key;
            if (is_string($value)) {
                $formattedValue = "'" . addslashes($value) . "'";
            } elseif (is_int($value) || is_float($value)) {
                $formattedValue = (string)$value;
            } elseif (is_bool($value)) {
                $formattedValue = $value ? '1' : '0';
            } elseif ($value === null) {
                $formattedValue = 'NULL';
            } else {
                $formattedValue = "'" . addslashes((string)$value) . "'";
            }
            $formatted = str_replace($placeholder, $formattedValue, $formatted);
        }

        return $formatted;
    }

    /**
     * Load migration class instance.
     *
     * @param string $version Migration version
     *
     * @return Migration Migration instance
     */
    protected function loadMigration(string $version): Migration
    {
        $filename = $this->migrationPath . '/m' . $version . '.php';

        if (!file_exists($filename)) {
            throw new QueryException("Migration file not found: {$filename}");
        }

        require_once $filename;

        // Convert version to class name: YYYY_MM_DD_HHMMSS_description -> mYYYYMMDDHHMMSSDescription
        $parts = explode('_', $version);
        $className = 'm';
        foreach ($parts as $part) {
            if (is_numeric($part)) {
                $className .= $part;
            } else {
                $className .= ucfirst($part);
            }
        }

        // Try namespaced class first
        $fullClassName = 'tommyknocker\\pdodb\\migrations\\' . $className;
        if (!class_exists($fullClassName)) {
            // Try without namespace
            if (!class_exists($className)) {
                throw new QueryException("Migration class {$className} not found in file {$filename}. Expected: {$fullClassName} or {$className}");
            }
            $fullClassName = $className;
        }

        $migration = new $fullClassName($this->db);

        if (!($migration instanceof Migration)) {
            throw new QueryException("Migration class {$fullClassName} must extend Migration");
        }

        return $migration;
    }

    /**
     * Record migration in history table.
     *
     * @param string $version Migration version
     * @param int $batch Batch number
     */
    protected function recordMigration(string $version, int $batch): void
    {
        $schema = $this->db->schema();
        $dialect = $schema->getDialect();
        [$sql, $params] = $dialect->buildMigrationInsertSql($this->migrationTable, $version, $batch);
        $this->db->rawQuery($sql, $params);
    }

    /**
     * Remove migration record from history table.
     *
     * @param string $version Migration version
     */
    protected function removeMigrationRecord(string $version): void
    {
        $this->db->find()
            ->from($this->migrationTable)
            ->where('version', $version)
            ->delete();
    }

    /**
     * Get next batch number.
     *
     * @return int Next batch number
     */
    protected function getNextBatchNumber(): int
    {
        $maxBatch = $this->db->find()
            ->from($this->migrationTable)
            ->select(['max_batch' => 'MAX(batch)'])
            ->getValue();

        return (int)($maxBatch ?? 0) + 1;
    }

    /**
     * Create a new migration file.
     *
     * @param string $name Migration name
     *
     * @return string Path to created migration file
     */
    public function create(string $name): string
    {
        $version = date('Y_m_d_His') . '_' . $this->sanitizeName($name);
        // Convert version to class name: mYYYY_MM_DD_HHMMSS_description -> mYYYYMMDDHHMMSSDescription
        $parts = explode('_', $version);
        $className = 'm';
        foreach ($parts as $part) {
            if (is_numeric($part)) {
                $className .= $part;
            } else {
                $className .= ucfirst($part);
            }
        }

        $filename = $this->migrationPath . '/m' . $version . '.php';

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\migrations;

/**
 * Migration: {$name}
 *
 * Created: {$version}
 */
class {$className} extends Migration
{
    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        // TODO: Implement migration up logic
    }

    /**
     * {@inheritDoc}
     */
    public function down(): void
    {
        // TODO: Implement migration down logic
    }
}

PHP;

        file_put_contents($filename, $content);

        return $filename;
    }

    /**
     * Sanitize migration name for use in class name and filename.
     *
     * @param string $name Migration name
     *
     * @return string Sanitized name
     */
    protected function sanitizeName(string $name): string
    {
        // Convert to lowercase and replace non-alphanumeric with underscores
        $sanitized = preg_replace('/[^a-z0-9_]/', '_', strtolower($name));
        if ($sanitized === null) {
            $sanitized = '';
        }
        // Remove multiple underscores
        $sanitized = preg_replace('/_+/', '_', $sanitized);
        if ($sanitized === null) {
            $sanitized = '';
        }
        // Remove leading/trailing underscores
        $result = trim($sanitized, '_');
        return $result !== '' ? $result : 'migration';
    }
}
