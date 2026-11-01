<?php

declare(strict_types=1);

/**
 * Error Diagnostics Examples
 *
 * This example demonstrates the enhanced error diagnostics features,
 * including query context information and sanitized parameter values.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\exceptions\ConstraintViolationException;
use tommyknocker\pdodb\debug\QueryDebugger;

$driver = getenv('PDODB_DRIVER') ?: 'sqlite';
echo "=== Error Diagnostics Examples (on {$driver}) ===\n\n";

// Example 1: Get debug information from QueryBuilder
echo "1. QueryBuilder Debug Information\n";
echo "----------------------------------\n";

try {
    $db = createExampleDb();

    // Create a test table using fluent API (cross-dialect)
    $schema = $db->schema();
    $schema->dropTableIfExists('debug_users');
    $schema->createTable('debug_users', [
        'id' => $schema->primaryKey(),
        'name' => $schema->string(255),
        'email' => $schema->string(255),
        'age' => $schema->integer(),
    ]);

    // Build a query
    $query = $db->find()
        ->from('debug_users')
        ->select(['id', 'name', 'email'])
        ->where('age', 25)
        ->andWhere('email', 'test@example.com')
        ->orderBy('name', 'ASC')
        ->limit(10)
        ->offset(0);

    // Get debug information
    $debugInfo = $query->getDebugInfo();

    echo "Debug Information:\n";
    echo "  Table: " . ($debugInfo['table'] ?? 'N/A') . "\n";
    echo "  Operation: " . ($debugInfo['operation'] ?? 'N/A') . "\n";
    echo "  Dialect: " . ($debugInfo['dialect'] ?? 'N/A') . "\n";
    echo "  WHERE conditions: " . (isset($debugInfo['where']['where_count']) ? $debugInfo['where']['where_count'] : '0') . "\n";
    echo "  SELECT columns: " . (isset($debugInfo['select_count']) ? $debugInfo['select_count'] : '0') . "\n";
    echo "  LIMIT: " . ($debugInfo['limit'] ?? 'N/A') . "\n";
    echo "  OFFSET: " . ($debugInfo['offset'] ?? 'N/A') . "\n";
    if (isset($debugInfo['params']) && !empty($debugInfo['params'])) {
        echo "  Parameters: " . json_encode($debugInfo['params'], JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Example 2: QueryException with query context
echo "2. QueryException with Query Context\n";
echo "-------------------------------------\n";

try {
    $db = createExampleDb();

    // Try to query a non-existent table
    $query = $db->find()
        ->from('nonexistent_table')
        ->where('id', 1)
        ->andWhere('status', 'active')
        ->get();
} catch (QueryException $e) {
    echo "Exception Message: " . $e->getMessage() . "\n";
    echo "Exception Description: " . $e->getDescription() . "\n\n";

    // Get query context
    $queryContext = $e->getQueryContext();
    if ($queryContext !== null) {
        echo "Query Context Available:\n";
        echo "  Table: " . ($queryContext['table'] ?? 'N/A') . "\n";
        echo "  Operation: " . ($queryContext['operation'] ?? 'N/A') . "\n";
        if (isset($queryContext['where'])) {
            echo "  WHERE conditions: " . ($queryContext['where']['where_count'] ?? '0') . "\n";
        }
        if (isset($queryContext['params']) && !empty($queryContext['params'])) {
            echo "  Parameters: " . json_encode($queryContext['params'], JSON_UNESCAPED_UNICODE) . "\n";
        }
    } else {
        echo "Query Context: Not available\n";
    }
}

echo "\n";

// Example 3: Sanitized parameters in error messages
echo "3. Sanitized Parameters in Error Messages\n";
echo "------------------------------------------\n";

try {
    $db = createExampleDb();

    // Create a table with constraints using fluent API (cross-dialect)
    $schema = $db->schema();
    $schema->dropTableIfExists('debug_accounts');
    $schema->createTable('debug_accounts', [
        'id' => $schema->primaryKey(),
        'username' => $schema->string(255)->unique(),
        'password' => $schema->string(255),
        'email' => $schema->string(255),
    ]);

    // Insert a user
    $db->find()->from('debug_accounts')->insert([
        'username' => 'testuser',
        'password' => 'secret123',
        'email' => 'test@example.com',
    ]);

    // Try to insert duplicate (should fail with constraint violation)
    // The error message should not expose the password
    $db->find()->from('debug_accounts')->insert([
        'username' => 'testuser',
        'password' => 'secret123',
        'email' => 'test2@example.com',
    ]);
} catch (ConstraintViolationException $e) {
    echo "Exception Description: " . $e->getDescription() . "\n";
    // Password should not be visible in plain text
    if (strpos($e->getDescription(), 'secret123') !== false) {
        echo "WARNING: Password found in error message!\n";
    } else {
        echo "OK: Password is properly sanitized\n";
    }
} catch (QueryException $e) {
    echo "Exception Description: " . $e->getDescription() . "\n";
    // Password should not be visible in plain text
    if (strpos($e->getDescription(), 'secret123') !== false) {
        echo "WARNING: Password found in error message!\n";
    } else {
        echo "OK: Password is properly sanitized\n";
    }
}

echo "\n";

// Example 4: QueryDebugger helper methods
echo "4. QueryDebugger Helper Methods\n";
echo "--------------------------------\n";

// Test sanitizeParams
$params = [
    'id' => 1,
    'name' => 'Alice',
    'password' => 'secret123',
    'token' => 'abc123',
    'email' => 'alice@example.com',
    'long_description' => str_repeat('a', 200),
];

$sanitized = QueryDebugger::sanitizeParams($params, ['password', 'token', 'secret', 'key'], 100);

echo "Original params:\n";
echo "  password: " . $params['password'] . "\n";
echo "  token: " . $params['token'] . "\n";
echo "  long_description length: " . strlen($params['long_description']) . "\n\n";

echo "Sanitized params:\n";
echo "  password: " . $sanitized['password'] . "\n";
echo "  token: " . $sanitized['token'] . "\n";
echo "  long_description: " . $sanitized['long_description'] . "\n";
echo "  long_description length: " . strlen($sanitized['long_description']) . "\n\n";

// Test formatContext
$debugInfo = [
    'table' => 'users',
    'operation' => 'UPDATE',
    'where' => ['where_count' => 2],
    'joins' => ['join_count' => 1],
    'params' => ['id' => 1, 'name' => 'Alice'],
];

$formatted = QueryDebugger::formatContext($debugInfo);
echo "Formatted Context: " . $formatted . "\n\n";

// Test extractOperation
echo "Operation extraction:\n";
echo "  'SELECT * FROM users' -> " . QueryDebugger::extractOperation('SELECT * FROM users') . "\n";
echo "  'INSERT INTO users' -> " . QueryDebugger::extractOperation('INSERT INTO users') . "\n";
echo "  'UPDATE users SET' -> " . QueryDebugger::extractOperation('UPDATE users SET') . "\n";
echo "  'DELETE FROM users' -> " . QueryDebugger::extractOperation('DELETE FROM users') . "\n";

echo "\n=== Examples completed ===\n";

