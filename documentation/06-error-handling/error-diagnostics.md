# Error Diagnostics

Enhanced error diagnostics help you understand what went wrong with your queries by providing detailed context information.

## Overview

PDOdb provides comprehensive error diagnostics that include:

- **Query Context** - Complete information about the query builder state at the time of error
- **Sanitized Parameters** - Parameter values in error messages (sensitive data masked)
- **Debug Information** - Detailed query structure for troubleshooting

## QueryBuilder Debug Information

The `QueryBuilder::getDebugInfo()` method returns comprehensive information about the current query state:

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('sqlite', ['path' => ':memory:']);

$query = $db->find()
    ->from('users')
    ->select(['id', 'name', 'email'])
    ->where('age', 25)
    ->where('status', 'active')
    ->orderBy('name', 'ASC')
    ->limit(10)
    ->offset(0);

// Get debug information
$debugInfo = $query->getDebugInfo();

print_r($debugInfo);
/*
Array
(
    [dialect] => sqlite
    [prefix] => 
    [table] => users
    [operation] => SELECT
    [sql] => SELECT id, name, email FROM users WHERE age = ? AND status = ? ORDER BY name ASC LIMIT 10 OFFSET 0
    [params] => Array
        (
            [age] => 25
            [status] => active
        )
    [where] => Array
        (
            [where_count] => 2
            [where] => Array(...)
        )
    [select] => Array
        (
            [0] => id
            [1] => name
            [2] => email
        )
    [select_count] => 3
    [order] => Array(...)
    [order_count] => 1
    [limit] => 10
    [offset] => 0
)
*/
```

### Debug Information Contents

The debug information includes:

- **table** - Table name
- **operation** - Query operation type (SELECT, INSERT, UPDATE, DELETE)
- **sql** - Generated SQL (if available)
- **params** - Query parameters
- **where** - WHERE conditions information
- **joins** - JOIN information
- **select** - SELECT columns
- **order** - ORDER BY clauses
- **limit** - LIMIT value
- **offset** - OFFSET value
- **group** - GROUP BY clause
- **having** - HAVING conditions
- **dialect** - Database dialect name
- **prefix** - Table prefix

## QueryException with Query Context

When a `QueryException` is thrown, it automatically includes query context information if available:

```php
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\exceptions\QueryException;

try {
    $db = new PdoDb('sqlite', ['path' => ':memory:']);
    
    $query = $db->find()
        ->from('nonexistent_table')
        ->where('id', 1)
        ->where('status', 'active')
        ->get();
} catch (QueryException $e) {
    // Get query context
    $queryContext = $e->getQueryContext();
    
    if ($queryContext !== null) {
        echo "Table: " . ($queryContext['table'] ?? 'N/A') . "\n";
        echo "Operation: " . ($queryContext['operation'] ?? 'N/A') . "\n";
        echo "WHERE conditions: " . ($queryContext['where']['where_count'] ?? '0') . "\n";
        
        if (isset($queryContext['params'])) {
            echo "Parameters: " . json_encode($queryContext['params']) . "\n";
        }
    }
    
    // Enhanced description includes context
    echo "Description: " . $e->getDescription() . "\n";
}
```

### Enhanced Error Messages

The `getDescription()` method automatically includes query context in the error message:

```php
try {
    $db->find()
        ->from('users')
        ->where('email', 'test@example.com')
        ->get();
} catch (QueryException $e) {
    // Description includes table, operation, and parameters
    echo $e->getDescription();
    // Output: SQLSTATE[HY000]: General error: no such table: users 
    // (Query: SELECT * FROM users WHERE email = ?) 
    // | Table: users | Operation: SELECT | Has WHERE conditions | Parameters: {"email":"test@example.com"}
}
```

## QueryDebugger Helper

The `QueryDebugger` helper class provides utilities for debugging queries:

### Sanitize Parameters

Mask sensitive data and truncate long values:

```php
use tommyknocker\pdodb\debug\QueryDebugger;

$params = [
    'id' => 1,
    'name' => 'Alice',
    'password' => 'secret123',
    'token' => 'abc123',
    'long_description' => str_repeat('a', 200),
];

$sanitized = QueryDebugger::sanitizeParams(
    $params,
    ['password', 'token', 'secret', 'key'],  // Sensitive keys to mask
    100  // Max length for strings
);

print_r($sanitized);
/*
Array
(
    [id] => 1
    [name] => Alice
    [password] => ***
    [token] => ***
    [long_description] => aaaaaaaaaa... (truncated to 100 chars)
)
*/
```

### Format Context

Format query context for display:

```php
use tommyknocker\pdodb\debug\QueryDebugger;

$debugInfo = [
    'table' => 'users',
    'operation' => 'UPDATE',
    'where' => ['where_count' => 2],
    'joins' => ['join_count' => 1],
    'params' => ['id' => 1, 'name' => 'Alice'],
];

$formatted = QueryDebugger::formatContext($debugInfo);
echo $formatted;
// Output: Table: users | Operation: UPDATE | Has WHERE conditions | Has JOINs: 1 | Parameters: {"id":1,"name":"Alice"}
```

### Extract Operation

Extract operation type from SQL:

```php
use tommyknocker\pdodb\debug\QueryDebugger;

echo QueryDebugger::extractOperation('SELECT * FROM users');
// Output: SELECT

echo QueryDebugger::extractOperation('INSERT INTO users');
// Output: INSERT

echo QueryDebugger::extractOperation(null);
// Output: UNKNOWN
```

## Best Practices

### 1. Log Query Context

Always log query context when errors occur:

```php
use tommyknocker\pdodb\exceptions\QueryException;

try {
    $users = $db->find()->from('users')->get();
} catch (QueryException $e) {
    $context = [
        'message' => $e->getMessage(),
        'query' => $e->getQuery(),
        'query_context' => $e->getQueryContext(),
    ];
    
    error_log(json_encode($context, JSON_PRETTY_PRINT));
    throw $e;
}
```

### 2. Use Debug Info for Troubleshooting

Get debug information before executing queries to understand what will be executed:

```php
$query = $db->find()
    ->from('users')
    ->where('age', 25);

// Check debug info before execution
$debugInfo = $query->getDebugInfo();
if (empty($debugInfo['where'])) {
    throw new \RuntimeException('WHERE conditions are required');
}

$users = $query->get();
```

### 3. Sanitize Sensitive Data

Always use `QueryDebugger::sanitizeParams()` when logging parameters:

```php
use tommyknocker\pdodb\debug\QueryDebugger;

try {
    $db->find()->table('users')->insert([
        'email' => 'user@example.com',
        'password' => 'secret123',
    ]);
} catch (QueryException $e) {
    $queryContext = $e->getQueryContext();
    
    if ($queryContext !== null && isset($queryContext['params'])) {
        $sanitized = QueryDebugger::sanitizeParams(
            $queryContext['params'],
            ['password', 'token', 'secret']
        );
        
        error_log('Query parameters: ' . json_encode($sanitized));
    }
}
```

## Integration with Error Monitoring

### Sentry Integration

```php
use tommyknocker\pdodb\exceptions\QueryException;
use Sentry\State\Scope;

try {
    $users = $db->find()->from('users')->get();
} catch (QueryException $e) {
    \Sentry\configureScope(function (Scope $scope) use ($e): void {
        $scope->setContext('query', [
            'sql' => $e->getQuery(),
            'debug_info' => $e->getQueryContext(),
        ]);
        
        $scope->setTag('database.driver', $e->getDriver());
        $scope->setTag('error.category', $e->getCategory());
    });
    
    \Sentry\captureException($e);
}
```

### Custom Error Handler

```php
use tommyknocker\pdodb\exceptions\QueryException;

set_exception_handler(function (\Throwable $e) {
    if ($e instanceof QueryException) {
        $errorData = [
            'type' => 'query_error',
            'message' => $e->getMessage(),
            'driver' => $e->getDriver(),
            'query' => $e->getQuery(),
            'context' => $e->getQueryContext(),
            'description' => $e->getDescription(),
        ];
        
        // Send to monitoring service
        sendToMonitoring($errorData);
    }
    
    // Handle other exceptions
});
```

## See Also

- [Exception Hierarchy](exception-hierarchy.md) - Understanding exception types
- [Logging](logging.md) - Query and error logging
- [Monitoring](monitoring.md) - Error monitoring and alerting
