# Pagination

The PDO Database Class provides three types of pagination to handle different use cases:

1. **Full Pagination** - Traditional page-number navigation with complete metadata
2. **Simple Pagination** - Faster pagination without total count (ideal for infinite scroll)
3. **Cursor Pagination** - Most efficient for large datasets and real-time data

## Table of Contents

- [Full Pagination](#full-pagination)
- [Simple Pagination](#simple-pagination)
- [Cursor Pagination](#cursor-pagination)
- [URL Generation](#url-generation)
- [JSON Serialization](#json-serialization)
- [Performance Comparison](#performance-comparison)
- [Best Practices](#best-practices)

---

## Full Pagination

Full pagination provides complete metadata including total count and page numbers. Best for traditional pagination UI with "Page 1 of 10" style navigation.

### Basic Usage

```php
$result = $db->find()
    ->from('posts')
    ->orderBy('created_at', 'DESC')
    ->paginate(20); // 20 items per page, auto-detect page from $_GET['page']

// Or specify page manually
$result = $db->find()
    ->from('posts')
    ->paginate(20, 3); // Page 3
```

### Available Methods

```php
// Get items
$items = $result->items();

// Metadata
$total = $result->total();           // Total items across all pages
$perPage = $result->perPage();       // Items per page
$currentPage = $result->currentPage(); // Current page number
$lastPage = $result->lastPage();     // Total pages

// Position info
$from = $result->from();             // First item number on current page
$to = $result->to();                 // Last item number on current page

// Pagination state
$hasMore = $result->hasMorePages();  // Are there more pages?
$isFirst = $result->onFirstPage();   // Is this the first page?
$isLast = $result->onLastPage();     // Is this the last page?

// URL generation
$url = $result->url(5);              // URL for page 5
$next = $result->nextPageUrl();      // URL for next page (or null)
$prev = $result->previousPageUrl();  // URL for previous page (or null)
```

### With Filters

```php
$result = $db->find()
    ->from('posts')
    ->where('status', 'published')
    ->where('views', 1000, '>')
    ->orderBy('views', 'DESC')
    ->paginate(15, 2); // Page 2 of published posts with >1000 views

echo "Showing {$result->from()}-{$result->to()} of {$result->total()} posts\n";
```

### SQL Queries

Full pagination executes **2 SQL queries**:

```sql
-- Query 1: Get total count
SELECT COUNT(*) as total FROM posts WHERE status = 'published';

-- Query 2: Get items for current page
SELECT * FROM posts WHERE status = 'published' 
ORDER BY views DESC LIMIT 15 OFFSET 15;
```

---

## Simple Pagination

Simple pagination performs only one query (no COUNT), making it significantly faster than full pagination. Best for infinite scroll or when total count is not needed.

### Basic Usage

```php
$result = $db->find()
    ->from('posts')
    ->orderBy('created_at', 'DESC')
    ->simplePaginate(20); // 20 items per page

// Or specify page manually
$result = $db->find()
    ->from('posts')
    ->simplePaginate(20, 3); // Page 3
```

### Available Methods

```php
// Get items
$items = $result->items();

// Metadata
$perPage = $result->perPage();       // Items per page
$currentPage = $result->currentPage(); // Current page number

// Pagination state
$hasMore = $result->hasMorePages();  // Are there more pages?
$isFirst = $result->onFirstPage();   // Is this the first page?

// URL generation
$url = $result->url(3);              // URL for page 3
$next = $result->nextPageUrl();      // URL for next page (or null)
$prev = $result->previousPageUrl();  // URL for previous page (or null)
```

### Infinite Scroll Example

```php
// API endpoint for infinite scroll
public function loadMorePosts(int $page = 1): array
{
    $result = $this->db->find()
        ->from('posts')
        ->where('status', 'published')
        ->orderBy('created_at', 'DESC')
        ->simplePaginate(20, $page);
    
    return [
        'posts' => $result->items(),
        'has_more' => $result->hasMorePages(),
        'next_page' => $result->hasMorePages() ? $page + 1 : null,
    ];
}
```

### SQL Query

Simple pagination executes **1 SQL query** (fetches +1 item to check if more pages exist):

```sql
-- Query: Get items + 1 extra to check for more pages
SELECT * FROM posts WHERE status = 'published' 
ORDER BY created_at DESC LIMIT 21 OFFSET 20;
-- If 21 items returned, has_more = true (and we discard the extra item)
```

---

## Cursor Pagination

Cursor pagination is the most efficient for very large datasets. Uses WHERE clauses instead of OFFSET, making it stable when new items are added and very fast on large tables.

### Basic Usage

```php
// First page
$result = $db->find()
    ->from('posts')
    ->orderBy('id', 'DESC')  // ORDER BY is required!
    ->cursorPaginate(20);

// Next page using cursor
$cursor = $result->nextCursor();
$result2 = $db->find()
    ->from('posts')
    ->orderBy('id', 'DESC')
    ->cursorPaginate(20, $cursor);

// Or from query string
// URL: /api/posts?cursor=eyJwYXJhbXMiOnsia...
$result = $db->find()
    ->from('posts')
    ->orderBy('id', 'DESC')
    ->cursorPaginate(20); // Auto-detects cursor from $_GET['cursor']
```

### Available Methods

```php
// Get items
$items = $result->items();

// Metadata
$perPage = $result->perPage();

// Pagination state
$hasMore = $result->hasMorePages();      // Are there more pages?
$hasPrev = $result->hasPreviousPages();  // Are there previous pages?

// Cursors (encoded strings)
$nextCursor = $result->nextCursor();     // Cursor for next page
$prevCursor = $result->previousCursor(); // Cursor for previous page

// URL generation
$nextUrl = $result->nextPageUrl();
$prevUrl = $result->previousPageUrl();
```

### Cursor Object

```php
use tommyknocker\pdodb\query\pagination\Cursor;

// Create cursor manually
$cursor = new Cursor(['id' => 42, 'created_at' => '2025-10-28']);
$encoded = $cursor->encode();

// Decode cursor
$cursor = Cursor::decode($encodedString);
$params = $cursor->parameters(); // ['id' => 42, 'created_at' => '2025-10-28']

// Create from item
$item = ['id' => 123, 'name' => 'Test', 'created_at' => '2025-10-28'];
$cursor = Cursor::fromItem($item, ['id', 'created_at']);
```

### SQL Query

Cursor pagination executes **1 SQL query** (uses WHERE instead of OFFSET):

```sql
-- First page
SELECT * FROM posts ORDER BY id DESC LIMIT 21;

-- Next page (cursor contains id=100)
SELECT * FROM posts WHERE id < 100 ORDER BY id DESC LIMIT 21;
-- Much faster than OFFSET on large tables!
```

### Benefits

- **Stable pagination**: New items don't affect cursor position
- **Performance**: No OFFSET performance issues on large tables
- **Scalability**: Works efficiently with millions of rows

### Limitations

- **Requires ORDER BY**: Cannot paginate without sorting
- **No page numbers**: Cannot jump to specific page
- **Sequential only**: Can only go next/previous

---

## URL Generation

All pagination types support URL generation for easy integration with web applications.

### Basic URL Generation

```php
$result = $db->find()
    ->from('posts')
    ->paginate(20, 2, [
        'path' => '/api/posts',
        'query' => ['status' => 'published', 'author' => 'john']
    ]);

echo $result->url(1);  
// Output: /api/posts?status=published&author=john&page=1

echo $result->nextPageUrl();
// Output: /api/posts?status=published&author=john&page=3

echo $result->previousPageUrl();
// Output: /api/posts?status=published&author=john&page=1
```

### Without Path (Relative URLs)

```php
$result = $db->find()
    ->from('posts')
    ->paginate(20);

echo $result->url(5);
// Output: ?page=5
```

### Cursor Pagination URLs

```php
$result = $db->find()
    ->from('posts')
    ->orderBy('id', 'DESC')
    ->cursorPaginate(20, null, [
        'path' => '/api/posts',
        'query' => ['filter' => 'active']
    ]);

echo $result->nextPageUrl();
// Output: /api/posts?filter=active&cursor=eyJwYXJhbXMiOnsia...
```

---

## JSON Serialization

All pagination results implement `JsonSerializable`, making them perfect for JSON APIs.

### Full Pagination JSON

```php
$result = $db->find()
    ->from('posts')
    ->paginate(10, 2);

header('Content-Type: application/json');
echo json_encode($result);
```

**Output:**

```json
{
  "data": [
    {"id": 11, "title": "Post 11"},
    {"id": 12, "title": "Post 12"}
  ],
  "meta": {
    "current_page": 2,
    "from": 11,
    "to": 20,
    "per_page": 10,
    "total": 156,
    "last_page": 16
  },
  "links": {
    "first": "?page=1",
    "last": "?page=16",
    "prev": "?page=1",
    "next": "?page=3"
  }
}
```

### Simple Pagination JSON

```php
$result = $db->find()
    ->from('posts')
    ->simplePaginate(10, 2);

echo json_encode($result);
```

**Output:**

```json
{
  "data": [...],
  "meta": {
    "current_page": 2,
    "per_page": 10,
    "has_more": true
  },
  "links": {
    "prev": "?page=1",
    "next": "?page=3"
  }
}
```

### Cursor Pagination JSON

```php
$result = $db->find()
    ->from('posts')
    ->orderBy('id', 'DESC')
    ->cursorPaginate(10);

echo json_encode($result);
```

**Output:**

```json
{
  "data": [...],
  "meta": {
    "per_page": 10,
    "has_more": true,
    "has_previous": false
  },
  "cursor": {
    "next": "eyJwYXJhbXMiOnsia...",
    "prev": null
  },
  "links": {
    "next": "?cursor=eyJwYXJhbXMiOnsia...",
    "prev": null
  }
}
```

---

## Performance Comparison

| Type | SQL Queries | 50 rows | 1M rows | Use Case |
|------|-------------|---------|---------|----------|
| **Full Pagination** | 2 (COUNT + SELECT) | ~2ms | ~200ms | Page numbers needed |
| **Simple Pagination** | 1 (SELECT +1) | ~1ms | ~50ms | Infinite scroll |
| **Cursor Pagination** | 1 (SELECT WHERE) | ~1ms | ~30ms | Large datasets, real-time |

### When to Use Each Type

#### Full Pagination (`paginate`)

**Use when:**
- Need to display page numbers (1, 2, 3...)
- Need total count
- Users can jump to specific pages
- Dataset is relatively small (<100k records)

**Examples:**
- Admin panels
- Search results with "Showing 1-20 of 156"
- Data tables with page navigation

#### Simple Pagination (`simplePaginate`)

**Use when:**
- Infinite scroll pattern
- Total count not needed
- Real-time data (total changes frequently)
- Large tables where COUNT(*) is slow

**Examples:**
- Social media feeds
- Mobile apps with "Load More" button
- Chat messages
- Activity logs

#### Cursor Pagination (`cursorPaginate`)

**Use when:**
- Very large datasets (millions of rows)
- Real-time data with frequent inserts
- Need stable pagination (new items don't affect position)
- Building GraphQL APIs

**Examples:**
- Time-series data
- Audit logs
- Message history
- Large product catalogs

---

## Best Practices

### 1. Always Use ORDER BY

```php
// ✓ Good - Consistent ordering
$result = $db->find()
    ->from('posts')
    ->orderBy('created_at', 'DESC')
    ->paginate(20);

// ✗ Bad - Unpredictable results without ORDER BY
$result = $db->find()
    ->from('posts')
    ->paginate(20);
```

### 2. Add Database Indexes

```sql
-- For cursor pagination on id
CREATE INDEX idx_posts_id ON posts(id);

-- For sorting by created_at
CREATE INDEX idx_posts_created_at ON posts(created_at DESC);

-- For filtered pagination
CREATE INDEX idx_posts_status_created ON posts(status, created_at DESC);
```

### 3. Validate Page Numbers

```php
$page = max(1, (int)($_GET['page'] ?? 1)); // Ensure page >= 1

$result = $db->find()
    ->from('posts')
    ->paginate(20, $page);
```

### 4. Cache Total Counts (Full Pagination)

```php
// Cache expensive COUNT(*) queries
$cacheKey = 'posts_total_' . md5(serialize($filters));
$total = $cache->get($cacheKey);

if ($total === null) {
    $total = $db->find()
        ->from('posts')
        ->where($filters)
        ->select([Db::count()])
        ->getValue();
    
    $cache->set($cacheKey, $total, 300); // Cache for 5 minutes
}
```

### 5. Limit Per-Page Value

```php
$perPage = min(100, max(10, (int)($_GET['per_page'] ?? 20)));
// Ensures per_page is between 10 and 100
```

### 6. Use Cursor Pagination for APIs

```php
// GraphQL-style API endpoint
public function posts(int $first = 20, ?string $after = null): array
{
    $result = $this->db->find()
        ->from('posts')
        ->orderBy('id', 'DESC')
        ->cursorPaginate($first, $after);
    
    return [
        'edges' => array_map(
            fn($post) => ['node' => $post, 'cursor' => $result->nextCursor()],
            $result->items()
        ),
        'pageInfo' => [
            'hasNextPage' => $result->hasMorePages(),
            'hasPreviousPage' => $result->hasPreviousPages(),
            'endCursor' => $result->nextCursor(),
        ],
    ];
}
```

### 7. Handle Empty Results

```php
$result = $db->find()
    ->from('posts')
    ->where('status', 'published')
    ->paginate(20);

if (empty($result->items())) {
    return ['message' => 'No posts found', 'data' => []];
}
```

---

## API Response Patterns

### REST API with Full Pagination

```php
class PostController
{
    public function index(Request $request): JsonResponse
    {
        $result = $this->db->find()
            ->from('posts')
            ->where('status', 'published')
            ->orderBy('created_at', 'DESC')
            ->paginate(
                perPage: $request->get('per_page', 20),
                page: $request->get('page', 1),
                options: [
                    'path' => $request->url(),
                    'query' => $request->query()
                ]
            );
        
        return response()->json($result);
    }
}
```

### Infinite Scroll with Simple Pagination

```php
class FeedController
{
    public function feed(Request $request): JsonResponse
    {
        $page = $request->get('page', 1);
        
        $result = $this->db->find()
            ->from('posts')
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at', 'DESC')
            ->simplePaginate(20, $page);
        
        return response()->json([
            'items' => $result->items(),
            'next_page' => $result->hasMorePages() ? $page + 1 : null,
        ]);
    }
}
```

### GraphQL-style with Cursor Pagination

```php
class GraphQLResolver
{
    public function posts(
        int $first = 20,
        ?string $after = null
    ): array {
        $result = $this->db->find()
            ->from('posts')
            ->orderBy('id', 'DESC')
            ->cursorPaginate($first, $after);
        
        return [
            'edges' => array_map(
                fn($post) => [
                    'node' => $post,
                    'cursor' => base64_encode("post_{$post['id']}")
                ],
                $result->items()
            ),
            'pageInfo' => [
                'hasNextPage' => $result->hasMorePages(),
                'endCursor' => $result->nextCursor(),
            ],
        ];
    }
}
```

---

## See Also

- [Query Builder Basics](../02-core-concepts/query-builder-basics.md)
- [Query Builder Methods Reference](../09-reference/query-builder-methods.md)
- [Pagination Examples](../../examples/13-pagination/)

