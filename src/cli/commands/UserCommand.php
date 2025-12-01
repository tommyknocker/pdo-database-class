<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\DatabaseManager;
use tommyknocker\pdodb\cli\UserManager;
use tommyknocker\pdodb\exceptions\ResourceException;

/**
 * User management command.
 */
class UserCommand extends Command
{
    /**
     * Create user command.
     */
    public function __construct()
    {
        parent::__construct('user', 'Manage database users');
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
            'grant' => $this->grant(),
            'revoke' => $this->revoke(),
            'password' => $this->password(),
            default => $this->showError("Unknown subcommand: {$subcommand}"),
        };
    }

    /**
     * Create a database user.
     *
     * @return int Exit code
     */
    protected function create(): int
    {
        $this->showDatabaseHeader();

        $username = $this->getArgument(1);

        if ($username === null) {
            $username = static::readInput('Enter username');
            if ($username === '') {
                return $this->showError('Username is required');
            }
        }

        $password = $this->getOption('password');
        if ($password === null) {
            $password = static::readPassword('Enter password');
            if ($password === '') {
                return $this->showError('Password is required');
            }
        }

        $host = $this->getOption('host');

        // Ask for confirmation unless --force is used
        $force = $this->getOption('force', false);
        if (!$force) {
            $hostText = $host !== null ? "@{$host}" : '';
            $confirmed = static::readConfirmation(
                "Are you sure you want to create user '{$username}{$hostText}'? This will create a new database user",
                true
            );

            if (!$confirmed) {
                static::info('Operation cancelled');
                return 0;
            }
        }

        try {
            $config = static::loadDatabaseConfig();
            $db = UserManager::createServerConnection($config);
            UserManager::create($username, $password, $host, $db);
            $hostText = $host !== null ? "@{$host}" : '';
            static::success("User '{$username}{$hostText}' created successfully");
            return 0;
        } catch (ResourceException $e) {
            return $this->showError($e->getMessage());
        }
    }

    /**
     * Drop a database user.
     *
     * @return int Exit code
     */
    protected function drop(): int
    {
        $this->showDatabaseHeader();

        $username = $this->getArgument(1);

        if ($username === null) {
            $username = static::readInput('Enter username');
            if ($username === '') {
                return $this->showError('Username is required');
            }
        }

        $host = $this->getOption('host');

        // Ask for confirmation unless --force is used
        $force = $this->getOption('force', false);
        if (!$force) {
            $hostText = $host !== null ? "@{$host}" : '';
            $confirmed = static::readConfirmation(
                "Are you sure you want to drop user '{$username}{$hostText}'? This action cannot be undone",
                false
            );

            if (!$confirmed) {
                static::info('Operation cancelled');
                return 0;
            }
        }

        try {
            $config = static::loadDatabaseConfig();
            $db = UserManager::createServerConnection($config);
            UserManager::drop($username, $host, $db);
            $hostText = $host !== null ? "@{$host}" : '';
            static::success("User '{$username}{$hostText}' dropped successfully");
            return 0;
        } catch (ResourceException $e) {
            return $this->showError($e->getMessage());
        }
    }

    /**
     * Check if a database user exists.
     *
     * @return int Exit code
     */
    protected function exists(): int
    {
        $this->showDatabaseHeader();

        $username = $this->getArgument(1);

        if ($username === null) {
            $username = static::readInput('Enter username');
            if ($username === '') {
                return $this->showError('Username is required');
            }
        }

        $host = $this->getOption('host');

        try {
            $config = static::loadDatabaseConfig();
            $db = UserManager::createServerConnection($config);
            $exists = UserManager::exists($username, $host, $db);

            $hostText = $host !== null ? "@{$host}" : '';
            if ($exists) {
                static::success("User '{$username}{$hostText}' exists");
                return 0;
            }

            static::info("User '{$username}{$hostText}' does not exist");
            return 1;
        } catch (ResourceException $e) {
            return $this->showError($e->getMessage());
        }
    }

    /**
     * List all database users.
     *
     * @return int Exit code
     */
    protected function list(): int
    {
        $this->showDatabaseHeader();

        try {
            $config = static::loadDatabaseConfig();
            $db = UserManager::createServerConnection($config);
            $users = UserManager::list($db);

            if (empty($users)) {
                static::info('No users found');
                return 0;
            }

            echo 'Users (' . count($users) . "):\n\n";
            foreach ($users as $user) {
                $userHost = $user['user_host'] ?? ($user['username'] . ($user['host'] !== null ? '@' . $user['host'] : ''));
                echo "  {$userHost}\n";
            }

            return 0;
        } catch (ResourceException $e) {
            return $this->showError($e->getMessage());
        }
    }

    /**
     * Show user information.
     *
     * @return int Exit code
     */
    protected function showInfo(): int
    {
        $this->showDatabaseHeader();

        $username = $this->getArgument(1);

        if ($username === null) {
            $username = static::readInput('Enter username');
            if ($username === '') {
                return $this->showError('Username is required');
            }
        }

        $host = $this->getOption('host');

        try {
            $config = static::loadDatabaseConfig();
            $db = UserManager::createServerConnection($config);
            $info = UserManager::getInfo($username, $host, $db);

            if (empty($info)) {
                $hostText = $host !== null ? "@{$host}" : '';
                static::info("User '{$username}{$hostText}' not found");
                return 1;
            }

            echo "User Information:\n\n";
            foreach ($info as $key => $value) {
                if ($value === null) {
                    continue;
                }
                if ($key === 'privileges' && is_array($value)) {
                    echo '  Privileges (' . count($value) . "):\n";
                    foreach ($value as $privilege) {
                        if (is_array($privilege)) {
                            $privText = implode(', ', array_filter($privilege, fn ($v) => $v !== null && $v !== ''));
                            echo "    - {$privText}\n";
                        } else {
                            echo "    - {$privilege}\n";
                        }
                    }
                    continue;
                }
                $displayValue = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                echo '  ' . ucfirst(str_replace('_', ' ', $key)) . ": {$displayValue}\n";
            }

            return 0;
        } catch (ResourceException $e) {
            return $this->showError($e->getMessage());
        }
    }

    /**
     * Grant privileges to a user.
     *
     * @return int Exit code
     */
    protected function grant(): int
    {
        $this->showDatabaseHeader();

        $username = $this->getArgument(1);
        $privileges = $this->getArgument(2);

        if ($username === null) {
            $username = static::readInput('Enter username');
            if ($username === '') {
                return $this->showError('Username is required');
            }
        }

        if ($privileges === null) {
            $privileges = static::readInput('Enter privileges (e.g., SELECT,INSERT,UPDATE or ALL)');
            if ($privileges === '') {
                return $this->showError('Privileges are required');
            }
        }

        $host = $this->getOption('host');
        $database = $this->getOption('database');
        $table = $this->getOption('table');

        try {
            $config = static::loadDatabaseConfig();
            $db = UserManager::createServerConnection($config);
            UserManager::grant($username, $privileges, $database, $table, $host, $db);

            $target = '';
            if ($database !== null) {
                $target = $database;
                if ($table !== null) {
                    $target .= '.' . $table;
                } else {
                    $target .= '.*';
                }
            } else {
                $target = '*.*';
            }

            $hostText = $host !== null ? "@{$host}" : '';
            static::success("Granted {$privileges} on {$target} to '{$username}{$hostText}'");
            return 0;
        } catch (ResourceException $e) {
            return $this->showError($e->getMessage());
        }
    }

    /**
     * Revoke privileges from a user.
     *
     * @return int Exit code
     */
    protected function revoke(): int
    {
        $this->showDatabaseHeader();

        $username = $this->getArgument(1);
        $privileges = $this->getArgument(2);

        if ($username === null) {
            $username = static::readInput('Enter username');
            if ($username === '') {
                return $this->showError('Username is required');
            }
        }

        if ($privileges === null) {
            $privileges = static::readInput('Enter privileges (e.g., SELECT,INSERT,UPDATE or ALL)');
            if ($privileges === '') {
                return $this->showError('Privileges are required');
            }
        }

        $host = $this->getOption('host');
        $database = $this->getOption('database');
        $table = $this->getOption('table');

        try {
            $config = static::loadDatabaseConfig();
            $db = UserManager::createServerConnection($config);
            UserManager::revoke($username, $privileges, $database, $table, $host, $db);

            $target = '';
            if ($database !== null) {
                $target = $database;
                if ($table !== null) {
                    $target .= '.' . $table;
                } else {
                    $target .= '.*';
                }
            } else {
                $target = '*.*';
            }

            $hostText = $host !== null ? "@{$host}" : '';
            static::success("Revoked {$privileges} on {$target} from '{$username}{$hostText}'");
            return 0;
        } catch (ResourceException $e) {
            return $this->showError($e->getMessage());
        }
    }

    /**
     * Change user password.
     *
     * @return int Exit code
     */
    protected function password(): int
    {
        $this->showDatabaseHeader();

        $username = $this->getArgument(1);

        if ($username === null) {
            $username = static::readInput('Enter username');
            if ($username === '') {
                return $this->showError('Username is required');
            }
        }

        $newPassword = $this->getOption('password');
        if ($newPassword === null) {
            $newPassword = static::readPassword('Enter new password');
            if ($newPassword === '') {
                return $this->showError('Password is required');
            }
        }

        $host = $this->getOption('host');

        try {
            $config = static::loadDatabaseConfig();
            $db = UserManager::createServerConnection($config);
            UserManager::changePassword($username, $newPassword, $host, $db);

            $hostText = $host !== null ? "@{$host}" : '';
            static::success("Password changed for user '{$username}{$hostText}'");
            return 0;
        } catch (ResourceException $e) {
            return $this->showError($e->getMessage());
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

            if (getenv('PHPUNIT') === false) {
                echo "PDOdb User Management\n";
                echo "Database: {$driver}";
                if (isset($info['current_database']) && is_string($info['current_database']) && $info['current_database'] !== '') {
                    echo " ({$info['current_database']})";
                }
                echo "\n\n";
            }
        } catch (\Exception $e) {
            // If we can't get database info, just show driver
            try {
                $db = $this->getDb();
                $driver = static::getDriverName($db);
                if (getenv('PHPUNIT') === false) {
                    echo "PDOdb User Management\n";
                    echo "Database: {$driver}\n\n";
                }
            } catch (\Exception $e2) {
                // If even that fails, just show header
                if (getenv('PHPUNIT') === false) {
                    echo "PDOdb User Management\n\n";
                }
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
        echo "User Management\n\n";
        echo "Usage: pdodb user <subcommand> [arguments] [options]\n\n";
        echo "Subcommands:\n";
        echo "  create [username]           Create a new database user (username will be prompted if not provided)\n";
        echo "  drop [username]              Drop a database user (username will be prompted if not provided)\n";
        echo "  exists [username]            Check if a user exists (username will be prompted if not provided)\n";
        echo "  list                         List all database users\n";
        echo "  info [username]              Show user information and privileges (username will be prompted if not provided)\n";
        echo "  grant <user> <privs>         Grant privileges to user\n";
        echo "  revoke <user> <privs>        Revoke privileges from user\n";
        echo "  password [username]          Change user password (username will be prompted if not provided)\n";
        echo "  help                         Show this help message\n\n";
        echo "Options:\n";
        echo "  --force                      Execute without confirmation\n";
        echo "  --password <pass>            Set password (for create/password)\n";
        echo "  --host <host>               Host for MySQL/MariaDB (default: '%')\n";
        echo "  --database <db>             Database name (for grant/revoke)\n";
        echo "  --table <table>               Table name (for grant/revoke)\n\n";
        echo "Examples:\n";
        echo "  pdodb user create john\n";
        echo "  pdodb user create john --password secret123\n";
        echo "  pdodb user create john --password secret123 --host localhost\n";
        echo "  pdodb user create    (will prompt for username and password)\n";
        echo "  pdodb user drop john\n";
        echo "  pdodb user drop john --force\n";
        echo "  pdodb user exists john\n";
        echo "  pdodb user list\n";
        echo "  pdodb user info john\n";
        echo "  pdodb user grant john SELECT,INSERT,UPDATE ON myapp.*\n";
        echo "  pdodb user grant john ALL --database myapp\n";
        echo "  pdodb user grant john SELECT,INSERT --database myapp --table users\n";
        echo "  pdodb user revoke john DELETE --database myapp\n";
        echo "  pdodb user password john\n";
        echo "  pdodb user password john --password newpass123\n";
        return 0;
    }
}
