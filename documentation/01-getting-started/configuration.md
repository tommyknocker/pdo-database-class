# Configuration

Database connection configuration for MySQL, MariaDB, PostgreSQL, and SQLite.

## MySQL Configuration

### Basic Configuration

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('mysql', [
    'host' => '127.0.0.1',
    'username' => 'testuser',
    'password' => 'testpass',
    'dbname' => 'testdb'
]);
```

### Full Configuration Options

```php
$db = new PdoDb('mysql', [
    // Connection options
    'pdo'         => null,                 // Optional: Existing PDO object
    'host'        => '127.0.0.1',          // Required: MySQL host
    'username'    => 'testuser',           // Required: MySQL username
    'password'    => 'testpass',           // Required: MySQL password
    'dbname'      => 'testdb',             // Required: Database name
    'port'        => 3306,                 // Optional: MySQL port (default: 3306)
    'prefix'      => 'my_',                // Optional: Table prefix (e.g. 'wp_')
    'charset'     => 'utf8mb4',            // Optional: Connection charset
    
    // Advanced options
    'unix_socket' => '/var/run/mysqld/mysqld.sock', // Optional: Unix socket path
    'sslca'       => '/path/ca.pem',       // Optional: SSL CA certificate
    'sslcert'     => '/path/client-cert.pem', // Optional: SSL client certificate
    'sslkey'      => '/path/client-key.pem',  // Optional: SSL client key
    'compress'    => true                  // Optional: Enable protocol compression
]);
```

### Using Existing PDO Connection

```php
$pdo = new PDO(
    'mysql:host=localhost;dbname=testdb',
    'user',
    'pass'
);

$db = new PdoDb('mysql', [
    'pdo' => $pdo,
    'prefix' => 'wp_'
]);
```

## PostgreSQL Configuration

### Basic Configuration

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('pgsql', [
    'host' => '127.0.0.1',
    'username' => 'testuser',
    'password' => 'testpass',
    'dbname' => 'testdb'
]);
```

### Full Configuration Options

```php
$db = new PdoDb('pgsql', [
    // Connection options
    'pdo'              => null,            // Optional: Existing PDO object
    'host'             => '127.0.0.1',     // Required: PostgreSQL host
    'username'         => 'testuser',      // Required: PostgreSQL username
    'password'         => 'testpass',       // Required: PostgreSQL password
    'dbname'           => 'testdb',        // Required: Database name
    'port'             => 5432,            // Optional: PostgreSQL port (default: 5432)
    'prefix'           => 'pg_',           // Optional: Table prefix
    
    // Advanced options
    'options'          => '--client_encoding=UTF8', // Optional: Extra options
    'sslmode'          => 'require',       // Optional: SSL mode (disable, allow, prefer, require, verify-ca, verify-full)
    'sslkey'           => '/path/client.key',   // Optional: SSL private key
    'sslcert'          => '/path/client.crt',   // Optional: SSL client certificate
    'sslrootcert'      => '/path/ca.crt',       // Optional: SSL root certificate
    'application_name' => 'MyApp',         // Optional: Application name (visible in pg_stat_activity)
    'connect_timeout'  => 5,               // Optional: Connection timeout in seconds
    'hostaddr'         => '192.168.1.10',  // Optional: Direct IP address (bypasses DNS)
    'service'          => 'myservice',     // Optional: Service name from pg_service.conf
    'target_session_attrs' => 'read-write' // Optional: For clusters (any, read-write)
]);
```

## SQLite Configuration

### Basic Configuration

```php
use tommyknocker\pdodb\PdoDb;

// In-memory database
$db = new PdoDb('sqlite', [
    'path' => ':memory:'
]);

// File-based database
$db = new PdoDb('sqlite', [
    'path' => '/path/to/database.sqlite'
]);
```

### Full Configuration Options

```php
$db = new PdoDb('sqlite', [
    // Connection options
    'pdo'   => null,                       // Optional: Existing PDO object
    'path'  => '/path/to/database.sqlite', // Required: Path to SQLite file
                                           // Use ':memory:' for in-memory database
    'prefix'=> 'sq_',                      // Optional: Table prefix
    'mode'  => 'rwc',                      // Optional: Open mode (ro, rw, rwc, memory)
    'cache' => 'shared'                    // Optional: Cache mode (shared, private)
]);
```

### SQLite Open Modes

- `ro` - Read-only access
- `rw` - Read/write access
- `rwc` - Read/write/create if not exists (default)
- `memory` - In-memory database

## PDO Options

You can pass additional PDO options when creating a connection:

```php
use tommyknocker\pdodb\PdoDb;

$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'testdb'
], $pdoOptions);
```

## Recommended PDO Options

```php
$pdoOptions = [
    // Error handling
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    
    // Fetch mode
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    
    // Disable emulated prepares for better performance and security
    PDO::ATTR_EMULATE_PREPARES => false,
    
    // Use persistent connections (optional, be careful with this)
    // PDO::ATTR_PERSISTENT => true,
    
    // Connection timeout (MySQL only)
    // PDO::ATTR_TIMEOUT => 5
];

$db = new PdoDb('mysql', $config, $pdoOptions);
```

## Prepared Statement Pool

Enable automatic prepared statement caching for better performance:

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'mydb',
    'stmt_pool' => [
        'enabled' => true,      // Enable statement pooling
        'capacity' => 256       // Maximum cached statements (default: 256)
    ]
]);
```

**See**: [Connection Management - Prepared Statement Pool](02-core-concepts/connection-management.md#prepared-statement-pool) for detailed documentation.

## Connection Pooling

You can manage multiple database connections:

```php
use tommyknocker\pdodb\PdoDb;

// Initialize without a default connection
$db = new PdoDb();

// Add MySQL connection
$db->addConnection('mysql_main', [
    'driver' => 'mysql',
    'host' => 'mysql.server.com',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'main_db'
]);

// Add PostgreSQL connection
$db->addConnection('pgsql_analytics', [
    'driver' => 'pgsql',
    'host' => 'postgres.server.com',
    'username' => 'analyst',
    'password' => 'pass',
    'dbname' => 'analytics'
]);

// Switch between connections
$users = $db->connection('mysql_main')->find()->from('users')->get();
$stats = $db->connection('pgsql_analytics')->find()->from('stats')->get();
```

## Environment-Based Configuration

Best practice: Use environment variables for configuration:

```php
// config.php
return [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'username' => getenv('DB_USERNAME') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'dbname' => getenv('DB_NAME') ?: 'testdb',
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4'
];

// Usage
$db = new PdoDb('mysql', require 'config.php');
```

## SSL/TLS Configuration

### MySQL SSL

```php
$db = new PdoDb('mysql', [
    'host' => 'mysql.example.com',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'mydb',
    'sslcert' => '/path/to/client-cert.pem',
    'sslkey' => '/path/to/client-key.pem',
    'sslca' => '/path/to/ca.pem'
]);
```

### PostgreSQL SSL

```php
$db = new PdoDb('pgsql', [
    'host' => 'postgres.example.com',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'mydb',
    'sslmode' => 'require',
    'sslcert' => '/path/to/client.crt',
    'sslkey' => '/path/to/client.key',
    'sslrootcert' => '/path/to/ca.crt'
]);
```

## Next Steps

- [First Connection](first-connection.md) - Make your first connection
- [Hello World](hello-world.md) - Build your first query
