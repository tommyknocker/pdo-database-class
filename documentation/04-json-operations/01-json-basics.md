# JSON Basics

Learn how to work with JSON data across MySQL, MariaDB, PostgreSQL, and SQLite using PDOdb's unified JSON API.

## Overview

PDOdb provides a unified JSON API that works consistently across all supported databases. Whether you're using MySQL's JSON type, PostgreSQL's JSONB, or SQLite's TEXT with JSON functions, the API remains the same.

## Creating JSON Data

### JSON Objects

Create JSON objects for storing structured data:

```php
use tommyknocker\pdodb\helpers\Db;

$db->find()->table('users')->insert([
    'name' => 'Alice',
    'meta' => Db::jsonObject([
        'city' => 'NYC',
        'age' => 30,
        'verified' => true,
        'preferences' => [
            'theme' => 'dark',
            'language' => 'en'
        ]
    ])
]);
```

### JSON Arrays

Create JSON arrays for lists of values:

```php
$db->find()->table('users')->insert([
    'name' => 'Alice',
    'tags' => Db::jsonArray('php', 'mysql', 'docker', 'testing')
]);
```

### Nested JSON

Combine objects and arrays:

```php
$db->find()->table('users')->insert([
    'name' => 'Alice',
    'profile' => Db::jsonObject([
        'hobbies' => Db::jsonArray('reading', 'coding', 'music'),
        'social' => Db::jsonObject([
            'twitter' => '@alice',
            'github' => 'alice'
        ])
    ])
]);
```

## Storage Differences

Each database stores JSON differently:

| Database | JSON Type | Indexing | Performance |
|----------|-----------|----------|-------------|
| MySQL 5.7+ | JSON | Virtual columns | Good |
| PostgreSQL | JSONB | GIN indexes | Excellent |
| SQLite 3.38+ | TEXT | Full-text search | Good |

## Table Creation

### MySQL

```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,           -- SERIAL in PostgreSQL, INTEGER AUTOINCREMENT in SQLite
    name VARCHAR(255),                           -- TEXT in SQLite
    meta JSON,                                    -- JSONB in PostgreSQL, TEXT in SQLite
    tags JSON                                     -- JSONB in PostgreSQL, TEXT in SQLite
);
```

### PostgreSQL

```sql
CREATE TABLE users (
    id SERIAL PRIMARY KEY,                       -- INT AUTO_INCREMENT in MySQL, INTEGER AUTOINCREMENT in SQLite
    name VARCHAR(255),                           -- TEXT in SQLite
    meta JSONB,                                   -- JSON in MySQL, TEXT in SQLite
    tags JSONB                                    -- JSON in MySQL, TEXT in SQLite
);
```

### SQLite

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,        -- INT AUTO_INCREMENT in MySQL, SERIAL in PostgreSQL
    name TEXT,                                   -- VARCHAR(255) in MySQL/PostgreSQL
    meta TEXT,                                   -- JSON in MySQL, JSONB in PostgreSQL
    tags TEXT                                    -- JSON in MySQL, JSONB in PostgreSQL
);
```

## Inserting JSON Data

### Simple Object

```php
use tommyknocker\pdodb\helpers\Db;

$db->find()->table('users')->insert([
    'name' => 'Alice',
    'meta' => Db::jsonObject(['city' => 'NYC', 'active' => true])
]);
```

### Complex Structure

```php
$db->find()->table('products')->insert([
    'name' => 'Laptop',
    'specs' => Db::jsonObject([
        'cpu' => 'Intel i7',
        'ram' => '16GB',
        'storage' => '512GB SSD',
        'features' => Db::jsonArray('backlit keyboard', 'touch screen', 'fingerprint scanner')
    ])
]);
```

### Updating JSON

```php
$db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'meta' => Db::jsonObject(['city' => 'London', 'active' => true])
    ]);
```

## Reading JSON Data

### Basic SELECT

When you select a JSON column, you get the raw JSON string:

```php
$user = $db->find()
    ->from('users')
    ->where('id', 1)
    ->getOne();

echo $user['meta'];  // {"city":"NYC","active":true}
```

### Parse in PHP

Parse the JSON in PHP:

```php
$users = $db->find()->from('users')->get();

foreach ($users as $user) {
    $meta = json_decode($user['meta'], true);
    echo "City: {$meta['city']}\n";
}
```

### Extract JSON Paths

See [JSON Querying](json-querying.md) for more details on extracting specific paths.

## Validating JSON

### Check if Valid JSON (SQLite)

```php
$result = $db->rawQuery("SELECT json_valid('{}')");
// Returns: 1 (valid) or 0 (invalid)
```

### Validate Before Insert

```php
$data = '{"city": "NYC", "active": true}';

if (json_decode($data) !== null) {
    // Valid JSON
    $db->find()->table('users')->insert([
        'name' => 'Alice',
        'meta' => Db::raw($data)
    ]);
}
```

## Common Patterns

### User Profiles

```php
$db->find()->table('users')->insert([
    'username' => 'alice',
    'profile' => Db::jsonObject([
        'first_name' => 'Alice',
        'last_name' => 'Smith',
        'email' => 'alice@example.com',
        'bio' => 'Software Developer',
        'location' => 'New York',
        'skills' => Db::jsonArray('PHP', 'JavaScript', 'MySQL'),
        'social' => Db::jsonObject([
            'twitter' => '@alice',
            'linkedin' => 'alice-smith'
        ])
    ])
]);
```

### Product Catalogs

```php
$db->find()->table('products')->insert([
    'name' => 'MacBook Pro',
    'description' => 'High-performance laptop',
    'attributes' => Db::jsonObject([
        'brand' => 'Apple',
        'model' => 'MacBook Pro 16"',
        'specs' => Db::jsonObject([
            'cpu' => 'M2 Max',
            'ram' => '32GB',
            'storage' => '1TB SSD'
        ]),
        'color' => Db::jsonArray('Space Gray', 'Silver'),
        'price' => 2499
    ])
]);
```

### Settings/Configuration

```php
$db->find()->table('settings')->insert([
    'app_name' => 'MyApp',
    'config' => Db::jsonObject([
        'features' => Db::jsonObject([
            'notifications' => true,
            'analytics' => true,
            'dark_mode' => false
        ]),
        'limits' => Db::jsonObject([
            'max_upload' => 10485760,  // 10MB
            'max_files' => 100,
            'session_timeout' => 3600
        ])
    ])
]);
```

## JSON and Arrays in PHP

PDOdb's `jsonArray()` and `jsonObject()` handle PHP arrays automatically:

```php
// This works too
$phpArray = ['a', 'b', 'c'];
$db->find()->table('users')->insert([
    'tags' => Db::jsonArray(...$phpArray)
]);

// Works with associative arrays
$meta = ['city' => 'NYC', 'active' => true];
$db->find()->table('users')->insert([
    'meta' => Db::jsonObject($meta)
]);
```

## Database-Specific Features

### MySQL: Virtual Columns

```sql
-- Create virtual column + index for JSON path
ALTER TABLE users 
ADD COLUMN meta_city VARCHAR(255) AS (JSON_EXTRACT(meta, '$.city'));

CREATE INDEX idx_meta_city ON users(meta_city);
```

### PostgreSQL: GIN Indexes

```sql
-- Create GIN index for JSONB
CREATE INDEX idx_meta ON users USING GIN (meta);

-- Index specific JSON path
CREATE INDEX idx_meta_city ON users((meta->>'city'));
```

### SQLite: JSON1 Extension

```sql
-- Check if JSON1 is available
SELECT json_valid('{}');
```

## Performance Tips

### 1. Denormalize Frequently Accessed Fields

Instead of always extracting JSON paths, store common fields as regular columns:

```php
// Good approach
$db->find()->table('users')->insert([
    'name' => 'Alice',
    'age' => 30,           // Regular column
    'city' => 'NYC',       // Regular column
    'meta' => Db::jsonObject([
        'preferences' => [...],  // Rarely accessed data in JSON
        'settings' => [...]
    ])
]);
```

### 2. Use Indexes

For frequently queried JSON paths, create indexes:

**MySQL:**
```sql
ALTER TABLE users ADD COLUMN meta_city VARCHAR(255) 
AS (JSON_EXTRACT(meta, '$.city'));
CREATE INDEX idx_meta_city ON users(meta_city);
```

**PostgreSQL:**
```sql
CREATE INDEX idx_meta_city ON users((meta->>'city'));
```

### 3. Limit JSON Data Size

Keep JSON objects reasonably sized for better performance:

```php
// ✅ Good: Reasonable size
$meta = Db::jsonObject(['city' => 'NYC', 'state' => 'NY']);

// ❌ Bad: Too much data
$meta = Db::jsonObject([/* thousands of items */]);
```

## Examples

- [JSON Operations](../../examples/04-json/01-json-basics.php) - Basic JSON operations (create, insert, read)
- [JSON Querying](../../examples/04-json/02-json-querying.php) - Query JSON data with path expressions
- [JSON Filtering](../../examples/04-json/03-json-filtering.php) - Filter by JSON values
- [JSON Modification](../../examples/04-json/04-json-modification.php) - Update JSON values

## Next Steps

- [JSON Querying](json-querying.md) - Query JSON data with path expressions
- [JSON Filtering](json-filtering.md) - Filter by JSON values
- [JSON Modification](json-modification.md) - Update JSON values
- [JSON Aggregations](json-aggregations.md) - JSON functions
