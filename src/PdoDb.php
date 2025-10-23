<?php

declare(strict_types=1);

namespace tommyknocker\pdodb;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use tommyknocker\pdodb\connection\ConnectionFactory;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\helpers\RawValue;
use tommyknocker\pdodb\query\QueryBuilder;

class PdoDb
{
    public const LOCK_WRITE = 'WRITE';
    public const LOCK_READ = 'READ';

    /** @var ConnectionInterface Current active connection */
    public ConnectionInterface $connection {
        get {
            if ($this->connectionStorage === null) {
                throw new RuntimeException(
                    'Connection not initialized. Use addConnection() to add a connection, then connection() to select it.'
                );
            }
            return $this->connectionStorage;
        }
    }

    /** @var ConnectionInterface|null Internal connection storage */
    protected ?ConnectionInterface $connectionStorage = null;

    /** @var array<string, ConnectionInterface> Named connections pool */
    protected array $connections = [];

    /** @var string Table prefix for queries */
    public string $prefix;

    public ?string $lastQuery {
        get {
            return $this->connection->getLastQuery();
        }
    }

    public ?string $lastError {
        get {
            return $this->connection->getLastError();
        }
    }

    public int $lastErrNo {
        get {
            return $this->connection->getLastErrno();
        }
    }

    public ?bool $executeState {
        get {
            return $this->connection->getExecuteState();
        }
    }

    /** @var string Lock method for table locking (WRITE/READ) */
    protected string $lockMethod = 'WRITE';

    /**
     * Initializes a new PdoDb object.
     *
     * @param string|null $driver The database driver to use. Pass null to use connection pooling without default connection.
     * @param array<string, mixed> $config An array of configuration options for the database connection.
     * @param array<int|string, mixed> $pdoOptions An array of PDO options to use to connect to the database.
     * @param LoggerInterface|null $logger The logger to use to log the queries.
     *
     * @see /README.md for details
     */
    public function __construct(
        ?string $driver = null,
        array $config = [],
        array $pdoOptions = [],
        ?LoggerInterface $logger = null
    ) {
        $this->prefix = $config['prefix'] ?? '';

        // Only create default connection if driver is provided
        if ($driver !== null) {
            $this->addConnection('default', [
                'driver' => $driver,
                ...$config,
            ], $pdoOptions, $logger);

            // use default connection
            $this->connection('default');
        }
    }

    /**
     * Returns a new QueryBuilder instance.
     *
     * @return QueryBuilder The new QueryBuilder instance.
     */
    public function find(): QueryBuilder
    {
        return new QueryBuilder($this->connection, $this->prefix);
    }

    /* ---------------- RAW ---------------- */

    /**
     * Execute a raw query.
     *
     * @param string|RawValue $query The raw query to be executed.
     * @param array<int|string, string|int|float|bool|null> $params The parameters to be bound to the query.
     *
     * @return array<int, array<string, mixed>> The result of the query.
     */
    public function rawQuery(string|RawValue $query, array $params = []): array
    {
        return $this->find()->fetchAll($query, $params);
    }

    /**
     * Execute a raw query and return the first row.
     *
     * @param string|RawValue $query The raw query to be executed.
     * @param array<int|string, string|int|float|bool|null> $params The parameters to be bound to the query.
     *
     * @return mixed The first row of the result.
     */
    public function rawQueryOne(string|RawValue $query, array $params = []): mixed
    {
        return $this->find()->fetch($query, $params);
    }

    /**
     * Execute a raw query and return the value of the first column of the first row.
     *
     * @param string|RawValue $query The raw query to be executed.
     * @param array<int|string, string|int|float|bool|null> $params The parameters to be bound to the query.
     *
     * @return mixed The value of the first column of the first row.
     */
    public function rawQueryValue(string|RawValue $query, array $params = []): mixed
    {
        return $this->find()->fetchColumn($query, $params);
    }

    /* ---------------- TRANSACTIONS ---------------- */

    /**
     * Starts a transaction.
     */
    public function startTransaction(): void
    {
        $conn = $this->connection;
        if (!$conn->inTransaction()) {
            $conn->transaction();
        }
    }

    /**
     * Commits the transaction.
     */
    public function commit(): void
    {
        $conn = $this->connection;
        if ($conn->inTransaction()) {
            $conn->commit();
        }
    }

    /**
     * Rolls back the transaction.
     */
    public function rollback(): void
    {
        $conn = $this->connection;
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
    }

    /**
     * Checks if a transaction is currently active.
     *
     * @return bool True if a transaction is active, false otherwise.
     */
    public function inTransaction(): bool
    {
        return $this->connection->inTransaction();
    }

    /**
     * Executes a callback within a transaction.
     *
     * @param callable(PdoDb): mixed $callback The callback to be executed.
     *
     * @return mixed The result of the callback.
     * @throws Throwable If the callback throws an exception, it will be rethrown after rolling back the transaction.
     */
    public function transaction(callable $callback): mixed
    {
        $this->startTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollback();

            throw $e;
        }
    }

    /* ---------------- LOCKING ---------------- */

    /**
     * Locks the specified tables.
     *
     * @param string|array<int, string> $tables The tables to lock.
     *
     * @return bool True if the lock was successful, false otherwise.
     */
    public function lock(string|array $tables): bool
    {
        $tables = (array)$tables;
        $conn = $this->connection;
        $sql = $conn->getDialect()->buildLockSql($tables, $this->prefix, $this->lockMethod);
        $conn->prepare($sql)->execute();
        return $this->executeState !== false;
    }

    /**
     * Unlocks the specified tables.
     *
     * @return bool True if unlock was successful, false otherwise.
     */
    public function unlock(): bool
    {
        $conn = $this->connection;
        $sql = $conn->getDialect()->buildUnlockSql();
        if ($sql === '') {
            return true;
        }
        $conn->prepare($sql)->execute();
        return $this->executeState !== false;
    }

    /**
     * Sets the lock method.
     *
     * @param string $method The lock method to use.
     *
     * @return self The current object.
     */
    public function setLockMethod(string $method): self
    {
        $upper = strtoupper($method);
        if (!in_array($upper, [self::LOCK_WRITE, self::LOCK_READ], true)) {
            throw new InvalidArgumentException("Invalid lock method: $method");
        }
        $this->lockMethod = $upper;
        return $this;
    }

    /* ---------------- CONNECTIONS ---------------- */

    /**
     * Adds a connection to the connection pool.
     *
     * @param string $name The name of the connection.
     * @param array<string, mixed> $params The parameters to use to connect to the database.
     * @param array<int|string, mixed> $pdoOptions The PDO options to use to connect to the database.
     * @param LoggerInterface|null $logger The logger to use to log the queries.
     */
    public function addConnection(
        string $name,
        array $params,
        array $pdoOptions = [],
        ?LoggerInterface $logger = null
    ): void {
        $params['options'] = $pdoOptions;
        $connectionFactory = new ConnectionFactory();
        $connection = $connectionFactory->create($params, $logger);
        $this->connections[$name] = $connection;
    }

    /**
     * Checks if a connection exists in the connection pool.
     *
     * @param string $name The name of the connection.
     *
     * @return bool True if the connection exists, false otherwise.
     */
    public function hasConnection(string $name): bool
    {
        return isset($this->connections[$name]);
    }

    /**
     * Returns a connection from the connection pool.
     *
     * @param string $name The name of the connection.
     *
     * @return self The connection.
     */
    public function connection(string $name): self
    {
        if (!isset($this->connections[$name])) {
            throw new RuntimeException("Connection $name not found");
        }
        $this->connectionStorage = $this->connections[$name];
        return $this;
    }

    /**
     * Disconnects from the database.
     *
     * @param string|null $name The name of the connection to disconnect.
     *                          Pass null to disconnect all connections.
     */
    public function disconnect(?string $name = null): void
    {
        if ($name === null) {
            $this->connectionStorage = null;
            $this->connections = [];
        } elseif (isset($this->connections[$name])) {
            if ($this->connectionStorage === $this->connections[$name]) {
                $this->connectionStorage = null;
            }
            unset($this->connections[$name]);
        }
    }

    /**
     * Pings the database.
     *
     * @return bool True if the ping was successful, false otherwise.
     */
    public function ping(): bool
    {
        try {
            $stmt = $this->connection->query('SELECT 1');
            if ($stmt !== false) {
                $stmt->execute();
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Sets the query timeout for the current connection.
     *
     * @param int $seconds The timeout in seconds.
     *
     * @return self The current object.
     * @throws RuntimeException If the timeout cannot be set.
     */
    public function setTimeout(int $seconds): self
    {
        try {
            $this->connection->setAttribute(\PDO::ATTR_TIMEOUT, $seconds);
            return $this;
        } catch (\PDOException $e) {
            // Some drivers (like SQLite) don't support ATTR_TIMEOUT
            if (str_contains($e->getMessage(), 'does not support this function') ||
                str_contains($e->getMessage(), 'driver does not support that attribute')) {
                // Silently ignore for unsupported drivers
                return $this;
            }

            throw new RuntimeException('Failed to set timeout: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Gets the current query timeout.
     *
     * @return int The timeout in seconds.
     * @throws RuntimeException If the timeout cannot be retrieved.
     */
    public function getTimeout(): int
    {
        try {
            $timeout = $this->connection->getAttribute(\PDO::ATTR_TIMEOUT);
            return (int)$timeout;
        } catch (\PDOException $e) {
            // Some drivers (like SQLite) don't support ATTR_TIMEOUT
            if (str_contains($e->getMessage(), 'does not support this function') ||
                str_contains($e->getMessage(), 'driver does not support that attribute')) {
                // Return 0 for unsupported drivers
                return 0;
            }

            throw new RuntimeException('Failed to get timeout: ' . $e->getMessage(), 0, $e);
        }
    }

    /* ---------------- INTROSPECT ---------------- */

    /**
     * Describes a table.
     *
     * @param string $table The table to describe.
     *
     * @return array<int, array<string, mixed>> The table description.
     */
    public function describe(string $table): array
    {
        $sql = $this->connection->getDialect()->buildDescribeSql($this->prefix . $table);
        return $this->rawQuery($sql);
    }

    /**
     * Explains a query.
     *
     * @param string $query The query to explain.
     * @param array<int|string, string|int|float|bool|null> $params The parameters to use to explain the query.
     *
     * @return array<int, array<string, mixed>> The query explanation.
     */
    public function explain(string $query, array $params = []): array
    {
        $sql = $this->connection->getDialect()->buildExplainSql($query);
        return $this->rawQuery($sql, $params);
    }

    /**
     * Explains and analyzes a query.
     *
     * @param string $query The query to explain and analyze.
     * @param array<int|string, string|int|float|bool|null> $params The parameters to use to explain and analyze the query.
     *
     * @return array<int, array<string, mixed>> The query explanation and analysis.
     */
    public function explainAnalyze(string $query, array $params = []): array
    {
        $sql = $this->connection->getDialect()->buildExplainAnalyzeSql($query);
        return $this->rawQuery($sql, $params);
    }
}
