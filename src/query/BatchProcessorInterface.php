<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\query;

interface BatchProcessorInterface
{
    /**
     * Execute query and return iterator for batch processing.
     *
     * Processes data in batches of specified size, yielding arrays of records.
     * Useful for processing large datasets without loading everything into memory.
     *
     * @param string $sql The SQL query to execute
     * @param array $params The parameters for the query
     * @param int $batchSize Number of records per batch (default: 100)
     *
     * @return \Generator<int, array<int, array<string, mixed>>, mixed, void>
     */
    public function batch(string $sql, array $params, int $batchSize = 100): \Generator;

    /**
     * Execute query and return iterator for individual record processing.
     *
     * Processes data one record at a time, but loads them from database in batches
     * for efficiency. Useful when you need to process each record individually
     * but want to avoid memory issues with large datasets.
     *
     * @param string $sql The SQL query to execute
     * @param array $params The parameters for the query
     * @param int $batchSize Internal batch size for database queries (default: 100)
     *
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    public function each(string $sql, array $params, int $batchSize = 100): \Generator;

    /**
     * Execute query and return iterator for individual record processing with cursor.
     *
     * Most memory efficient method for very large datasets. Uses database cursor
     * to stream results without loading them into memory. Best for simple
     * sequential processing of large datasets.
     *
     * @param string $sql The SQL query to execute
     * @param array $params The parameters for the query
     *
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    public function cursor(string $sql, array $params): \Generator;
}
