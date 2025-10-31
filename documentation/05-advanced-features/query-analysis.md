# Query Analysis

Analyze query performance using EXPLAIN.

## EXPLAIN

### MySQL

```php
$result = $db->find()
    ->from('users')
    ->where('email', 'user@example.com')
    ->explain();

// Returns execution plan
/*
Array
(
    [0] => Array
        (
            [id] => 1
            [select_type] => SIMPLE
            [table] => users
            [type] => ref
            [possible_keys] => email
            [key] => email
            [key_len] => 203
            [ref] => const
            [rows] => 1
            [Extra] => Using where
        )
)
*/
```

### PostgreSQL

```php
$result = $db->find()
    ->from('users')
    ->where('email', 'user@example.com')
    ->explain();

// Returns query plan with nodes
```

## EXPLAIN ANALYZE

```php
// PostgreSQL only
$result = $db->find()
    ->from('users')
    ->where('email', 'user@example.com')
    ->explainAnalyze();

// Returns actual execution time
```

## EXPLAIN with Recommendations

Get optimization recommendations automatically:

```php
$analysis = $db->find()
    ->from('users')
    ->where('status', 'active')
    ->explainAdvice();

// Returns ExplainAnalysis object with:
// - rawExplain: Original EXPLAIN output
// - plan: Parsed execution plan
// - issues: Detected issues
// - recommendations: Optimization suggestions
```

### Analyzing Results

```php
$analysis = $db->find()
    ->from('users')
    ->where('email', 'user@example.com')
    ->explainAdvice('users');

// Check plan details
echo "Access Type: " . $analysis->plan->accessType . "\n";
echo "Used Index: " . ($analysis->plan->usedIndex ?? 'None') . "\n";
echo "Estimated Rows: " . $analysis->plan->estimatedRows . "\n";

// Check for issues
if (!empty($analysis->plan->tableScans)) {
    echo "Full table scans detected: " . implode(', ', $analysis->plan->tableScans) . "\n";
}

// Get recommendations
foreach ($analysis->recommendations as $rec) {
    echo "[{$rec->severity}] {$rec->type}: {$rec->message}\n";
    if ($rec->suggestion) {
        echo "  Suggestion: {$rec->suggestion}\n";
    }
}
```

### Detecting Full Table Scans

```php
$analysis = $db->find()
    ->from('orders')
    ->where('status', 'pending')
    ->explainAdvice();

// Check for full table scans
if (!empty($analysis->plan->tableScans)) {
    foreach ($analysis->plan->tableScans as $table) {
        echo "Full table scan on: $table\n";
    }
}
```

### Getting Index Recommendations

```php
$analysis = $db->find()
    ->from('users')
    ->where('email', 'user@example.com')
    ->explainAdvice('users');

// Recommendations include SQL suggestions
foreach ($analysis->recommendations as $rec) {
    if ($rec->type === 'missing_index' && $rec->suggestion) {
        // Execute suggestion to create index
        // $db->rawQuery($rec->suggestion);
        echo $rec->suggestion . "\n";
    }
}
```

### Checking for Critical Issues

```php
$analysis = $db->find()
    ->from('users')
    ->where('age', 25, '>')
    ->explainAdvice();

if ($analysis->hasCriticalIssues()) {
    echo "⚠ Critical performance issues detected!\n";
}

if ($analysis->hasRecommendations()) {
    echo "ℹ Optimization recommendations available\n";
}
```

## DESCRIBE

### Describe Table

```php
$columns = $db->find()
    ->from('users')
    ->describe();

// Returns column information
/*
Array
(
    [0] => Array
        (
            [Field] => id
            [Type] => int(11)
            [Null] => NO
            [Key] => PRI
            [Default] => NULL
            [Extra] => auto_increment
        )
    ...
)
*/
```

### Describe Index

```php
$indexes = $db->rawQuery('SHOW INDEX FROM users');
```

## Performance Tips

### Check Index Usage

```php
$result = $db->find()
    ->from('users')
    ->where('email', 'user@example.com')
    ->explain();

if ($result[0]['key'] === null) {
    echo "No index used!";
}
```

### Analyze Slow Queries

```php
// Enable query log
$db->enableQueryLog();

$users = $db->find()
    ->from('users')
    ->join('profiles', 'profiles.user_id', 'users.id')
    ->get();

$log = $db->getQueryLog();

foreach ($log as $query) {
    echo $query['sql'] . "\n";
    echo "Time: " . $query['time'] . "ms\n";
}
```

## Next Steps

- [Performance](../08-best-practices/performance.md) - Query optimization
- [Common Pitfalls](../08-best-practices/common-pitfalls.md) - Mistakes to avoid
- [Troubleshooting](../10-cookbook/troubleshooting.md) - Common issues
