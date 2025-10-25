<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

use Generator;
use InvalidArgumentException;
use PDO;
use PDOException;
use tommyknocker\pdodb\connection\ConnectionInterface;
use tommyknocker\pdodb\query\interfaces\BatchProcessorInterface;
use tommyknocker\pdodb\query\interfaces\ExecutionEngineInterface;
use tommyknocker\pdodb\query\interfaces\ParameterManagerInterface;

class BatchProcessor implements BatchProcessorInterface
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
     * Execute query and return iterator for batch processing.
     *
     * Processes data in batches of specified size, yielding arrays of records.
     * Useful for processing large datasets without loading everything into memory.
     *
     * @param string $sql The SQL query to execute
     * @param array<string, mixed> $params The parameters for the query
     * @param int $batchSize Number of records per batch (default: 100)
     *
     * @return Generator<int, array<int, array<string, mixed>>, mixed, void>
     * @throws InvalidArgumentException If batch size is invalid
     * @throws PDOException
     */
    public function batch(string $sql, array $params, int $batchSize = 100): Generator
    {
        if ($batchSize <= 0) {
            throw new InvalidArgumentException('Batch size must be greater than 0');
        }

        $offset = 0;

        while (true) {
            // Add LIMIT and OFFSET to the query
            $batchSql = $sql . " LIMIT {$batchSize} OFFSET {$offset}";

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
     * Execute query and return iterator for individual record processing.
     *
     * Processes data one record at a time, but loads them from database in batches
     * for efficiency. Useful when you need to process each record individually
     * but want to avoid memory issues with large datasets.
     *
     * @param string $sql The SQL query to execute
     * @param array<string, mixed> $params The parameters for the query
     * @param int $batchSize Internal batch size for database queries (default: 100)
     *
     * @return Generator<int, array<string, mixed>, mixed, void>
     * @throws InvalidArgumentException If batch size is invalid
     * @throws PDOException
     */
    public function each(string $sql, array $params, int $batchSize = 100): Generator
    {
        if ($batchSize <= 0) {
            throw new InvalidArgumentException('Batch size must be greater than 0');
        }

        $offset = 0;
        $buffer = [];

        while (true) {
            // Refill buffer if empty
            if (empty($buffer)) {
                $batchSql = $sql . " LIMIT {$batchSize} OFFSET {$offset}";
                $buffer = $this->executionEngine->fetchAll($batchSql, $params);

                if (empty($buffer)) {
                    break; // No more data
                }

                $offset += $batchSize;
            }

            // Yield one record from buffer
            yield array_shift($buffer);
        }
    }

    /**
     * Execute query and return iterator for individual record processing with cursor.
     *
     * Most memory efficient method for very large datasets. Uses database cursor
     * to stream results without loading them into memory. Best for simple
     * sequential processing of large datasets.
     *
     * @param string $sql The SQL query to execute
     * @param array<string, mixed> $params The parameters for the query
     *
     * @return Generator<int, array<string, mixed>, mixed, void>
     * @throws PDOException
     */
    public function cursor(string $sql, array $params): Generator
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
