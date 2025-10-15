<?php
declare(strict_types=1);

namespace tommyknocker\pdodb\connection;

use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;
use Throwable;
use tommyknocker\pdodb\dialects\DialectInterface;

/**
 * Connection
 *
 * Thin wrapper around PDO. Responsible for lifecycle, tracing and error normalization.
 */
class Connection implements ConnectionInterface
{
    protected PDO $pdo;
    protected DialectInterface $dialect;
    protected ?LoggerInterface $logger;
    protected ?string $lastQuery = null;
    protected ?string $lastError = null;
    protected int $lastErrno = 0;
    protected mixed $executeState = null;

    public function __construct(PDO $pdo, DialectInterface $dialect, ?LoggerInterface $logger = null)
    {
        $this->pdo = $pdo;
        $this->dialect = $dialect;
        $this->logger = $logger;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function getDriverName(): string
    {
        return (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function getDialect(): DialectInterface
    {
        return $this->dialect;
    }

    public function execute(string $sql, array $params = []): PDOStatement
    {
        $this->lastQuery = $sql;
        $this->lastError = null;
        $this->lastErrno = 0;
        $this->executeState = null;

        $driver = $this->getDriverName();

        $tsStart = microtime(true);
        $this->logger?->debug('operation.start', [
            'operation' => 'execute',
            'driver' => $driver,
            'sql' => $sql,
            'ts_start' => microtime(true)
        ]);

        try {
            $stmt = $this->pdo->prepare($sql);
            $this->executeState = $stmt->execute($params);
            $rows = $stmt->rowCount();

            $durationMs = round((microtime(true) - $tsStart) * 1000.0, 3);
            $this->logger?->debug('operation.end', [
                'operation' => 'execute',
                'driver' => $driver,
                'sql' => $sql,
                'ts_stop' => microtime(true),
                'duration_ms' => $durationMs,
                'rows_affected' => $rows,
                'success' => (bool)$this->executeState,
            ]);

            return $stmt;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->lastErrno = (int)$e->getCode();
            $this->logger?->error('operation.error', [
                'operation' => 'execute',
                'driver' => $driver,
                'sql' => $sql,
                'ts_stop' => microtime(true),
                'duration_ms' =>  round((microtime(true) - $tsStart) * 1000.0, 3),
                'exception' => $e
            ]);
            throw $e;
        }
    }

    public function query(string $sql): PDOStatement|false
    {
        $this->lastQuery = $sql;
        $driver = $this->getDriverName();
        $this->logger?->debug('operation.start', [
            'operation' => 'query',
            'driver' => $driver,
            'timestamp' => microtime(true),
            'sql' => $sql,
        ]);
        try {
            $res = $this->pdo->query($sql);
            $this->logger?->debug('operation.end', [
                'operation' => 'query',
                'driver' => $driver,
                'timestamp' => microtime(true),
                'sql' => $sql,
            ]);
            return $res;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->lastErrno = (int)$e->getCode();
            $this->logger?->error('operation.error', [
                'operation' => 'query',
                'driver' => $driver,
                'timestamp' => microtime(true),
                'sql' => $sql,
                'exception' => $e
            ]);
            throw $e;
        }
    }

    public function quote(mixed $value): string|false
    {
        return $this->pdo->quote((string)$value);
    }

    public function transaction(): bool
    {
        $this->logger?->debug('operation.start', [
            'operation' => 'transaction.begin',
            'driver' => $this->getDriverName(),
            'timestamp' => microtime(true)
        ]);
        try {
            return $this->pdo->beginTransaction();
        } catch (Throwable $e) {
            $this->logger?->error('operation.error', [
                'operation' => 'transaction.begin',
                'driver' => $this->getDriverName(),
                'timestamp' => microtime(true),
                'exception' => $e
            ]);
            throw $e;
        }
    }

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
        } catch (Throwable $e) {
            $this->logger?->error('operation.error', [
                'operation' => 'transaction.commit',
                'driver' => $this->getDriverName(),
                'timestamp' => microtime(true),
                'exception' => $e
            ]);
            throw $e;
        }
    }

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
        } catch (Throwable $e) {
            $this->logger?->error('operation.error', [
                'operation' => 'transaction.rollback',
                'driver' => $this->getDriverName(),
                'timestamp' => microtime(true),
                'exception' => $e
            ]);
            throw $e;
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function getExecuteState(): bool
    {
        return $this->executeState;
    }


    public function getLastInsertId(?string $name = null): false|string
    {
        return $this->pdo->lastInsertId($name);
    }

    public function getLastQuery(): ?string
    {
        return $this->lastQuery;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getLastErrno(): int
    {
        return $this->lastErrno;
    }

}
