# API Reference

Complete reference for PDOdb public API.

## PdoDb Class

### Constructor

```php
new PdoDb(?string $driver = null, array $config = [], array $pdoOptions = [], ?LoggerInterface $logger = null)
```

### Public Methods

#### Query Execution

- `find()` - Get QueryBuilder instance
- `rawQuery(string|RawValue, array)` - Execute raw SQL, return array
- `rawQueryOne(string|RawValue, array)` - Execute raw SQL, return single row
- `rawQueryValue(string|RawValue, array)` - Execute raw SQL, return single value

#### Transactions

- `startTransaction()` - Begin transaction
- `commit()` - Commit transaction
- `rollback()` - Roll back transaction
- `inTransaction()` - Check if transaction is active
- `transaction(callable)` - Execute callback in transaction

#### Connection Management

- `addConnection(string, array, array, ?LoggerInterface)` - Add named connection
- `hasConnection(string)` - Check if connection exists
- `connection(string)` - Switch to named connection
- `disconnect(?string)` - Disconnect from connection(s)
- `ping()` - Test connection

#### Query Configuration

- `setTimeout(int)` - Set query timeout
- `getTimeout()` - Get current timeout

#### Introspection

- `describe(string)` - Get table structure
- `explain(string, array)` - Analyze query execution
- `explainAnalyze(string, array)` - Detailed query analysis

#### Other

- `lock(string|array)` - Lock tables
- `unlock()` - Unlock tables
- `setLockMethod(string)` - Set lock method (READ/WRITE)

### Public Properties

- `$lastQuery` - Last executed query
- `$lastError` - Last error message
- `$lastErrNo` - Last error code
- `$executeState` - Execution status
- `$connection` - Current connection interface

## QueryBuilder Class

### Query Building

- `from(string)` - Set table
- `table(string)` - Alias for from()
- `select(array|string|RawValue)` - Set columns
- `where(...)` - Add WHERE condition
- `andWhere(...)` - Add AND WHERE
- `orWhere(...)` - Add OR WHERE
- `having(...)` - Add HAVING condition
- `orHaving(...)` - Add OR HAVING

### Execution

- `get()` - Execute and get all rows
- `getOne()` - Execute and get first row
- `getColumn()` - Execute and get column values
- `getValue()` - Execute and get single value
- `exists()` - Check if records exist
- `notExists()` - Check if no records exist
- `tableExists()` - Check if table exists

### Data Manipulation

- `insert(array)` - Insert single row
- `insertMulti(array)` - Insert multiple rows
- `update(array)` - Update rows
- `delete()` - Delete rows
- `replace(array)` - Replace row
- `truncate()` - Truncate table

### Pagination

- `limit(int)` - Limit results
- `offset(int)` - Set offset
- `orderBy(string|RawValue, string)` - Add ORDER BY
- `groupBy(string|array|RawValue)` - Add GROUP BY

### Joins

- `join(string, string|RawValue, string)` - Add JOIN
- `leftJoin(string, string|RawValue)` - LEFT JOIN
- `rightJoin(string, string|RawValue)` - RIGHT JOIN
- `innerJoin(string, string|RawValue)` - INNER JOIN

### Batch Processing

- `batch(int)` - Process in batches (returns Generator)
- `each(int)` - Process one at a time (returns Generator)
- `stream()` - Stream results (returns Generator)

### Query Analysis

- `toSQL()` - Get SQL and parameters
- `explain()` - Execution plan
- `explainAnalyze()` - Detailed analysis
- `describe()` - Table structure
- `explainAiAdvice(?string $table, ?string $provider, array $options)` - AI review of the execution plan, see [AI query advice](../05-advanced-features/24-ai-query-advice.md)

### Other

- `option(string|array)` - Add query options
- `asObject()` - Fetch as objects
- `prefix(string)` - Set table prefix
- `onDuplicate(array, array|string|null $conflictTarget)` - UPSERT clause; the optional target names the unique key columns (PostgreSQL, SQLite)

## Outbox and Inbox

See [Transactional outbox](../05-advanced-features/27-transactional-outbox.md) and [Inbox](../05-advanced-features/28-inbox.md) for usage and the [minimum server versions](../05-advanced-features/27-transactional-outbox.md#minimum-server-versions).

`tommyknocker\pdodb\outbox\Outbox` (constructed with a `PdoDb`, a table name optional):

- `add(string $eventType, array $payload): string` - Enqueue inside the caller's transaction, returns the message id
- `worker(?string $workerId, int $batchSize, int $lockSeconds, int $maxAttempts): OutboxWorker` - Claim, publish and retry
- `countByStatus()`, `listMessages(string $status, int $limit)` - Inspect the queue
- `requeueFailed(string $id, bool $resetAttempts)`, `requeueAllFailed(int $limit, bool $resetAttempts, bool $dryRun)` - Retry failed messages
- `purge(string $olderThanSpec, ?string $status, bool $dryRun)` - Delete old `published` or `failed` rows in batches

`tommyknocker\pdodb\inbox\Inbox` (constructed with a `PdoDb`, a consumer name and a table name):

- `once(string $messageId, callable $handler): bool` - Run the handler once per message id and consumer
- `processed(string $messageId): bool` - Check without claiming
- `purge(string $olderThanSpec, bool $dryRun): int` - Forget processed ids older than the given age
- `getSchemaSql()`, `ensureSchema()` - Create the table

## Helper Functions (Db class)

### Core

- `Db::raw(string, array)` - Raw SQL expression
- `Db::escape(string)` - Escape string
- `Db::config(...)` - Database config

### Null Handling

- `Db::null()` - NULL value
- `Db::isNull(string)` - IS NULL
- `Db::isNotNull(string)` - IS NOT NULL
- `Db::ifNull(...)` - Handle NULL

### Numeric

- `Db::inc(int)` - Increment
- `Db::dec(int)` - Decrement
- `Db::abs(...)` - Absolute value
- `Db::round(...)` - Round number
- `Db::mod(...)` - Modulo

### String

- `Db::concat(...)` - Concatenate
- `Db::upper(...)` - Uppercase
- `Db::lower(...)` - Lowercase
- `Db::trim(...)` - Trim whitespace

### Date/Time

- `Db::now(string)` - Current timestamp
- `Db::ts(string)` - Unix timestamp
- `Db::curDate()` - Current date
- `Db::curTime()` - Current time

### JSON

- `Db::jsonObject(...)` - JSON object
- `Db::jsonArray(...)` - JSON array
- `Db::jsonGet(...)` - Get JSON value
- `Db::jsonContains(...)` - Check if contains

### Comparison

- `Db::like(...)` - LIKE pattern
- `Db::between(...)` - Value range
- `Db::in(...)` - IN array
- `Db::isNull(...)` - NULL check

## Next Steps

- [Query Builder Methods](02-query-builder-methods.md) - Detailed QueryBuilder reference
- [Helper Functions Reference](04-helper-functions-reference.md) - Complete helper functions
