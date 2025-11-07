# Set Operations Examples

This directory contains examples of SQL set operations: UNION, INTERSECT, and EXCEPT.

## Examples

### 01-set-operations.php
Demonstrates various set operations:
- **UNION** - Combine results removing duplicates
- **UNION ALL** - Combine results keeping duplicates
- **INTERSECT** - Find common rows between queries
- **EXCEPT** - Find rows in first query but not in second
- Multiple UNION operations
- UNION with aggregations
- UNION with WHERE filters

## Running Examples

```bash
# Run all examples
php 01-set-operations.php

# Or use the test script
cd ../..
./scripts/test-examples.sh
```

## Database Support

- **MySQL 8.0+**: Full support
- **PostgreSQL**: Full support
- **SQLite 3.8.3+**: Full support

## Key Concepts

### UNION
Combines results from two queries and removes duplicates:
```php
$db->find()
    ->from('table1')
    ->union(fn($qb) => $qb->from('table2'))
    ->get();
```

### UNION ALL
Combines results keeping all rows including duplicates:
```php
$db->find()
    ->from('table1')
    ->unionAll(fn($qb) => $qb->from('table2'))
    ->get();
```

### INTERSECT
Returns only rows that appear in both queries:
```php
$db->find()
    ->from('table1')
    ->intersect(fn($qb) => $qb->from('table2'))
    ->get();
```

### EXCEPT
Returns rows from first query that don't appear in second:
```php
$db->find()
    ->from('table1')
    ->except(fn($qb) => $qb->from('table2'))
    ->get();
```

## Notes

- ORDER BY, LIMIT, and OFFSET apply to the final combined result
- All queries must have the same number of columns
- Column types should be compatible across queries








