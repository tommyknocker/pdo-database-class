<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\DatabaseManager;
use tommyknocker\pdodb\connection\ExtensionChecker;
use tommyknocker\pdodb\exceptions\ResourceException;

/**
 * Database management command.
 */
class DbCommand extends Command
{
    /**
     * Create db command.
     */
    public function __construct()
    {
        parent::__construct('db', 'Manage databases');
    }

    /**
     * Execute command.
     *
     * @return int Exit code
     */
    public function execute(): int
    {
        $subcommand = $this->getArgument(0);

        if ($subcommand === null || $subcommand === '--help' || $subcommand === 'help') {
            $this->showHelp();
            return 0;
        }

        return match ($subcommand) {
            'create' => $this->create(),
            'drop' => $this->drop(),
            'exists' => $this->exists(),
            'list' => $this->list(),
            'info' => $this->showInfo(),
            default => $this->fail("Unknown subcommand: {$subcommand}"),
        };
    }

    /**
     * Create a database.
     *
     * @return int Exit code
     */
    protected function create(): int
    {
        $this->showDatabaseHeader();

        $databaseName = $this->getArgument(1);

        if ($databaseName === null) {
            $databaseName = static::readInput('Enter database name');
            if ($databaseName === '') {
                $this->showError('Database name is required');
            }
        }

        // Ask for confirmation unless --force is used
        $force = $this->getOption('force', false);
        if (!$force) {
            $confirmed = static::readConfirmation(
                "Are you sure you want to create database '{$databaseName}'? This will create a new database",
                true
            );

            if (!$confirmed) {
                static::info('Operation cancelled');
                return 0;
            }
        }

        try {
            $config = static::loadDatabaseConfig();
            $db = DatabaseManager::createServerConnection($config);
            DatabaseManager::create($databaseName, $db);
            static::success("Database '{$databaseName}' created successfully");
            return 0;
        } catch (ResourceException $e) {
            $this->showError($e->getMessage());
        }
    }

    /**
     * Drop a database.
     *
     * @return int Exit code
     */
    protected function drop(): int
    {
        $this->showDatabaseHeader();

        $databaseName = $this->getArgument(1);

        if ($databaseName === null) {
            $databaseName = static::readInput('Enter database name');
            if ($databaseName === '') {
                $this->showError('Database name is required');
            }
        }

        // Ask for confirmation unless --force is used
        $force = $this->getOption('force', false);
        if (!$force) {
            $confirmed = static::readConfirmation(
                "Are you sure you want to drop database '{$databaseName}'? This action cannot be undone",
                false
            );

            if (!$confirmed) {
                static::info('Operation cancelled');
                return 0;
            }
        }

        try {
            $config = static::loadDatabaseConfig();
            $db = DatabaseManager::createServerConnection($config);
            DatabaseManager::drop($databaseName, $db);
            static::success("Database '{$databaseName}' dropped successfully");
            return 0;
        } catch (ResourceException $e) {
            $this->showError($e->getMessage());
        }
    }

    /**
     * Check if a database exists.
     *
     * @return int Exit code
     */
    protected function exists(): int
    {
        $this->showDatabaseHeader();

        $databaseName = $this->getArgument(1);

        if ($databaseName === null) {
            $databaseName = static::readInput('Enter database name');
            if ($databaseName === '') {
                $this->showError('Database name is required');
            }
        }

        try {
            $config = static::loadDatabaseConfig();
            $db = DatabaseManager::createServerConnection($config);
            $exists = DatabaseManager::exists($databaseName, $db);

            if ($exists) {
                static::success("Database '{$databaseName}' exists");
                return 0;
            }

            static::info("Database '{$databaseName}' does not exist");
            return 1;
        } catch (ResourceException $e) {
            $this->showError($e->getMessage());
        }
    }

    /**
     * List all databases.
     *
     * @return int Exit code
     */
    protected function list(): int
    {
        $this->showDatabaseHeader();

        try {
            $config = static::loadDatabaseConfig();
            $db = DatabaseManager::createServerConnection($config);
            $databases = DatabaseManager::list($db);

            if (empty($databases)) {
                static::info('No databases found');
                return 0;
            }

            $this->write('Databases (' . count($databases) . "):\n\n");
            foreach ($databases as $database) {
                $this->writeln("  {$database}");
            }

            return 0;
        } catch (ResourceException $e) {
            $this->showError($e->getMessage());
        }
    }

    /**
     * Show database information.
     *
     * @return int Exit code
     */
    protected function showInfo(): int
    {
        $this->showDatabaseHeader();

        try {
            // Validate extension before attempting connection
            $config = static::loadDatabaseConfig();
            if (isset($config['driver']) && is_string($config['driver'])) {
                $driver = mb_strtolower($config['driver'], 'UTF-8');

                try {
                    ExtensionChecker::validate($driver);
                } catch (\InvalidArgumentException $e) {
                    $this->showError($e->getMessage());
                }
            }

            $db = $this->getDb();
            $info = DatabaseManager::getInfo($db);

            $this->writeln("Database Information:\n");
            foreach ($info as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $displayValue = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                if (is_int($value) && $key === 'file_size') {
                    $displayValue = number_format($value) . ' bytes';
                }
                $this->write('  ' . ucfirst(str_replace('_', ' ', $key)) . ": {$displayValue}\n");
            }

            return 0;
        } catch (\Exception $e) {
            $this->showError($e->getMessage());
        }
    }

    /**
     * Show database header with current database information.
     */
    protected function showDatabaseHeader(): void
    {
        try {
            $db = $this->getDb();
            $driver = static::getDriverName($db);
            $info = DatabaseManager::getInfo($db);

            $this->writeln('PDOdb Database Management');
            $this->write("Database: {$driver}");
            if (isset($info['current_database']) && is_string($info['current_database']) && $info['current_database'] !== '') {
                $this->write(" ({$info['current_database']})");
            }
            $this->writeln("\n");
        } catch (\Exception $e) {
            // If we can't get database info, just show driver
            try {
                $db = $this->getDb();
                $driver = static::getDriverName($db);
                $this->writeln('PDOdb Database Management');
                $this->writeln("Database: {$driver}\n");
            } catch (\Exception $e2) {
                // If even that fails, just show header
                $this->writeln("PDOdb Database Management\n");
            }
        }
    }

    /**
     * Show help message.
     *
     * @return int Exit code
     */
    protected function showHelp(): int
    {
        $this->writeln("Database Management\n");
        $this->writeln("Usage: pdodb db <subcommand> [arguments] [options]\n");
        $this->writeln('Subcommands:');
        $this->writeln('  create [name]      Create a new database (name will be prompted if not provided)');
        $this->writeln('  drop [name]        Drop a database (name will be prompted if not provided)');
        $this->writeln('  exists [name]      Check if a database exists (name will be prompted if not provided)');
        $this->writeln('  list               List all databases');
        $this->writeln('  info               Show information about current database');
        $this->writeln("  help               Show this help message\n");
        $this->writeln('Options:');
        $this->writeln("  --force            Execute without confirmation\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb db create myapp');
        $this->writeln('  pdodb db create myapp --force');
        $this->writeln('  pdodb db create    (will prompt for name)');
        $this->writeln('  pdodb db drop myapp');
        $this->writeln('  pdodb db drop myapp --force');
        $this->writeln('  pdodb db exists myapp');
        $this->writeln('  pdodb db list');
        $this->writeln('  pdodb db info');
        return 0;
    }
}
