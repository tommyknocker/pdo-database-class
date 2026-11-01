<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\runner;

use Psr\EventDispatcher\EventDispatcherInterface;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\PdoDb;

/**
 * What migrations and seeds have in common: a directory of PHP files, a history table in the database, a dry-run
 * mode that only lists what would happen, and the events sent to the connection's dispatcher.
 */
abstract class AbstractFileRunner
{
    /** @var PdoDb Database instance */
    protected PdoDb $db;

    /** @var bool Dry-run mode (list the files without executing PHP) */
    protected bool $dryRun = false;

    /** @var array<int, string> Collected SQL queries in dry-run mode */
    protected array $collectedQueries = [];

    public function __construct(PdoDb $db)
    {
        $this->db = $db;
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
     * Get collected SQL queries (for dry-run mode).
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
     * Name of the table that records what ran.
     */
    abstract protected function historyTableName(): string;

    /**
     * Create the history table when it is missing.
     */
    abstract protected function ensureHistoryTable(): void;

    /**
     * A directory path without a trailing separator; it has to exist.
     *
     * @param string $kind What the files are, for the error message ("Migration", "Seed")
     */
    protected static function existingDirectory(string $path, string $kind): string
    {
        $path = rtrim($path, '/\\');
        if (!is_dir($path)) {
            throw new QueryException("{$kind} path does not exist: {$path}");
        }

        return $path;
    }

    /**
     * Whether the history table can be read. A dry run never creates it; any other run creates it on first use.
     */
    protected function historyTableAvailable(): bool
    {
        if ($this->db->schema()->tableExists($this->historyTableName())) {
            return true;
        }
        if ($this->dryRun) {
            return false;
        }
        $this->ensureHistoryTable();

        return true;
    }

    /**
     * Get event dispatcher from database connection.
     */
    protected function getEventDispatcher(): ?EventDispatcherInterface
    {
        return $this->db->connection->getEventDispatcher();
    }

    /**
     * Get driver name from database connection.
     */
    protected function getDriverName(): string
    {
        return $this->db->connection->getDriverName();
    }
}
