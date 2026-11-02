<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

use tommyknocker\pdodb\cli\CliExitException;
use tommyknocker\pdodb\cli\Command;
use tommyknocker\pdodb\cli\DatabaseManager;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\exceptions\ResourceException;

/**
 * Connection management command.
 */
class ConnectionCommand extends Command
{
    /**
     * Create connection command.
     */
    public function __construct()
    {
        parent::__construct('connection', 'Manage database connections');
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
            return $this->showHelp();
        }

        return match ($subcommand) {
            'test' => $this->test(),
            'info' => $this->showInfo(),
            'list' => $this->list(),
            'ping' => $this->ping(),
            default => $this->fail("Unknown subcommand: {$subcommand}"),
        };
    }

    /**
     * Test database connection.
     *
     * @return int Exit code
     */
    protected function test(): int
    {
        $connectionName = $this->getOption('connection');
        if (is_string($connectionName) && $connectionName !== '') {
            $this->exportEnv('PDODB_CONNECTION', $connectionName);
        }

        try {
            $db = $this->getDb();
            // Try to execute a simple query
            $db->rawQueryValue('SELECT 1');
            static::success('Connection test successful');
            return 0;
        } catch (QueryException | ResourceException $e) {
            static::error('Connection test failed: ' . $e->getMessage());
        } catch (CliExitException $e) {
            throw $e;
        } catch (\Exception $e) {
            static::error('Connection test failed: ' . $e->getMessage());
        }
    }

    /**
     * Show connection information.
     *
     * @return int Exit code
     */
    protected function showInfo(): int
    {
        $connectionName = $this->getArgument(1);
        $format = $this->getOption('format', 'table');

        // If connection name provided, set it before loading config
        if (is_string($connectionName) && $connectionName !== '') {
            $this->exportEnv('PDODB_CONNECTION', $connectionName);
        } else {
            // Check if --connection option is provided
            $connOpt = $this->getOption('connection');
            if (is_string($connOpt) && $connOpt !== '') {
                $this->exportEnv('PDODB_CONNECTION', $connOpt);
                $connectionName = $connOpt;
            }
        }

        try {
            // Load full config file
            $customConfig = getenv('PDODB_CONFIG_PATH');
            $rootConfig = ($customConfig !== false && is_string($customConfig) && $customConfig !== '')
                ? (string)$customConfig
                : (getcwd() . '/config/db.php');

            if (!file_exists($rootConfig)) {
                // Fallback to loadDatabaseConfig if no config file
                $config = static::loadDatabaseConfig();
                $info = $this->getConnectionInfoFromConfig($config, $connectionName);
            } else {
                $fullConfig = require $rootConfig;

                // Extract connection config
                if (is_array($fullConfig) && isset($fullConfig['connections']) && is_array($fullConfig['connections'])) {
                    if ($connectionName !== null && isset($fullConfig['connections'][$connectionName])) {
                        $config = $fullConfig['connections'][$connectionName];
                    } else {
                        $default = $fullConfig['default'] ?? null;
                        if ($default !== null && isset($fullConfig['connections'][$default])) {
                            $config = $fullConfig['connections'][$default];
                            $connectionName = $default;
                        } else {
                            $config = reset($fullConfig['connections']);
                            $connectionName = array_key_first($fullConfig['connections']);
                        }
                    }
                } elseif (is_array($fullConfig) && isset($fullConfig['driver'])) {
                    $config = $fullConfig;
                } else {
                    $config = static::loadDatabaseConfig();
                }

                $info = $this->getConnectionInfoFromConfig($config, $connectionName);
            }

            // Try to connect and get additional info
            // Reset cached connection to ensure we use the correct one
            $this->resetDb();

            // Set connection name before connecting
            if ($connectionName !== null) {
                $this->exportEnv('PDODB_CONNECTION', $connectionName);
            }

            try {
                $db = $this->getDb();
                $info['status'] = 'connected';
                $dbInfo = DatabaseManager::getInfo($db);
                if (isset($dbInfo['version'])) {
                    $info['server_version'] = $dbInfo['version'];
                }
            } catch (\Exception $e) {
                $info['status'] = 'not connected';
                $info['error'] = $e->getMessage();
            }

            return $this->printFormatted($info, (string)$format);
        } catch (CliExitException $e) {
            throw $e;
        } catch (\Exception $e) {
            static::error('Failed to get connection info: ' . $e->getMessage());
        }
    }

    /**
     * List all available connections.
     *
     * @return int Exit code
     */
    protected function list(): int
    {
        $format = $this->getOption('format', 'table');

        try {
            $connections = $this->getAllConnections();
            return $this->printFormatted(['connections' => $connections], (string)$format);
        } catch (CliExitException $e) {
            throw $e;
        } catch (\Exception $e) {
            static::error('Failed to list connections: ' . $e->getMessage());
        }
    }

    /**
     * Ping database connection.
     *
     * @return int Exit code
     */
    protected function ping(): int
    {
        $connectionName = $this->getOption('connection');
        if (is_string($connectionName) && $connectionName !== '') {
            $this->exportEnv('PDODB_CONNECTION', $connectionName);
        }

        try {
            $db = $this->getDb();
            $start = microtime(true);
            $db->rawQueryValue('SELECT 1');
            $duration = round((microtime(true) - $start) * 1000, 2);

            $this->writeln("Ping successful (response time: {$duration}ms)");
            return 0;
        } catch (QueryException | ResourceException $e) {
            static::error('Ping failed: ' . $e->getMessage());
        } catch (CliExitException $e) {
            throw $e;
        } catch (\Exception $e) {
            static::error('Ping failed: ' . $e->getMessage());
        }
    }

    /**
     * Get connection information from config (without connecting).
     *
     * @param array<string, mixed> $config
     * @param string|null $connectionName
     *
     * @return array<string, mixed>
     */
    protected function getConnectionInfoFromConfig(array $config, ?string $connectionName = null): array
    {
        $info = [
            'name' => $connectionName ?? 'default',
            'driver' => $config['driver'] ?? 'unknown',
        ];

        // Add connection details (without password)
        if (isset($config['host'])) {
            $info['host'] = $config['host'];
        }
        if (isset($config['port'])) {
            $info['port'] = $config['port'];
        }
        if (isset($config['database'])) {
            $info['database'] = $config['database'];
        }
        if (isset($config['username'])) {
            $info['username'] = $config['username'];
        }
        if (isset($config['path'])) {
            $info['path'] = $config['path'];
        }

        return $info;
    }

    /**
     * Get connection information (with connection attempt).
     *
     * @param array<string, mixed> $config
     * @param string|null $connectionName
     *
     * @return array<string, mixed>
     */
    protected function getConnectionInfo(array $config, ?string $connectionName = null): array
    {
        $info = $this->getConnectionInfoFromConfig($config, $connectionName);

        // Try to get database info if connected
        try {
            $db = $this->getDb();
            $info['status'] = 'connected';
            $dbInfo = DatabaseManager::getInfo($db);
            if (isset($dbInfo['version'])) {
                $info['server_version'] = $dbInfo['version'];
            }
        } catch (\Exception $e) {
            $info['status'] = 'not connected';
            $info['error'] = $e->getMessage();
        }

        return $info;
    }

    /**
     * Get all available connections from config.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getAllConnections(): array
    {
        $connections = [];
        $customConfig = getenv('PDODB_CONFIG_PATH');
        $rootConfig = ($customConfig !== false && is_string($customConfig) && $customConfig !== '')
            ? (string)$customConfig
            : (getcwd() . '/config/db.php');

        if (!file_exists($rootConfig)) {
            // Load .env file first to ensure environment variables are available
            static::loadEnvFile();

            // Try to get from environment
            $driver = getenv('PDODB_DRIVER') ?: '';
            if ($driver !== '') {
                $config = static::loadDatabaseConfig();
                $connections[] = $this->getConnectionInfo($config, 'default');
            }
            return $connections;
        }

        $config = require $rootConfig;

        // Check for modern multi-connection structure
        if (is_array($config) && isset($config['connections']) && is_array($config['connections'])) {
            $default = $config['default'] ?? null;
            foreach ($config['connections'] as $name => $connConfig) {
                if (is_array($connConfig)) {
                    $isDefault = ($default === $name);
                    // Set connection name before getting info
                    // The environment is put back when the command ends (see Command::restoreEnvironment()).
                    $this->exportEnv('PDODB_CONNECTION', (string)$name);
                    $connInfo = $this->getConnectionInfo($connConfig, (string)$name);
                    $connInfo['is_default'] = $isDefault;
                    $connections[] = $connInfo;
                }
            }
        } elseif (is_array($config) && isset($config['driver'])) {
            // Single connection
            $connections[] = $this->getConnectionInfo($config, 'default');
        } else {
            // Legacy named connections
            foreach ($config as $name => $value) {
                if (is_array($value) && isset($value['driver'])) {
                    // Set connection name before getting info
                    // The environment is put back when the command ends (see Command::restoreEnvironment()).
                    $this->exportEnv('PDODB_CONNECTION', (string)$name);
                    $connInfo = $this->getConnectionInfo($value, (string)$name);
                    $connections[] = $connInfo;
                }
            }
        }

        return $connections;
    }

    /**
     * Print formatted output.
     *
     * @param array<string, mixed> $data
     * @param string $format
     *
     * @return int
     */
    protected function printFormatted(array $data, string $format): int
    {
        $fmt = strtolower($format);
        if ($fmt === 'json') {
            $this->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return 0;
        }
        if ($fmt === 'yaml') {
            $this->printYaml($data);
            return 0;
        }

        // Table format
        $this->printTable($data);
        return 0;
    }

    /**
     * Print YAML-like output.
     *
     * @param array<string, mixed> $data
     * @param int $indent
     */
    protected function printYaml(array $data, int $indent = 0): void
    {
        foreach ($data as $key => $val) {
            $pad = str_repeat('  ', $indent);
            if (is_array($val)) {
                if (isset($val[0]) && is_array($val[0])) {
                    // List of items
                    $this->writeln("{$pad}{$key}:");
                    foreach ($val as $item) {
                        $this->writeln("{$pad}  -");
                        $this->printYaml($item, $indent + 2);
                    }
                } else {
                    $this->writeln("{$pad}{$key}:");
                    $this->printYaml($val, $indent + 1);
                }
            } else {
                $displayValue = $val === null ? 'null' : (is_bool($val) ? ($val ? 'true' : 'false') : $val);
                $this->writeln("{$pad}{$key}: {$displayValue}");
            }
        }
    }

    /**
     * Print table format.
     *
     * @param array<string, mixed> $data
     */
    protected function printTable(array $data): void
    {
        if (isset($data['connections']) && is_array($data['connections']) && !empty($data['connections'])) {
            $this->writeln("Available Connections:\n");
            foreach ($data['connections'] as $conn) {
                if (is_array($conn)) {
                    $name = $conn['name'] ?? 'unknown';
                    $driver = $conn['driver'] ?? 'unknown';
                    $status = $conn['status'] ?? 'unknown';
                    $isDefault = $conn['is_default'] ?? false;

                    $this->write("Connection: {$name}");
                    if ($isDefault) {
                        $this->write(' (default)');
                    }
                    $this->writeln('');
                    $this->writeln("  Driver: {$driver}");
                    if (isset($conn['host'])) {
                        $host = $conn['host'];
                        $port = $conn['port'] ?? '';
                        $this->writeln("  Host: {$host}" . ($port !== '' ? ":{$port}" : ''));
                    }
                    if (isset($conn['database'])) {
                        $this->writeln("  Database: {$conn['database']}");
                    }
                    if (isset($conn['username'])) {
                        $this->writeln("  Username: {$conn['username']}");
                    }
                    if (isset($conn['path'])) {
                        $this->writeln("  Path: {$conn['path']}");
                    }
                    $this->writeln("  Status: {$status}");
                    if (isset($conn['server_version'])) {
                        $this->writeln("  Server Version: {$conn['server_version']}");
                    }
                    $this->writeln('');
                }
            }
        } elseif (isset($data['name'])) {
            // Single connection info
            $conn = $data;
            $name = $conn['name'] ?? 'unknown';
            $driver = $conn['driver'] ?? 'unknown';
            $status = $conn['status'] ?? 'unknown';

            $this->writeln("Connection Information:\n");
            $this->writeln("Name: {$name}");
            $this->writeln("Driver: {$driver}");
            if (isset($conn['host'])) {
                $host = $conn['host'];
                $port = $conn['port'] ?? '';
                $this->writeln("Host: {$host}" . ($port !== '' ? ":{$port}" : ''));
            }
            if (isset($conn['database'])) {
                $this->writeln("Database: {$conn['database']}");
            }
            if (isset($conn['username'])) {
                $this->writeln("Username: {$conn['username']}");
            }
            if (isset($conn['path'])) {
                $this->writeln("Path: {$conn['path']}");
            }
            $this->writeln("Status: {$status}");
            if (isset($conn['server_version'])) {
                $this->writeln("Server Version: {$conn['server_version']}");
            }
            if (isset($conn['error'])) {
                $this->writeErrorLine("Error: {$conn['error']}");
            }
        }
    }

    /**
     * Show help.
     *
     * @return int Exit code
     */
    protected function showHelp(): int
    {
        $this->writeln("Connection Management\n");
        $this->writeln("Usage: pdodb connection <subcommand> [arguments] [options]\n");
        $this->writeln('Subcommands:');
        $this->writeln('  test [--connection=<name>]        Test database connection');
        $this->writeln('  info [<name>] [--format=table|json|yaml]  Show connection information');
        $this->writeln('  list [--format=table|json|yaml]   List all available connections');
        $this->writeln("  ping [--connection=<name>]        Ping database connection (check availability)\n");
        $this->writeln('Options:');
        $this->writeln('  --connection=<name>               Use specific connection name');
        $this->writeln("  --format=table|json|yaml          Output format (default: table)\n");
        $this->writeln('Examples:');
        $this->writeln('  pdodb connection test');
        $this->writeln('  pdodb connection test --connection=reporting');
        $this->writeln('  pdodb connection info');
        $this->writeln('  pdodb connection info main --format=json');
        $this->writeln('  pdodb connection list');
        $this->writeln('  pdodb connection ping --connection=reporting');
        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function knownOptions(): array
    {
        return [
            'format',
        ];
    }
}
