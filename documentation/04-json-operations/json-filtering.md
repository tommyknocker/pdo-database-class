# JSON Filtering

Filter results based on JSON path values and structure.

## JSON Path Filtering

### Compare JSON Path Value

```php
use tommyknocker\pdodb\helpers\Db;

// Find users with age > 25 in JSON
$users = $db->find()
    ->from('users')
    ->where(Db::jsonPath('meta', ['age'], '>', 25))
    ->get();
```

### Multiple Path Conditions

```php
$users = $db->find()
    ->from('users')
    ->where(Db::jsonPath('meta', ['age'], '>', 25))
    ->andWhere(Db::jsonPath('meta', ['age'], '<', 65))
    ->andWhere(Db::jsonPath('meta', ['verified']), true, '=')
    ->get();
```

## JSON Contains

### Check if Array Contains Value

```php
// Find users with 'php' in tags array
$phpDevs = $db->find()
    ->from('users')
    ->where(Db::jsonContains('tags', 'php'))
    ->get();
```

### Check if Contains All Values

```php
// Find users with both 'php' AND 'mysql'
$fullStack = $db->find()
    ->from('users')
    ->where(Db::jsonContains('tags', ['php', 'mysql']))
    ->get();
```

### Check if Object Contains Key

```php
// Find users with 'city' in meta
$withCity = $db->find()
    ->from('users')
    ->where(Db::jsonPath('meta', ['city'], '!=', null))
    ->get();
```

## JSON Exists

### Check if Path Exists

```php
use tommyknocker\pdodb\helpers\Db;

$users = $db->find()
    ->from('users')
    ->where(Db::jsonExists('meta', ['city']))
    ->get();
```

### Nested Paths

```php
// Check nested JSON path
$users = $db->find()
    ->from('users')
    ->where(Db::jsonExists('profile', ['address', 'street']))
    ->get();
```

## JSON Type Filtering

### Filter by JSON Type

```php
$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'name',
        'meta_type' => Db::jsonType('meta')
    ])
    ->where(Db::jsonType('meta'), 'object', '=')
    ->get();
```

## Complex Filtering Examples

### Search in Nested JSON

```php
// Search in deeply nested JSON
// meta: { profile: { address: { city: "NYC" } } }
$users = $db->find()
    ->from('users')
    ->where(Db::jsonPath('meta', ['profile', 'address', 'city'], '=', 'NYC'))
    ->get();
```

### Filter by JSON Array Length

```php
// Find users with more than 5 skills
$users = $db->find()
    ->from('users')
    ->where(Db::jsonLength('skills'), 5, '>')
    ->get();
```

### Filter by JSON Array Contains

```php
// Find users who have all required skills
$users = $db->find()
    ->from('users')
    ->where(Db::jsonContains('skills', ['php', 'mysql', 'docker', 'redis']))
    ->get();
```

## Combining JSON Filters

### Multiple JSON Conditions

```php
$users = $db->find()
    ->from('users')
    ->where(Db::jsonPath('meta', ['age'], '>', 18))
    ->andWhere(Db::jsonPath('meta', ['age'], '<', 65))
    ->andWhere(Db::jsonContains('tags', 'php'))
    ->andWhere(Db::jsonExists('meta', ['city']))
    ->andWhere(Db::jsonLength('tags'), 3, '>')
    ->get();
```

## Performance Tips

### Create JSON Indexes (MySQL)

```sql
-- Virtual column + index
ALTER TABLE users 
ADD COLUMN meta_age INT AS (JSON_EXTRACT(meta, '$.age'));

CREATE INDEX idx_meta_age ON users(meta_age);
```

```php
// Now query uses index
$users = $db->find()
    ->from('users')
    ->where(Db::jsonPath('meta', ['age']), 25, '>')
    ->get();
```

### Denormalize Frequently Queried Fields

```php
// Store age in regular column for better performance
$db->find()->table('users')->insert([
    'name' => 'Alice',
    'age' => 30,  // Regular column for fast queries
    'meta' => Db::jsonObject(['detailed_info' => '...'])  // JSON for flexible data
]);
```

## Next Steps

- [JSON Querying](json-querying.md) - Extract JSON values
- [JSON Modification](json-modification.md) - Update JSON
- [JSON Basics](json-basics.md) - JSON fundamentals
