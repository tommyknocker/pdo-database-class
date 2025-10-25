<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;
use tommyknocker\pdodb\dialects\DialectInterface;
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
     * Handle PDO exceptions with consistent error processing.
     *
     * @param PDOException $e The PDO exception
     * @param string $operation The operation that failed
     * @param string|null $sql The SQL query (if applicable)
     * @param array<string, mixed> $context Additional context
     *
     * @throws \tommyknocker\pdodb\exceptions\DatabaseException Always throws a specialized exception
     */
    protected function handlePdoException(
        PDOException $e,
        string $operation,
        ?string $sql = null,
        array $context = []
    ): never {
        $this->state->setError($e->getMessage(), (int)$e->getCode());

        $dbException = ExceptionFactory::createFromPdoException(
            $e,
            $this->getDriverName(),
            $sql,
            array_merge(['operation' => $operation], $context)
        );

        $this->logOperationError($operation, $e, $sql, [
            'exception_type' => $dbException::class,
            'category' => $dbException->getCategory(),
            'retryable' => $dbException->isRetryable(),
        ]);

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
        $this->logOperationStart('prepare', $sql);

        try {
            $this->stmt = $this->pdo->prepare($sql, $params);
            $this->logOperationEnd('prepare', $this->stmt->queryString);
            return $this;
        } catch (PDOException $e) {
            $this->handlePdoException($e, 'prepare', $this->stmt?->queryString);
        }
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

        try {
            $this->state->setExecuteState($stmt->execute($params));
            $this->state->setLastQuery($stmt->queryString);
            $this->logOperationEnd('execute', $stmt->queryString, [
                'rows_affected' => $stmt->rowCount(),
                'success' => (bool)$this->state->getExecuteState(),
            ]);
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
        $this->resetState();
        $this->logOperationStart('query', $sql);

        try {
            $stmt = $this->pdo->query($sql);
            $this->state->setLastQuery($sql);
            $this->logOperationEnd('query', $sql);
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
            return $this->pdo->beginTransaction();
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
            $res = $this->pdo->commit();
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
            $res = $this->pdo->rollBack();
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
