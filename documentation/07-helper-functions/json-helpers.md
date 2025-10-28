# JSON Helper Functions

Create and manipulate JSON data using PDOdb's JSON helpers.

## Creating JSON

### jsonObject() - Create Object

```php
use tommyknocker\pdodb\helpers\Db;

$db->find()->table('users')->insert([
    'name' => 'Alice',
    'meta' => Db::jsonObject([
        'city' => 'NYC',
        'age' => 30,
        'verified' => true
    ])
]);
```

### jsonArray() - Create Array

```php
$db->find()->table('users')->insert([
    'name' => 'Alice',
    'tags' => Db::jsonArray('php', 'mysql', 'docker')
]);
```

## JSON Queries

### jsonGet() - Extract Value

```php
$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'name',
        'city' => Db::jsonGet('meta', ['city'])
    ])
    ->get();
```

### jsonPath() - Path Comparison

```php
$users = $db->find()
    ->from('users')
    ->where(Db::jsonPath('meta', ['age'], '>', 25))
    ->get();
```

### jsonContains() - Check Contents

```php
// Check if array contains value
$phpDevs = $db->find()
    ->from('users')
    ->where(Db::jsonContains('tags', 'php'))
    ->get();
```

### jsonExists() - Check Path

```php
$users = $db->find()
    ->from('users')
    ->where(Db::jsonExists('meta', ['city']))
    ->get();
```

## JSON Functions

### jsonLength() - Array/Object Length

```php
$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'tag_count' => Db::jsonLength('tags')
    ])
    ->get();
```

### jsonKeys() - Object Keys

```php
$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'meta_keys' => Db::jsonKeys('meta')
    ])
    ->get();
```

### jsonType() - Value Type

```php
$users = $db->find()
    ->from('users')
    ->select([
        'id',
        'tags_type' => Db::jsonType('tags')
    ])
    ->get();
```

## Common Patterns

### User Profile

```php
$db->find()->table('users')->insert([
    'username' => 'alice',
    'profile' => Db::jsonObject([
        'bio' => 'Software Developer',
        'location' => 'NYC',
        'skills' => Db::jsonArray('PHP', 'MySQL', 'Docker')
    ])
]);
```

### Product Specs

```php
$db->find()->table('products')->insert([
    'name' => 'Laptop',
    'specs' => Db::jsonObject([
        'cpu' => 'Intel i7',
        'ram' => '16GB',
        'storage' => '512GB SSD',
        'ports' => Db::jsonArray('USB-A', 'USB-C', 'HDMI')
    ])
]);
```

## Next Steps

- [JSON Basics](../04-json-operations/json-basics.md) - JSON fundamentals
- [JSON Querying](../04-json-operations/json-querying.md) - Query JSON
- [JSON Filtering](../04-json-operations/json-filtering.md) - Filter by JSON

