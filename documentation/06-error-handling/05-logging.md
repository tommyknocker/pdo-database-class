# Logging

Configure and use logging with PDOdb.

## Setup Logger

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('database');
$logger->pushHandler(new StreamHandler('php://stdout'));

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'test'
], [], $logger);
```

## Query Logging

### Enable Query Log

```php
$db->enableQueryLog();

// Execute queries
$users = $db->find()->from('users')->get();
$orders = $db->find()->from('orders')->get();

// Get log
$log = $db->getQueryLog();

foreach ($log as $entry) {
    echo $entry['sql'] . "\n";
    echo "Time: " . $entry['time'] . "ms\n";
}
```

### Disable Query Log

```php
$db->disableQueryLog();
```

## Log Levels

### Connection Log

```php
// Logs connection attempts and failures
$logger->info('Connecting to database', [
    'host' => 'localhost',
    'database' => 'mydb'
]);
```

### Query Log

```php
// Logs executed queries
$logger->debug('Executing query', [
    'sql' => $sql,
    'params' => $params,
    'time' => $executionTime
]);
```

### Error Log

```php
try {
    $users = $db->find()->from('users')->get();
} catch (QueryException $e) {
    $logger->error('Query failed', [
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
}
```

## Custom Log Handler

```php
use Monolog\Handler\AbstractHandler;

class DatabaseHandler extends AbstractHandler {
    public function handle(array $record): bool {
        // Save to database
        // ...
        return true;
    }
}

$handler = new DatabaseHandler();
$logger->pushHandler($handler);
```

## Next Steps

- [Exception Hierarchy](01-exception-hierarchy.md) - Exception types
- [Monitoring](06-monitoring.md) - Error tracking
- [Retry Logic](04-retry-logic.md) - Build retry logic
