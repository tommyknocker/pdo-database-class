# Batch Processing Examples

Generator-based methods for efficiently processing large datasets without memory exhaustion.

## Overview

PDOdb provides three generator-based methods for batch processing:

1. **`getBatch()`** - Process SELECT results in batches
2. **`insertBatch()`** - Insert large datasets in batches
3. **`updateBatch()`** - Update multiple records in batches

These methods use PHP generators to process data incrementally, keeping memory usage constant regardless of dataset size.

## Example

### 01-batch-examples.php
Comprehensive batch processing demonstrations.

**Topics covered:**
- Processing millions of records with `getBatch()`
- Batch inserts with `insertBatch()`
- Batch updates with `updateBatch()`
- Memory-efficient data transformations
- Progress tracking during batch operations
- Configurable batch sizes
- Error handling in batch operations
- Performance comparison: batch vs. non-batch

## Why Use Batch Processing?

### Memory Efficiency

**Without batching:**
```php
// ❌ Loads ALL records into memory at once
$users = $db->find()->from('users')->get();
foreach ($users as $user) {
    processUser($user);
}
// Memory usage: ~100MB for 100K records
```

**With batching:**
```php
// ✅ Loads records in chunks (e.g., 1000 at a time)
foreach ($db->find()->from('users')->getBatch(1000) as $batch) {
    foreach ($batch as $user) {
        processUser($user);
    }
}
// Memory usage: ~1MB constant, regardless of total records
```

### Performance

| Operation | Without Batching | With Batching | Memory Saved |
|-----------|------------------|---------------|--------------|
| Process 100K records | 100MB+ RAM | ~1-2MB RAM | 98% |
| Insert 50K records | Single transaction | Chunked commits | Faster |
| Update 1M records | Risk timeout | Reliable completion | N/A |

## Methods

### getBatch(batchSize = 1000)

Process SELECT query results in batches.

```php
$query = $db->find()
    ->from('orders')
    ->where('status', 'pending')
    ->orderBy('created_at', 'DESC');

foreach ($query->getBatch(500) as $batch) {
    foreach ($batch as $order) {
        processOrder($order);
    }
    echo "Processed batch of " . count($batch) . " orders\n";
}
```

**Use cases:**
- Export large datasets
- Data migration
- Report generation
- Email sending
- Data transformation

### insertBatch(data, batchSize = 1000)

Insert large datasets in batches.

```php
$records = loadFromFile('huge-dataset.csv'); // Generator

$inserted = $db->find()
    ->table('products')
    ->insertBatch($records, 500);

echo "Inserted {$inserted} products\n";
```

**Use cases:**
- Import from CSV/XML
- Data seeding
- ETL processes
- Bulk data creation

### updateBatch(data, batchSize = 1000)

Update multiple records in batches.

```php
$updates = [
    ['id' => 1, 'status' => 'shipped'],
    ['id' => 2, 'status' => 'shipped'],
    // ... thousands more
];

$updated = $db->find()
    ->table('orders')
    ->updateBatch($updates, 100);

echo "Updated {$updated} orders\n";
```

**Use cases:**
- Status updates
- Price adjustments
- Data correction
- Bulk modifications

## Configuration

All batch methods accept a `batchSize` parameter:

```php
// Small batches (more database round-trips, less memory)
$query->getBatch(100);

// Large batches (fewer round-trips, more memory per batch)
$query->getBatch(5000);

// Default (balanced)
$query->getBatch(); // 1000 records per batch
```

**Choosing batch size:**
- **100-500**: Very large records, limited memory
- **500-1000**: Standard size (default)
- **1000-5000**: Small records, plenty of memory
- **5000+**: Very small records, high-performance server

## Running Example

### SQLite (default)
```bash
php 01-batch-examples.php
```

### MySQL
```bash
PDODB_DRIVER=mysql php 01-batch-examples.php
```

### PostgreSQL
```bash
PDODB_DRIVER=pgsql php 01-batch-examples.php
```

## Performance Tips

1. **Use transactions** with batch inserts/updates for speed
2. **Adjust batch size** based on record size and available memory
3. **Add indexes** on columns used in WHERE clauses
4. **Use ORDER BY** with `getBatch()` for consistent results
5. **Monitor memory** usage with `memory_get_usage()`

## Real-World Example

Processing 1 million orders:

```php
$totalProcessed = 0;
$totalRevenue = 0;

foreach ($db->find()->from('orders')->getBatch(1000) as $batch) {
    foreach ($batch as $order) {
        // Process each order
        sendInvoice($order);
        $totalRevenue += $order['amount'];
    }
    
    $totalProcessed += count($batch);
    echo "Processed {$totalProcessed} orders...\n";
}

echo "Total revenue: \${$totalRevenue}\n";
```

**Result**: Process 1M orders using only ~2MB RAM, vs 500MB+ without batching.

## Next Steps

- [File Loading](../05-file-loading/) - Load data from JSON files
- [Bulk Operations](../03-advanced/02-bulk-operations.php) - CSV and XML loading
- [Documentation: Batch Processing](../../documentation/05-advanced-features/batch-processing.md)

