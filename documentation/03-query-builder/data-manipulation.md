# Data Manipulation

Learn how to INSERT, UPDATE, DELETE, and REPLACE data with PDOdb.

## INSERT Operations

### Single Insert

Insert a single row and get the inserted ID:

```php
use tommyknocker\pdodb\helpers\Db;

$userId = $db->find()->table('users')->insert([
    'name' => 'Alice',
    'email' => 'alice@example.com',
    'age' => 30,
    'created_at' => Db::now()
]);

echo "Inserted user with ID: $userId\n";
```

### Multiple Inserts

Insert multiple rows at once:

```php
$users = [
    ['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30],
    ['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 25],
    ['name' => 'Carol', 'email' => 'carol@example.com', 'age' => 28]
];

$count = $db->find()->table('users')->insertMulti($users);
echo "Inserted $count users\n";
```

### Insert with Helper Functions

```php
use tommyknocker\pdodb\helpers\Db;

$db->find()->table('users')->insert([
    'name' => 'Alice',
    'age' => 25,
    'updated_at' => Db::now(),      // Current timestamp
    'counter' => Db::inc(),          // Increment counter by 1
    'meta' => Db::jsonObject(['city' => 'NYC', 'active' => true])
]);
```

## UPDATE Operations

### Basic UPDATE

```php
use tommyknocker\pdodb\helpers\Db;

$affected = $db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'name' => 'Alice Updated',
        'email' => 'alice.new@example.com',
        'updated_at' => Db::now()
    ]);

echo "Updated $affected row(s)\n";
```

### UPDATE with Helper Functions

```php
$db->find()
    ->table('users')
    ->where('id', 1)
    ->update([
        'age' => Db::inc(5),        // Increment by 5
        'counter' => Db::dec()      // Decrement by 1
    ]);

// Or using raw for complex operations
$db->find()->table('users')->update([
    'score' => Db::raw('score + :bonus', ['bonus' => 100])
]);
```

### UPDATE with Conditions

```php
// Update all inactive users
$affected = $db->find()
    ->table('users')
    ->where('last_login', '2020-01-01', '<')
    ->andWhere('status', 'inactive')
    ->update([
        'status' => 'archived',
        'updated_at' => Db::now()
    ]);
```

## DELETE Operations

### Basic DELETE

```php
$deleted = $db->find()
    ->table('users')
    ->where('id', 1)
    ->delete();

echo "Deleted $deleted row(s)\n";
```

### DELETE with Conditions

```php
// Delete old inactive users
$deleted = $db->find()
    ->table('users')
    ->where('status', 'inactive')
    ->andWhere('created_at', '2020-01-01', '<')
    ->delete();

echo "Deleted $deleted inactive users\n";
```

### Bulk DELETE

```php
// Delete users in specific IDs
$ids = [100, 101, 102, 103];
$deleted = $db->find()
    ->table('users')
    ->where(Db::in('id', $ids))
    ->delete();

echo "Deleted $deleted users\n";
```

## TRUNCATE Operations

### TRUNCATE Table

```php
$db->find()->table('users')->truncate();
// Removes all data and resets auto-increment
```

> **Note:** TRUNCATE is emulated in SQLite using DELETE + reset sequence.

## REPLACE Operations

### Single REPLACE

Replace a row if it exists, otherwise insert:

```php
$db->find()->table('users')->replace([
    'id' => 1,
    'name' => 'Alice',
    'email' => 'alice@example.com'
]);
```

### Multiple REPLACE

```php
$users = [
    ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
    ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com']
];

$db->find()->table('users')->replaceMulti($users);
```

> **Note:** In PostgreSQL and SQLite, REPLACE is emulated as UPSERT.

## UPSERT Operations (INSERT with UPDATE)

### Using onDuplicate()

```php
use tommyknocker\pdodb\helpers\Db;

// Insert or update if duplicate
$db->find()->table('users')
    ->onDuplicate([
        'name' => Db::raw('VALUES(name)'),
        'age' => Db::raw('VALUES(age)'),
        'updated_at' => Db::now()
    ])
    ->insert([
        'email' => 'alice@example.com',
        'name' => 'Alice',
        'age' => 30
    ]);
```

### Increment on Duplicate

```php
$db->find()->table('users')
    ->onDuplicate([
        'login_count' => Db::inc(),
        'last_login' => Db::now()
    ])
    ->insert([
        'email' => 'alice@example.com',
        'login_count' => 1,
        'last_login' => Db::now()
    ]);
```

## Batch Operations

### Batch INSERT

For large datasets, use batch processing:

```php
function insertBatch($db, $data, $batchSize = 1000) {
    $batches = array_chunk($data, $batchSize);
    
    foreach ($batches as $batch) {
        $db->find()->table('users')->insertMulti($batch);
    }
}

$users = /* array of 10,000 users */;
insertBatch($db, $users);
```

### Batch UPDATE

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
    $db->rollBack();
    throw $e;
}
```

## Helper Functions Reference

### Increment/Decrement

```php
use tommyknocker\pdodb\helpers\Db;

// Increment by 1
$db->find()->table('users')->update(['counter' => Db::inc()]);

// Increment by 5
$db->find()->table('users')->update(['score' => Db::inc(5)]);

// Decrement by 1
$db->find()->table('users')->update(['stock' => Db::dec()]);

// Decrement by 10
$db->find()->table('users')->update(['credits' => Db::dec(10)]);
```

### Date/Time

```php
$db->find()->table('users')->update([
    'updated_at' => Db::now(),
    'created_at' => Db::now('-1 DAY')
]);
```

### JSON Values

```php
$db->find()->table('users')->insert([
    'meta' => Db::jsonObject(['city' => 'NYC', 'age' => 30]),
    'tags' => Db::jsonArray('php', 'mysql', 'docker')
]);
```

## Safety Considerations

### Always Use WHERE

```php
// ❌ DANGEROUS: Updates all rows
$db->find()->table('users')->update(['status' => 'inactive']);

// ✅ SAFE: Updates only specific rows
$db->find()
    ->table('users')
    ->where('id', $userId)
    ->update(['status' => 'inactive']);
```

### Always Use Transactions for Multiple Operations

```php
$db->startTransaction();

try {
    // Insert order
    $orderId = $db->find()->table('orders')->insert([
        'user_id' => $userId,
        'total' => $total
    ]);
    
    // Insert order items
    foreach ($items as $item) {
        $db->find()->table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity']
        ]);
    }
    
    // Update inventory
    foreach ($items as $item) {
        $db->find()
            ->table('products')
            ->where('id', $item['product_id'])
            ->update(['stock' => Db::dec($item['quantity'])]);
    }
    
    $db->commit();
} catch (\Exception $e) {
    $db->rollBack();
    throw $e;
}
```

## Performance Tips

### 1. Use insertMulti() for Multiple Rows

```php
// ❌ Slow: Multiple single inserts
foreach ($users as $user) {
    $db->find()->table('users')->insert($user);
}

// ✅ Fast: Single multi-insert
$db->find()->table('users')->insertMulti($users);
```

### 2. Use Transactions

```php
$db->startTransaction();
try {
    // Multiple operations
    $db->find()->table('users')->insert($user1);
    $db->find()->table('users')->insert($user2);
    $db->commit();
} catch (\Exception $e) {
    $db->rollBack();
}
```

### 3. Batch Large Updates

```php
$offset = 0;
$limit = 1000;

while (true) {
    $db->startTransaction();
    
    try {
        $users = $db->find()
            ->from('users')
            ->where('old_flag', 1)
            ->limit($limit)
            ->offset($offset)
            ->get();
        
        if (empty($users)) {
            break;
        }
        
        foreach ($users as $user) {
            $db->find()
                ->table('users')
                ->where('id', $user['id'])
                ->update(['new_flag' => 1]);
        }
        
        $db->commit();
        $offset += $limit;
    } catch (\Exception $e) {
        $db->rollBack();
        throw $e;
    }
}
```

## Returning Affected Rows

### INSERT Returns ID

```php
$id = $db->find()->table('users')->insert(['name' => 'Alice']);
echo "Inserted ID: $id\n";
```

### UPDATE/DELETE Returns Count

```php
$affected = $db->find()
    ->table('users')
    ->where('status', 'inactive')
    ->update(['status' => 'active']);

echo "Updated $affected row(s)\n";
```

### Check if Row Exists Before Insert

```php
$exists = $db->find()
    ->from('users')
    ->where('email', 'alice@example.com')
    ->exists();

if (!$exists) {
    $db->find()->table('users')->insert([
        'email' => 'alice@example.com',
        'name' => 'Alice'
    ]);
}
```

## Next Steps

- [SELECT Operations](select-operations.md) - Read data from database
- [Filtering Conditions](filtering-conditions.md) - Complex WHERE clauses
- [Transactions](../05-advanced-features/transactions.md) - Transaction management
- [Bulk Operations](../05-advanced-features/bulk-operations.md) - Large dataset handling

