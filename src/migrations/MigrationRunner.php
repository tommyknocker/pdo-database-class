<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\migrations;

use PDOException;
use tommyknocker\pdodb\events\MigrationCompletedEvent;
use tommyknocker\pdodb\events\MigrationRolledBackEvent;
use tommyknocker\pdodb\events\MigrationStartedEvent;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\runner\AbstractFileRunner;

/**
 * Migration runner for executing database migrations.
 *
 * This class manages migration execution, rollback, and history tracking.
 */
class MigrationRunner extends AbstractFileRunner
{
    /** @var string Path to migration files directory */
    protected string $migrationPath;

    /** @var string Migration table name */
    protected string $migrationTable = '__migrations';

    /** Namespace tried first when looking for a migration class; a class without a namespace is always accepted. */
    protected string $migrationNamespace = 'tommyknocker\\pdodb\\migrations';

    /**
     * Set the namespace your migration classes are declared in.
     *
     * @return $this
     */
    public function setMigrationNamespace(string $namespace): static
    {
        $this->migrationNamespace = trim($namespace, '\\');

        return $this;
    }

    /**
     * MigrationRunner constructor.
     *
     * @param PdoDb $db Database instance
     * @param string $migrationPath Path to migration files directory
     */
    public function __construct(PdoDb $db, string $migrationPath)
    {
        parent::__construct($db);
        $this->migrationPath = static::existingDirectory($migrationPath, 'Migration');
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
        if (!$this->historyTableAvailable()) {
            return [];
        }
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

        if ($this->dryRun) {
            $this->clearCollectedQueries();
            foreach ($newMigrations as $version) {
                $this->migrateUp($version);
            }

            return $newMigrations;
        }

        $applied = [];
        $batch = $this->getNextBatchNumber();

        foreach ($newMigrations as $version) {
            $migration = null;

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
                $migration = $this->loadMigration($version);
                $this->runMigrationStep($migration, function () use ($migration, $version, $batch): void {
                    $migration->up();
                    $this->recordMigration($version, $batch);
                });
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
                $previous = $e instanceof PDOException ? $e : null;

                throw new QueryException(
                    $this->failureMessage("Migration {$version} failed: ", $e, $migration),
                    0,
                    $previous
                );
            }
        }

        return $applied;
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

        // In dry-run mode, just return the list without executing
        if ($this->dryRun) {
            $this->clearCollectedQueries();
            $rolledBack = [];

            foreach ($history as $record) {
                $version = $record['version'];
                $this->collectedQueries[] = "-- Rollback Migration: {$version}";
                $this->collectedQueries[] = '-- Dry-run: migration PHP is not executed.';
                $this->collectedQueries[] = '-- Would remove migration record';
                $rolledBack[] = $version;
            }

            return $rolledBack;
        }

        $rolledBack = [];

        foreach ($history as $record) {
            $version = $record['version'];

            $migration = null;

            try {
                $migration = $this->loadMigration($version);
                $this->runMigrationStep($migration, function () use ($migration, $version): void {
                    $migration->down();
                    $this->removeMigrationRecord($version);
                });
                $rolledBack[] = $version;
            } catch (\Throwable $e) {
                $previous = $e instanceof PDOException ? $e : null;

                throw new QueryException(
                    $this->failureMessage("Rollback of migration {$version} failed: ", $e, $migration),
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
        // In dry-run mode, just collect info without executing
        if ($this->dryRun) {
            $this->collectedQueries[] = "-- Rollback Migration: {$version}";
            $this->collectedQueries[] = '-- Dry-run: migration PHP is not executed.';
            $this->collectedQueries[] = '-- Would remove migration record';
            return;
        }

        $migration = null;

        try {
            $migration = $this->loadMigration($version);
            $this->runMigrationStep($migration, function () use ($migration, $version): void {
                $migration->down();
                $this->removeMigrationRecord($version);
            });

            // Dispatch migration rolled back event
            $dispatcher = $this->getEventDispatcher();
            if ($dispatcher !== null) {
                $dispatcher->dispatch(new MigrationRolledBackEvent(
                    $version,
                    $this->getDriverName()
                ));
            }
        } catch (\Throwable $e) {
            $previous = $e instanceof PDOException ? $e : null;

            throw new QueryException(
                $this->failureMessage("Rollback of migration {$version} failed: ", $e, $migration),
                0,
                $previous
            );
        }
    }

    /**
     * Run one migration step, inside a transaction when the migration and the database allow it.
     *
     * @param callable(): void $step Migration body plus the history bookkeeping
     */
    protected function runMigrationStep(Migration $migration, callable $step): void
    {
        if (!$this->usesTransaction($migration)) {
            $step();

            return;
        }

        $this->db->startTransaction();

        try {
            $step();
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            throw $e;
        }
    }

    /**
     * A transaction protects a migration only where DDL can be rolled back; elsewhere it would roll back the
     * history row while the schema changes stay, so the migration decides (null follows the database).
     */
    protected function usesTransaction(Migration $migration): bool
    {
        return $migration->transactional() ?? $this->db->schema()->getDialect()->supportsTransactionalDdl();
    }

    /**
     * Error text for a failed step; says so when the failure could not be rolled back.
     */
    protected function failureMessage(string $prefix, \Throwable $e, ?Migration $migration): string
    {
        $message = $prefix . $e->getMessage();
        if ($migration !== null && !$this->usesTransaction($migration)) {
            $message .= ' This migration ran without a transaction, so statements executed before the failure stay'
                . ' applied and the migration was not recorded. Check the schema and finish or revert it by hand'
                . ' before running the migration again.';
        }

        return $message;
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

        if ($this->dryRun) {
            $batch = $this->getNextBatchNumber();
            $this->collectedQueries[] = "-- Migration: {$version}";
            $this->collectedQueries[] = '-- Dry-run: migration PHP is not executed.';
            $this->collectedQueries[] = '-- SQL from arbitrary PHP cannot be shown without side effects on some drivers.';
            $this->collectedQueries[] = "-- Would record migration in batch {$batch}";
            return;
        }

        $migration = null;

        try {
            $migration = $this->loadMigration($version);
            $this->runMigrationStep($migration, function () use ($migration, $version): void {
                $migration->up();
                $this->recordMigration($version, $this->getNextBatchNumber());
            });
        } catch (\Throwable $e) {
            $previous = $e instanceof PDOException ? $e : null;

            throw new QueryException(
                $this->failureMessage("Migration {$version} failed: ", $e, $migration),
                0,
                $previous
            );
        }
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
        $fullClassName = ($this->migrationNamespace !== '' ? $this->migrationNamespace . '\\' : '') . $className;
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
        $this->ensureMigrationTable();
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
        if (!$this->historyTableAvailable()) {
            return 1;
        }
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

    protected function historyTableName(): string
    {
        return $this->migrationTable;
    }

    protected function ensureHistoryTable(): void
    {
        $this->ensureMigrationTable();
    }
}
