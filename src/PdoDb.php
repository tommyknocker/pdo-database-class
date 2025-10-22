<?php
declare(strict_types=1);

namespace tommyknocker\pdodb;

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
    /** @var DialectInterface Current dialect instance */
    public DialectInterface $dialect;

    /** @var ConnectionInterface|null Current active connection */
    public ?ConnectionInterface $connection = null {
        get {
            if ($this->connection === null) {
                throw new RuntimeException(
                    'Connection not initialized. Use addConnection() to add a connection, then connection() to select it.'
                );
            }
            return $this->connection;
        }
    }

    /** @var array<string, ConnectionInterface> Named connections pool */
    protected array $connections = [];

    /** @var string Table prefix for queries */
    public string $prefix;
    
    public ?string $lastQuery {
        get {
            return $this->getConn()->getLastQuery();
        }
    }
    
    public ?string $lastError {
        get {
            return $this->getConn()->getLastError();
        }
    }
    
    public int $lastErrNo {
        get {
            return $this->getConn()->getLastErrno();
        }
    }
    
    public ?bool $executeState {
        get {
            return $this->getConn()->getExecuteState();
        }
    }
    
    /** @var string Lock method for table locking (WRITE/READ) */
    protected string $lockMethod = 'WRITE';
    
    /**
     * Get non-null connection or throw exception
     * @return ConnectionInterface
     */
    private function getConn(): ConnectionInterface
    {
        if ($this->connection === null) {
            throw new RuntimeException('No connection available. Call addConnection() and connection() first.');
        }
        return $this->connection;
    }

    /**
     * Initializes a new PdoDb object.
     *
     * @param string|null $driver The database driver to use. Pass null to use connection pooling without default connection.
     * @param array<string, mixed> $config An array of configuration options for the database connection.
     * @param array<int|string, mixed> $pdoOptions An array of PDO options to use to connect to the database.
     * @param LoggerInterface|null $logger The logger to use to log the queries.
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
                ...$config
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
        return new QueryBuilder($this->getConn(), $this->prefix);
    }

    /* ---------------- RAW ---------------- */

    /**
     * Execute a raw query.
     *
     * @param string|RawValue $query The raw query to be executed.
     * @param array<int|string, string|int|float|bool|null> $params The parameters to be bound to the query.
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
     * @return mixed The value of the first column of the first row.
     */
    public function rawQueryValue(string|RawValue $query, array $params = []): mixed
    {
        return $this->find()->fetchColumn($query, $params);
    }

    /* ---------------- TRANSACTIONS ---------------- */

    /**
     * Starts a transaction.
     *
     * @return void
     */
    public function startTransaction(): void
    {
        $conn = $this->getConn();
        if (!$conn->inTransaction()) {
            $conn->transaction();
        }
    }

    /**
     * Commits the transaction.
     *
     * @return void
     */
    public function commit(): void
    {
        $conn = $this->getConn();
        if ($conn->inTransaction()) {
            $conn->commit();
        }
    }

    /**
     * Rolls back the transaction.
     *
     * @return void
     */
    public function rollback(): void
    {
        $conn = $this->getConn();
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
    }


    /* ---------------- LOCKING ---------------- */

    /**
     * Locks the specified tables.
     *
     * @param string|array<int, string> $tables The tables to lock.
     * @return bool True if the lock was successful, false otherwise.
     */
    public function lock(string|array $tables): bool
    {
        $tables = (array)$tables;
        $conn = $this->getConn();
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
        $conn = $this->getConn();
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
     * @return self The current object.
     */
    public function setLockMethod(string $method): self
    {
        $this->lockMethod = strtoupper($method);
        return $this;
    }

    /**
     * Disconnects from the database.
     *
     * @return void
     */
    public function disconnect(): void
    {
        $this->connection = null;
    }

    /**
     * Pings the database.
     *
     * @return bool True if the ping was successful, false otherwise.
     */
    public function ping(): bool
    {
        try {
            $stmt = $this->getConn()->query('SELECT 1');
            if ($stmt !== false) {
                $stmt->execute();
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /* ---------------- CONNECTIONS ---------------- */

    /**
     * Adds a connection to the connection pool.
     *
     * @param string $name The name of the connection.
     * @param array<string, mixed> $params The parameters to use to connect to the database.
     * @param array<int|string, mixed> $pdoOptions The PDO options to use to connect to the database.
     * @param LoggerInterface|null $logger The logger to use to log the queries.
     * @return void
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
     * Returns a connection from the connection pool.
     *
     * @param string $name The name of the connection.
     * @return self The connection.
     */
    public function connection(string $name): self
    {
        if (!isset($this->connections[$name])) {
            throw new RuntimeException("Connection $name not found");
        }
        $this->connection = $this->connections[$name];
        return $this;
    }

    /**
     * Describes a table.
     *
     * @param string $table The table to describe.
     * @return array<int, array<string, mixed>> The table description.
     */
    public function describe(string $table): array
    {
        $sql = $this->getConn()->getDialect()->buildDescribeSql($this->prefix . $table);
        return $this->rawQuery($sql);
    }

    /**
     * Explains a query.
     *
     * @param string $query The query to explain.
     * @param array<int|string, string|int|float|bool|null> $params The parameters to use to explain the query.
     * @return array<int, array<string, mixed>> The query explanation.
     */
    public function explain(string $query, array $params = []): array
    {
        $sql = $this->getConn()->getDialect()->buildExplainSql($query, false);
        return $this->rawQuery($sql, $params);
    }

    /**
     * Explains and analyzes a query.
     *
     * @param string $query The query to explain and analyze.
     * @param array<int|string, string|int|float|bool|null> $params The parameters to use to explain and analyze the query.
     * @return array<int, array<string, mixed>> The query explanation and analysis.
     */
    public function explainAnalyze(string $query, array $params = []): array
    {
        $sql = $this->getConn()->getDialect()->buildExplainSql($query, true);
        return $this->rawQuery($sql, $params);
    }
}
