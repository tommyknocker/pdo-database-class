# Full-Text Search

Full-text search allows you to perform fast text searches across multiple columns in your database.

## Supported Databases

- **MySQL**: Uses `MATCH AGAINST` with FULLTEXT indexes
- **PostgreSQL**: Uses `@@ to_tsquery()` with text search vectors
- **SQLite**: Uses `MATCH` with FTS5 virtual tables

## Basic Usage

### Simple Full-Text Search

```php
use tommyknocker\pdodb\helpers\Db;

// Search across multiple columns
$results = $db->find()
    ->from('articles')
    ->where(Db::fulltextMatch('title, content', 'database tutorial'))
    ->get();
```

### Single Column Search

```php
$results = $db->find()
    ->from('articles')
    ->where(Db::fulltextMatch('title', 'PHP'))
    ->get();
```

### Search with Mode (MySQL Only)

```php
// Natural language mode (default)
$results = $db->find()
    ->from('articles')
    ->where(Db::fulltextMatch('title, content', 'optimization tips', 'natural'))
    ->get();

// Boolean mode
$results = $db->find()
    ->from('articles')
    ->where(Db::fulltextMatch('title, content', '+optimization -security', 'boolean'))
    ->get();
```

### Query Expansion (MySQL Only)

```php
// Expand search with related terms
$results = $db->find()
    ->from('articles')
    ->where(Db::fulltextMatch('title, content', 'database', null, true))
    ->get();
```

## Setting Up Full-Text Indexes

### MySQL

```sql
CREATE TABLE articles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255),
    content TEXT,
    FULLTEXT (title, content)
);
```

### PostgreSQL

```sql
CREATE TABLE articles (
    id SERIAL PRIMARY KEY,
    title VARCHAR(255),
    content TEXT,
    ts_vector TSVECTOR GENERATED ALWAYS AS (
        to_tsvector('english', title || ' ' || content)
    ) STORED
);

CREATE INDEX idx_ts_vector ON articles USING GIN (ts_vector);
```

### SQLite (FTS5)

```sql
CREATE VIRTUAL TABLE articles USING fts5(title, content);
```

## Notes

- Full-text indexes must be created before using full-text search
- Full-text search requires proper indexing for optimal performance
- For SQLite, use FTS5 virtual tables for full-text search
- PostgreSQL requires creating text search vectors and indexes
- MySQL FULLTEXT indexes support only `MyISAM` or `InnoDB` with InnoDB full-text parser plugin

## Related

- [Filtering Conditions](filtering-conditions.md) - WHERE clauses
- [Helper Functions Reference](../09-reference/helper-functions-reference.md) - Complete helpers
- [Performance](../08-best-practices/performance.md) - Query optimization

