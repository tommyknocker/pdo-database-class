<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
use tommyknocker\pdodb\exceptions\DatabaseException;
use tommyknocker\pdodb\exceptions\ExceptionFactory;

/**
 * Connection
 *
 * Thin wrapper around PDO. Responsible for lifecycle, tracing and error normalization.
 */
class Connection implements ConnectionInterface
{
    /** @var PDO PDO instance */
    protected PDO $pdo;
    
    /** @var DialectInterface Dialect instance for database-specific SQL */
    protected DialectInterface $dialect;
    
    /** @var LoggerInterface|null Logger instance for query logging */
    protected ?LoggerInterface $logger;
    
    /** @var PDOStatement|null Last prepared statement */
    protected ?PDOStatement $stmt = null;
    
    /** @var string|null Last executed query */
    protected ?string $lastQuery = null;
    
    /** @var string|null Last error message */
    protected ?string $lastError = null;
    
    /** @var int Last error code */
    protected int $lastErrno = 0;
    
    /** @var mixed Last execute state (success/failure) */
    protected mixed $executeState = null;

    /**
     * Connection constructor.
     *
     * @param PDO $pdo The PDO instance to use.
     * @param DialectInterface $dialect The dialect to use.
     * @param LoggerInterface|null $logger The logger to use.
     */
    public function __construct(PDO $pdo, DialectInterface $dialect, ?LoggerInterface $logger = null)
    {
        $this->pdo = $pdo;
        $this->dialect = $dialect;
        $this->logger = $logger;
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
     *
     * @return void
     */
    public function resetState(): void
    {
        $this->lastError = null;
        $this->lastErrno = 0;
        $this->executeState = null;
    }

    /**
     * Prepare SQL query
     * @param string $sql
     * @param array<int|string, string|int|float|bool|null> $params
     * @return $this
     */
    public function prepare(string $sql, array $params = []): static
    {
        $this->logger?->debug('operation.start', [
            'operation' => 'prepare',
            'driver' => $this->getDriverName(),
            'sql' => $sql,
            'timestamp' => microtime(true)
        ]);
        try {
            $this->stmt = $this->pdo->prepare($sql, $params);
            $this->logger?->debug('operation.end', [
                'operation' => 'prepare',
                'driver' => $this->getDriverName(),
                'sql' => $this->stmt->queryString,
                'timestamp' => microtime(true),
            ]);
            return $this;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->lastErrno = (int)$e->getCode();
            
            $dbException = ExceptionFactory::createFromPdoException(
                $e,
                $this->getDriverName(),
                $this->stmt?->queryString,
                ['operation' => 'prepare']
            );
            
            $this->logger?->error('operation.error', [
                'operation' => 'prepare',
                'driver' => $this->getDriverName(),
                'sql' => $this->stmt?->queryString,
                'timestamp' => microtime(true),
                'exception' => $e,
                'exception_type' => $dbException::class,
                'category' => $dbException->getCategory(),
                'retryable' => $dbException->isRetryable()
            ]);
            throw $dbException;
        }
    }

    /**
     * Executes a SQL statement.
     *
     * @param array<int|string, string|int|float|bool|null> $params
     * @return PDOStatement The PDOStatement instance.
     */
    public function execute(array $params = []): PDOStatement
    {
        $this->resetState();
        $stmt = $this->stmt;
        
        if ($stmt === null) {
            throw new \RuntimeException('No statement prepared. Call prepare() first.');
        }
        $this->logger?->debug('operation.start', [
            'operation' => 'execute',
            'driver' => $this->getDriverName(),
            'sql' => $stmt->queryString,
            'timestamp' => microtime(true)
        ]);
        try {
            $this->executeState = $stmt->execute($params);
            $this->lastQuery = $stmt->queryString;
            $this->logger?->debug('operation.end', [
                'operation' => 'execute',
                'driver' => $this->getDriverName(),
                'sql' => $stmt->queryString,
                'timestamp' => microtime(true),
                'rows_affected' => $stmt->rowCount(),
                'success' => (bool)$this->executeState,
            ]);
            $this->stmt = null;
            return $stmt;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->lastErrno = (int)$e->getCode();
            
            $dbException = ExceptionFactory::createFromPdoException(
                $e,
                $this->getDriverName(),
                $stmt->queryString,
                ['operation' => 'execute', 'params' => $params]
            );
            
            $this->logger?->error('operation.error', [
                'operation' => 'execute',
                'driver' => $this->getDriverName(),
                'sql' => $stmt->queryString,
                'timestamp' => microtime(true),
                'exception' => $e,
                'exception_type' => $dbException::class,
                'category' => $dbException->getCategory(),
                'retryable' => $dbException->isRetryable()
            ]);
            throw $dbException;
        }
    }

    /**
     * Executes a SQL query.
     *
     * @param string $sql The SQL query to execute.
     * @return PDOStatement|false The PDOStatement instance or false on failure.
     */
    public function query(string $sql): PDOStatement|false
    {
        $this->resetState();
        $this->logger?->debug('operation.start', [
            'operation' => 'query',
            'driver' => $this->getDriverName(),
            'timestamp' => microtime(true),
            'sql' => $sql,
        ]);
        try {
            $stmt = $this->pdo->query($sql);
            $this->lastQuery = $sql;
            $this->logger?->debug('operation.end', [
                'operation' => 'query',
                'driver' => $this->getDriverName(),
                'timestamp' => microtime(true),
                'sql' => $sql,
            ]);
            return $stmt;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            $this->lastErrno = (int)$e->getCode();
            
            $dbException = ExceptionFactory::createFromPdoException(
                $e,
                $this->getDriverName(),
                $sql,
                ['operation' => 'query']
            );
            
            $this->logger?->error('operation.error', [
                'operation' => 'query',
                'driver' => $this->getDriverName(),
                'timestamp' => microtime(true),
                'sql' => $sql,
                'exception' => $e,
                'exception_type' => $dbException::class,
                'category' => $dbException->getCategory(),
                'retryable' => $dbException->isRetryable()
            ]);
            throw $dbException;
        }
    }

    /**
     * Quotes a string for use in a query.
     *
     * @param mixed $value The value to quote.
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
        $this->logger?->debug('operation.start', [
            'operation' => 'transaction.begin',
            'driver' => $this->getDriverName(),
            'timestamp' => microtime(true)
        ]);
        try {
            return $this->pdo->beginTransaction();
        } catch (PDOException $e) {
            $dbException = ExceptionFactory::createFromPdoException(
                $e,
                $this->getDriverName(),
                null,
                ['operation' => 'transaction.begin']
            );
            
            $this->logger?->error('operation.error', [
                'operation' => 'transaction.begin',
                'driver' => $this->getDriverName(),
                'timestamp' => microtime(true),
                'exception' => $e,
                'exception_type' => $dbException::class,
                'category' => $dbException->getCategory(),
                'retryable' => $dbException->isRetryable()
            ]);
            throw $dbException;
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
            $res = $this->pdo->commit();
            $this->logger?->debug('operation.end', [
                'operation' => 'transaction.commit',
                'driver' => $this->getDriverName(),
                'timestamp' => microtime(true),
            ]);
            return $res;
        } catch (PDOException $e) {
            $dbException = ExceptionFactory::createFromPdoException(
                $e,
                $this->getDriverName(),
                null,
                ['operation' => 'transaction.commit']
            );
            
            $this->logger?->error('operation.error', [
                'operation' => 'transaction.commit',
                'driver' => $this->getDriverName(),
                'timestamp' => microtime(true),
                'exception' => $e,
                'exception_type' => $dbException::class,
                'category' => $dbException->getCategory(),
                'retryable' => $dbException->isRetryable()
            ]);
            throw $dbException;
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
            $res = $this->pdo->rollBack();
            $this->logger?->debug('operation.end', [
                'operation' => 'transaction.rollback',
                'driver' => $this->getDriverName(),
                'timestamp' => microtime(true)
            ]);
            return $res;
        } catch (PDOException $e) {
            $dbException = ExceptionFactory::createFromPdoException(
                $e,
                $this->getDriverName(),
                null,
                ['operation' => 'transaction.rollback']
            );
            
            $this->logger?->error('operation.error', [
                'operation' => 'transaction.rollback',
                'driver' => $this->getDriverName(),
                'timestamp' => microtime(true),
                'exception' => $e,
                'exception_type' => $dbException::class,
                'category' => $dbException->getCategory(),
                'retryable' => $dbException->isRetryable()
            ]);
            throw $dbException;
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
        return $this->executeState;
    }

    /**
     * Returns the last insert ID.
     *
     * @param string|null $name The name of the sequence to use.
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
        return $this->lastQuery;
    }

    /**
     * Returns the last error message.
     *
     * @return string|null The last error message or null if no error has occurred.
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Returns the last error number.
     *
     * @return int The last error number.
     */
    public function getLastErrno(): int
    {
        return $this->lastErrno;
    }

}
