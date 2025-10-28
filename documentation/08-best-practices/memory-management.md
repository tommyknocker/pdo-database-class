# Memory Management

Handle large datasets efficiently without memory issues.

## Use Generators

### batch() - Process in Chunks

```php
// Process data in batches of 100 records
foreach ($db->find()
    ->from('users')
    ->orderBy('id')
    ->batch(100) as $batch) {
    
    foreach ($batch as $user) {
        processUser($user);
    }
    
    // Memory is freed after each batch
    unset($batch);
    gc_collect_cycles();
}
```

### each() - One Record at a Time

```php
// Process one record at a time
foreach ($db->find()
    ->from('users')
    ->where('active', 1)
    ->each(50) as $user) {
    
    processUser($user);
    
    // Memory stays low
}
```

### cursor() - Streaming

```php
// Stream results with minimal memory
foreach ($db->find()
    ->from('users')
    ->orderBy('id')
    ->cursor() as $user) {
    
    processUser($user);
    
    // Results are not buffered
}
```

## Avoid Loading Everything

### ❌ Bad: Load All Data

```php
// Loads entire table into memory
$users = $db->find()->from('users')->get();

foreach ($users as $user) {
    processUser($user);  // 1M users = 1GB+ memory
}
```

### ✅ Good: Use Generators

```php
// Streams one at a time
foreach ($db->find()->from('users')->cursor() as $user) {
    processUser($user);  // Only one user in memory
}
```

## Limit Query Results

### Always Use LIMIT

```php
// ✅ Good: Limited results
$users = $db->find()
    ->from('users')
    ->where('active', 1)
    ->orderBy('created_at', 'DESC')
    ->limit(100)
    ->get();

// ❌ Bad: Could load millions
$users = $db->find()->from('users')->get();
```

## Process Large Datasets

### Export to CSV

```php
function exportUsersToCsv($filename) {
    $file = fopen($filename, 'w');
    fputcsv($file, ['ID', 'Name', 'Email']);
    
    foreach ($db->find()
        ->from('users')
        ->orderBy('id')
        ->cursor() as $user) {
        
        fputcsv($file, [$user['id'], $user['name'], $user['email']]);
    }
    
    fclose($file);
}
```

### Batch Updates

```php
// Update in batches
foreach ($db->find()
    ->from('users')
    ->where('last_login', Db::now('-90 DAYS'), '<')
    ->orderBy('id')
    ->batch(1000) as $batch) {
    
    foreach ($batch as $user) {
        $db->find()
            ->table('users')
            ->where('id', $user['id'])
            ->update(['status' => 'inactive']);
    }
}
```

## Monitor Memory Usage

### Check Memory Consumption

```php
$initialMemory = memory_get_usage(true);

foreach ($db->find()->from('users')->cursor() as $user) {
    processUser($user);
}

$finalMemory = memory_get_usage(true);
$usedMemory = ($finalMemory - $initialMemory) / 1024 / 1024;
echo "Memory used: {$usedMemory} MB\n";
```

## Clear Memory

### Explicit Cleanup

```php
foreach ($db->find()->from('users')->batch(1000) as $batch) {
    processBatch($batch);
    
    // Clear variables
    unset($batch);
    
    // Force garbage collection every 10 batches
    if (($i++ % 10) === 0) {
        gc_collect_cycles();
    }
}
```

## Next Steps

- [Performance](performance.md) - Query optimization
- [Security](security.md) - Best security practices
- [Batch Processing](../05-advanced-features/batch-processing.md) - Batch operations

