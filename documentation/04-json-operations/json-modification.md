# JSON Modification

Update JSON values and structure using PDOdb.

## Setting JSON Values

### jsonSet()

Update a JSON field by setting a value at a specific path:

```php
// Update city in meta JSON
$db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'meta' => $db->find()->jsonSet('meta', ['city'], 'London')
    ]);
```

### Nested Paths

```php
// Update nested value
// meta: { profile: { address: { city: "NYC" } } }
$db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'meta' => $db->find()->jsonSet('meta', ['profile', 'address', 'city'], 'Boston')
    ]);
```

## Removing JSON Paths

### jsonRemove()

Remove a path from JSON:

```php
// Remove a field from meta
$db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'meta' => $db->find()->jsonRemove('meta', ['old_field'])
    ]);
```

### Remove Array Element

```php
// Remove from JSON array
$db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'tags' => $db->find()->jsonRemove('tags', [1])  // Remove index 1
    ]);
```

## Adding to JSON Arrays

### Append to Array

```php
use tommyknocker\pdodb\helpers\Db;

// Add tag to existing tags array
$db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'tags' => Db::jsonArray(Db::raw('JSON_EXTRACT(tags, "$[*]")'), 'new_tag')
    ]);
```

## JSON Object Updates

### Merge Objects

```php
// Add new field to existing object
$db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'meta' => Db::raw('JSON_OBJECT(
            JSON_EXTRACT(meta, "$.existing_field"),
            "new_value",
            "additional_field", "additional_value"
        )')
    ]);
```

## Batch JSON Updates

### Update Multiple JSON Fields

```php
$db->find()
    ->table('users')
    ->where('active', 1)
    ->update([
        'meta->city' => 'Updated City',
        'meta->updated_at' => Db::now()
    ]);
```

## Common Patterns

### Update User Preferences

```php
$db->find()
    ->table('users')
    ->where('id', $userId)
    ->update([
        'preferences' => $db->find()->jsonSet('preferences', ['theme'], 'dark'),
        'updated_at' => Db::now()
    ]);
```

### Increment JSON Counter

```php
// Increment counter in JSON metadata
$db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'meta' => Db::raw('JSON_SET(meta, "$.login_count", 
                           COALESCE(JSON_EXTRACT(meta, "$.login_count"), 0) + 1)')
    ]);
```

## Dialect-Specific Behavior

### MySQL JSON_SET

```sql
UPDATE users SET meta = JSON_SET(meta, '$.city', 'London');
```

### PostgreSQL jsonb_set

```sql
UPDATE users SET meta = jsonb_set(meta, '{city}', '"London"');
```

### SQLite json_set

```sql
UPDATE users SET meta = json_set(meta, '$.city', 'London');
```

PDOdb handles these differences automatically.

## Next Steps

- [JSON Querying](json-querying.md) - Query JSON data
- [JSON Filtering](json-filtering.md) - Filter by JSON
- [JSON Aggregations](json-aggregations.md) - JSON functions

