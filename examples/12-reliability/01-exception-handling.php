<?php

declare(strict_types=1);

/**
 * Exception Handling Examples
 * 
 * This example demonstrates how to use the new exception hierarchy
 * for better error handling in your applications.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\exceptions\AuthenticationException;
use tommyknocker\pdodb\exceptions\ConnectionException;
use tommyknocker\pdodb\exceptions\ConstraintViolationException;
use tommyknocker\pdodb\exceptions\DatabaseException;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\exceptions\ResourceException;
use tommyknocker\pdodb\exceptions\TimeoutException;
use tommyknocker\pdodb\exceptions\TransactionException;

$driver = getenv('PDODB_DRIVER') ?: 'sqlite';
echo "=== Exception Handling Examples (on {$driver}) ===\n\n";

// Example 1: Basic exception handling with specific types
echo "1. Basic Exception Handling\n";
echo "----------------------------\n";

try {
    $db = createExampleDb();
    
    // Drop table if exists (for idempotency)
    $db->rawQuery("DROP TABLE IF EXISTS users");
    
    // Create a table with unique constraint (dialect-specific)
    if ($driver === 'mysql') {
        $db->rawQuery("CREATE TABLE users (id INT PRIMARY KEY AUTO_INCREMENT, email VARCHAR(255) UNIQUE, name VARCHAR(255))");
    } elseif ($driver === 'pgsql') {
        $db->rawQuery("CREATE TABLE users (id SERIAL PRIMARY KEY, email VARCHAR(255) UNIQUE, name VARCHAR(255))");
    } else {
        $db->rawQuery("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, name TEXT)");
    }
    
    // Insert first user
    $db->find()->from('users')->insert(['email' => 'test@example.com', 'name' => 'Test User']);
    
    // Try to insert duplicate email - this will cause constraint violation
    $db->find()->from('users')->insert(['email' => 'test@example.com', 'name' => 'Another User']);
} catch (ConstraintViolationException $e) {
    echo "Constraint Violation: {$e->getMessage()}\n";
    echo "Driver: {$e->getDriver()}\n";
    echo "Retryable: " . ($e->isRetryable() ? 'Yes' : 'No') . "\n";
    echo "Category: {$e->getCategory()}\n";
    echo "Constraint: " . ($e->getConstraintName() ?? 'Unknown') . "\n";
    echo "Table: " . ($e->getTableName() ?? 'Unknown') . "\n";
    echo "Column: " . ($e->getColumnName() ?? 'Unknown') . "\n";
    echo "Context: " . json_encode($e->getContext()) . "\n";
} catch (AuthenticationException $e) {
    echo "Authentication Error: {$e->getMessage()}\n";
    echo "Driver: {$e->getDriver()}\n";
    echo "Retryable: " . ($e->isRetryable() ? 'Yes' : 'No') . "\n";
} catch (DatabaseException $e) {
    echo "Database Error: {$e->getMessage()}\n";
    echo "Driver: {$e->getDriver()}\n";
    echo "Category: {$e->getCategory()}\n";
    echo "Retryable: " . ($e->isRetryable() ? 'Yes' : 'No') . "\n";
}

echo "\n";

// Example 2: Constraint violation handling
echo "2. Constraint Violation Handling\n";
echo "--------------------------------\n";

try {
    $db = createExampleDb();
    
    // Drop table if exists (for idempotency)
    $db->rawQuery('DROP TABLE IF EXISTS users');
    
    // Create table with unique constraint (dialect-specific)
    if ($driver === 'mysql' || $driver === 'mariadb') {
        $db->rawQuery('CREATE TABLE users (
            id INT PRIMARY KEY AUTO_INCREMENT,
            email VARCHAR(255) UNIQUE NOT NULL,
            name VARCHAR(255) NOT NULL
        )');
    } elseif ($driver === 'pgsql') {
        $db->rawQuery('CREATE TABLE users (
            id SERIAL PRIMARY KEY,
            email VARCHAR(255) UNIQUE NOT NULL,
            name VARCHAR(255) NOT NULL
        )');
    } else {
        $db->rawQuery('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL
        )');
    }
    
    // Insert first user
    $db->find()->table('users')->insert([
        'email' => 'test@example.com',
        'name' => 'Test User'
    ]);
    
    // Try to insert duplicate email (this will fail)
    $db->find()->table('users')->insert([
        'email' => 'test@example.com', // Duplicate!
        'name' => 'Another User'
    ]);
    
} catch (ConstraintViolationException $e) {
    echo "Constraint Violation: {$e->getMessage()}\n";
    echo "Constraint: {$e->getConstraintName()}\n";
    echo "Table: {$e->getTableName()}\n";
    echo "Column: {$e->getColumnName()}\n";
    echo "Query: {$e->getQuery()}\n";
    echo "Retryable: " . ($e->isRetryable() ? 'Yes' : 'No') . "\n";
    
    // Handle the constraint violation appropriately
    echo "Handling: Updating existing user instead of inserting\n";
    
    // Update existing user
    $affected = $db->find()
        ->table('users')
        ->where('email', 'test@example.com')
        ->update(['name' => 'Updated User']);
    
    echo "Updated {$affected} user(s)\n";
}

echo "\n";

// Example 3: Transaction error handling
echo "3. Transaction Error Handling\n";
echo "-----------------------------\n";

try {
    $db = createExampleDb();
    
    // Drop table if exists (for idempotency)
    $db->rawQuery('DROP TABLE IF EXISTS accounts');
    
    // Create table (dialect-specific)
    if ($driver === 'mysql') {
        $db->rawQuery('CREATE TABLE accounts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            balance DECIMAL(10,2) NOT NULL DEFAULT 0
        )');
    } elseif ($driver === 'pgsql') {
        $db->rawQuery('CREATE TABLE accounts (
            id SERIAL PRIMARY KEY,
            balance DECIMAL(10,2) NOT NULL DEFAULT 0
        )');
    } else {
        $db->rawQuery('CREATE TABLE accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            balance DECIMAL(10,2) NOT NULL DEFAULT 0
        )');
    }
    
    // Insert test account
    $db->find()->table('accounts')->insert(['balance' => 1000]);
    
    // Start transaction
    $db->startTransaction();
    
    try {
        // Simulate concurrent update (this would cause issues in real scenario)
        $db->find()
            ->table('accounts')
            ->where('id', 1)
            ->update(['balance' => 500]);
        
        // Commit transaction
        $db->commit();
        echo "Transaction completed successfully\n";
        
    } catch (TransactionException $e) {
        echo "Transaction Error: {$e->getMessage()}\n";
        echo "Retryable: " . ($e->isRetryable() ? 'Yes' : 'No') . "\n";
        
        // Rollback and potentially retry
        $db->rollBack();
        echo "Transaction rolled back\n";
        
        if ($e->isRetryable()) {
            echo "Retrying transaction...\n";
            // In real application, implement retry logic here
        }
    }
    
} catch (DatabaseException $e) {
    echo "Database Error: {$e->getMessage()}\n";
}

echo "\n";

// Example 4: Comprehensive error handling with logging
echo "4. Comprehensive Error Handling with Logging\n";
echo "-------------------------------------------\n";

function handleDatabaseError(DatabaseException $e): void
{
    $errorData = $e->toArray();
    
    echo "=== Error Details ===\n";
    echo "Type: {$errorData['exception']}\n";
    echo "Message: {$errorData['message']}\n";
    echo "Code: {$errorData['code']}\n";
    echo "Driver: {$errorData['driver']}\n";
    echo "Category: {$errorData['category']}\n";
    echo "Retryable: " . ($errorData['retryable'] ? 'Yes' : 'No') . "\n";
    
    if ($errorData['query']) {
        echo "Query: {$errorData['query']}\n";
    }
    
    if (!empty($errorData['context'])) {
        echo "Context: " . json_encode($errorData['context']) . "\n";
    }
    
    // Additional details for specific exception types
    if ($e instanceof ConstraintViolationException) {
        echo "Constraint: {$e->getConstraintName()}\n";
        echo "Table: {$e->getTableName()}\n";
        echo "Column: {$e->getColumnName()}\n";
    }
    
    if ($e instanceof TimeoutException) {
        echo "Timeout: {$e->getTimeoutSeconds()}s\n";
    }
    
    if ($e instanceof ResourceException) {
        echo "Resource Type: {$e->getResourceType()}\n";
    }
    
    echo "===================\n";
}

try {
    $db = createExampleDb();
    
    // This will fail with a query error
    $db->rawQuery('SELECT * FROM nonexistent_table');
    
} catch (QueryException $e) {
    echo "Query Error occurred:\n";
    handleDatabaseError($e);
} catch (DatabaseException $e) {
    echo "Database Error occurred:\n";
    handleDatabaseError($e);
}

echo "\n";

// Example 5: Retry logic with exception types
echo "5. Retry Logic with Exception Types\n";
echo "-----------------------------------\n";

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
                echo "Connection error (attempt {$attempt}/{$maxRetries}): {$e->getMessage()}\n";
                echo "Retrying in " . (2 ** $attempt) . " seconds...\n";
                sleep(2 ** $attempt);
            }
        } catch (TimeoutException $e) {
            $lastException = $e;
            $attempt++;
            
            if ($attempt < $maxRetries) {
                echo "Timeout error (attempt {$attempt}/{$maxRetries}): {$e->getMessage()}\n";
                echo "Retrying in " . (2 ** $attempt) . " seconds...\n";
                sleep(2 ** $attempt);
            }
        } catch (ResourceException $e) {
            $lastException = $e;
            $attempt++;
            
            if ($attempt < $maxRetries) {
                echo "Resource error (attempt {$attempt}/{$maxRetries}): {$e->getMessage()}\n";
                echo "Retrying in " . (2 ** $attempt) . " seconds...\n";
                sleep(2 ** $attempt);
            }
        } catch (TransactionException $e) {
            $lastException = $e;
            $attempt++;
            
            if ($attempt < $maxRetries) {
                echo "Transaction error (attempt {$attempt}/{$maxRetries}): {$e->getMessage()}\n";
                echo "Retrying in " . (2 ** $attempt) . " seconds...\n";
                sleep(2 ** $attempt);
            }
        } catch (DatabaseException $e) {
            // Non-retryable errors
            throw $e;
        }
    }
    
    throw $lastException;
}

try {
    $result = executeWithRetry(function() {
        $db = createExampleDb();
        return $db->rawQuery('SELECT 1 as test');
    });
    
    echo "Operation succeeded: " . json_encode($result[0]) . "\n";
    
} catch (DatabaseException $e) {
    echo "Operation failed after retries: {$e->getMessage()}\n";
}

echo "\n";

// Example 6: Error monitoring and alerting
echo "6. Error Monitoring and Alerting\n";
echo "-------------------------------\n";

class DatabaseErrorMonitor
{
    private array $errorCounts = [];
    private array $criticalErrors = [];
    
    public function handleError(DatabaseException $e): void
    {
        $category = $e->getCategory();
        $this->errorCounts[$category] = ($this->errorCounts[$category] ?? 0) + 1;
        
        // Log critical errors
        if ($this->isCriticalError($e)) {
            $this->criticalErrors[] = [
                'timestamp' => date('Y-m-d H:i:s'),
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'category' => $category,
                'driver' => $e->getDriver(),
                'query' => $e->getQuery(),
                'context' => $e->getContext()
            ];
            
            $this->sendAlert($e);
        }
        
        // Log error for monitoring
        $this->logError($e);
    }
    
    private function isCriticalError(DatabaseException $e): bool
    {
        return $e instanceof AuthenticationException ||
               $e instanceof ResourceException ||
               ($e instanceof ConnectionException && !$e->isRetryable());
    }
    
    private function sendAlert(DatabaseException $e): void
    {
        echo "🚨 CRITICAL ALERT: " . $e::class . "\n";
        echo "   Message: {$e->getMessage()}\n";
        echo "   Driver: {$e->getDriver()}\n";
        echo "   Category: {$e->getCategory()}\n";
        echo "   Time: " . date('Y-m-d H:i:s') . "\n";
        echo "   Action Required: Immediate investigation needed\n\n";
    }
    
    private function logError(DatabaseException $e): void
    {
        echo "📝 Error logged: " . $e::class . " - {$e->getMessage()}\n";
    }
    
    public function getErrorStats(): array
    {
        return [
            'counts' => $this->errorCounts,
            'critical_count' => count($this->criticalErrors),
            'critical_errors' => $this->criticalErrors
        ];
    }
}

$monitor = new DatabaseErrorMonitor();

// Simulate various errors
try {
    $db = createExampleDb();
    $db->rawQuery('SELECT * FROM nonexistent_table');
} catch (QueryException $e) {
    $monitor->handleError($e);
}

// Display error statistics
$stats = $monitor->getErrorStats();
echo "\nError Statistics:\n";
echo "Total errors by category: " . json_encode($stats['counts']) . "\n";
echo "Critical errors: {$stats['critical_count']}\n";

echo "\n=== Exception Handling Examples Complete ===\n";
