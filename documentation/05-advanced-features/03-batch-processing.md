# Batch Processing

Process large datasets efficiently using batch processing methods.

## Overview

PDOdb provides these methods for processing large datasets:

1. **`batch()`** - Process data in configurable chunks
2. **`each()`** - Process one record at a time with internal buffering
3. **`batch(..., key: 'id')` / `each(..., key: 'id')`** - The same, following a unique key instead of an OFFSET (safe while rows change)
4. **`stream()`** - Stream results with minimal memory usage

Without a `key`, `batch()` and `each()` page with `LIMIT ... OFFSET`. If the loop changes the rows that the query selects
(updates the column you filter on, or deletes rows), the next page starts too far and rows are skipped, and deep pages
get slower on a large table. Pass `key: 'id'` for that (see [Follow a Key](#follow-a-key)).

## batch() - Process in Batches

Process data in chunks of specified size:

```php
// Process in batches of 100 records
foreach ($db->find()
    ->from('users')
    ->orderBy('id')
    ->batch(100) as $batch) {
    
    echo "Processing batch of " . count($batch) . " users\n";
    
    foreach ($batch as $user) {
        // Process each user in the batch
        processUser($user);
    }
}
```

### Batch Size Configuration

```php
// Small batches for memory-constrained environments
foreach ($db->find()->from('users')->batch(50) as $batch) {
    // Process 50 records at a time
}

// Large batches for fast processing
foreach ($db->find()->from('users')->batch(1000) as $batch) {
    // Process 1000 records at a time
}
```

### Batch with Conditions

```php
foreach ($db->find()
    ->from('users')
    ->where('active', 1)
    ->orderBy('id')
    ->batch(100) as $batch) {
    
    foreach ($batch as $user) {
        sendEmail($user['email']);
    }
}
```

## each() - Process One Record at a Time

Process records individually with internal buffering:

```php
// Process one record at a time
foreach ($db->find()
    ->from('users')
    ->where('active', 1)
    ->orderBy('id')
    ->each(50) as $user) {
    
    // Process individual user
    sendEmail($user['email']);
    
    // Memory usage stays low despite internal batching
}
```

### Use When You Need Individual Processing

```php
foreach ($db->find()
    ->from('orders')
    ->where('amount', 1000, '>')
    ->orderBy('created_at')
    ->each(100) as $order) {
    
    // Generate PDF for each order
    generateInvoice($order);
}
```

If the loop changes the rows the query selects, for example by moving them out of `status = 'pending'`,
pass a `key` (see below).

## Follow a Key

With `key`, `batch()` and `each()` continue after the last key it saw (`WHERE id > :last ORDER BY id LIMIT n`) instead of using an OFFSET.
Rows can be updated or deleted while you iterate without any being skipped, and each batch costs the same on a
large table.

```php
foreach ($db->find()
    ->from('orders')
    ->where('status', 'pending')
    ->each(100, key: 'id') as $order) {

    generateInvoice($order);

    // Leaves the 'pending' set; the next batch still starts right after this order
    $db->find()
        ->table('orders')
        ->where('id', $order['id'])
        ->update(['status' => 'processed']);
}

// Another key column, in batches
foreach ($db->find()->from('events')->batch(500, key: 'event_id') as $batch) {
    processEvents($batch);
}
```

Requirements: the column is unique and grows with each row (a primary key is the usual choice), it is part
of the selected columns, and the query has no `ORDER BY` of its own (the result is ordered by the key).

## stream() - Stream with Minimal Memory

Most memory-efficient method for very large datasets:

```php
// Stream results without loading into memory
foreach ($db->find()
    ->from('users')
    ->where('age', 18, '>=')
    ->orderBy('id')
    ->stream() as $user) {
    
    // Stream processing with minimal memory usage
    exportUser($user);
}
```

### Best for Large Datasets

```php
function exportUsersToCsv($db, $filename) {
    $file = fopen($filename, 'w');
    fputcsv($file, ['ID', 'Name', 'Email', 'Age']);
    
    foreach ($db->find()
        ->from('users')
        ->orderBy('id')
        ->stream() as $user) {
        
        fputcsv($file, [
            $user['id'],
            $user['name'],
            $user['email'],
            $user['age']
        ]);
    }
    
    fclose($file);
}

// Export 1M+ users without memory issues
exportUsersToCsv($db, 'users_export.csv');
```

## Comparison Table

| Method | Memory Usage | Speed | Best For |
|--------|-------------|-------|----------|
| `get()` | High (loads all) | Fast | Small datasets, complex processing |
| `batch()` | Medium (configurable) | Medium | Bulk operations, parallel processing |
| `each()` | Medium (internal buffering) | Medium | Individual record processing |
| `batch()` / `each()` with `key` | Medium (configurable) | Medium | Loops that change the rows they read |
| `stream()` | Low (streaming) | Slow | Large datasets, simple processing |

## Common Use Cases

### 1. Data Export

```php
function exportToCsv($db, $table, $filename) {
    $file = fopen($filename, 'w');
    
    // Get headers
    $first = $db->find()->from($table)->limit(1)->getOne();
    if ($first) {
        fputcsv($file, array_keys($first));
    }
    
    // Stream data
    foreach ($db->find()->from($table)->stream() as $row) {
        fputcsv($file, $row);
    }
    
    fclose($file);
}
```

### 2. Data Migration

```php
$db->startTransaction();

try {
    foreach ($db->find()
        ->from('old_table')
        ->orderBy('id')
        ->batch(100) as $batch) {
        
        // Transform data
        $newData = array_map(function($row) {
            return [
                'new_field' => $row['old_field'],
                'updated_at' => Db::now()
            ];
        }, $batch);
        
        // Bulk insert to new table
        $db->find()->table('new_table')->insertMulti($newData);
    }
    
    $db->commit();
} catch (\Exception $e) {
    $db->rollBack();
    throw $e;
}
```

### 3. Bulk Updates

```php
foreach ($db->find()
    ->from('users')
    ->where('last_login', '2020-01-01', '<')
    ->orderBy('id')
    ->each(1000) as $user) {
    
    // Update in batches
    $db->find()
        ->table('users')
        ->where('id', $user['id'])
        ->update([
            'status' => 'inactive',
            'updated_at' => Db::now()
        ]);
}
```

### 4. Report Generation

```php
function generateReport($db, $date) {
    $report = [];
    
    foreach ($db->find()
        ->from('sales')
        ->where('date', $date)
        ->orderBy('product_id')
        ->stream() as $sale) {
        
        $productId = $sale['product_id'];
        
        if (!isset($report[$productId])) {
            $report[$productId] = [
                'product_id' => $productId,
                'total' => 0,
                'count' => 0
            ];
        }
        
        $report[$productId]['total'] += $sale['amount'];
        $report[$productId]['count']++;
    }
    
    return array_values($report);
}
```

## Performance Tips

### 1. Choose the Right Method

```php
// Small dataset - use get()
$users = $db->find()->from('users')->limit(100)->get();

// Medium dataset - use batch()
foreach ($db->find()->from('users')->batch(1000) as $batch) {
    processBatch($batch);
}

// Large dataset - use stream()
foreach ($db->find()->from('users')->stream() as $user) {
    processUser($user);
}
```

### 2. Use ORDER BY

Always use ORDER BY for consistent results:

```php
// ✅ Good
foreach ($db->find()->from('users')->orderBy('id')->batch(100) as $batch) {
    // Consistent processing
}

// ❌ Bad
foreach ($db->find()->from('users')->batch(100) as $batch) {
    // Order may vary
}
```

### 3. Limit Memory Usage

```php
// ✅ Good: Process and discard
foreach ($db->find()->from('users')->stream() as $user) {
    $result = expensiveOperation($user);
    saveResult($result);
    // $user is discarded automatically
}

// ❌ Bad: Accumulate in memory
$results = [];
foreach ($db->find()->from('users')->stream() as $user) {
    $results[] = expensiveOperation($user);
}
```

## Memory Management

### Monitor Memory Usage

```php
foreach ($db->find()
    ->from('users')
    ->orderBy('id')
    ->batch(1000) as $i => $batch) {
    
    echo "Processing batch $i\n";
    echo "Memory: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB\n";
    
    processBatch($batch);
    
    // Clear variables
    unset($batch);
}
```

### Force Garbage Collection

```php
foreach ($db->find()
    ->from('users')
    ->orderBy('id')
    ->stream() as $user) {
    
    processUser($user);
    
    // Force garbage collection every 1000 iterations
    if (($i++ % 1000) === 0) {
        gc_collect_cycles();
    }
}
```

## Error Handling

### Handle Errors Gracefully

```php
foreach ($db->find()
    ->from('users')
    ->orderBy('id')
    ->each(100) as $user) {
    
    try {
        processUser($user);
    } catch (\Exception $e) {
        error_log("Failed to process user {$user['id']}: {$e->getMessage()}");
        // Continue with next user
    }
}
```

## ActiveRecord models

`Model::find()->each()`, `chunk()`, and `cursor()` yield model instances and apply global scopes. `each()` and `chunk()` eager-load relations from `with()` once per batch. `getQueryBuilder()->each()` and `stream()` still yield raw rows.

## Examples

- [Batch Processing](../../examples/06-data-management/02-batch-processing.php) - batch(), each(), stream() methods

## Next Steps

- [Bulk Operations](04-bulk-operations.md) - Bulk inserts and updates
- [Transactions](01-transactions.md) - Transaction management
- [Performance](../08-best-practices/02-performance.md) - Performance optimization
