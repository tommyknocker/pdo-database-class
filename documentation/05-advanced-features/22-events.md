# Event System

The PDOdb library provides a comprehensive event system based on PSR-14 Event Dispatcher. Events allow you to hook into various database operations and react to them, enabling logging, monitoring, query modification, and more.

## Overview

Events are dispatched at key points during database operations:

- **Connection Events**: Connection lifecycle (opened, closed)
- **Query Events**: Query execution (before, after, errors)
- **Transaction Events**: Transaction lifecycle (started, committed, rolled back)
- **Savepoint Events**: Savepoint operations (created, rolled back, released)
- **Cache Events**: Cache operations (hit, miss, set, delete, clear)
- **Migration Events**: Migration execution (started, completed, rolled back)
- **DDL Events**: Schema operations (create table, drop table, alter column, etc.)
- **Seed Events**: Seed execution (started, completed)
- **Model Events**: ActiveRecord operations (before/after save, insert, update, delete)

## Setting Up Event Dispatcher

To use events, you need to set an event dispatcher that implements `Psr\EventDispatcher\EventDispatcherInterface`:

```php
use Psr\EventDispatcher\EventDispatcherInterface;
use tommyknocker\pdodb\PdoDb;

// Create your event dispatcher (e.g., Symfony EventDispatcher)
$dispatcher = new YourEventDispatcher();

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'test',
    'user' => 'root',
    'password' => ''
]);

// Set the event dispatcher
$db->setEventDispatcher($dispatcher);
```

## Connection Events

### ConnectionOpenedEvent

Fired when a database connection is opened.

**Properties:**
- `getDriver(): string` - Database driver name
- `getDsn(): string` - Data Source Name
- `getOptions(): array` - Connection options

**Example:**
```php
use tommyknocker\pdodb\events\ConnectionOpenedEvent;

$dispatcher->addListener(ConnectionOpenedEvent::class, function (ConnectionOpenedEvent $event) {
    echo "Connection opened: {$event->getDriver()}\n";
    echo "DSN: {$event->getDsn()}\n";
});
```

### ConnectionClosedEvent

Fired when a database connection is closed.

**Properties:**
- `getDriver(): string` - Database driver name
- `getDsn(): string` - Data Source Name
- `getDuration(): float` - Connection duration in seconds

**Example:**
```php
use tommyknocker\pdodb\events\ConnectionClosedEvent;

$dispatcher->addListener(ConnectionClosedEvent::class, function (ConnectionClosedEvent $event) {
    echo "Connection closed after {$event->getDuration()} seconds\n";
});
```

## Query Events

### QueryBeforeExecuteEvent

Fired before a query is executed. This event allows you to modify the SQL and parameters before execution, or cancel the query entirely.

**Properties:**
- `getSql(): string` - SQL query
- `getParams(): array` - Query parameters
- `getDriver(): string` - Database driver name
- `setSql(string $sql): void` - Modify SQL query
- `setParams(array $params): void` - Modify parameters
- `stopPropagation(): void` - Cancel query execution

**Example - Modify Query:**
```php
use tommyknocker\pdodb\events\QueryBeforeExecuteEvent;

$dispatcher->addListener(QueryBeforeExecuteEvent::class, function (QueryBeforeExecuteEvent $event) {
    // Add logging to all SELECT queries
    if (stripos($event->getSql(), 'SELECT') === 0) {
        $sql = $event->getSql();
        $event->setSql("/* Logged at " . date('Y-m-d H:i:s') . " */ " . $sql);
    }
});
```

**Example - Cancel Query:**
```php
$dispatcher->addListener(QueryBeforeExecuteEvent::class, function (QueryBeforeExecuteEvent $event) {
    // Cancel all DELETE queries
    if (stripos($event->getSql(), 'DELETE') === 0) {
        $event->stopPropagation();
        throw new \RuntimeException('DELETE queries are not allowed');
    }
});
```

### QueryExecutedEvent

Fired after a query is executed successfully.

**Properties:**
- `getSql(): string` - Executed SQL query
- `getParams(): array` - Query parameters
- `getExecutionTime(): float` - Execution time in milliseconds
- `getRowsAffected(): int` - Number of rows affected/returned
- `getDriver(): string` - Database driver name
- `isFromCache(): bool` - Whether result came from cache

**Example:**
```php
use tommyknocker\pdodb\events\QueryExecutedEvent;

$dispatcher->addListener(QueryExecutedEvent::class, function (QueryExecutedEvent $event) {
    if ($event->getExecutionTime() > 1000) {
        // Log slow queries
        error_log("Slow query: {$event->getSql()} ({$event->getExecutionTime()}ms)");
    }
});
```

### QueryErrorEvent

Fired when a query execution fails.

**Properties:**
- `getSql(): string` - SQL query that failed
- `getParams(): array` - Query parameters
- `getError(): string` - Error message
- `getErrorCode(): string|int` - Error code
- `getDriver(): string` - Database driver name

## Transaction Events

### TransactionStartedEvent

Fired when a transaction starts.

**Properties:**
- `getDriver(): string` - Database driver name

### TransactionCommittedEvent

Fired when a transaction is committed.

**Properties:**
- `getDriver(): string` - Database driver name
- `getDuration(): float` - Transaction duration in milliseconds

### TransactionRolledBackEvent

Fired when a transaction is rolled back.

**Properties:**
- `getDriver(): string` - Database driver name
- `getDuration(): float` - Transaction duration in milliseconds

**Example:**
```php
use tommyknocker\pdodb\events\TransactionStartedEvent;
use tommyknocker\pdodb\events\TransactionCommittedEvent;
use tommyknocker\pdodb\events\TransactionRolledBackEvent;

$dispatcher->addListener(TransactionStartedEvent::class, function () {
    echo "Transaction started\n";
});

$dispatcher->addListener(TransactionCommittedEvent::class, function (TransactionCommittedEvent $event) {
    echo "Transaction committed in {$event->getDuration()}ms\n";
});

$dispatcher->addListener(TransactionRolledBackEvent::class, function (TransactionRolledBackEvent $event) {
    echo "Transaction rolled back after {$event->getDuration()}ms\n";
});
```

## Savepoint Events

### SavepointCreatedEvent

Fired when a savepoint is created.

**Properties:**
- `getName(): string` - Savepoint name
- `getDriver(): string` - Database driver name

### SavepointRolledBackEvent

Fired when a rollback to a savepoint is performed.

**Properties:**
- `getName(): string` - Savepoint name
- `getDriver(): string` - Database driver name

### SavepointReleasedEvent

Fired when a savepoint is released.

**Properties:**
- `getName(): string` - Savepoint name
- `getDriver(): string` - Database driver name

**Example:**
```php
use tommyknocker\pdodb\events\SavepointCreatedEvent;
use tommyknocker\pdodb\events\SavepointRolledBackEvent;

$dispatcher->addListener(SavepointCreatedEvent::class, function (SavepointCreatedEvent $event) {
    echo "Savepoint created: {$event->getName()}\n";
});

$dispatcher->addListener(SavepointRolledBackEvent::class, function (SavepointRolledBackEvent $event) {
    echo "Rolled back to savepoint: {$event->getName()}\n";
});
```

## Cache Events

### CacheHitEvent

Fired when a cache hit occurs.

**Properties:**
- `getKey(): string` - Cache key
- `getValue(): mixed` - Cached value
- `getTtl(): int|null` - Time-to-live in seconds (null if not specified)

### CacheMissEvent

Fired when a cache miss occurs.

**Properties:**
- `getKey(): string` - Cache key that was not found

### CacheSetEvent

Fired when a value is stored in cache.

**Properties:**
- `getKey(): string` - Cache key
- `getValue(): mixed` - Value being cached
- `getTtl(): int` - Time-to-live in seconds
- `getTables(): array|null` - Associated table names (if provided)

### CacheDeleteEvent

Fired when a value is deleted from cache.

**Properties:**
- `getKey(): string` - Cache key that was deleted
- `isSuccess(): bool` - Whether deletion was successful

### CacheClearedEvent

Fired when the entire cache is cleared.

**Properties:**
- `isSuccess(): bool` - Whether cache clear was successful

**Example:**
```php
use tommyknocker\pdodb\events\CacheHitEvent;
use tommyknocker\pdodb\events\CacheMissEvent;

$dispatcher->addListener(CacheHitEvent::class, function (CacheHitEvent $event) {
    echo "Cache hit: {$event->getKey()}\n";
});

$dispatcher->addListener(CacheMissEvent::class, function (CacheMissEvent $event) {
    echo "Cache miss: {$event->getKey()}\n";
});
```

## Migration Events

### MigrationStartedEvent

Fired when a migration starts executing.

**Properties:**
- `getVersion(): string` - Migration version/name
- `getDriver(): string` - Database driver name

### MigrationCompletedEvent

Fired when a migration completes successfully.

**Properties:**
- `getVersion(): string` - Migration version/name
- `getDriver(): string` - Database driver name
- `getDuration(): float` - Migration execution duration in milliseconds

### MigrationRolledBackEvent

Fired when a migration is rolled back.

**Properties:**
- `getVersion(): string` - Migration version/name
- `getDriver(): string` - Database driver name

**Example:**
```php
use tommyknocker\pdodb\events\MigrationStartedEvent;
use tommyknocker\pdodb\events\MigrationCompletedEvent;

$dispatcher->addListener(MigrationStartedEvent::class, function (MigrationStartedEvent $event) {
    echo "Running migration: {$event->getVersion()}\n";
});

$dispatcher->addListener(MigrationCompletedEvent::class, function (MigrationCompletedEvent $event) {
    echo "Migration completed: {$event->getVersion()} in {$event->getDuration()}ms\n";
});
```

## DDL Events

### DdlOperationEvent

Fired when a DDL (Data Definition Language) operation is executed.

**Properties:**
- `getOperation(): string` - Operation type (CREATE_TABLE, DROP_TABLE, ALTER_TABLE, ADD_COLUMN, DROP_COLUMN, CREATE_INDEX, DROP_INDEX, etc.)
- `getTable(): string` - Table name (if applicable)
- `getSql(): string` - SQL statement that was executed
- `getDriver(): string` - Database driver name

**Example:**
```php
use tommyknocker\pdodb\events\DdlOperationEvent;

$dispatcher->addListener(DdlOperationEvent::class, function (DdlOperationEvent $event) {
    echo "DDL operation: {$event->getOperation()} on table {$event->getTable()}\n";
    echo "SQL: {$event->getSql()}\n";
});
```

## Seed Events

### SeedStartedEvent

Fired when a seed starts executing.

**Properties:**
- `getName(): string` - Seed name
- `getDriver(): string` - Database driver name

### SeedCompletedEvent

Fired when a seed completes successfully.

**Properties:**
- `getName(): string` - Seed name
- `getDriver(): string` - Database driver name
- `getDuration(): float` - Seed execution duration in milliseconds

**Example:**
```php
use tommyknocker\pdodb\events\SeedStartedEvent;
use tommyknocker\pdodb\events\SeedCompletedEvent;

$dispatcher->addListener(SeedStartedEvent::class, function (SeedStartedEvent $event) {
    echo "Running seed: {$event->getName()}\n";
});

$dispatcher->addListener(SeedCompletedEvent::class, function (SeedCompletedEvent $event) {
    echo "Seed completed: {$event->getName()} in {$event->getDuration()}ms\n";
});
```

## Model Events (ActiveRecord)

See [ActiveRecord documentation](16-active-record.md) for model events (ModelBeforeSaveEvent, ModelAfterSaveEvent, etc.).

## Complete Example

Here's a complete example showing how to use events for logging and monitoring:

```php
use Psr\EventDispatcher\EventDispatcherInterface;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\events\QueryExecutedEvent;
use tommyknocker\pdodb\events\QueryErrorEvent;
use tommyknocker\pdodb\events\TransactionCommittedEvent;

// Create event dispatcher (example using Symfony EventDispatcher)
$dispatcher = new Symfony\Component\EventDispatcher\EventDispatcher();

// Log all queries
$dispatcher->addListener(QueryExecutedEvent::class, function (QueryExecutedEvent $event) {
    $logger->info('Query executed', [
        'sql' => $event->getSql(),
        'params' => $event->getParams(),
        'time' => $event->getExecutionTime(),
        'rows' => $event->getRowsAffected(),
    ]);
});

// Log query errors
$dispatcher->addListener(QueryErrorEvent::class, function (QueryErrorEvent $event) {
    $logger->error('Query error', [
        'sql' => $event->getSql(),
        'error' => $event->getError(),
        'code' => $event->getErrorCode(),
    ]);
});

// Monitor transaction duration
$dispatcher->addListener(TransactionCommittedEvent::class, function (TransactionCommittedEvent $event) {
    if ($event->getDuration() > 5000) {
        $logger->warning('Long transaction', [
            'duration' => $event->getDuration(),
        ]);
    }
});

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'test',
]);
$db->setEventDispatcher($dispatcher);

// Now all operations will trigger events
$db->find()->table('users')->get();
```

## Best Practices

1. **Use events for cross-cutting concerns**: Logging, monitoring, auditing, etc.
2. **Keep event listeners lightweight**: Avoid heavy operations in listeners
3. **Don't modify queries unnecessarily**: Only modify queries in QueryBeforeExecuteEvent when necessary
4. **Handle errors gracefully**: Event listeners should not throw exceptions unless absolutely necessary
5. **Use typed listeners**: Type-hint event classes in listener callbacks for better IDE support

## Event Priority

If your event dispatcher supports priority (e.g., Symfony EventDispatcher), you can use it to control the order of event listeners:

```php
// High priority - runs first
$dispatcher->addListener(QueryBeforeExecuteEvent::class, function () {
    // ...
}, 10);

// Low priority - runs last
$dispatcher->addListener(QueryBeforeExecuteEvent::class, function () {
    // ...
}, -10);
```

