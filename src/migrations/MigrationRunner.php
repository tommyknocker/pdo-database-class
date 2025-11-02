<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\migrations;

use PDOException;
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
     * Ensure migration table exists.
     */
    protected function ensureMigrationTable(): void
    {
        $schema = $this->db->schema();
        $driver = $schema->getDialect()->getDriverName();

        // Check if table exists
        $exists = $schema->tableExists($this->migrationTable);

        if (!$exists) {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $this->db->rawQuery("CREATE TABLE {$this->migrationTable} (
                    version VARCHAR(255) PRIMARY KEY,
                    apply_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    batch INTEGER NOT NULL
                ) ENGINE=InnoDB");
            } elseif ($driver === 'pgsql') {
                $this->db->rawQuery("CREATE TABLE {$this->migrationTable} (
                    version VARCHAR(255) PRIMARY KEY,
                    apply_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    batch INTEGER NOT NULL
                )");
            } else {
                // SQLite
                $this->db->rawQuery("CREATE TABLE {$this->migrationTable} (
                    version TEXT PRIMARY KEY,
                    apply_time TEXT DEFAULT CURRENT_TIMESTAMP,
                    batch INTEGER NOT NULL
                )");
            }
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

        return $query->get();
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

        $applied = [];
        $batch = $this->getNextBatchNumber();

        foreach ($newMigrations as $version) {
            try {
                $this->db->startTransaction();
                $migration = $this->loadMigration($version);
                $migration->up();
                $this->recordMigration($version, $batch);
                $this->db->commit();
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
     * Migrate to a specific version.
     *
     * @param string $version Target version
     */
    public function migrateTo(string $version): void
    {
        $history = $this->getMigrationHistory();
        $currentVersion = $this->getMigrationVersion();

        if ($currentVersion === null) {
            // Apply all migrations up to version
            $allMigrations = $this->getAllMigrationFiles();
            $targetIndex = array_search($version, $allMigrations, true);
            if ($targetIndex === false) {
                throw new QueryException("Migration version {$version} not found");
            }

            $migrationsToApply = array_slice($allMigrations, 0, $targetIndex + 1);
            foreach ($migrationsToApply as $migrationVersion) {
                $this->migrateUp($migrationVersion);
            }
        } elseif ($currentVersion === $version) {
            // Already at target version
            return;
        } else {
            // Rollback or apply migrations
            $allMigrations = $this->getAllMigrationFiles();
            $currentIndex = array_search($currentVersion, $allMigrations, true);
            $targetIndex = array_search($version, $allMigrations, true);

            if ($targetIndex === false) {
                throw new QueryException("Migration version {$version} not found");
            }

            if ($targetIndex < $currentIndex) {
                // Rollback
                $migrationsToRollback = array_slice($allMigrations, $targetIndex + 1, $currentIndex - $targetIndex);
                foreach (array_reverse($migrationsToRollback) as $migrationVersion) {
                    $this->migrateDown(1);
                }
            } else {
                // Apply
                $migrationsToApply = array_slice($allMigrations, $currentIndex + 1, $targetIndex - $currentIndex);
                foreach ($migrationsToApply as $migrationVersion) {
                    $this->migrateUp($migrationVersion);
                }
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
        $driver = $schema->getDialect()->getDriverName();

        if ($driver === 'pgsql') {
            $this->db->rawQuery(
                "INSERT INTO {$this->migrationTable} (version, batch) VALUES (:version, :batch)",
                ['version' => $version, 'batch' => $batch]
            );
        } else {
            $this->db->rawQuery(
                "INSERT INTO {$this->migrationTable} (version, batch) VALUES (?, ?)",
                [$version, $batch]
            );
        }
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
