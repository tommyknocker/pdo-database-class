# Exception Handling Examples

This directory contains comprehensive examples demonstrating the new exception hierarchy for better error handling in database operations.

## Files

- `01-exception-examples.php` - Complete examples of exception handling patterns
- `02-error-diagnostics.php` - Enhanced error diagnostics with query context and debug information

## Exception Types

The library now provides a hierarchy of specialized exceptions:

### Base Exception
- **`DatabaseException`** - Base class for all database-related errors

### Specialized Exceptions
- **`ConnectionException`** - Connection-related errors (retryable)
- **`QueryException`** - Query execution errors (not retryable)
- **`ConstraintViolationException`** - Constraint violations (not retryable)
- **`TransactionException`** - Transaction-related errors (retryable)
- **`AuthenticationException`** - Authentication/authorization errors (not retryable)
- **`TimeoutException`** - Timeout errors (retryable)
- **`ResourceException`** - Resource exhaustion errors (retryable)

## Key Features

### 1. **Specific Error Handling**
```php
try {
    $users = $db->find()->from('users')->get();
} catch (ConnectionException $e) {
    // Handle connection issues
} catch (ConstraintViolationException $e) {
    // Handle constraint violations
} catch (QueryException $e) {
    // Handle query errors
} catch (DatabaseException $e) {
    // Handle any other database errors
}
```

### 2. **Retry Logic**
```php
if ($e->isRetryable()) {
    // Implement retry logic
    sleep(2);
    // Retry operation
}
```

### 3. **Rich Context Information**
```php
echo "Driver: {$e->getDriver()}\n";
echo "Query: {$e->getQuery()}\n";
echo "Category: {$e->getCategory()}\n";
echo "Context: " . json_encode($e->getContext()) . "\n";
```

### 4. **Constraint Details**
```php
if ($e instanceof ConstraintViolationException) {
    echo "Constraint: {$e->getConstraintName()}\n";
    echo "Table: {$e->getTableName()}\n";
    echo "Column: {$e->getColumnName()}\n";
}
```

### 5. **Error Monitoring**
```php
$errorData = $e->toArray();
// Use for logging, monitoring, alerting
```

## Running the Examples

```bash
# Run the exception handling examples
php 01-exception-examples.php
```

## Benefits

1. **Better Error Handling** - Catch specific types of errors
2. **Retry Logic** - Know which errors can be retried
3. **Rich Context** - Get detailed information about errors
4. **Monitoring** - Better error tracking and alerting
5. **Debugging** - Easier to identify and fix issues

## Migration from PDOException

The new exceptions extend `PDOException`, so existing code will continue to work. However, you can now catch more specific exception types for better error handling:

```php
// Old way (still works)
try {
    $result = $db->query('SELECT * FROM users');
} catch (PDOException $e) {
    // Generic error handling
}

// New way (recommended)
try {
    $result = $db->query('SELECT * FROM users');
} catch (ConnectionException $e) {
    // Handle connection issues specifically
} catch (QueryException $e) {
    // Handle query issues specifically
} catch (DatabaseException $e) {
    // Handle any other database issues
}
```
