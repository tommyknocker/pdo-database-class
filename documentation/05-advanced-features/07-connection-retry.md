# Connection Retry

Automatic retry mechanism for handling connection failures.

## Basic Configuration

### Enable Retry

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'mydb',
    
    'retry' => [
        'enabled' => true,
        'max_attempts' => 3,
        'delay_ms' => 1000,
        'backoff_multiplier' => 2.0,
        'max_delay_ms' => 10000
    ]
]);
```

## Retry Configuration Options

### Enabled

```php
'retry' => [
    'enabled' => true  // Enable/disable retry
]
```

### Max Attempts

```php
'retry' => [
    'max_attempts' => 5  // Retry up to 5 times
]
```

### Delay

```php
'retry' => [
    'delay_ms' => 1000  // Start with 1 second delay
]
```

### Backoff

```php
'retry' => [
    'backoff_multiplier' => 2.0  // Double delay each retry
]
```

### Max Delay

```php
'retry' => [
    'max_delay_ms' => 5000  // Cap at 5 seconds
]
```

## Exponential Backoff

### How It Works

Attempt 1: Wait 1s
Attempt 2: Wait 2s  
Attempt 3: Wait 4s
Attempt 4: Wait 8s
Attempt 5: Wait 10s (capped at max_delay_ms)

### Configuration Example

```php
'retry' => [
    'enabled' => true,
    'max_attempts' => 5,
    'delay_ms' => 1000,
    'backoff_multiplier' => 2.0,
    'max_delay_ms' => 5000
]
```

## Retryable Errors

### MySQL

```php
use tommyknocker\pdodb\helpers\DbError;

'retry' => [
    'enabled' => true,
    'retryable_errors' => DbError::getRetryableErrors('mysql')
]
```

### Custom Error Codes

```php
'retry' => [
    'enabled' => true,
    'retryable_errors' => [
        2002,  // Can't connect to server
        2003,  // Connection attempt failed
        2006   // MySQL server has gone away
    ]
]
```

## Retryable vs Non-Retryable

### Retryable Errors

- Connection lost
- Timeouts
- Temporary failures
- Deadlocks

### Non-Retryable Errors

- Authentication failed
- Constraint violations
- Syntax errors
- Missing tables

## Logging

### Enable Retry Logging

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('database');
$logger->pushHandler(new StreamHandler('php://stdout'));

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'test',
    'retry' => [
        'enabled' => true,
        'max_attempts' => 3
    ]
], [], $logger);
```

### Log Messages

- `connection.retry.start` - Retry begins
- `connection.retry.attempt` - Attempt number
- `connection.retry.success` - Success
- `connection.retry.exhausted` - All attempts failed

## Best Practices

### Keep Retries Short

```php
// ✅ Good: Quick retries
'retry' => [
    'max_attempts' => 3,
    'delay_ms' => 500,
    'max_delay_ms' => 2000
]

// ❌ Bad: Long retries
'retry' => [
    'max_attempts' => 10,
    'delay_ms' => 5000
]
```

### Use Appropriate Retryable Errors

```php
use tommyknocker\pdodb\helpers\DbError;

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'dbname' => 'test',
    'retry' => [
        'enabled' => true,
        'retryable_errors' => DbError::getRetryableErrors('mysql')
    ]
]);
```

## Next Steps

- [Exception Hierarchy](../06-error-handling/01-exception-hierarchy.md) - Error handling
- [Transactions](transactions.md) - Transaction management
- [Error Codes](../06-error-handling/02-error-codes.md) - Error constants
