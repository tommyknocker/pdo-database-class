# Bulk Operations

Process large volumes of data efficiently with PDOdb.

## Multi-Row Inserts

### insertMulti()

Insert multiple rows in a single query:

```php
$users = [
    ['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30],
    ['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 25],
    ['name' => 'Carol', 'email' => 'carol@example.com', 'age' => 28]
];

$count = $db->find()->table('users')->insertMulti($users);
echo "Inserted {$count} users\n";
```

### Generated SQL

```sql
INSERT INTO users (name, email, age) VALUES
    (:name_0, :email_0, :age_0),
    (:name_1, :email_1, :age_1),
    (:name_2, :email_2, :age_2)
```

## Bulk Updates

### Update Multiple Rows

```php
// Update in batch
foreach ($users as $user) {
    $db->find()
        ->table('users')
        ->where('id', $user['id'])
        ->update(['last_login' => Db::now()]);
}
```

### Batch Update with Transaction

```php
$db->startTransaction();
try {
    foreach ($userIds as $userId) {
        $db->find()
            ->table('users')
            ->where('id', $userId)
            ->update(['status' => 'active']);
    }
    $db->commit();
} catch (\Exception $e) {
    $db->rollback();
    throw $e;
}
```

## CSV Import

### Basic CSV Load

```php
$db->find()->table('users')->loadCsv('/path/to/users.csv');
```

### With Options

```php
$db->find()->table('products')->loadCsv('/path/to/products.csv', [
    'fieldChar' => ',',
    'fieldEnclosure' => '"',
    'fields' => ['id', 'name', 'price'],
    'linesToIgnore' => 1,  // Skip header
    'local' => true
]);
```

## XML Import

### Basic XML Load

```php
$db->find()->table('users')->loadXml('/path/to/users.xml', [
    'rowTag' => '<user>',
    'linesToIgnore' => 0
]);
```

## Performance Comparison

### Single Inserts (Slow)

```php
// ❌ Slow: Multiple round trips
foreach ($users as $user) {
    $db->find()->table('users')->insert($user);
}
// 1000 users = 1000 queries
```

### Bulk Insert (Fast)

```php
// ✅ Fast: Single query
$db->find()->table('users')->insertMulti($users);
// 1000 users = 1 query
```

## Best Practices

### Use Transactions

```php
$db->startTransaction();
try {
    $db->find()->table('users')->insertMulti($users);
    $db->find()->table('profiles')->insertMulti($profiles);
    $db->commit();
} catch (\Exception $e) {
    $db->rollback();
    throw $e;
}
```

### Batch Large Inserts

```php
// Insert in batches of 1000
$batches = array_chunk($largeDataset, 1000);

foreach ($batches as $batch) {
    $db->startTransaction();
    try {
        $db->find()->table('users')->insertMulti($batch);
        $db->commit();
    } catch (\Exception $e) {
        $db->rollback();
        throw $e;
    }
}
```

## Next Steps

- [Batch Processing](batch-processing.md) - Process large datasets
- [Transactions](transactions.md) - Ensure data consistency
- [Performance](../08-best-practices/performance.md) - Optimization tips
