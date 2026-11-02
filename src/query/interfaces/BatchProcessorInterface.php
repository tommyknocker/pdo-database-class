<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query\interfaces;

interface BatchProcessorInterface
{
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
     * @return \Generator<int, array<int, array<string, mixed>>, mixed, void>
     */
    public function batch(string $sql, array $params, int $batchSize = 100, ?string $key = null): \Generator;

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
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    public function each(string $sql, array $params, int $batchSize = 100, ?string $key = null): \Generator;

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
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    public function stream(string $sql, array $params): \Generator;
}
