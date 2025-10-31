# Query Compilation Cache Examples

This directory contains examples demonstrating query compilation caching functionality.

## Files

- `01-compilation-cache-examples.php` - Basic compilation cache usage and performance demonstrations

## Running Examples

```bash
# Run with SQLite (default)
php examples/20-query-compilation-cache/01-compilation-cache-examples.php

# Run with MySQL
PDODB_DRIVER=mysql php examples/20-query-compilation-cache/01-compilation-cache-examples.php

# Run with PostgreSQL
PDODB_DRIVER=pgsql php examples/20-query-compilation-cache/01-compilation-cache-examples.php
```

## What's Demonstrated

1. **Basic Usage**: How compilation cache works automatically
2. **Performance Comparison**: Measuring improvement with and without cache
3. **Cache Hits**: Demonstrating cache effectiveness
4. **Real-world Scenarios**: API endpoints with similar query structures
5. **Configuration**: Customizing TTL and prefix

## Requirements

- PHP 8.1+
- PSR-16 cache implementation (Symfony Cache used in examples)
- Database connection (SQLite/MySQL/PostgreSQL)
