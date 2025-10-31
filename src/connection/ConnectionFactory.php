<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use InvalidArgumentException;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * ConnectionFactory.
 *
 * Build DSN, create PDO and wire Dialect -> Connection.
 * Uses DialectRegistry for extensible dialect resolution.
 */
class ConnectionFactory
{
    /**
     * Create a new Connection instance.
     *
     * @param array<string, mixed> $config Configuration array.
     * @param LoggerInterface|null $logger Logger instance.
     *
     * @return Connection The created Connection instance.
     * @throws InvalidArgumentException If the driver is not specified or unsupported.
     */
    public function create(array $config, ?LoggerInterface $logger): Connection
    {
        $driver = $config['driver'] ?? throw new InvalidArgumentException('driver is required');
        $dialect = DialectRegistry::resolve($driver);
        $pdo = $config['pdo'] ?? null;

        if (!$pdo) {
            $dsn = $dialect->buildDsn($config);
            $user = $config['username'] ?? '';
            $pass = $config['password'] ?? '';
            $options = $dialect->defaultPdoOptions() + ($config['options'] ?? []);
            $pdo = new PDO($dsn, $user, $pass, $options);
        }

        $dialect->setPdo($pdo);

        // Use RetryableConnection if retry is enabled
        $retryConfig = $config['retry'] ?? [];
        $connection = null;
        if (!empty($retryConfig['enabled'])) {
            $connection = new RetryableConnection($pdo, $dialect, $logger, $retryConfig);
        } else {
            $connection = new Connection($pdo, $dialect, $logger);
        }

        // Configure prepared statement pool if enabled
        $poolConfig = $config['stmt_pool'] ?? [];
        if (!empty($poolConfig['enabled'])) {
            $capacity = (int)($poolConfig['capacity'] ?? 256);
            $pool = new PreparedStatementPool($capacity, true);
            $connection->setStatementPool($pool);
        }

        return $connection;
    }
}
