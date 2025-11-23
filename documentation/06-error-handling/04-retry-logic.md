# Retry Logic

Build robust retry mechanisms using PDOdb exception handling.

## Basic Retry Pattern

### Simple Retry Function

```php
function executeWithRetry(callable $operation, int $maxRetries = 3): mixed
{
    $attempt = 0;
    $lastException = null;
    
    while ($attempt < $maxRetries) {
        try {
            return $operation();
        } catch (ConnectionException $e) {
            $lastException = $e;
            $attempt++;
            
            if ($attempt < $maxRetries) {
                sleep(2 ** $attempt);  // Exponential backoff
            }
        } catch (\Exception $e) {
            // Don't retry on non-retryable errors
            throw $e;
        }
    }
    
    throw $lastException;
}
```

### Usage

```php
$result = executeWithRetry(function() use ($db) {
    return $db->find()->from('users')->get();
});
```

## Smart Retry with Error Detection

```php
use tommyknocker\pdodb\exceptions\DatabaseException;

function smartRetry(callable $operation, int $maxRetries = 3): mixed
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
                // Don't retry
                throw $e;
            }
            
            if ($attempt < $maxRetries) {
                $delay = 2 ** $attempt;
                sleep($delay);
            }
        }
    }
    
    throw $lastException;
}
```

## Retry with Logging

```php
function retryWithLogging(callable $operation, Logger $logger): mixed
{
    $maxRetries = 3;
    $attempt = 0;
    $lastException = null;
    
    while ($attempt < $maxRetries) {
        try {
            $logger->info("Attempt " . ($attempt + 1));
            return $operation();
        } catch (DatabaseException $e) {
            $lastException = $e;
            $attempt++;
            
            if ($e->isRetryable() && $attempt < $maxRetries) {
                $logger->warning("Retryable error, retrying...", [
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                sleep(2 ** $attempt);
            } else {
                throw $e;
            }
        }
    }
    
    throw $lastException;
}
```

## Next Steps

- [Exception Hierarchy](01-exception-hierarchy.md) - Exception types
- [Connection Retry](../05-advanced-features/07-connection-retry.md) - Automatic retry
- [Error Codes](02-error-codes.md) - Error constants
