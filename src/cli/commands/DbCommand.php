<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\DatabaseManager;
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
            default => $this->showError("Unknown subcommand: {$subcommand}"),
        };
    }

    /**
     * Create a database.
     *
     * @return int Exit code
     */
    protected function create(): int
    {
        $databaseName = $this->getArgument(1);

        if ($databaseName === null) {
            $this->showError('Database name is required');
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
        $databaseName = $this->getArgument(1);

        if ($databaseName === null) {
            $this->showError('Database name is required');
        }

        // Ask for confirmation
        $confirmed = static::readConfirmation("Are you sure you want to drop database '{$databaseName}'? This action cannot be undone", false);

        if (!$confirmed) {
            static::info('Operation cancelled');
            return 0;
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
        $databaseName = $this->getArgument(1);

        if ($databaseName === null) {
            $this->showError('Database name is required');
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
        try {
            $config = static::loadDatabaseConfig();
            $db = DatabaseManager::createServerConnection($config);
            $databases = DatabaseManager::list($db);

            if (empty($databases)) {
                static::info('No databases found');
                return 0;
            }

            echo 'Databases (' . count($databases) . "):\n\n";
            foreach ($databases as $database) {
                echo "  {$database}\n";
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
        try {
            $db = $this->getDb();
            $info = DatabaseManager::getInfo($db);

            echo "Database Information:\n\n";
            foreach ($info as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $displayValue = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                if (is_int($value) && $key === 'file_size') {
                    $displayValue = number_format($value) . ' bytes';
                }
                echo '  ' . ucfirst(str_replace('_', ' ', $key)) . ": {$displayValue}\n";
            }

            return 0;
        } catch (\Exception $e) {
            $this->showError($e->getMessage());
        }
    }

    /**
     * Show help message.
     *
     * @return int Exit code
     */
    protected function showHelp(): int
    {
        echo "Database Management\n\n";
        echo "Usage: pdodb db <subcommand> [arguments] [options]\n\n";
        echo "Subcommands:\n";
        echo "  create <name>     Create a new database\n";
        echo "  drop <name>        Drop a database (with confirmation)\n";
        echo "  exists <name>      Check if a database exists\n";
        echo "  list               List all databases\n";
        echo "  info               Show information about current database\n";
        echo "  help               Show this help message\n\n";
        echo "Examples:\n";
        echo "  pdodb db create myapp\n";
        echo "  pdodb db drop myapp\n";
        echo "  pdodb db exists myapp\n";
        echo "  pdodb db list\n";
        echo "  pdodb db info\n";
        return 0;
    }
}
