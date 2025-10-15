<?php
declare(strict_types=1);

namespace tommyknocker\pdodb;

use PDO;
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
    public DialectInterface $dialect;

    public ?ConnectionInterface $connection {
        get {
            if (!$this->connection instanceof ConnectionInterface) {
                throw new RuntimeException('Connection not initialized');
            }
            return $this->connection;
        }
    }

    protected array $connections = [];

    public string $prefix;
    public string $lastQuery {
        get {
            return $this->connection->getLastQuery();
        }
    }
    public string $lastError {
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
    protected string $lockMethod = 'WRITE';


    /**
     * Initializes a new PdoDb object.
     *
     * @param string $driver The database driver to use. Defaults to 'mysql'.
     * @param array $config An array of configuration options for the database connection.
     * @param array $pdoOptions An array of PDO options to use to connect to the database.
     * @param LoggerInterface|null $logger The logger to use to log the queries.
     * @see /README.md for details
     */
    public function __construct(
        string $driver = 'mysql',
        array $config = [],
        array $pdoOptions = [],
        ?LoggerInterface $logger = null
    ) {
        $this->prefix = $config['prefix'] ?? '';

        $this->addConnection('default', [
            'driver' => $driver,
            ...$config
        ], $pdoOptions, $logger);

        // use default connection
        $this->connection('default');
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
     * @param string $query The raw query to be executed.
     * @param array $params The parameters to be bound to the query.
     * @return array The result of the query.
     */
    public function rawQuery(string $query, array $params = []): array
    {
        return $this->find()->fetchAll($query, $params);
    }

    /**
     * Execute a raw query and return the first row.
     *
     * @param string $query The raw query to be executed.
     * @param array $params The parameters to be bound to the query.
     * @return array The first row of the result.
     */
    public function rawQueryOne(string $query, array $params = []): array
    {
        return $this->find()->fetch($query, $params);
    }

    /**
     * Execute a raw query and return the value of the first column of the first row.
     *
     * @param string $query The raw query to be executed.
     * @param array $params The parameters to be bound to the query.
     * @return mixed The value of the first column of the first row.
     */
    public function rawQueryValue(string $query, array $params = []): mixed
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
        if (!$this->connection->inTransaction()) {
            $this->connection->transaction();
        }
    }

    /**
     * Commits the transaction.
     *
     * @return void
     */
    public function commit(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->commit();
        }
    }

    /**
     * Rolls back the transaction.
     *
     * @return void
     */
    public function rollback(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }


    /* ---------------- LOCKING ---------------- */

    /**
     * Locks the specified tables.
     *
     * @param string|array $tables The tables to lock.
     * @return bool True if the lock was successful, false otherwise.
     */
    public function lock(string|array $tables): bool
    {
        $tables = (array)$tables;
        $sql = $this->connection->getDialect()->buildLockSql($tables, $this->prefix, $this->lockMethod);
        $this->connection->execute($sql);
        return $this->executeState !== false;
    }

    /**
     * Unlocks the specified tables.
     *
     * @return bool True if unlock was successful, false otherwise.
     */
    public function unlock(): bool
    {
        $sql = $this->connection->getDialect()->buildUnlockSql();
        if ($sql === '') {
            return true;
        }
        $this->connection->execute($sql);
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

    /* ---------------- HELPERS ---------------- */

    /**
     * Returns an array with an increment operation.
     *
     * @param int|float $num The number to increment by.
     * @return array The array with the increment operation.
     */
    public function inc(int|float $num = 1): array
    {
        return ['__op' => 'inc', 'val' => $num];
    }

    /**
     * Returns an array with a decrement operation.
     *
     * @param int|float $num The number to decrement by.
     * @return array The array with the decrement operation.
     */
    public function dec(int|float $num = 1): array
    {
        return ['__op' => 'dec', 'val' => $num];
    }

    /**
     * Returns an array with a not operation.
     *
     * @param mixed $val The value to negate.
     * @return array The array with the not operation.
     */
    public function not(mixed $val): array
    {
        return ['__op' => 'not', 'val' => $val];
    }

    /**
     * Escapes a string for use in a SQL query.
     *
     * @param string $str The string to escape.
     * @return string The escaped string.
     */
    public function escape(string $str): string
    {
        return $this->connection->quote($str);
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
            $this->connection->query('SELECT 1')->execute();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Checks if a table exists.
     *
     * @param string $table The table to check.
     * @return bool True if the table exists, false otherwise.
     */
    public function tableExists(string $table): bool
    {
        $sql = $this->connection->getDialect()->buildExistsSql($this->prefix . $table);
        $res = $this->rawQueryValue($sql);
        return !empty($res);
    }


    /* ---------------- UTILS ---------------- */


    /**
     * Returns a string with the current date and time.
     *
     * @param string $diff The time interval to add to the current date and time.
     * @return RawValue The current date and time.
     */
    public function now(string $diff = ''): RawValue
    {
        return $this->connection->getDialect()->now($diff);
    }

    /* ---------------- CONNECTIONS ---------------- */

    /**
     * Adds a connection to the connection pool.
     *
     * @param string $name The name of the connection.
     * @param array $params The parameters to use to connect to the database.
     * @param array $pdoOptions The PDO options to use to connect to the database.
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


    /* ---------------- LOAD DATA/XML ---------------- */

    /**
     * Loads data from a CSV file into a table.
     *
     * @param string $table The table to load data into.
     * @param string $filePath The path to the CSV file.
     * @param array $options The options to use to load the data.
     * @return bool True on success, false on failure.
     */
    public function loadData(string $table, string $filePath, array $options = []): bool
    {
        $this->startTransaction();
        try {
            $sql = $this->connection->getDialect()->buildLoadDataSql($this->connection->getPdo(),
                $this->prefix . $table, $filePath, $options);
            $this->connection->execute($sql);
            $this->commit();
            return $this->executeState !== false;
        } catch (Throwable $e) {
            $this->rollback();
        }
        return false;
    }


    /**
     * Loads data from an XML file into a table.
     *
     * @param string $table The table to load data into.
     * @param string $filePath The path to the XML file.
     * @param string $rowTag The tag that identifies a row.
     * @param int|null $linesToIgnore The number of lines to ignore at the beginning of the file.
     * @return bool True on success, false on failure.
     */
    public function loadXml(string $table, string $filePath, string $rowTag = '<row>', ?int $linesToIgnore = null): bool
    {
        $this->startTransaction();
        try {
            $options = [
                'rowTag' => $rowTag,
                'linesToIgnore' => $linesToIgnore
            ];
            $sql = $this->connection->getDialect()->buildLoadXML($this->connection->getPdo(), $this->prefix . $table,
                $filePath, $options);
            $this->connection->execute($sql);
            $this->commit();
            return $this->executeState !== false;
        } catch (Throwable $e) {
            $this->rollback();
        }
        return false;
    }

    /**
     * Describes a table.
     *
     * @param string $table The table to describe.
     * @return array The table description.
     */
    public function describe(string $table): array
    {
        $sql = $this->connection->getDialect()->buildDescribeTableSql($this->prefix . $table);
        return $this->rawQuery($sql);
    }

    /**
     * Explains a query.
     *
     * @param string $query The query to explain.
     * @param array $params The parameters to use to explain the query.
     * @return array The query explanation.
     */
    public function explain(string $query, array $params = []): array
    {
        $sql = $this->connection->getDialect()->buildExplainSql($query, false);
        return $this->rawQuery($sql, $params);
    }

    /**
     * Explains and analyzes a query.
     *
     * @param string $query The query to explain and analyze.
     * @param array $params The parameters to use to explain and analyze the query.
     * @return array The query explanation and analysis.
     */
    public function explainAnalyze(string $query, array $params = []): array
    {
        $sql = $this->connection->getDialect()->buildExplainSql($query, true);
        return $this->rawQuery($sql, $params);
    }
}
