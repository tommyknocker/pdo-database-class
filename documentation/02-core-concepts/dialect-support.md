# Dialect Support

PDOdb provides unified API across MySQL, MariaDB, PostgreSQL, and SQLite while handling dialect-specific differences automatically.

## Supported Databases

| Database | Minimum Version | Native Features |
|----------|----------------|-----------------|
| MySQL | 5.7+ | JSON support, replication |
| MariaDB | 10.3+ | JSON support, compatibility mode |
| PostgreSQL | 9.4+ | JSONB support, advanced types |
| SQLite | 3.38+ | In-memory, file-based |

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
- PostgreSQL: `CURRENT_TIMESTAMP`
- SQLite: `CURRENT_TIMESTAMP`

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
- PostgreSQL: JSONB column
- SQLite: TEXT column with JSON functions

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
- MySQL: `SELECT `id`, `name` FROM `users``
- PostgreSQL: `SELECT "id", "name" FROM "users"`
- SQLite: `SELECT "id", "name" FROM "users"`

### String Concatenation

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->select(['full_name' => Db::concat('first_name', ' ', 'last_name')])
    ->get();
```

**Generated SQL:**
- MySQL: `CONCAT(first_name, ' ', last_name)`
- PostgreSQL: `first_name || ' ' || last_name`
- SQLite: `first_name || ' ' || last_name`

## LIMIT and OFFSET

```php
$users = $db->find()
    ->from('users')
    ->limit(10)
    ->offset(20)
    ->get();
```

**Generated SQL:**
- MySQL: `... LIMIT 10 OFFSET 20`
- PostgreSQL: `... LIMIT 10 OFFSET 20`
- SQLite: `... LIMIT 10 OFFSET 20`

> **Note:** SQLite requires LIMIT when using OFFSET.

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

## Bulk Loading

### CSV Loader

```php
$db->find()->table('users')->loadCsv('/path/to/file.csv');
```

**Implementation:**
- MySQL: `LOAD DATA LOCAL INFILE`
- PostgreSQL: `COPY FROM`
- SQLite: Row-by-row inserts in transaction

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

| Feature | MySQL | PostgreSQL | SQLite |
|---------|-------|------------|--------|
| Prepared statements | ✅ | ✅ | ✅ |
| Transactions | ✅ | ✅ | ✅ |
| JSON support | ✅ | ✅ | ✅ (if compiled with JSON1) |
| UPSERT | ✅ | ✅ | ✅ |
| Bulk loading | ✅ | ✅ | ✅ (emulated) |
| Table locking | ✅ | ✅ | ✅ (BEGIN IMMEDIATE) |
| Schema support | ❌ | ✅ | ❌ |
| Table prefixes | ✅ | ✅ | ✅ |
| REPEAT/REVERSE/LPAD/RPAD | ✅ | ✅ | ✅ (emulated) |

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

### Data Types

| MySQL | PostgreSQL | SQLite |
|-------|-----------|--------|
| VARCHAR(255) | VARCHAR(255) | TEXT |
| INT | INTEGER | INTEGER |
| DATETIME | TIMESTAMP | DATETIME |
| JSON | JSONB | TEXT |
| TINYINT | BOOLEAN | INTEGER |

## Next Steps

- [Connection Management](connection-management.md) - Learn about connections
- [Query Builder Basics](query-builder-basics.md) - Fluent API overview
- [SELECT Operations](../03-query-builder/select-operations.md) - SELECT queries
