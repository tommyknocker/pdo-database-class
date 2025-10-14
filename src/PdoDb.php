<?php
declare(strict_types=1);

namespace tommyknocker\pdodb;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\dialects\MySQLDialect;
use tommyknocker\pdodb\dialects\PostgreSQLDialect;
use tommyknocker\pdodb\dialects\SqliteDialect;
use tommyknocker\pdodb\helpers\RawValue;

class PdoDb
{
    public ?PDO $pdo = null {
        get {
            if (!$this->pdo instanceof PDO) {
                throw new RuntimeException('PDO object not initialized');
            }
            return $this->pdo;
        }
        set (?PDO $pdo) {
            $this->pdo = $pdo;
        }
    }
    public DialectInterface $dialect {
        get {
            return $this->dialect;
        }
        set (DialectInterface $dialect) {
            $this->dialect = $dialect;
        }
    }

    public string $prefix = '' {
        get {
            return $this->prefix;
        }
        set (string $prefix) {
            $this->prefix = $prefix;
        }
    }
    public string $lastQuery = '' {
        get {
            return $this->lastQuery;
        }
        set (string $query) {
            $this->lastQuery = $query;
        }
    }
    public string $lastError = '' {
        get {
            return $this->lastError;
        }
        set (string $error) {
            $this->lastError = $error;
        }
    }
    public int $lastErrno = 0 {
        get {
            return $this->lastErrno;
        }
        set (int $errorNumber) {
            $this->lastErrno = $errorNumber;
        }
    }
    protected bool $transactionInProgress = false;
    protected bool $traceEnabled = false;
    public array $traceLog = [];

    protected string $lockMethod = 'WRITE';
    protected array $connections = [];

    /**
     * Initializes a new PdoDb object.
     *
     * @param string $driver The database driver to use. Defaults to 'mysql'.
     * @param array $config An array of configuration options for the database connection.
     * @see /README.md for details
     */
    public function __construct(string $driver = 'mysql', array $config = [])
    {
        $this->prefix = $config['prefix'] ?? '';

        $this->addConnection('default', [
            'driver' => $driver,
            ...$config
        ]);

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
        return new QueryBuilder($this);
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
        $this->lastQuery = $query;
        $this->trace($query, $params);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function rawQueryOne(string $query, array $params = []): array
    {
        $this->lastQuery = $query;
        $this->trace($query, $params);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetch() ?: [];
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
        $this->lastQuery = $query;
        $this->trace($query, $params);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /* ---------------- TRANSACTIONS ---------------- */

    /**
     * Starts a transaction.
     *
     * @return void
     */
    public function startTransaction(): void
    {
        if (!$this->transactionInProgress) {
            $this->pdo->beginTransaction();
            $this->transactionInProgress = true;
        }
    }

    /**
     * Commits the transaction.
     *
     * @return void
     */
    public function commit(): void
    {
        if ($this->transactionInProgress) {
            $this->pdo->commit();
            $this->transactionInProgress = false;
        }
    }

    /**
     * Rolls back the transaction.
     *
     * @return void
     */
    public function rollback(): void
    {
        if ($this->transactionInProgress) {
            $this->pdo->rollBack();
            $this->transactionInProgress = false;
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

        $sql = $this->dialect->buildLockSql($tables, $this->prefix, $this->lockMethod);

        $this->trace($sql);
        $this->lastQuery = $sql;

        return $this->pdo->exec($sql) !== false;
    }

    /**
     * Unlocks the specified tables.
     *
     * @return bool True if unlock was successful, false otherwise.
     */
    public function unlock(): bool
    {
        $sql = $this->dialect->buildUnlockSql();

        $this->trace($sql);
        $this->lastQuery = $sql;

        // In Postgres unlock — no‑op, return true
        if ($sql === '') {
            return true;
        }

        return $this->pdo->exec($sql) !== false;
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
        return $this->pdo->quote($str);
    }

    /**
     * Disconnects from the database.
     *
     * @return void
     */
    public function disconnect(): void
    {
        $this->pdo = null;
    }

    /**
     * Pings the database.
     *
     * @return bool True if the ping was successful, false otherwise.
     */
    public function ping(): bool
    {
        try {
            $this->pdo->query('SELECT 1');
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
        $sql = $this->dialect->buildExistsSql($this->prefix . $table);
        $res = $this->rawQueryValue($sql);
        return !empty($res);
    }
    
    
    /* ---------------- TRACE ---------------- */

    /**
     * Enables the trace.
     *
     * @return void
     */
    public function enableTrace(): void
    {
        $this->traceEnabled = true;
    }

    /**
     * Disables the trace.
     *
     * @return void
     */
    public function disableTrace(): void
    {
        $this->traceEnabled = false;
    }

    /**
     * Traces a query.
     *
     * @param string $sql The query to trace.
     * @param array $params The parameters to use to trace the query.
     * @return void
     */
    public function trace(string $sql, array $params = []): void
    {
        if ($this->traceEnabled) {
            $this->traceLog[] = [
                'query' => $sql,
                'params' => $params,
                'time' => microtime(true),
            ];
        }
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
        return $this->dialect->now($diff);
    }

    /* ---------------- CONNECTIONS ---------------- */

    /**
     * Builds a DSN from the given parameters.
     *
     * @param array $params The parameters to use to build the DSN.
     * @return string The DSN.
     */
    public function buildDsn(array $params): string
    {
        return $this->dialect->buildDsn($params);
    }

    /**
     * Adds a connection to the connection pool.
     *
     * @param string $name The name of the connection.
     * @param array $params The parameters to use to connect to the database.
     * @return void
     */
    public function addConnection(string $name, array $params): void
    {
        $this->dialect = match ($params['driver']) {
            'mysql' => new MySQLDialect(),
            'pgsql' => new PostgreSQLDialect(),
            'sqlite' => new SqliteDialect(),
            default => throw new InvalidArgumentException("Unsupported driver: {$params['driver']}"),
        };

        $dsn = $this->buildDsn($params);
        $pdo = $params['pdo'] ?? null;

        if (!$pdo) {
            $pdo = new PDO($dsn,
                $params['username'] ?? null,
                $params['password'] ?? null,
                $this->dialect->defaultPdoOptions() // @todo Support client's options
            );
        }
        $this->connections[$name] = $pdo;

        if ($name === 'default') {
            $this->pdo = $pdo;
        }
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
        $clone = clone $this;
        $clone->pdo = $this->connections[$name];
        return $clone;
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
            $sql = $this->dialect->buildLoadDataSql($this->pdo, $this->prefix . $table, $filePath, $options);
            $this->lastQuery = $sql;
            $this->trace($sql);
            return $this->pdo->exec($sql) !== false;
        } catch (\Throwable $e) {
            $this->lastErrno = $e->getCode();
            $this->lastError = $e->getMessage();
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
            $sql = $this->dialect->buildLoadXML($this->pdo, $this->prefix . $table, $filePath, $options);
            $this->lastQuery = $sql;
            $this->trace($sql);
            return $this->pdo->exec($sql) !== false;
        } catch (\Throwable $e) {
            $this->lastErrno = $e->getCode();
            $this->lastError = $e->getMessage();
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
        $sql = $this->dialect->buildDescribeTableSql($this->prefix . $table);
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
        $sql = $this->dialect->buildExplainSql($query, false);
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
        $sql = $this->dialect->buildExplainSql($query, true);
        return $this->rawQuery($sql, $params);
    }


    /**
     * Returns a string representation of the last query.
     *
     * @return string The last query.
     */
    public function __toString(): string
    {
        return $this->lastQuery;
    }
}
