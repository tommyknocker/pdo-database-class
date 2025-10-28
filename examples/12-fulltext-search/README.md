# Full-Text Search Examples

This directory contains examples demonstrating full-text search functionality.

## Examples

### 01-fulltext-examples.php

Demonstrates full-text search features:

- Basic full-text search syntax
- Multiple column searches
- Case-insensitive searches using ILIKE
- Schema introspection examples

## Running Examples

```bash
# Run on SQLite (default)
php 01-fulltext-examples.php

# Run on MySQL
PDODB_DRIVER=mysql php 01-fulltext-examples.php

# Run on PostgreSQL
PDODB_DRIVER=pgsql php 01-fulltext-examples.php
```

## Notes

- Full-text search requires proper indexing
- For SQLite, FTS5 virtual tables must be used
- PostgreSQL requires text search vectors to be set up
- MySQL uses FULLTEXT indexes on MyISAM or InnoDB (with plugin)

## Related Documentation

- [Full-Text Search Guide](../../documentation/03-query-builder/fulltext-search.md)
- [Schema Introspection Guide](../../documentation/03-query-builder/schema-introspection.md)

