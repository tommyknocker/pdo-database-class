# PdoDb Methods

Complete reference for PdoDb class methods.

## Connection Methods

### `find(): QueryBuilder`

Create new query builder.

```php
$db->find()->from('users')->get();
```

### `rawQuery(string $sql, ?array $params = null)`

Execute raw SQL query.

```php
$db->rawQuery('SELECT * FROM users WHERE id = ?', [1]);
```

## Transaction Methods

### `startTransaction(): void`

Start transaction.

```php
$db->startTransaction();
```

### `commit(): void`

Commit transaction.

```php
$db->commit();
```

### `rollback(): void`

Rollback transaction.

```php
$db->rollback();
```

### `transaction(callable $callback)`

Execute in transaction.

```php
$result = $db->transaction(function() use ($db) {
    // ...
});
```

## Connection Management

### `switchConnection(string $name): void`

Switch to another connection.

```php
$db->switchConnection('slave');
```

### `getConnection(?string $name = null): ConnectionInterface`

Get connection instance.

```php
$connection = $db->getConnection();
```

## Query Logging

### `enableQueryLog(): void`

Enable query logging.

```php
$db->enableQueryLog();
```

### `disableQueryLog(): void`

Disable query logging.

```php
$db->disableQueryLog();
```

### `getQueryLog(): array`

Get query log.

```php
$log = $db->getQueryLog();
```

## Batch Processing

### `batch(int $size, callable $callback): void`

Process in batches.

```php
$db->batch(100, function($batch) {
    // Process batch
});
```

### `each(callable $callback): void`

Iterate over results.

```php
$db->find()->from('users')->each(function($user) {
    // Process user
});
```

### `stream(): \Generator`

Stream query results without loading into memory.

```php
foreach ($db->find()->from('users')->stream() as $user) {
    // Process user
}
```

## Next Steps

- [API Reference](01-api-reference.md) - Complete API
- [Query Builder Methods](02-query-builder-methods.md) - Query builder methods
- [Helper Functions Reference](04-helper-functions-reference.md) - Helpers
