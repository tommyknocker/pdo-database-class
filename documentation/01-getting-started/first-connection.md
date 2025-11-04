# First Connection

Learn how to make your first database connection with PDOdb.

## SQLite Example (No Setup Required)

SQLite requires no setup - it's perfect for testing and development:

```php
<?php
require 'vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;

// In-memory database (no file needed)
$db = new PdoDb('sqlite', [
    'path' => ':memory:'
]);

// Create a table
$db->rawQuery('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,  -- INT AUTO_INCREMENT in MySQL, SERIAL in PostgreSQL
    name TEXT NOT NULL,                      -- VARCHAR(255) in MySQL/PostgreSQL
    email TEXT NOT NULL                      -- VARCHAR(255) in MySQL/PostgreSQL
)');

// Insert data
$db->find()->table('users')->insert([
    'name' => 'Alice',
    'email' => 'alice@example.com'
]);

// Query data
$users = $db->find()->from('users')->get();

foreach ($users as $user) {
    echo "User: {$user['name']} ({$user['email']})\n";
}
```

## MySQL Example

### 1. Create a database

```sql
CREATE DATABASE testdb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Configure and connect

```php
<?php
require 'vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'root',
    'password' => 'your_password',
    'dbname' => 'testdb',
    'charset' => 'utf8mb4'
]);

// Test the connection
if ($db->ping()) {
    echo "Connected to MySQL successfully!\n";
} else {
    echo "Failed to connect to MySQL\n";
    exit(1);
}

// Create a table
$db->rawQuery('CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,           -- SERIAL in PostgreSQL, INTEGER AUTOINCREMENT in SQLite
    name VARCHAR(255) NOT NULL,                   -- TEXT in SQLite
    email VARCHAR(255) NOT NULL,                  -- TEXT in SQLite
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP -- DATETIME in SQLite
)');

// Insert a user
$id = $db->find()->table('users')->insert([
    'name' => 'Alice',
    'email' => 'alice@example.com'
]);

echo "Inserted user with ID: $id\n";
```

## MariaDB Example

MariaDB uses the same driver as MySQL (`'mysql'`), but PDOdb automatically detects MariaDB and uses optimized SQL generation.

### 1. Create a database

```sql
CREATE DATABASE testdb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Configure and connect

```php
<?php
require 'vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'root',
    'password' => 'your_password',
    'dbname' => 'testdb',
    'charset' => 'utf8mb4'
]);

// Test the connection
if ($db->ping()) {
    echo "Connected to MariaDB successfully!\n";
} else {
    echo "Failed to connect to MariaDB\n";
    exit(1);
}

// Create a table
$db->rawQuery('CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,           -- SERIAL in PostgreSQL, INTEGER AUTOINCREMENT in SQLite
    name VARCHAR(255) NOT NULL,                   -- TEXT in SQLite
    email VARCHAR(255) NOT NULL,                  -- TEXT in SQLite
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP -- DATETIME in SQLite
)');

// Insert a user
$id = $db->find()->table('users')->insert([
    'name' => 'Alice',
    'email' => 'alice@example.com'
]);

echo "Inserted user with ID: $id\n";
```

**Note**: MariaDB uses the same driver name (`'mysql'`) as MySQL. PDOdb automatically detects MariaDB and uses the MariaDB dialect for optimized SQL generation.

## PostgreSQL Example

### 1. Create a database

```bash
createdb testdb
```

### 2. Configure and connect

```php
<?php
require 'vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('pgsql', [
    'host' => 'localhost',
    'username' => 'postgres',
    'password' => 'your_password',
    'dbname' => 'testdb',
    'port' => 5432
]);

// Test the connection
if ($db->ping()) {
    echo "Connected to PostgreSQL successfully!\n";
} else {
    echo "Failed to connect to PostgreSQL\n";
    exit(1);
}

// Create a table
$db->rawQuery('CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,                       -- INT AUTO_INCREMENT in MySQL, INTEGER AUTOINCREMENT in SQLite
    name VARCHAR(255) NOT NULL,                  -- TEXT in SQLite
    email VARCHAR(255) NOT NULL,                  -- TEXT in SQLite
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP -- DATETIME in SQLite
)');

// Insert a user
$id = $db->find()->table('users')->insert([
    'name' => 'Alice',
    'email' => 'alice@example.com'
]);

echo "Inserted user with ID: $id\n";
```

## Checking Connection Status

### Ping

```php
if ($db->ping()) {
    echo "Database is reachable\n";
}
```

### Last Error

```php
// Check for errors
if ($db->lastError) {
    echo "Error: {$db->lastError}\n";
    echo "Error Code: {$db->lastErrNo}\n";
}
```

### Connection Properties

```php
// Get PDO connection (only for advanced use)
$pdo = $db->connection->getPdo();

// Check connection attributes
$timeout = $db->getTimeout();
echo "Query timeout: $timeout seconds\n";
```

## Disconnecting

Clean up connections when done:

```php
// Disconnect from all databases
$db->disconnect();

// Disconnect from a specific connection
$db->disconnect('mysql_main');
```

## Handling Connection Errors

### Try-Catch

```php
use tommyknocker\pdodb\exceptions\ConnectionException;

try {
    $db = new PdoDb('mysql', [
        'host' => 'invalid-host',
        'username' => 'user',
        'password' => 'pass',
        'dbname' => 'testdb'
    ]);
} catch (ConnectionException $e) {
    echo "Connection failed: {$e->getMessage()}\n";
    // Handle reconnection logic
}
```

### Check Last Error

```php
$db = new PdoDb('mysql', $config);

// Perform operations
$result = $db->find()->from('users')->get();

if ($db->lastError) {
    echo "Error occurred: {$db->lastError}\n";
    echo "Error number: {$db->lastErrNo}\n";
}
```

## Using Existing PDO Connections

If you already have a PDO connection:

```php
use tommyknocker\pdodb\PdoDb;

// Create PDO connection
$pdo = new PDO(
    'mysql:host=localhost;dbname=testdb',
    'username',
    'password'
);

// Use existing connection
$db = new PdoDb('mysql', [
    'pdo' => $pdo,
    'prefix' => 'app_'
]);

// You can also set a logger
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('database');
$logger->pushHandler(new StreamHandler('php://stdout'));

$db = new PdoDb('mysql', [
    'pdo' => $pdo
], [], $logger);
```

## Connection Retry

Configure automatic retry for connection failures:

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'testdb',
    
    // Retry configuration
    'retry' => [
        'enabled' => true,
        'max_attempts' => 3,
        'delay_ms' => 1000,
        'backoff_multiplier' => 2,
        'max_delay_ms' => 10000
    ]
]);
```

For more details, see [Connection Retry](../05-advanced-features/connection-retry.md).

## Next Steps

- [Hello World](hello-world.md) - Build your first query
- [Query Builder Basics](../02-core-concepts/query-builder-basics.md) - Learn about the fluent API
