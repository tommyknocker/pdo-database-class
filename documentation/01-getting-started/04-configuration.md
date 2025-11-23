# Configuration

Database connection configuration for MySQL, MariaDB, PostgreSQL, SQLite, Microsoft SQL Server (MSSQL), and Oracle Database.

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

## MariaDB Configuration

MariaDB uses the same driver as MySQL (`'mysql'`) and shares the same configuration options. MariaDB is fully supported as a separate dialect with optimized SQL generation.

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
    'host'        => '127.0.0.1',          // Required: MariaDB host
    'username'    => 'testuser',           // Required: MariaDB username
    'password'    => 'testpass',           // Required: MariaDB password
    'dbname'      => 'testdb',             // Required: Database name
    'port'        => 3306,                 // Optional: MariaDB port (default: 3306)
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
    'prefix' => 'app_'
]);
```

**Note**: MariaDB uses the same driver name (`'mysql'`) as MySQL, but PDOdb automatically detects MariaDB and uses the MariaDB dialect for optimized SQL generation.

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
    'pdo'         => null,                       // Optional: Existing PDO object
    'path'        => '/path/to/database.sqlite', // Required: Path to SQLite file
                                                 // Use ':memory:' for in-memory database
    'prefix'      => 'sq_',                      // Optional: Table prefix
    'mode'        => 'rwc',                      // Optional: Open mode (ro, rw, rwc, memory)
    'cache'       => 'shared',                   // Optional: Cache mode (shared, private)
    'enable_regexp' => true                     // Optional: Enable REGEXP functions (default: true)
                                                 // Automatically registers REGEXP, regexp_replace, regexp_extract
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

**See**: [Connection Management - Prepared Statement Pool](02-core-concepts/02-connection-management.md#prepared-statement-pool) for detailed documentation.

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

## Microsoft SQL Server (MSSQL) Configuration

### Basic Configuration

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('sqlsrv', [
    'host' => 'localhost',
    'username' => 'testuser',
    'password' => 'testpass',
    'dbname' => 'testdb'
]);
```

### Full Configuration Options

```php
$db = new PdoDb('sqlsrv', [
    // Connection options
    'pdo'                    => null,            // Optional: Existing PDO object
    'host'                   => 'localhost',     // Required: SQL Server host
    'username'               => 'testuser',      // Required: SQL Server username
    'password'               => 'testpass',      // Required: SQL Server password
    'dbname'                 => 'testdb',        // Required: Database name
    'port'                   => 1433,            // Optional: SQL Server port (default: 1433)
    'prefix'                 => 'mssql_',        // Optional: Table prefix
    
    // SSL/TLS options
    'trust_server_certificate' => true,          // Optional: Trust server certificate (default: true)
                                                  // Set to false for production with valid certificates
    'encrypt'                => true,            // Optional: Enable encryption (default: true)
]);
```

### Using Existing PDO Connection

```php
$pdo = new PDO(
    'sqlsrv:Server=localhost,1433;Database=testdb;TrustServerCertificate=yes;Encrypt=yes',
    'user',
    'pass'
);

$db = new PdoDb('sqlsrv', [
    'pdo' => $pdo,
    'prefix' => 'app_'
]);
```

### MSSQL SSL Configuration

For self-signed certificates (development/testing):

```php
$db = new PdoDb('sqlsrv', [
    'host' => 'localhost',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'mydb',
    'trust_server_certificate' => true,  // Trust self-signed certificates
    'encrypt' => true                     // Enable encryption
]);
```

For production with valid certificates:

```php
$db = new PdoDb('sqlsrv', [
    'host' => 'sqlserver.example.com',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'mydb',
    'trust_server_certificate' => false,  // Require valid certificate
    'encrypt' => true                     // Enable encryption
]);
```

**Note**: MSSQL uses the `sqlsrv` driver name. The Microsoft ODBC Driver for SQL Server must be installed, and the PHP `sqlsrv` extension must be enabled.

## Oracle Database Configuration

### Basic Configuration

```php
use tommyknocker\pdodb\PdoDb;

$db = new PdoDb('oci', [
    'host' => 'localhost',
    'port' => 1521,
    'username' => 'testuser',
    'password' => 'testpass',
    'service_name' => 'XEPDB1', // Oracle service name (or use 'sid' for SID)
    'charset' => 'UTF8'
]);
```

### Full Configuration Options

```php
$db = new PdoDb('oci', [
    // Connection options
    'pdo'          => null,            // Optional: Existing PDO object
    'host'         => 'localhost',     // Required: Oracle host
    'port'         => 1521,            // Optional: Oracle port (default: 1521)
    'username'     => 'testuser',      // Required: Oracle username
    'password'     => 'testpass',       // Required: Oracle password
    'service_name' => 'XEPDB1',        // Required: Oracle service name (preferred)
    // OR use 'sid' instead of 'service_name' for SID-based connections
    'sid'          => 'XE',            // Optional: Oracle SID (alternative to service_name)
    'dbname'       => 'XEPDB1',        // Optional: Fallback to dbname if service_name/sid not provided
    'prefix'       => 'app_',          // Optional: Table prefix
    'charset'      => 'UTF8',          // Optional: Connection charset (default: UTF8)
]);
```

### Using Service Name (Recommended)

Oracle 12c+ uses service names for Pluggable Databases (PDBs):

```php
$db = new PdoDb('oci', [
    'host' => 'localhost',
    'port' => 1521,
    'username' => 'testuser',
    'password' => 'testpass',
    'service_name' => 'XEPDB1' // Pluggable Database service name
]);
```

### Using SID (Legacy)

For older Oracle installations or non-CDB databases:

```php
$db = new PdoDb('oci', [
    'host' => 'localhost',
    'port' => 1521,
    'username' => 'testuser',
    'password' => 'testpass',
    'sid' => 'XE' // Oracle SID
]);
```

### Using Existing PDO Connection

```php
$pdo = new PDO(
    'oci:dbname=//localhost:1521/XEPDB1',
    'user',
    'pass'
);

$db = new PdoDb('oci', [
    'pdo' => $pdo,
    'prefix' => 'app_'
]);
```

### Environment Variables

Oracle configuration via environment variables:

```bash
export PDODB_DRIVER=oci
export PDODB_HOST=localhost
export PDODB_PORT=1521
export PDODB_USERNAME=testuser
export PDODB_PASSWORD=testpass
export PDODB_SERVICE_NAME=XEPDB1
export PDODB_CHARSET=UTF8
```

**Note**: Oracle uses the `oci` driver name. The PHP `pdo_oci` extension must be installed and enabled. Oracle Database 12c+ is recommended for full feature support (JSON operations, LATERAL JOINs, etc.).

## Next Steps

- [First Connection](05-first-connection.md) - Make your first connection
- [Hello World](06-hello-world.md) - Build your first query
