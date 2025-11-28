<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use PDO;
use PDOException;
use PDOStatement;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\events\QueryErrorEvent;
use tommyknocker\pdodb\events\QueryExecutedEvent;
use tommyknocker\pdodb\events\TransactionCommittedEvent;
use tommyknocker\pdodb\events\TransactionRolledBackEvent;
use tommyknocker\pdodb\events\TransactionStartedEvent;
use tommyknocker\pdodb\exceptions\DatabaseException;
use tommyknocker\pdodb\exceptions\ExceptionFactory;

/**
 * Connection.
 *
 * Thin wrapper around PDO. Responsible for lifecycle, tracing and error normalization.
 */
class Connection implements ConnectionInterface
{
    use ConnectionLogger;

    /** @var PDO PDO instance */
    protected PDO $pdo;

    /** @var DialectInterface Dialect instance for database-specific SQL */
    protected DialectInterface $dialect;

    /** @var PDOStatement|null Last prepared statement */
    protected ?PDOStatement $stmt = null;

    /** @var ConnectionState Connection state manager */
    protected ConnectionState $state;

    /** @var PreparedStatementPool|null Prepared statement pool */
    protected ?PreparedStatementPool $statementPool = null;

    /** @var array<string, mixed>|null Temporary query context for error reporting */
    protected ?array $tempQueryContext = null;

    /** @var EventDispatcherInterface|null Event dispatcher */
    protected ?EventDispatcherInterface $eventDispatcher = null;

    /** @var array<string, mixed> Connection options */
    protected array $options = [];

    /**
     * Connection constructor.
     *
     * @param PDO $pdo The PDO instance to use.
     * @param DialectInterface $dialect The dialect to use.
     * @param LoggerInterface|null $logger The logger to use.
     * @param array<string, mixed> $options Connection options
     */
    public function __construct(PDO $pdo, DialectInterface $dialect, ?LoggerInterface $logger = null, array $options = [])
    {
        $this->pdo = $pdo;
        $this->dialect = $dialect;
        $this->logger = $logger;
        $this->options = $options;
        $this->state = new ConnectionState();
    }

    /**
     * Returns the PDO instance.
     *
     * @return PDO The PDO instance.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Returns the driver name.
     *
     * @return string The driver name.
     */
    public function getDriverName(): string
    {
        return (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Returns the dialect.
     *
     * @return DialectInterface The dialect.
     */
    public function getDialect(): DialectInterface
    {
        return $this->dialect;
    }

    /**
     * Resets the state.
     */
    public function resetState(): void
    {
        $this->state->reset();
    }

    /**
     * Set the prepared statement pool.
     *
     * @param PreparedStatementPool|null $pool The pool instance or null to disable
     */
    public function setStatementPool(?PreparedStatementPool $pool): void
    {
        $this->statementPool = $pool;
    }

    /**
     * Get the prepared statement pool.
     *
     * @return PreparedStatementPool|null The pool instance or null if not set
     */
    public function getStatementPool(): ?PreparedStatementPool
    {
        return $this->statementPool;
    }

    /**
     * Set the event dispatcher.
     *
     * @param EventDispatcherInterface|null $dispatcher The dispatcher instance or null to disable
     */
    public function setEventDispatcher(?EventDispatcherInterface $dispatcher): void
    {
        $this->eventDispatcher = $dispatcher;
    }

    /**
     * Get the event dispatcher.
     *
     * @return EventDispatcherInterface|null The dispatcher instance or null if not set
     */
    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    /**
     * Get connection options.
     *
     * @return array<string, mixed> Connection options
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Dispatch an event if dispatcher is available.
     *
     * @param object $event The event to dispatch
     */
    protected function dispatch(object $event): void
    {
        $this->eventDispatcher?->dispatch($event);
    }

    /**
     * Set temporary query context for error reporting.
     *
     * @param array<string, mixed>|null $queryContext Query builder debug information
     */
    public function setTempQueryContext(?array $queryContext): void
    {
        $this->tempQueryContext = $queryContext;
    }

    /**
     * Get and clear temporary query context.
     *
     * @return array<string, mixed>|null
     */
    protected function getAndClearTempQueryContext(): ?array
    {
        $context = $this->tempQueryContext;
        $this->tempQueryContext = null;
        return $context;
    }

    /**
     * Handle PDO exceptions with consistent error processing.
     *
     * @param PDOException $e The PDO exception
     * @param string $operation The operation that failed
     * @param string|null $sql The SQL query (if applicable)
     * @param array<string, mixed> $context Additional context
     *
     * @throws DatabaseException Always throws a specialized exception
     */
    protected function handlePdoException(
        PDOException $e,
        string $operation,
        ?string $sql = null,
        array $context = []
    ): never {
        $this->state->setError($e->getMessage(), (int)$e->getCode());

        // Check for queryContext in context or temporary storage
        $finalContext = array_merge(['operation' => $operation], $context);
        if (isset($context['queryContext'])) {
            $finalContext['queryContext'] = $context['queryContext'];
        } elseif ($this->tempQueryContext !== null) {
            $finalContext['queryContext'] = $this->getAndClearTempQueryContext();
        }

        $dbException = ExceptionFactory::createFromPdoException(
            $e,
            $this->getDriverName(),
            $sql,
            $finalContext
        );

        $this->logOperationError($operation, $e, $sql, [
            'exception_type' => $dbException::class,
            'category' => $dbException->getCategory(),
            'retryable' => $dbException->isRetryable(),
        ]);

        // Dispatch error event
        $this->dispatch(new QueryErrorEvent(
            $sql ?? '',
            $context['params'] ?? [],
            $dbException,
            $this->getDriverName()
        ));

        throw $dbException;
    }

    /**
     * Prepare SQL query.
     *
     * @param string $sql
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return $this
     */
    public function prepare(string $sql, array $params = []): static
    {
        // Normalize SQL for dialect-specific requirements
        $sql = $this->dialect->normalizeSqlForExecution($sql);

        $this->logOperationStart('prepare', $sql);

        try {
            $pool = $this->statementPool;
            if ($pool !== null && $pool->isEnabled()) {
                $key = $this->buildStatementKey($sql);
                $cachedStmt = $pool->get($key);
                if ($cachedStmt !== null) {
                    $this->stmt = $cachedStmt;
                    $this->logOperationEnd('prepare', $this->stmt->queryString, ['cached' => true]);
                    return $this;
                }
                // Prepare new statement and cache it
                $this->stmt = $this->pdo->prepare($sql, $params);
                $pool->put($key, $this->stmt);
                $this->logOperationEnd('prepare', $this->stmt->queryString);
                return $this;
            }

            $this->stmt = $this->pdo->prepare($sql, $params);
            $this->logOperationEnd('prepare', $this->stmt->queryString);
            return $this;
        } catch (PDOException $e) {
            $this->handlePdoException($e, 'prepare', $this->stmt?->queryString);
        }
    }

    /**
     * Build a stable cache key for prepared statements.
     *
     * @param string $sql The SQL query
     *
     * @return string The cache key
     */
    protected function buildStatementKey(string $sql): string
    {
        // Include driver name to avoid collisions between dialects
        // Hash the SQL for stable, short keys
        return sha1($this->getDriverName() . '|' . $sql);
    }

    /**
     * Executes a SQL statement.
     *
     * @param array<int|string, string|int|float|bool|null> $params
     *
     * @return PDOStatement The PDOStatement instance.
     */
    public function execute(array $params = []): PDOStatement
    {
        $this->resetState();
        $stmt = $this->stmt;

        if ($stmt === null) {
            throw new \RuntimeException('No statement prepared. Call prepare() first.');
        }

        $this->logOperationStart('execute', $stmt->queryString);
        $startTime = microtime(true);

        try {
            $this->state->setExecuteState($stmt->execute($params));
            $this->state->setLastQuery($stmt->queryString);
            $executionTime = (microtime(true) - $startTime) * 1000; // milliseconds
            $rowsAffected = $stmt->rowCount();

            $this->logOperationEnd('execute', $stmt->queryString, [
                'rows_affected' => $rowsAffected,
                'success' => (bool)$this->state->getExecuteState(),
                'execution_time_ms' => $executionTime,
            ]);

            // Dispatch query executed event
            $this->dispatch(new QueryExecutedEvent(
                $stmt->queryString,
                $params,
                $executionTime,
                $rowsAffected,
                $this->getDriverName(),
                false
            ));

            // Clear statement reference to allow GC (statement is returned to caller)
            // Note: We return the statement, caller should close cursor when done
            // For pooled statements, closeCursor() can be called safely - it only closes
            // the result set cursor, not the statement itself, so pooled statements can be reused
            $this->stmt = null;
            return $stmt;
        } catch (PDOException $e) {
            $this->handlePdoException($e, 'execute', $stmt->queryString, ['params' => $params]);
        }
    }

    /**
     * Executes a SQL query.
     *
     * @param string $sql The SQL query to execute.
     *
     * @return PDOStatement|false The PDOStatement instance or false on failure.
     */
    public function query(string $sql): PDOStatement|false
    {
        // Normalize SQL for dialect-specific requirements
        $sql = $this->dialect->normalizeSqlForExecution($sql);

        $this->resetState();
        $this->logOperationStart('query', $sql);
        $startTime = microtime(true);

        try {
            $stmt = $this->pdo->query($sql);
            if ($stmt !== false) {
                $executionTime = (microtime(true) - $startTime) * 1000; // milliseconds
                $rowsAffected = $stmt->rowCount();

                $this->state->setLastQuery($sql);
                $this->logOperationEnd('query', $sql, [
                    'rows_affected' => $rowsAffected,
                    'execution_time_ms' => $executionTime,
                ]);

                // Dispatch query executed event
                $this->dispatch(new QueryExecutedEvent(
                    $sql,
                    [],
                    $executionTime,
                    $rowsAffected,
                    $this->getDriverName(),
                    false
                ));
            }
            return $stmt;
        } catch (PDOException $e) {
            $this->handlePdoException($e, 'query', $sql);
        }
    }

    /**
     * Quotes a string for use in a query.
     *
     * @param mixed $value The value to quote.
     *
     * @return string|false The quoted string or false on failure.
     */
    public function quote(mixed $value): string|false
    {
        return $this->pdo->quote((string)$value);
    }

    /**
     * Begins a transaction.
     *
     * @return bool True on success, false on failure.
     */
    public function transaction(): bool
    {
        $this->logOperationStart('transaction.begin');

        try {
            $result = $this->pdo->beginTransaction();
            if ($result) {
                $this->dispatch(new TransactionStartedEvent($this->getDriverName()));
            }
            return $result;
        } catch (PDOException $e) {
            $this->handlePdoException($e, 'transaction.begin');
        }
    }

    /**
     * Commits a transaction.
     *
     * @return bool True on success, false on failure.
     */
    public function commit(): bool
    {
        try {
            $startTime = microtime(true);
            $res = $this->pdo->commit();
            if ($res) {
                $duration = (microtime(true) - $startTime) * 1000; // milliseconds
                $this->dispatch(new TransactionCommittedEvent($this->getDriverName(), $duration));
            }
            $this->logOperationEnd('transaction.commit');
            return $res;
        } catch (PDOException $e) {
            $this->handlePdoException($e, 'transaction.commit');
        }
    }

    /**
     * Rolls back a transaction.
     *
     * @return bool True on success, false on failure.
     */
    public function rollBack(): bool
    {
        try {
            $startTime = microtime(true);
            $res = $this->pdo->rollBack();
            if ($res) {
                $duration = (microtime(true) - $startTime) * 1000; // milliseconds
                $this->dispatch(new TransactionRolledBackEvent($this->getDriverName(), $duration));
            }
            $this->logOperationEnd('transaction.rollback');
            return $res;
        } catch (PDOException $e) {
            $this->handlePdoException($e, 'transaction.rollback');
        }
    }

    /**
     * Checks if a transaction is active.
     *
     * @return bool True if a transaction is active, false otherwise.
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Returns the last execute state.
     *
     * @return bool|null The last execute state.
     */
    public function getExecuteState(): ?bool
    {
        return $this->state->getExecuteState();
    }

    /**
     * Returns the last insert ID.
     *
     * @param string|null $name The name of the sequence to use.
     *
     * @return false|string The last insert ID or false on failure.
     */
    public function getLastInsertId(?string $name = null): false|string
    {
        return $this->pdo->lastInsertId($name);
    }

    /**
     * Returns the last query.
     *
     * @return string|null The last query or null if no query has been executed.
     */
    public function getLastQuery(): ?string
    {
        return $this->state->getLastQuery();
    }

    /**
     * Returns the last error message.
     *
     * @return string|null The last error message or null if no error has occurred.
     */
    public function getLastError(): ?string
    {
        return $this->state->getLastError();
    }

    /**
     * Returns the last error number.
     *
     * @return int The last error number.
     */
    public function getLastErrno(): int
    {
        return $this->state->getLastErrno();
    }

    /**
     * Set a PDO attribute.
     *
     * @param int $attribute The attribute to set.
     * @param mixed $value The value to set.
     *
     * @return bool True on success, false on failure.
     */
    public function setAttribute(int $attribute, mixed $value): bool
    {
        return $this->pdo->setAttribute($attribute, $value);
    }

    /**
     * Get a PDO attribute.
     *
     * @param int $attribute The attribute to get.
     *
     * @return mixed The attribute value.
     */
    public function getAttribute(int $attribute): mixed
    {
        return $this->pdo->getAttribute($attribute);
    }
}
