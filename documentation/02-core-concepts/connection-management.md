# Connection Management

Learn how to manage database connections with PDOdb.

## Single Connection

The simplest way to use PDOdb is with a single connection:

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'mydb'
]);

// All queries use this connection
$users = $db->find()->from('users')->get();
```

## Connection Pooling

Manage multiple database connections and switch between them:

### Without Default Connection

```php
use tommyknocker\pdodb\PdoDb;

// Initialize without a default connection
$db = new PdoDb();

// Add connections
$db->addConnection('mysql_main', [
    'driver' => 'mysql',
    'host' => 'mysql.server.com',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'main_db'
]);

$db->addConnection('pgsql_analytics', [
    'driver' => 'pgsql',
    'host' => 'postgres.server.com',
    'username' => 'analyst',
    'password' => 'pass',
    'dbname' => 'analytics'
]);

$db->addConnection('sqlite_cache', [
    'driver' => 'sqlite',
    'path' => '/path/to/cache.db'
]);
```

### Switching Connections

```php
// Use MySQL for main data
$users = $db->connection('mysql_main')->find()->from('users')->get();

// Use PostgreSQL for analytics
$stats = $db->connection('pgsql_analytics')->find()->from('stats')->get();

// Use SQLite for caching
$cache = $db->connection('sqlite_cache')->find()->from('cache')->get();
```

### Connection Status

```php
// Check if a connection exists
if ($db->hasConnection('mysql_main')) {
    echo "Connection exists\n";
}

// Check if database is reachable
if ($db->connection('mysql_main')->ping()) {
    echo "Database is reachable\n";
}
```

## Connection with Options

You can pass PDO options and a logger to each connection:

```php
use tommyknocker\pdodb\PdoDb;
use Psr\Log\LoggerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('database');
$logger->pushHandler(new StreamHandler('php://stdout'));

$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

$db = new PdoDb();

$db->addConnection('mysql_main', [
    'driver' => 'mysql',
    'host' => 'localhost',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'mydb'
], $pdoOptions, $logger);
```

## Using Existing PDO Connections

You can use an existing PDO connection:

```php
use tommyknocker\pdodb\PdoDb;

// Create PDO connection
$pdo = new PDO(
    'mysql:host=localhost;dbname=mydb',
    'user',
    'pass',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

// Use with PDOdb
$db = new PdoDb('mysql', [
    'pdo' => $pdo,
    'prefix' => 'app_'
]);
```

## Table Prefix

Add a prefix to all table names:

```php
$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'mydb',
    'prefix' => 'myapp_'
]);

// This will query 'myapp_users' table
$users = $db->find()->from('users')->get();
```

### Dynamic Prefix

You can also set prefix per query:

```php
$db = new PdoDb('mysql', $config);

// Use default prefix
$users = $db->find()->from('users')->get();

// Override prefix for this query
$temp = $db->find()->prefix('temp_')->from('users')->get();
```

## Disconnecting

Clean up connections when done:

```php
// Disconnect from all connections
$db->disconnect();

// Disconnect from specific connection
$db->disconnect('mysql_main');

// Disconnect from current active connection
$db->disconnect();
```

## Connection Retry

Configure automatic retry for connection failures:

```php
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
        'max_delay_ms' => 10000,
        'retryable_errors' => [2002, 2003, 2006]
    ]
]);
```

For more details, see [Connection Retry](../05-advanced-features/connection-retry.md).

## Query Timeout

Set query timeout for slow queries:

```php
// Set timeout to 60 seconds
$db->setTimeout(60);

// Get current timeout
$timeout = $db->getTimeout();
echo "Query timeout: $timeout seconds\n";

// Different timeouts for different connections
$db->addConnection('fast', $fastConfig);
$db->addConnection('slow', $slowConfig);

$db->connection('fast')->setTimeout(5);    // Quick queries
$db->connection('slow')->setTimeout(300);  // Long-running reports
```

## Connection State

Check connection state and errors:

```php
// Check if last query succeeded
if ($db->executeState === true) {
    echo "Query executed successfully\n";
}

// Get last error
if ($db->lastError) {
    echo "Error: {$db->lastError}\n";
    echo "Error code: {$db->lastErrNo}\n";
}

// Get last query
echo "Last query: {$db->lastQuery}\n";
```

## Best Practices

### 1. Connection Reuse

Reuse connections instead of creating new ones:

```php
// ❌ Bad: Creating new connections repeatedly
function getUsers() {
    $db = new PdoDb('mysql', $config);
    return $db->find()->from('users')->get();
}

// ✅ Good: Reuse connection
class UserRepository {
    protected PdoDb $db;
    
    public function __construct(PdoDb $db) {
        $this->db = $db;
    }
    
    public function getAll() {
        return $this->db->find()->from('users')->get();
    }
}
```

### 2. Connection Pooling for Different Databases

```php
// Database abstraction layer
class DatabaseManager {
    protected PdoDb $db;
    
    public function __construct() {
        $this->db = new PdoDb();
    }
    
    public function addConnection(string $name, array $config) {
        $this->db->addConnection($name, $config);
    }
    
    public function query(): PdoDb {
        return $this->db->connection('default');
    }
    
    public function analytics(): PdoDb {
        return $this->db->connection('analytics');
    }
    
    public function cache(): PdoDb {
        return $this->db->connection('cache');
    }
}

// Usage
$dbManager = new DatabaseManager();
$dbManager->addConnection('default', $mainDbConfig);
$dbManager->addConnection('analytics', $analyticsDbConfig);
$dbManager->addConnection('cache', $cacheDbConfig);

// Use specific connection
$users = $dbManager->query()->find()->from('users')->get();
$stats = $dbManager->analytics()->find()->from('stats')->get();
$cache = $dbManager->cache()->find()->from('cache')->get();
```

### 3. Environment-Based Configuration

```php
class ConnectionFactory {
    public static function create(): PdoDb {
        $config = [
            'host' => getenv('DB_HOST'),
            'username' => getenv('DB_USERNAME'),
            'password' => getenv('DB_PASSWORD'),
            'dbname' => getenv('DB_NAME'),
            'port' => getenv('DB_PORT') ?: 3306,
            'prefix' => getenv('DB_PREFIX') ?: ''
        ];
        
        return new PdoDb('mysql', $config);
    }
}

$db = ConnectionFactory::create();
```

## Next Steps

- [Query Builder Basics](query-builder-basics.md) - Learn about the fluent API
- [Parameter Binding](parameter-binding.md) - Prepared statements and security
- [Dialect Support](dialect-support.md) - Database differences
