# Dialect Support

PDOdb provides unified API across MySQL, MariaDB, PostgreSQL, SQLite, and Microsoft SQL Server (MSSQL) while handling dialect-specific differences automatically.

## Supported Databases

| Database | Minimum Version | Native Features |
|----------|----------------|-----------------|
| MySQL | 5.7+ | JSON support, replication |
| MariaDB | 10.3+ | JSON support, compatibility mode |
| PostgreSQL | 9.4+ | JSONB support, advanced types |
| SQLite | 3.38+ | In-memory, file-based |
| Microsoft SQL Server | 2019+ | JSON support, MERGE statements, CROSS APPLY |

## Automatic Dialect Handling

PDOdb automatically adapts to your database:

```php
use tommyknocker\pdodb\helpers\Db;

// This works identically on all databases
$users = $db->find()
    ->from('users')
    ->where(Db::jsonContains('tags', 'php'))
    ->get();
```

### Generated SQL

The library generates appropriate SQL for each database:

**MySQL:**
```sql
SELECT * FROM users WHERE JSON_CONTAINS(tags, '"php"')
```

**PostgreSQL:**
```sql
SELECT * FROM users WHERE tags @> '"php"'
```

**SQLite:**
```sql
SELECT * FROM users WHERE EXISTS (
    SELECT 1 FROM json_each(tags) WHERE value = '"php"'
)
```

## Configuration Differences

### Connection Strings

Each database requires different connection parameters:

```php
// MySQL
$db = new PdoDb('mysql', [
    'host' => 'localhost',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'mydb',
    'port' => 3306
]);

// PostgreSQL
$db = new PdoDb('pgsql', [
    'host' => 'localhost',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'mydb',
    'port' => 5432
]);

// SQLite
$db = new PdoDb('sqlite', [
    'path' => '/path/to/database.sqlite'
]);

// Microsoft SQL Server
$db = new PdoDb('sqlsrv', [
    'host' => 'localhost',
    'username' => 'user',
    'password' => 'pass',
    'dbname' => 'mydb',
    'port' => 1433
]);
```

## Data Type Differences

### Auto-Increment IDs

```php
// MySQL
$id = $db->find()->table('users')->insert(['name' => 'Alice']);
// Returns: auto-incrementing integer

// PostgreSQL
$id = $db->find()->table('users')->insert(['name' => 'Alice']);
// Returns: SERIAL integer

// SQLite
$id = $db->find()->table('users')->insert(['name' => 'Alice']);
// Returns: INTEGER PRIMARY KEY

// Microsoft SQL Server
$id = $db->find()->table('users')->insert(['name' => 'Alice']);
// Returns: IDENTITY(1,1) integer
```

### Timestamps

```php
use tommyknocker\pdodb\helpers\Db;

// NOW() works on all databases
$db->find()->table('users')->update([
    'updated_at' => Db::now()
]);
```

**Generated SQL:**
- MySQL: `NOW()`
- MariaDB: `NOW()`
- PostgreSQL: `CURRENT_TIMESTAMP`
- SQLite: `CURRENT_TIMESTAMP`
- MSSQL: `GETDATE()`

### Boolean Values

```php
$db->find()->table('users')->insert([
    'active' => true  // Automatically converts to 1/0 or TRUE/FALSE
]);
```

## JSON Support

### Creating JSON

```php
use tommyknocker\pdodb\helpers\Db;

// Works identically across all databases
$db->find()->table('users')->insert([
    'name' => 'Alice',
    'meta' => Db::jsonObject(['city' => 'NYC', 'age' => 30]),
    'tags' => Db::jsonArray('php', 'mysql', 'docker')
]);
```

**Storage:**
- MySQL: JSON column (5.7+)
- MariaDB: JSON column (10.3+)
- PostgreSQL: JSONB column
- SQLite: TEXT column with JSON functions
- MSSQL: NVARCHAR(MAX) or JSON column (2016+)

### Querying JSON

```php
// Find users older than 25 (from JSON)
$users = $db->find()
    ->from('users')
    ->where(Db::jsonPath('meta', ['age'], '>', 25))
    ->get();
```

## SQL Dialects

### Identifier Quoting

Automatically handled:

```php
// Table names
$db->find()->from('users');  // No need to quote

// Column names
$db->find()->from('users')->select(['id', 'name']);  // No need to quote
```

**Generated SQL:**
- MySQL/MariaDB: ``SELECT `id`, `name` FROM `users` ``
- PostgreSQL: `SELECT "id", "name" FROM "users"`
- SQLite: `SELECT "id", "name" FROM "users"`
- MSSQL: `SELECT [id], [name] FROM [users]`

### String Concatenation

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->select(['full_name' => Db::concat('first_name', ' ', 'last_name')])
    ->get();
```

**Generated SQL:**
- MySQL/MariaDB: `CONCAT(first_name, ' ', last_name)`
- PostgreSQL: `first_name || ' ' || last_name`
- SQLite: `first_name || ' ' || last_name`
- MSSQL: `first_name + ' ' + last_name`

## LIMIT and OFFSET

```php
$users = $db->find()
    ->from('users')
    ->limit(10)
    ->offset(20)
    ->get();
```

**Generated SQL:**
- MySQL/MariaDB: `... LIMIT 10 OFFSET 20`
- PostgreSQL: `... LIMIT 10 OFFSET 20`
- SQLite: `... LIMIT 10 OFFSET 20`
- MSSQL: `... ORDER BY (SELECT NULL) OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY`

> **Note:** SQLite requires LIMIT when using OFFSET. MSSQL requires ORDER BY when using OFFSET/FETCH.

## UPSERT Operations

PDOdb provides unified UPSERT API:

```php
use tommyknocker\pdodb\helpers\Db;

$db->find()->table('users')->onDuplicate([
    'age' => Db::inc(),
    'updated_at' => Db::now()
])->insert([
    'email' => 'alice@example.com',
    'name' => 'Alice',
    'age' => 30
]);
```

**Generated SQL:**

**MySQL:**
```sql
INSERT INTO users (email, name, age) 
VALUES (:email, :name, :age)
ON DUPLICATE KEY UPDATE 
    age = age + 1, 
    updated_at = NOW()
```

**PostgreSQL/SQLite:**
```sql
INSERT INTO users (email, name, age) 
VALUES (:email, :name, :age)
ON CONFLICT (email) DO UPDATE SET 
    age = users.age + 1, 
    updated_at = CURRENT_TIMESTAMP
```

**MSSQL:**
```sql
MERGE users AS target
USING (VALUES (:email, :name, :age)) AS source (email, name, age)
ON target.email = source.email
WHEN MATCHED THEN UPDATE SET 
    age = target.age + 1,
    updated_at = GETDATE()
WHEN NOT MATCHED THEN INSERT (email, name, age) 
    VALUES (source.email, source.name, source.age);
```

## REPLACE Operations

```php
$db->find()->table('users')->replace([
    'id' => 1,
    'name' => 'Alice Updated'
]);
```

**Generated SQL:**

**MySQL:**
```sql
REPLACE INTO users (id, name) VALUES (:id, :name)
```

**PostgreSQL/SQLite:**
```sql
INSERT INTO users (id, name) VALUES (:id, :name)
ON CONFLICT (id) DO UPDATE SET name = :name
```

**MSSQL:**
```sql
MERGE users AS target
USING (VALUES (:id, :name)) AS source (id, name)
ON target.id = source.id
WHEN MATCHED THEN UPDATE SET name = source.name
WHEN NOT MATCHED THEN INSERT (id, name) VALUES (source.id, source.name);
```

## TRUNCATE Operations

```php
$db->find()->table('users')->truncate();
```

**Generated SQL:**

**MySQL/PostgreSQL:**
```sql
TRUNCATE TABLE users
```

**SQLite:**
```sql
DELETE FROM users;
DELETE FROM sqlite_sequence WHERE name = 'users';
```

**MSSQL:**
```sql
TRUNCATE TABLE users
```

## Table Locking

```php
$db->lock(['users', 'orders'])->setLockMethod('WRITE');
// Perform exclusive operations
$db->unlock();
```

**Generated SQL:**

**MySQL:**
```sql
LOCK TABLES users WRITE, orders WRITE
UNLOCK TABLES
```

**PostgreSQL:**
```sql
LOCK TABLE users, orders IN EXCLUSIVE MODE
```

**SQLite:**
```sql
BEGIN IMMEDIATE
COMMIT
```

**MSSQL:**
```sql
BEGIN TRANSACTION
-- Operations here
COMMIT TRANSACTION
```

## Bulk Loading

### CSV Loader

```php
$db->find()->table('users')->loadCsv('/path/to/file.csv');
```

**Implementation:**
- MySQL/MariaDB: `LOAD DATA LOCAL INFILE`
- PostgreSQL: `COPY FROM`
- SQLite: Row-by-row inserts in transaction
- MSSQL: `BULK INSERT` or row-by-row inserts

### XML Loader

```php
$db->find()->table('users')->loadXml('/path/to/file.xml');
```

## NULL Handling

```php
use tommyknocker\pdodb\helpers\Db;

// Check for NULL
$users = $db->find()
    ->from('users')
    ->where(Db::isNull('deleted_at'))
    ->get();

// Handle NULL
$result = $db->find()
    ->from('users')
    ->select(['name' => Db::ifNull('username', 'Anonymous')])
    ->get();
```

## Performance Considerations

### Indexes

**MySQL:**
```sql
CREATE INDEX idx_email ON users(email);
CREATE INDEX idx_meta_age ON users((CAST(meta->>'age' AS UNSIGNED)));
```

**PostgreSQL:**
```sql
CREATE INDEX idx_email ON users(email);
CREATE INDEX idx_meta_age ON users((meta->>'age'));
```

**SQLite:**
```sql
CREATE INDEX idx_email ON users(email);
```

### EXPLAIN

```php
// Get query execution plan
$plan = $db->find()
    ->from('users')
    ->where('age', 25, '>')
    ->explain();
```

Returns dialect-specific execution plans.

## Feature Compatibility Matrix

| Feature | MySQL | MariaDB | PostgreSQL | SQLite | MSSQL |
|---------|-------|---------|------------|--------|-------|
| **Core Features** |
| Prepared statements | ✅ | ✅ | ✅ | ✅ | ✅ |
| Transactions | ✅ | ✅ | ✅ | ✅ | ✅ |
| Savepoints | ✅ | ✅ | ✅ | ✅ | ❌ |
| **Data Types** |
| JSON support | ✅ | ✅ | ✅ (JSONB) | ✅ (if JSON1) | ✅ |
| Boolean type | TINYINT(1) | TINYINT(1) | BOOLEAN | INTEGER | BIT |
| Auto-increment | AUTO_INCREMENT | AUTO_INCREMENT | SERIAL | AUTOINCREMENT | IDENTITY(1,1) |
| **Operations** |
| UPSERT | ✅ | ✅ | ✅ | ✅ | ✅ (MERGE) |
| Bulk loading | ✅ | ✅ | ✅ | ✅ (emulated) | ✅ |
| Table locking | ✅ | ✅ | ✅ | ✅ (BEGIN IMMEDIATE) | ✅ |
| **Advanced Features** |
| Schema support | ❌ | ❌ | ✅ | ❌ | ✅ |
| Table prefixes | ✅ | ✅ | ✅ | ✅ | ✅ |
| REPEAT/REVERSE/LPAD/RPAD | ✅ | ✅ | ✅ (emulated) | ✅ (emulated) | ✅ |
| MERGE statements | ❌ (emulated) | ❌ (emulated) | ✅ | ❌ (emulated) | ✅ |
| LATERAL JOINs | ✅ | ✅ | ✅ | ❌ | ✅ (CROSS APPLY) |
| Window functions | ✅ (8.0+) | ✅ (10.2+) | ✅ | ✅ (3.25+) | ✅ |
| Recursive CTEs | ✅ | ✅ | ✅ | ✅ | ✅ |
| Full-text search | ✅ | ✅ | ✅ | ✅ (FTS5) | ✅ |
| **String Functions** |
| LENGTH() | ✅ | ✅ | ✅ | ✅ | LEN() |
| SUBSTRING() | ✅ | ✅ | ✅ | SUBSTR() | ✅ |
| CONCAT() | ✅ | ✅ | ✅ (||) | ✅ (||) | ✅ (+) |
| **Date Functions** |
| NOW() | ✅ | ✅ | CURRENT_TIMESTAMP | CURRENT_TIMESTAMP | GETDATE() |
| DATE() | ✅ | ✅ | ✅ | ✅ | CAST() |
| **LIMIT/OFFSET** |
| LIMIT/OFFSET | ✅ | ✅ | ✅ | ✅ | OFFSET/FETCH |

## Migration Between Databases

### Schema Differences

**MySQL:**
```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,           -- SERIAL in PostgreSQL, INTEGER AUTOINCREMENT in SQLite
    name VARCHAR(255),                           -- TEXT in SQLite
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP  -- DATETIME in SQLite
);
```

**PostgreSQL:**
```sql
CREATE TABLE users (
    id SERIAL PRIMARY KEY,                       -- INT AUTO_INCREMENT in MySQL, INTEGER AUTOINCREMENT in SQLite
    name VARCHAR(255),                           -- TEXT in SQLite
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP  -- DATETIME in SQLite
);
```

**SQLite:**
```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,        -- INT AUTO_INCREMENT in MySQL, SERIAL in PostgreSQL
    name TEXT,                                   -- VARCHAR(255) in MySQL/PostgreSQL
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP  -- TIMESTAMP in MySQL/PostgreSQL
);
```

### Data Types Comparison

| Type | MySQL | MariaDB | PostgreSQL | SQLite | MSSQL |
|------|-------|---------|------------|--------|-------|
| String | VARCHAR(255) | VARCHAR(255) | VARCHAR(255) | TEXT | NVARCHAR(255) |
| Integer | INT | INT | INTEGER | INTEGER | INT |
| Big Integer | BIGINT | BIGINT | BIGINT | INTEGER | BIGINT |
| Decimal | DECIMAL(10,2) | DECIMAL(10,2) | NUMERIC(10,2) | REAL | DECIMAL(10,2) |
| Date/Time | DATETIME | DATETIME | TIMESTAMP | DATETIME | DATETIME |
| Timestamp | TIMESTAMP | TIMESTAMP | TIMESTAMP | DATETIME | DATETIME |
| JSON | JSON | JSON | JSONB | TEXT | NVARCHAR(MAX) or JSON |
| Boolean | TINYINT(1) | TINYINT(1) | BOOLEAN | INTEGER | BIT |
| Text | TEXT | TEXT | TEXT | TEXT | NTEXT or NVARCHAR(MAX) |
| Binary | BLOB | BLOB | BYTEA | BLOB | VARBINARY(MAX) |

## Next Steps

- [Connection Management](connection-management.md) - Learn about connections
- [Query Builder Basics](query-builder-basics.md) - Fluent API overview
- [SELECT Operations](../03-query-builder/select-operations.md) - SELECT queries
