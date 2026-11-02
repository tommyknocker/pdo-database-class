<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use Generator;
use InvalidArgumentException;
use PDO;
use PDOException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\query\interfaces\BatchProcessorInterface;
use tommyknocker\pdodb\query\interfaces\ConnectionAwareInterface;
use tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface;
use tommyknocker\pdodb\query\interfaces\ParameterManagerInterface;

class BatchProcessor implements BatchProcessorInterface, ConnectionAwareInterface
{
    protected ConnectionInterface $connection;
    protected ExecutionEngineInterface $executionEngine;
    protected ParameterManagerInterface $parameterManager;
    protected RawValueResolver $rawValueResolver;

    public function __construct(
        ConnectionInterface $connection,
        ExecutionEngineInterface $executionEngine,
        ParameterManagerInterface $parameterManager,
        RawValueResolver $rawValueResolver
    ) {
        $this->connection = $connection;
        $this->executionEngine = $executionEngine;
        $this->parameterManager = $parameterManager;
        $this->rawValueResolver = $rawValueResolver;
    }

    /**
     * {@inheritDoc}
     */
    public function rebindConnection(ConnectionInterface $connection): void
    {
        $this->connection = $connection;
    }

    /**
     * Execute query and return iterator for batch processing.
     *
     * Processes data in batches of specified size, yielding arrays of records.
     * Useful for processing large datasets without loading everything into memory.
     *
     * @param string $sql The SQL query to execute
     * @param array<string, mixed> $params The parameters for the query
     * @param int $batchSize Number of records per batch (default: 100)
     * @param string|null $key A unique column that grows with each row (usually the primary key). With it the batches
     *                         follow the key instead of an OFFSET: rows may be updated or deleted while iterating
     *                         without the next batch skipping any, and every batch costs the same on a large table.
     *                         The result is ordered by the key, so the query must not have an ORDER BY of its own and
     *                         must select that column. Without it the batches use LIMIT/OFFSET.
     *
     * @return Generator<int, array<int, array<string, mixed>>, mixed, void>
     * @throws InvalidArgumentException If batch size is invalid
     * @throws PDOException
     */
    public function batch(string $sql, array $params, int $batchSize = 100, ?string $key = null): Generator
    {
        if ($batchSize <= 0) {
            throw new InvalidArgumentException('Batch size must be greater than 0');
        }
        if ($key !== null) {
            yield from $this->batchByKey($sql, $params, $batchSize, $key);

            return;
        }

        $offset = 0;

        while (true) {
            // Add LIMIT and OFFSET to the query (dialect-specific)
            $batchSql = $this->connection->getDialect()->appendLimitOffset($sql, $batchSize, $offset);

            $rows = $this->executionEngine->fetchAll($batchSql, $params);

            if (empty($rows)) {
                break; // No more data
            }

            yield $rows;

            // If we got less than batchSize, we're done
            if (count($rows) < $batchSize) {
                break;
            }

            $offset += $batchSize;
        }
    }

    /**
     * Batches that continue after the last key seen, so rows may change while iterating.
     *
     * The query is used as a derived table; `$column` must be unique and increasing, and the query must not have
     * an ORDER BY of its own.
     *
     * @param array<string, mixed> $params
     *
     * @return Generator<int, array<int, array<string, mixed>>, mixed, void>
     * @throws InvalidArgumentException If the result has no such column
     */
    protected function batchByKey(string $sql, array $params, int $batchSize, string $column): Generator
    {
        $dialect = $this->connection->getDialect();
        // The query is a derived table: its rows are keyed by the bare column name, whatever qualifier it was selected with
        $dot = strrpos($column, '.');
        $column = $dot === false ? $column : substr($column, $dot + 1);
        $quoted = $dialect->quoteIdentifier($column);
        $last = null;

        while (true) {
            $batchParams = $params;
            $where = '';
            if ($last !== null) {
                $where = " WHERE {$quoted} > :pdodb_batch_last";
                $batchParams[':pdodb_batch_last'] = $last;
            }
            $batchSql = $dialect->appendLimitOffset(
                "SELECT * FROM ({$sql}) pdodb_batch{$where} ORDER BY {$quoted}",
                $batchSize,
                0
            );

            $rows = array_values($this->executionEngine->fetchAll($batchSql, $batchParams));
            if ($rows === []) {
                return;
            }

            yield $rows;

            if (count($rows) < $batchSize) {
                return;
            }

            $last = $this->keyOf($rows[count($rows) - 1], $column);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function keyOf(array $row, string $column): mixed
    {
        foreach ($row as $name => $value) {
            if (strcasecmp((string)$name, $column) === 0 && $value !== null) {
                return $value;
            }
        }

        throw new InvalidArgumentException("The result has no non-null '{$column}' column to continue after.");
    }

    /**
     * Execute query and return iterator for individual record processing.
     *
     * Processes data one record at a time, but loads them from database in batches
     * for efficiency. Useful when you need to process each record individually
     * but want to avoid memory issues with large datasets.
     *
     * @param string $sql The SQL query to execute
     * @param array<string, mixed> $params The parameters for the query
     * @param int $batchSize Internal batch size for database queries (default: 100)
     * @param string|null $key A unique column that grows with each row (usually the primary key). With it the batches
     *                         follow the key instead of an OFFSET: rows may be updated or deleted while iterating
     *                         without the next batch skipping any, and every batch costs the same on a large table.
     *                         The result is ordered by the key, so the query must not have an ORDER BY of its own and
     *                         must select that column. Without it the batches use LIMIT/OFFSET.
     *
     * @return Generator<int, array<string, mixed>, mixed, void>
     * @throws InvalidArgumentException If batch size is invalid
     * @throws PDOException
     */
    public function each(string $sql, array $params, int $batchSize = 100, ?string $key = null): Generator
    {
        foreach ($this->batch($sql, $params, $batchSize, $key) as $rows) {
            foreach ($rows as $row) {
                yield $row;
            }
        }
    }

    /**
     * Stream query results without loading into memory.
     *
     * Most memory efficient method for very large datasets. Uses database cursor
     * to stream results row by row without loading them into memory. Best for simple
     * sequential processing of large datasets.
     *
     * @param string $sql The SQL query to execute
     * @param array<string, mixed> $params The parameters for the query
     *
     * @return Generator<int, array<string, mixed>, mixed, void>
     * @throws PDOException
     */
    public function stream(string $sql, array $params): Generator
    {
        $normalizedParams = $this->parameterManager->normalizeParams($params);

        // Use executeStatement which returns PDOStatement directly
        $stmt = $this->executionEngine->executeStatement($sql, $normalizedParams);
        $stmt->setFetchMode(PDO::FETCH_ASSOC);

        while ($row = $stmt->fetch()) {
            yield $row;
        }

        $stmt->closeCursor();
    }
}
