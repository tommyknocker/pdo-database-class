<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\DumpManager;
use tommyknocker\pdodb\exceptions\ResourceException;

/**
 * Dump and restore command.
 */
class DumpCommand extends Command
{
    /**
     * Create dump command.
     */
    public function __construct()
    {
        parent::__construct('dump', 'Dump and restore database');
    }

    /**
     * Execute command.
     *
     * @return int Exit code
     */
    public function execute(): int
    {
        // Show help if explicitly requested via option or argument
        if ($this->getOption('help', false)) {
            $this->showHelp();
            return 0;
        }

        $firstArg = $this->getArgument(0);

        if ($firstArg === '--help' || $firstArg === 'help') {
            $this->showHelp();
            return 0;
        }

        if ($firstArg === 'restore') {
            return $this->restore();
        }

        // If no arguments, check if dump-specific options are provided
        // If yes, dump entire database; if no, show help
        if ($firstArg === null) {
            $hasDumpOptions = $this->getOption('schema-only', false) !== false
                || $this->getOption('data-only', false) !== false
                || $this->getOption('output') !== null;
            if (!$hasDumpOptions) {
                $this->showHelp();
                return 0;
            }
        }

        // First arg is table name (dump specific table), or null for entire database
        return $this->dump();
    }

    /**
     * Dump database or table.
     *
     * @return int Exit code
     */
    protected function dump(): int
    {
        $table = $this->getArgument(0);
        $schemaOnly = (bool)$this->getOption('schema-only', false);
        $dataOnly = (bool)$this->getOption('data-only', false);
        $output = $this->getOption('output');

        if ($schemaOnly && $dataOnly) {
            return $this->showError('Cannot use --schema-only and --data-only together');
        }

        try {
            $db = $this->getDb();
            $sql = DumpManager::dump($db, is_string($table) ? $table : null, $schemaOnly, $dataOnly);

            if (is_string($output) && $output !== '') {
                $written = file_put_contents($output, $sql);
                if ($written === false) {
                    return $this->showError("Failed to write dump to: {$output}");
                }
                static::success("Dump written to: {$output}");
                return 0;
            }

            echo $sql;
            return 0;
        } catch (ResourceException $e) {
            return $this->showError($e->getMessage());
        }
    }

    /**
     * Restore database from dump file.
     *
     * @return int Exit code
     */
    protected function restore(): int
    {
        $file = $this->getArgument(1);
        if (!is_string($file) || $file === '') {
            return $this->showError('Dump file path is required');
        }

        $force = (bool)$this->getOption('force', false);

        if (!$force) {
            $confirmed = static::readConfirmation(
                "Are you sure you want to restore from '{$file}'? This will modify your database",
                false
            );

            if (!$confirmed) {
                static::info('Operation cancelled');
                return 0;
            }
        }

        try {
            $db = $this->getDb();
            DumpManager::restore($db, $file, $force);
            static::success("Database restored from: {$file}");
            return 0;
        } catch (ResourceException $e) {
            return $this->showError($e->getMessage());
        }
    }

    /**
     * Show help message.
     *
     * @return int Exit code
     */
    protected function showHelp(): int
    {
        echo "Database Dump and Restore\n\n";
        echo "Usage:\n";
        echo "  pdodb dump [table] [--schema-only] [--data-only] [--output=file.sql]\n";
        echo "  pdodb dump restore <file> [--force]\n\n";
        echo "Commands:\n";
        echo "  dump [table]        Dump database or specific table to SQL (default)\n";
        echo "  restore <file>      Restore database from SQL dump file\n\n";
        echo "Options:\n";
        echo "  --schema-only       Dump only schema (CREATE TABLE, indexes, etc.)\n";
        echo "  --data-only         Dump only data (INSERT statements)\n";
        echo "  --output=<file>     Write dump to file instead of stdout\n";
        echo "  --force             Skip confirmation (for restore)\n\n";
        echo "Examples:\n";
        echo "  pdodb dump --output=backup.sql\n";
        echo "  pdodb dump users --data-only --output=users_data.sql\n";
        echo "  pdodb dump --schema-only --output=schema.sql\n";
        echo "  pdodb dump restore backup.sql\n";
        echo "  pdodb dump restore backup.sql --force\n";
        return 0;
    }
}
