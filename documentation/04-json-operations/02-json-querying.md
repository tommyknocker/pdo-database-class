# JSON Querying

Query and extract JSON data using PDOdb's JSON API.

## Extract JSON Values

### jsonGet() - Extract by Path

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'name',
        'city' => Db::jsonGet('meta', ['city']),
        'age' => Db::jsonGet('meta', ['age'])
    ])
    ->get();
```

### Select JSON Columns

```php
$products = $db->find()
    ->from('products')
    ->select([
        'id',
        'name',
        'specs' => 'specs'
    ])
    ->get();

foreach ($products as $product) {
    $specs = json_decode($product['specs'], true);
    // Process parsed JSON
}
```

## Filter by JSON Values

### JSON Path Comparison

```php
// Find users older than 25 (from JSON metadata)
$adults = $db->find()
    ->from('users')
    ->where(Db::jsonPath('meta', ['age'], '>', 25))
    ->get();
```

A number is compared with the JSON value as a number, so `age > 27` does not pick `5`, and a boolean as a boolean, so
`verified = true` matches the JSON `true` and not the number `1`. A JSON value of another type (a string, `null`, a missing
key) does not match such a condition and never makes the statement fail. Any other value (a string, for example) is compared
as text:

```php
->where(Db::jsonPath('meta', ['age'], '>', 27))         // numeric: matches 30 and 100, not 5 and not "abc"
->where(Db::jsonPath('meta', ['verified'], '=', true))  // boolean: matches true, not 1 and not "yes"
->where(Db::jsonPath('meta', ['city'], '=', 'NYC'))     // text
```

Numbers are compared exactly within what the database converts: a 64-bit integer and a fraction down to `1e-30` on MySQL and
MariaDB, `1e-18` on SQL Server. A number outside that range (MySQL and MariaDB: beyond `1e35` or closer to zero than `1e-30`;
SQL Server: beyond `1e20`) matches no numeric condition instead of being rounded.

A JSON string that holds a number (`"30"`) or a boolean (`"true"`) is not one on MySQL, MariaDB, PostgreSQL and SQLite, so
`= 30` and `= true` do not match it. On SQL Server and Oracle it is, because `jsonSet()` writes values as strings there.

### Multiple JSON Conditions

```php
$active = $db->find()
    ->from('users')
    ->where(Db::jsonPath('meta', ['age'], '>', 25))
    ->andWhere(Db::jsonContains('tags', 'php'))
    ->andWhere(Db::jsonExists('meta', ['verified']))
    ->get();
```

## Check JSON Contains

### Single Value

```php
// Find users with 'php' in tags array
$phpDevs = $db->find()
    ->from('users')
    ->where(Db::jsonContains('tags', 'php'))
    ->get();
```

### Multiple Values

```php
// Find users with both 'php' and 'mysql' in tags
$fullStack = $db->find()
    ->from('users')
    ->where(Db::jsonContains('tags', ['php', 'mysql']))
    ->get();
```

### Specific Path

```php
// Check if JSON object has specific key
$withCity = $db->find()
    ->from('users')
    ->where(Db::jsonContains('meta', 'city', ['location']))
    ->get();
```

## Order by JSON Values

### Sort by JSON Field

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->orderBy(Db::jsonGet('meta', ['age']), 'DESC')
    ->get();
```

### Sort by Array Length

```php
$users = $db->find()
    ->from('users')
    ->orderBy(Db::jsonLength('tags'), 'DESC')
    ->get();
```

## JSON Functions

### Get Length

```php
$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'name',
        'tag_count' => Db::jsonLength('tags')
    ])
    ->get();
```

### Get Keys

```php
$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'meta_keys' => Db::jsonKeys('meta')
    ])
    ->get();
```

### Get Type

```php
$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'tags_type' => Db::jsonType('tags')
    ])
    ->get();
```

## Complex Examples

### Search in Nested JSON

```php
$users = $db->find()
    ->from('users')
    ->where(Db::jsonPath('profile', ['address', 'city'], '=', 'NYC'))
    ->get();

// JSON structure:
// { profile: { address: { city: "NYC" } } }
```

### Filter by Array Contains All

```php
// Users who have ALL of these skills
$experts = $db->find()
    ->from('users')
    ->where(Db::jsonContains('skills', ['php', 'mysql', 'docker']))
    ->get();
```

### Order by JSON Array Length

```php
$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'name',
        'skill_count' => Db::jsonLength('skills')
    ])
    ->orderBy('skill_count', 'DESC')
    ->get();
```

## Database Differences

### Generated SQL

**MySQL:**
```sql
SELECT JSON_EXTRACT(meta, '$.city') as city FROM users
```

**PostgreSQL:**
```sql
SELECT meta->>'city' as city FROM users
```

**SQLite:**
```sql
SELECT json_extract(meta, '$.city') as city FROM users
```

PDOdb handles this automatically.

## Examples

- [JSON Querying](../../examples/04-json/02-json-queries.php) - Extract JSON values, filter, order by JSON

## Next Steps

- [JSON Basics](01-json-basics.md) - Creating JSON data
- [JSON Filtering](03-json-filtering.md) - Advanced JSON queries
- [JSON Modification](04-json-modification.md) - Update JSON values
