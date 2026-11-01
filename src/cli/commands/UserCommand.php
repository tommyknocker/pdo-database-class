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
            default => $this->fail("Unknown subcommand: {$subcommand}"),
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
                return $this->fail('Username is required');
            }
        }

        $password = $this->resolvePassword('Enter password');
        if ($password === '') {
            return $this->fail('Password is required');
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
            return $this->fail($e->getMessage());
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
                return $this->fail('Username is required');
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
            return $this->fail($e->getMessage());
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
                return $this->fail('Username is required');
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
            return $this->fail($e->getMessage());
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

            $this->write('Users (' . count($users) . "):\n\n");
            foreach ($users as $user) {
                $userHost = $user['user_host'] ?? ($user['username'] . ($user['host'] !== null ? '@' . $user['host'] : ''));
                $this->writeln("  {$userHost}");
            }

            return 0;
        } catch (ResourceException $e) {
            return $this->fail($e->getMessage());
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
                return $this->fail('Username is required');
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

            $this->writeln("User Information:\n");
            foreach ($info as $key => $value) {
                if ($value === null) {
                    continue;
                }
                if ($key === 'privileges' && is_array($value)) {
                    $this->write('  Privileges (' . count($value) . "):\n");
                    foreach ($value as $privilege) {
                        if (is_array($privilege)) {
                            $privText = implode(', ', array_filter($privilege, fn ($v) => $v !== null && $v !== ''));
                            $this->writeln("    - {$privText}");
                        } else {
                            $this->writeln("    - {$privilege}");
                        }
                    }
                    continue;
                }
                $displayValue = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                $this->write('  ' . ucfirst(str_replace('_', ' ', $key)) . ": {$displayValue}\n");
            }

            return 0;
        } catch (ResourceException $e) {
            return $this->fail($e->getMessage());
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
                return $this->fail('Username is required');
            }
        }

        if ($privileges === null) {
            $privileges = static::readInput('Enter privileges (e.g., SELECT,INSERT,UPDATE or ALL)');
            if ($privileges === '') {
                return $this->fail('Privileges are required');
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
            return $this->fail($e->getMessage());
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
                return $this->fail('Username is required');
            }
        }

        if ($privileges === null) {
            $privileges = static::readInput('Enter privileges (e.g., SELECT,INSERT,UPDATE or ALL)');
            if ($privileges === '') {
                return $this->fail('Privileges are required');
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
            return $this->fail($e->getMessage());
        }
    }

    /**
     * Read the password from an environment variable, stdin or the prompt, so it stays out of the process list
     * and the shell history. `--password <pass>` still works but is discouraged.
     */
    protected function resolvePassword(string $prompt): string
    {
        $envName = $this->getOption('password-env');
        if (is_string($envName) && $envName !== '') {
            $value = getenv($envName);

            return $value === false ? '' : $value;
        }

        if ($this->getOption('password-stdin', false)) {
            return rtrim((string)fgets(STDIN), "\r\n");
        }

        $password = $this->getOption('password');
        if (is_string($password)) {
            static::warning('--password puts the password in the process list and shell history; prefer --password-env=NAME, --password-stdin or the prompt.');

            return $password;
        }

        return static::readPassword($prompt);
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
                return $this->fail('Username is required');
            }
        }

        $newPassword = $this->resolvePassword('Enter new password');
        if ($newPassword === '') {
            return $this->fail('Password is required');
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
            return $this->fail($e->getMessage());
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
                $this->writeln('PDOdb User Management');
                $this->write("Database: {$driver}");
                if (isset($info['current_database']) && is_string($info['current_database']) && $info['current_database'] !== '') {
                    $this->write(" ({$info['current_database']})");
                }
                $this->writeln("\n");
            }
        } catch (\Exception $e) {
            // If we can't get database info, just show driver
            try {
                $db = $this->getDb();
                $driver = static::getDriverName($db);
                if (getenv('PHPUNIT') === false) {
                    $this->writeln('PDOdb User Management');
                    $this->writeln("Database: {$driver}\n");
                }
            } catch (\Exception $e2) {
                // If even that fails, just show header
                if (getenv('PHPUNIT') === false) {
                    $this->writeln("PDOdb User Management\n");
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
        $this->writeln("User Management\n");
        $this->writeln("Usage: pdodb user <subcommand> [arguments] [options]\n");
        $this->writeln('Subcommands:');
        $this->writeln('  create [username]           Create a new database user (username will be prompted if not provided)');
        $this->writeln('  drop [username]              Drop a database user (username will be prompted if not provided)');
        $this->writeln('  exists [username]            Check if a user exists (username will be prompted if not provided)');
        $this->writeln('  list                         List all database users');
        $this->writeln('  info [username]              Show user information and privileges (username will be prompted if not provided)');
        $this->writeln('  grant <user> <privs>         Grant privileges to user');
        $this->writeln('  revoke <user> <privs>        Revoke privileges from user');
        $this->writeln('  password [username]          Change user password (username will be prompted if not provided)');
        $this->writeln("  help                         Show this help message\n");
        $this->writeln('Options:');
        $this->writeln('  --force                      Execute without confirmation');
        $this->writeln('  --password-env <NAME>        Read the password from an environment variable (for create/password)');
        $this->writeln('  --password-stdin             Read the password from standard input (for create/password)');
        $this->writeln('  --password <pass>            Set password on the command line (visible to other users; discouraged)');
        $this->writeln("  --host <host>               Host for MySQL/MariaDB (default: '%')");
        $this->writeln('  --database <db>             Database name (for grant/revoke)');
        $this->writeln("  --table <table>               Table name (for grant/revoke)\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb user create john');
        $this->writeln('  pdodb user create john --password-env=NEW_USER_PASSWORD');
        $this->writeln('  pdodb user create john --password-env=NEW_USER_PASSWORD --host localhost');
        $this->writeln('  pdodb user create    (will prompt for username and password)');
        $this->writeln('  pdodb user drop john');
        $this->writeln('  pdodb user drop john --force');
        $this->writeln('  pdodb user exists john');
        $this->writeln('  pdodb user list');
        $this->writeln('  pdodb user info john');
        $this->writeln('  pdodb user grant john SELECT,INSERT,UPDATE ON myapp.*');
        $this->writeln('  pdodb user grant john ALL --database myapp');
        $this->writeln('  pdodb user grant john SELECT,INSERT --database myapp --table users');
        $this->writeln('  pdodb user revoke john DELETE --database myapp');
        $this->writeln('  pdodb user password john');
        $this->writeln('  echo "$NEW_PASSWORD" | pdodb user password john --password-stdin');
        return 0;
    }
}
