<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\commands;

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
            default => $this->showError("Unknown subcommand: {$subcommand}"),
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
            putenv('PDODB_CONNECTION=' . $connectionName);
            $_ENV['PDODB_CONNECTION'] = $connectionName;
        }

        try {
            $db = $this->getDb();
            // Try to execute a simple query
            $db->rawQueryValue('SELECT 1');
            static::success('Connection test successful');
            return 0;
        } catch (QueryException | ResourceException $e) {
            static::error('Connection test failed: ' . $e->getMessage());
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
            putenv('PDODB_CONNECTION=' . $connectionName);
            $_ENV['PDODB_CONNECTION'] = $connectionName;
        } else {
            // Check if --connection option is provided
            $connOpt = $this->getOption('connection');
            if (is_string($connOpt) && $connOpt !== '') {
                putenv('PDODB_CONNECTION=' . $connOpt);
                $_ENV['PDODB_CONNECTION'] = $connOpt;
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
                putenv('PDODB_CONNECTION=' . $connectionName);
                $_ENV['PDODB_CONNECTION'] = $connectionName;
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
            putenv('PDODB_CONNECTION=' . $connectionName);
            $_ENV['PDODB_CONNECTION'] = $connectionName;
        }

        try {
            $db = $this->getDb();
            $start = microtime(true);
            $db->rawQueryValue('SELECT 1');
            $duration = round((microtime(true) - $start) * 1000, 2);

            echo "Ping successful (response time: {$duration}ms)\n";
            return 0;
        } catch (QueryException | ResourceException $e) {
            static::error('Ping failed: ' . $e->getMessage());
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
                    $oldConnection = getenv('PDODB_CONNECTION');
                    putenv('PDODB_CONNECTION=' . $name);
                    $_ENV['PDODB_CONNECTION'] = $name;

                    try {
                        $connInfo = $this->getConnectionInfo($connConfig, (string)$name);
                    } finally {
                        // Restore original connection
                        if ($oldConnection !== false) {
                            putenv('PDODB_CONNECTION=' . $oldConnection);
                            $_ENV['PDODB_CONNECTION'] = $oldConnection;
                        } else {
                            putenv('PDODB_CONNECTION');
                            unset($_ENV['PDODB_CONNECTION']);
                        }
                    }
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
                    $oldConnection = getenv('PDODB_CONNECTION');
                    putenv('PDODB_CONNECTION=' . $name);
                    $_ENV['PDODB_CONNECTION'] = $name;

                    try {
                        $connInfo = $this->getConnectionInfo($value, (string)$name);
                    } finally {
                        // Restore original connection
                        if ($oldConnection !== false) {
                            putenv('PDODB_CONNECTION=' . $oldConnection);
                            $_ENV['PDODB_CONNECTION'] = $oldConnection;
                        } else {
                            putenv('PDODB_CONNECTION');
                            unset($_ENV['PDODB_CONNECTION']);
                        }
                    }
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
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
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
                    echo "{$pad}{$key}:\n";
                    foreach ($val as $item) {
                        echo "{$pad}  -\n";
                        $this->printYaml($item, $indent + 2);
                    }
                } else {
                    echo "{$pad}{$key}:\n";
                    $this->printYaml($val, $indent + 1);
                }
            } else {
                $displayValue = $val === null ? 'null' : (is_bool($val) ? ($val ? 'true' : 'false') : $val);
                echo "{$pad}{$key}: {$displayValue}\n";
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
            echo "Available Connections:\n\n";
            foreach ($data['connections'] as $conn) {
                if (is_array($conn)) {
                    $name = $conn['name'] ?? 'unknown';
                    $driver = $conn['driver'] ?? 'unknown';
                    $status = $conn['status'] ?? 'unknown';
                    $isDefault = $conn['is_default'] ?? false;

                    echo "Connection: {$name}";
                    if ($isDefault) {
                        echo ' (default)';
                    }
                    echo "\n";
                    echo "  Driver: {$driver}\n";
                    if (isset($conn['host'])) {
                        $host = $conn['host'];
                        $port = $conn['port'] ?? '';
                        echo "  Host: {$host}" . ($port !== '' ? ":{$port}" : '') . "\n";
                    }
                    if (isset($conn['database'])) {
                        echo "  Database: {$conn['database']}\n";
                    }
                    if (isset($conn['username'])) {
                        echo "  Username: {$conn['username']}\n";
                    }
                    if (isset($conn['path'])) {
                        echo "  Path: {$conn['path']}\n";
                    }
                    echo "  Status: {$status}\n";
                    if (isset($conn['server_version'])) {
                        echo "  Server Version: {$conn['server_version']}\n";
                    }
                    echo "\n";
                }
            }
        } elseif (isset($data['name'])) {
            // Single connection info
            $conn = $data;
            $name = $conn['name'] ?? 'unknown';
            $driver = $conn['driver'] ?? 'unknown';
            $status = $conn['status'] ?? 'unknown';

            echo "Connection Information:\n\n";
            echo "Name: {$name}\n";
            echo "Driver: {$driver}\n";
            if (isset($conn['host'])) {
                $host = $conn['host'];
                $port = $conn['port'] ?? '';
                echo "Host: {$host}" . ($port !== '' ? ":{$port}" : '') . "\n";
            }
            if (isset($conn['database'])) {
                echo "Database: {$conn['database']}\n";
            }
            if (isset($conn['username'])) {
                echo "Username: {$conn['username']}\n";
            }
            if (isset($conn['path'])) {
                echo "Path: {$conn['path']}\n";
            }
            echo "Status: {$status}\n";
            if (isset($conn['server_version'])) {
                echo "Server Version: {$conn['server_version']}\n";
            }
            if (isset($conn['error'])) {
                echo "Error: {$conn['error']}\n";
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
        echo "Connection Management\n\n";
        echo "Usage: pdodb connection <subcommand> [arguments] [options]\n\n";
        echo "Subcommands:\n";
        echo "  test [--connection=<name>]        Test database connection\n";
        echo "  info [<name>] [--format=table|json|yaml]  Show connection information\n";
        echo "  list [--format=table|json|yaml]   List all available connections\n";
        echo "  ping [--connection=<name>]        Ping database connection (check availability)\n\n";
        echo "Options:\n";
        echo "  --connection=<name>               Use specific connection name\n";
        echo "  --format=table|json|yaml          Output format (default: table)\n\n";
        echo "Examples:\n";
        echo "  pdodb connection test\n";
        echo "  pdodb connection test --connection=reporting\n";
        echo "  pdodb connection info\n";
        echo "  pdodb connection info main --format=json\n";
        echo "  pdodb connection list\n";
        echo "  pdodb connection ping --connection=reporting\n";
        return 0;
    }
}
