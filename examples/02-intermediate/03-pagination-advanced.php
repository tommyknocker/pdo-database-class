<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\PdoDb;

/**
 * Pagination examples demonstrating different pagination styles.
 */

// Get database instance
$db = createExampleDb();

echo "=== Pagination Examples ===\n\n";

// Create and populate sample table
$db->rawQuery('DROP TABLE IF EXISTS posts');

// Create table syntax varies by dialect
$driver = getenv('PDODB_DRIVER') ?: 'sqlite';
if ($driver === 'mysql' || $driver === 'mariadb') {
    $db->rawQuery('
        CREATE TABLE posts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            author VARCHAR(100) NOT NULL,
            views INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
} elseif ($driver === 'pgsql') {
    $db->rawQuery('
        CREATE TABLE posts (
            id SERIAL PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            author VARCHAR(100) NOT NULL,
            views INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
} else { // sqlite
    $db->rawQuery('
        CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            author TEXT NOT NULL,
            views INTEGER DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ');
}

// Insert sample data
$posts = [];
for ($i = 1; $i <= 50; $i++) {
    $posts[] = [
        'title' => "Blog Post #$i",
        'author' => 'Author ' . (($i % 5) + 1),
        'views' => rand(100, 10000),
    ];
}
$db->find()->table('posts')->insertMulti($posts);

echo "Created 50 sample blog posts\n\n";

// ============================================================================
// Example 1: Full Pagination (with total count and page numbers)
// ============================================================================
echo "--- Example 1: Full Pagination ---\n";
echo "Best for: Traditional page-number navigation\n\n";

$result = $db->find()
    ->from('posts')
    ->orderBy('id', 'DESC')
    ->paginate(10, 1); // 10 per page, page 1

echo "Results:\n";
echo "  Total items: " . $result->total() . "\n";
echo "  Current page: " . $result->currentPage() . " of " . $result->lastPage() . "\n";
echo "  Items: " . $result->from() . " - " . $result->to() . "\n";
echo "  Items on page: " . count($result->items()) . "\n";
echo "  Has more pages: " . ($result->hasMorePages() ? 'Yes' : 'No') . "\n\n";

echo "First 3 posts:\n";
foreach (array_slice($result->items(), 0, 3) as $post) {
    echo "  - {$post['title']} by {$post['author']} ({$post['views']} views)\n";
}
echo "\n";

// JSON API Response
echo "JSON API Response:\n";
$jsonData = $result->jsonSerialize();
echo "  data: " . count($jsonData['data']) . " items\n";
echo "  meta: current_page={$jsonData['meta']['current_page']}, total={$jsonData['meta']['total']}, last_page={$jsonData['meta']['last_page']}\n";
echo "  links: first, last, prev, next\n\n";

// ============================================================================
// Example 2: Simple Pagination (without total count)
// ============================================================================
echo "--- Example 2: Simple Pagination ---\n";
echo "Best for: Infinite scroll, faster performance\n\n";

$result2 = $db->find()
    ->from('posts')
    ->orderBy('views', 'DESC')
    ->simplePaginate(15, 1); // 15 per page, page 1

echo "Results:\n";
echo "  Current page: " . $result2->currentPage() . "\n";
echo "  Items on page: " . count($result2->items()) . "\n";
echo "  Has more pages: " . ($result2->hasMorePages() ? 'Yes' : 'No') . "\n";
echo "  Total: Not calculated (faster query!)\n\n";

echo "Top 3 most viewed posts:\n";
foreach (array_slice($result2->items(), 0, 3) as $post) {
    echo "  - {$post['title']} ({$post['views']} views)\n";
}
echo "\n";

// JSON API Response
echo "JSON API Response:\n";
$jsonData2 = $result2->jsonSerialize();
echo "  data: " . count($jsonData2['data']) . " items\n";
echo "  meta: current_page={$jsonData2['meta']['current_page']}, has_more=" . ($jsonData2['meta']['has_more'] ? 'true' : 'false') . "\n";
echo "  links: prev, next (no first/last - we don't know total)\n\n";

// ============================================================================
// Example 3: Cursor-Based Pagination
// ============================================================================
echo "--- Example 3: Cursor-Based Pagination ---\n";
echo "Best for: Large datasets, real-time data, infinite scroll\n\n";

$result3 = $db->find()
    ->from('posts')
    ->orderBy('id', 'DESC')
    ->cursorPaginate(10); // 10 per page, first page

echo "Results (First Page):\n";
echo "  Items: " . count($result3->items()) . "\n";
echo "  Has more: " . ($result3->hasMorePages() ? 'Yes' : 'No') . "\n";
echo "  Next cursor: " . substr($result3->nextCursor() ?? 'null', 0, 20) . "...\n\n";

echo "First 3 posts:\n";
foreach (array_slice($result3->items(), 0, 3) as $post) {
    echo "  - {$post['title']}\n";
}
echo "\n";

// Load next page using cursor
if ($result3->hasMorePages()) {
    $result3Next = $db->find()
        ->from('posts')
        ->orderBy('id', 'DESC')
        ->cursorPaginate(10, $result3->nextCursor());

    echo "Results (Next Page):\n";
    echo "  Items: " . count($result3Next->items()) . "\n";
    echo "  Has more: " . ($result3Next->hasMorePages() ? 'Yes' : 'No') . "\n\n";

    echo "First 3 posts on page 2:\n";
    foreach (array_slice($result3Next->items(), 0, 3) as $post) {
        echo "  - {$post['title']}\n";
    }
    echo "\n";
}

// JSON API Response
echo "JSON API Response:\n";
$jsonData3 = $result3->jsonSerialize();
echo "  data: " . count($jsonData3['data']) . " items\n";
echo "  meta: has_more=" . ($jsonData3['meta']['has_more'] ? 'true' : 'false') . "\n";
echo "  cursor: next=<encoded>, prev=null\n";
echo "  links: next, prev\n\n";

// ============================================================================
// Example 4: Pagination with URL Options
// ============================================================================
echo "--- Example 4: Pagination with URL Options ---\n";
echo "Generate pagination links for web applications\n\n";

$result4 = $db->find()
    ->from('posts')
    ->where('author', 'Author 1')
    ->orderBy('views', 'DESC')
    ->paginate(5, 2, [
        'path' => '/api/posts',
        'query' => ['author' => 'Author 1', 'sort' => 'views']
    ]);

echo "Results:\n";
echo "  Current page: " . $result4->currentPage() . " of " . $result4->lastPage() . "\n";
echo "  Total matching: " . $result4->total() . "\n\n";

echo "Generated Links:\n";
echo "  First: " . $result4->url(1) . "\n";
echo "  Previous: " . ($result4->previousPageUrl() ?? 'none') . "\n";
echo "  Next: " . ($result4->nextPageUrl() ?? 'none') . "\n";
echo "  Last: " . $result4->url($result4->lastPage()) . "\n\n";

// ============================================================================
// Example 5: Performance Comparison
// ============================================================================
echo "--- Example 5: Performance Comparison ---\n\n";

// Full pagination (2 queries: COUNT + SELECT)
$start = microtime(true);
$full = $db->find()->from('posts')->orderBy('id')->paginate(20);
$timeFullPagination = microtime(true) - $start;

// Simple pagination (1 query: SELECT only)
$start = microtime(true);
$simple = $db->find()->from('posts')->orderBy('id')->simplePaginate(20);
$timeSimplePagination = microtime(true) - $start;

// Cursor pagination (1 query: SELECT only)
$start = microtime(true);
$cursor = $db->find()->from('posts')->orderBy('id')->cursorPaginate(20);
$timeCursorPagination = microtime(true) - $start;

echo "Performance (50 records):\n";
echo sprintf("  Full Pagination:   %.4f ms (2 queries: COUNT + SELECT)\n", $timeFullPagination * 1000);
echo sprintf("  Simple Pagination: %.4f ms (1 query: SELECT)\n", $timeSimplePagination * 1000);
echo sprintf("  Cursor Pagination: %.4f ms (1 query: SELECT)\n", $timeCursorPagination * 1000);
echo "\n";
echo "Note: On large tables (millions of rows), the difference is more significant!\n\n";

// ============================================================================
// Example 6: Real-World API Response
// ============================================================================
echo "--- Example 6: Real-World API Response ---\n\n";

$apiResult = $db->find()
    ->from('posts')
    ->select(['id', 'title', 'author', 'views'])
    ->where('views', 1000, '>')
    ->orderBy('views', 'DESC')
    ->paginate(10, 1, [
        'path' => 'https://api.example.com/v1/posts',
        'query' => ['min_views' => 1000]
    ]);

echo "API Response (JSON):\n";
echo json_encode([
    'success' => true,
    'data' => array_slice($apiResult->items(), 0, 3), // First 3 items
    'pagination' => [
        'current_page' => $apiResult->currentPage(),
        'per_page' => $apiResult->perPage(),
        'total' => $apiResult->total(),
        'last_page' => $apiResult->lastPage(),
        'from' => $apiResult->from(),
        'to' => $apiResult->to(),
    ],
    'links' => $apiResult->links(),
], JSON_PRETTY_PRINT) . "\n\n";

// ============================================================================
// Choosing the Right Pagination Type
// ============================================================================
echo "=== Which Pagination Type to Use? ===\n\n";

echo "1. Full Pagination (paginate):\n";
echo "   ✓ Traditional page numbers (1, 2, 3...)\n";
echo "   ✓ Need to show total count\n";
echo "   ✓ Jump to specific page\n";
echo "   ✗ Slower on large tables (requires COUNT)\n\n";

echo "2. Simple Pagination (simplePaginate):\n";
echo "   ✓ Faster than full pagination\n";
echo "   ✓ Infinite scroll patterns\n";
echo "   ✓ Real-time data (total changes frequently)\n";
echo "   ✗ No page numbers or total count\n\n";

echo "3. Cursor Pagination (cursorPaginate):\n";
echo "   ✓ Most efficient for large datasets\n";
echo "   ✓ Stable pagination (new items don't affect position)\n";
echo "   ✓ Best for infinite scroll with millions of rows\n";
echo "   ✗ Cannot jump to specific page\n";
echo "   ✗ Requires ORDER BY clause\n\n";

// Cleanup
$db->rawQuery('DROP TABLE posts');

echo "Done!\n";

