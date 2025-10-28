# Pagination Examples

This directory contains examples of different pagination styles available in the PDO Database Class.

## Files

- `01-pagination-examples.php` - Comprehensive pagination examples

## Pagination Types

### 1. Full Pagination (`paginate()`)

**Best for:** Traditional page-number navigation

**Features:**
- Complete metadata (total count, page numbers)
- Jump to any page
- Generated pagination links

**Performance:** 2 SQL queries (COUNT + SELECT)

**Use Cases:**
- Admin panels with page numbers
- Search results with "Page 1 of 10"
- Data tables with pagination controls

### 2. Simple Pagination (`simplePaginate()`)

**Best for:** Infinite scroll, faster performance

**Features:**
- Next/Previous navigation only
- No total count calculation
- Faster than full pagination

**Performance:** 1 SQL query (SELECT with +1 item)

**Use Cases:**
- Infinite scroll feeds (Twitter, Instagram)
- Mobile apps
- Real-time data where total changes frequently
- Very large tables where COUNT(*) is slow

### 3. Cursor-Based Pagination (`cursorPaginate()`)

**Best for:** Large datasets, real-time data

**Features:**
- Stable pagination (new items don't affect position)
- Most efficient for very large datasets
- Encoded cursor for security

**Performance:** 1 SQL query (SELECT with WHERE clause)

**Use Cases:**
- Millions of records
- Real-time feeds
- GraphQL APIs
- Time-series data

## Running Examples

```bash
# Run pagination examples
php examples/13-pagination/01-pagination-examples.php
```

## Performance Comparison

| Type | Queries | Performance | Use Case |
|------|---------|-------------|----------|
| **Full** | 2 (COUNT + SELECT) | ~200ms on 1M rows | Page numbers needed |
| **Simple** | 1 (SELECT +1) | ~50ms on 1M rows | Infinite scroll |
| **Cursor** | 1 (SELECT WHERE) | ~30ms on 1M rows | Large datasets |

## JSON API Response Formats

### Full Pagination Response

```json
{
  "data": [...],
  "meta": {
    "current_page": 2,
    "from": 11,
    "to": 20,
    "per_page": 10,
    "total": 156,
    "last_page": 16
  },
  "links": {
    "first": "/api/items?page=1",
    "last": "/api/items?page=16",
    "prev": "/api/items?page=1",
    "next": "/api/items?page=3"
  }
}
```

### Simple Pagination Response

```json
{
  "data": [...],
  "meta": {
    "current_page": 2,
    "per_page": 10,
    "has_more": true
  },
  "links": {
    "prev": "/api/items?page=1",
    "next": "/api/items?page=3"
  }
}
```

### Cursor Pagination Response

```json
{
  "data": [...],
  "meta": {
    "per_page": 10,
    "has_more": true,
    "has_previous": true
  },
  "cursor": {
    "next": "eyJwYXJhbXMiOnsia...",
    "prev": "eyJwYXJhbXMiOnsia..."
  },
  "links": {
    "next": "/api/items?cursor=eyJwYXJhbXMiOnsia...",
    "prev": "/api/items?cursor=eyJwYXJhbXMiOnsia..."
  }
}
```

## Quick Reference

```php
// Full pagination with metadata
$result = $db->find()
    ->from('posts')
    ->orderBy('created_at', 'DESC')
    ->paginate(20, 1); // per_page, page

// Simple pagination (faster)
$result = $db->find()
    ->from('posts')
    ->orderBy('created_at', 'DESC')
    ->simplePaginate(20, 1);

// Cursor pagination (most efficient)
$result = $db->find()
    ->from('posts')
    ->orderBy('id', 'DESC')
    ->cursorPaginate(20, $cursor);

// With URL options for link generation
$result = $db->find()
    ->from('posts')
    ->paginate(20, 1, [
        'path' => '/api/posts',
        'query' => ['filter' => 'active']
    ]);

// JSON API response
header('Content-Type: application/json');
echo json_encode($result);
```

## Learn More

- [Documentation: Pagination](../../documentation/05-advanced-features/pagination.md)
- [API Reference: Query Builder Methods](../../documentation/09-reference/query-builder-methods.md)

