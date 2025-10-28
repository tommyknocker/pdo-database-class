# Exception Hierarchy

Learn about PDOdb's exception system for better error handling.

## Overview

PDOdb provides a comprehensive exception hierarchy based on error types. All exceptions extend `PDOException` for backward compatibility.

## Exception Classes

```php
use tommyknocker\pdodb\exceptions\{
    DatabaseException,           // Base exception class
    ConnectionException,         // Connection-related errors
    QueryException,             // Query execution errors
    ConstraintViolationException, // Constraint violations
    TransactionException,       // Transaction errors
    AuthenticationException,     // Authentication errors
    TimeoutException,           // Timeout errors
    ResourceException           // Resource exhaustion
};
```

## Base Exception: DatabaseException

All database exceptions extend this class:

```php
use tommyknocker\pdodb\exceptions\DatabaseException;

try {
    $users = $db->find()->from('users')->get();
} catch (DatabaseException $e) {
    echo "Exception: {$e->getMessage()}\n";
    echo "Driver: {$e->getDriver()}\n";
    echo "Query: {$e->getQuery()}\n";
    echo "Category: {$e->getCategory()}\n";
    echo "Retryable: " . ($e->isRetryable() ? 'Yes' : 'No') . "\n";
}
```

## Specific Exceptions

### ConnectionException

Connection-related errors (retryable):

```php
use tommyknocker\pdodb\exceptions\ConnectionException;
use tommyknocker\pdodb\exceptions\DatabaseException;

try {
    $db = new PdoDb('mysql', [
        'host' => 'invalid-host',
        'username' => 'user',
        'password' => 'pass',
        'dbname' => 'db'
    ]);
} catch (ConnectionException $e) {
    error_log("Connection failed: {$e->getMessage()}");
    
    if ($e->isRetryable()) {
        // Implement retry logic
    }
} catch (DatabaseException $e) {
    // Handle other database errors
}
```

### QueryException

Query execution errors (usually not retryable):

```php
use tommyknocker\pdodb\exceptions\QueryException;

try {
    $users = $db->find()
        ->from('nonexistent_table')
        ->get();
} catch (QueryException $e) {
    error_log("Query failed: {$e->getMessage()}");
    error_log("SQL: {$e->getQuery()}");
    
    if ($e->isRetryable()) {
        // Implement retry logic
    }
}
```

### ConstraintViolationException

Constraint violations (not retryable):

```php
use tommyknocker\pdodb\exceptions\ConstraintViolationException;

try {
    $db->find()->table('users')->insert([
        'email' => 'existing@example.com'  // Duplicate
    ]);
} catch (ConstraintViolationException $e) {
    error_log("Constraint violation: {$e->getMessage()}");
    error_log("Constraint: {$e->getConstraintName()}");
    error_log("Table: {$e->getTableName()}");
    error_log("Column: {$e->getColumnName()}");
    
    // Handle specific constraint violations
    if ($e->getConstraintName() === 'unique_email') {
        // Handle duplicate email
    }
}
```

### TransactionException

Transaction-related errors (retryable):

```php
use tommyknocker\pdodb\exceptions\TransactionException;

$db->startTransaction();
try {
    $userId = $db->find()->table('users')->insert(['name' => 'Alice']);
    $db->find()->table('orders')->insert(['user_id' => $userId, 'total' => 100]);
    $db->commit();
} catch (TransactionException $e) {
    $db->rollBack();
    error_log("Transaction failed: {$e->getMessage()}");
    
    if ($e->isRetryable()) {
        // Implement retry logic for deadlocks, etc.
    }
    throw $e;
}
```

### AuthenticationException

Authentication/authorization errors (not retryable):

```php
use tommyknocker\pdodb\exceptions\AuthenticationException;

try {
    $db = new PdoDb('mysql', [
        'host' => 'localhost',
        'username' => 'wrong_user',
        'password' => 'wrong_pass',
        'dbname' => 'testdb'
    ]);
} catch (AuthenticationException $e) {
    error_log("Authentication failed: {$e->getMessage()}");
    // Don't retry - invalid credentials
}
```

### TimeoutException

Timeout errors (retryable):

```php
use tommyknocker\pdodb\exceptions\TimeoutException;

try {
    $db->setTimeout(5);  // 5 seconds
    $users = $db->find()->from('users')->get();
} catch (TimeoutException $e) {
    error_log("Query timeout: {$e->getTimeoutSeconds()}s");
    
    if ($e->getTimeoutSeconds() > 30) {
        // Might be a complex query
        logSlowQuery($e->getQuery());
    }
}
```

### ResourceException

Resource exhaustion errors (retryable):

```php
use tommyknocker\pdodb\exceptions\ResourceException;

try {
    $users = $db->find()->from('users')->get();
} catch (ResourceException $e) {
    error_log("Resource exhausted: {$e->getResourceType()}");
    
    if ($e->getResourceType() === 'connections') {
        // Implement connection pooling or queuing
        queueRequest();
    }
}
```

## Exception Properties

### Basic Properties

```php
try {
    $users = $db->find()->from('users')->get();
} catch (DatabaseException $e) {
    // Basic properties
    echo "Message: {$e->getMessage()}\n";
    echo "Code: {$e->getCode()}\n";
    echo "Driver: {$e->getDriver()}\n";
    echo "Query: {$e->getQuery()}\n";
    echo "Category: {$e->getCategory()}\n";
    echo "Retryable: " . ($e->isRetryable() ? 'Yes' : 'No') . "\n";
}
```

### Context Information

```php
try {
    $users = $db->find()->from('users')->get();
} catch (DatabaseException $e) {
    // Get context
    $context = $e->getContext();
    echo "Context: " . json_encode($context) . "\n";
    
    // Add custom context
    $e->addContext('user_id', 123);
    $e->addContext('action', 'fetch_users');
}
```

### Convert to Array

```php
try {
    $users = $db->find()->from('users')->get();
} catch (DatabaseException $e) {
    // Convert to array for logging
    $errorData = $e->toArray();
    
    error_log(json_encode([
        'timestamp' => date('c'),
        'exception_type' => $errorData['exception'],
        'message' => $errorData['message'],
        'code' => $errorData['code'],
        'driver' => $errorData['driver'],
        'category' => $errorData['category'],
        'retryable' => $errorData['retryable'],
        'query' => $errorData['query'],
        'context' => $errorData['context']
    ]));
}
```

## Error Handling Patterns

### Pattern 1: Specific Exception Handling

```php
use tommyknocker\pdodb\exceptions\{
    ConnectionException,
    QueryException,
    ConstraintViolationException
};

try {
    $users = $db->find()->from('users')->get();
} catch (ConnectionException $e) {
    // Handle connection issues
    handleConnectionError($e);
} catch (QueryException $e) {
    // Handle query issues
    handleQueryError($e);
} catch (ConstraintViolationException $e) {
    // Handle constraint violations
    handleConstraintError($e);
} catch (\Exception $e) {
    // Handle any other errors
    handleGenericError($e);
}
```

### Pattern 2: Retry Logic

```php
function executeWithRetry(callable $operation, int $maxRetries = 3): mixed
{
    $attempt = 0;
    $lastException = null;
    
    while ($attempt < $maxRetries) {
        try {
            return $operation();
        } catch (DatabaseException $e) {
            $lastException = $e;
            $attempt++;
            
            if (!$e->isRetryable()) {
                throw $e;  // Don't retry
            }
            
            if ($attempt < $maxRetries) {
                sleep(2 ** $attempt);  // Exponential backoff
            }
        }
    }
    
    throw $lastException;
}

// Usage
$result = executeWithRetry(function() use ($db) {
    return $db->find()->from('users')->get();
});
```

### Pattern 3: Error Monitoring

```php
function handleDatabaseError(DatabaseException $e): void
{
    $errorData = $e->toArray();
    
    // Log structured error data
    error_log(json_encode([
        'timestamp' => date('c'),
        'exception_type' => $errorData['exception'],
        'message' => $errorData['message'],
        'code' => $errorData['code'],
        'driver' => $errorData['driver'],
        'category' => $errorData['category'],
        'retryable' => $errorData['retryable'],
        'query' => $errorData['query'],
        'context' => $errorData['context']
    ]));
    
    // Send alerts for critical errors
    if ($e instanceof AuthenticationException || 
        $e instanceof ResourceException) {
        sendCriticalAlert($e);
    }
}

try {
    $users = $db->find()->from('users')->get();
} catch (DatabaseException $e) {
    handleDatabaseError($e);
    throw $e;
}
```

## Retryable vs Non-Retryable Errors

### Retryable Errors

These errors can be automatically retried:

- ConnectionExceptions
- TransactionExceptions (deadlocks)
- TimeoutExceptions
- ResourceExceptions (temporary)

### Non-Retryable Errors

These errors should NOT be retried:

- AuthenticationException (wrong credentials)
- QueryException (SQL syntax errors)
- ConstraintViolationException (data conflicts)

## Backward Compatibility

All new exceptions extend `PDOException`, so existing code continues to work:

```php
// Old way (still works)
try {
    $result = $db->rawQuery('SELECT * FROM users');
} catch (\PDOException $e) {
    // Generic error handling
}

// New way (recommended)
try {
    $result = $db->rawQuery('SELECT * FROM users');
} catch (ConnectionException $e) {
    // Handle connection issues specifically
} catch (QueryException $e) {
    // Handle query issues specifically
} catch (\PDOException $e) {
    // Handle any other PDO errors
}
```

## Next Steps

- [Error Codes](error-codes.md) - Standardized error codes
- [Retry Logic](retry-logic.md) - Building retry mechanisms
- [Logging](logging.md) - Query and error logging
