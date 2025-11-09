<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use InvalidArgumentException;
use PDO;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use tommyknocker\pdodb\dialects\MSSQLDialect;
use tommyknocker\pdodb\dialects\SqliteDialect;
use tommyknocker\pdodb\events\ConnectionOpenedEvent;

/**
 * ConnectionFactory.
 *
 * Build DSN, create PDO and wire Dialect -> Connection.
 * Uses DialectRegistry for extensible dialect resolution.
 */
class ConnectionFactory
{
    /** @var EventDispatcherInterface|null Event dispatcher */
    protected ?EventDispatcherInterface $eventDispatcher = null;

    /**
     * Set the event dispatcher.
     *
     * @param EventDispatcherInterface|null $dispatcher
     */
    public function setEventDispatcher(?EventDispatcherInterface $dispatcher): void
    {
        $this->eventDispatcher = $dispatcher;
    }

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
        $dsn = '';
        $options = [];

        if (!$pdo) {
            $dsn = $dialect->buildDsn($config);
            $user = $config['username'] ?? '';
            $pass = $config['password'] ?? '';
            $options = $dialect->defaultPdoOptions() + ($config['options'] ?? []);
            $pdo = new PDO($dsn, $user, $pass, $options);
        } else {
            // If PDO is provided, use DSN from config if available, otherwise use empty string
            $dsn = $config['dsn'] ?? '';
            $options = $config['options'] ?? [];
        }

        $dialect->setPdo($pdo);

        // Register REGEXP functions for SQLite if enabled (default: true)
        if ($driver === 'sqlite' && $dialect instanceof SqliteDialect) {
            $enableRegexp = $config['enable_regexp'] ?? true;
            if ($enableRegexp) {
                $dialect->registerRegexpFunctions($pdo);
            }
        }

        // Register REGEXP functions for MSSQL if enabled (default: true)
        if ($driver === 'sqlsrv' && $dialect instanceof MSSQLDialect) {
            $enableRegexp = $config['enable_regexp'] ?? true;
            if ($enableRegexp) {
                $dialect->registerRegexpFunctions($pdo);
            }
        }

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

        // Configure event dispatcher if available
        if ($this->eventDispatcher !== null) {
            $connection->setEventDispatcher($this->eventDispatcher);
        }

        // Dispatch connection opened event
        if ($this->eventDispatcher !== null && $dsn !== '') {
            $this->eventDispatcher->dispatch(new ConnectionOpenedEvent(
                $driver,
                $dsn,
                $options
            ));
        }

        return $connection;
    }
}
