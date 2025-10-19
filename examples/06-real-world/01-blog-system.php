<?php
/**
 * Real-World Example: Simple Blog System
 * 
 * Demonstrates a complete blog with posts, comments, and tags
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

$db = new PdoDb('sqlite', ['path' => ':memory:']);

echo "=== Blog System Example ===\n\n";

// Create schema
echo "Setting up blog database schema...\n";

$db->rawQuery("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        email TEXT UNIQUE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

$db->rawQuery("
    CREATE TABLE posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        slug TEXT UNIQUE NOT NULL,
        content TEXT,
        author_id INTEGER,
        meta TEXT,  -- JSON for SEO, featured_image, etc.
        status TEXT DEFAULT 'draft',
        view_count INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        published_at DATETIME
    )
");

$db->rawQuery("
    CREATE TABLE comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER,
        author_name TEXT,
        author_email TEXT,
        content TEXT,
        status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

$db->rawQuery("
    CREATE TABLE tags (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE,
        slug TEXT UNIQUE
    )
");

$db->rawQuery("
    CREATE TABLE post_tags (
        post_id INTEGER,
        tag_id INTEGER,
        PRIMARY KEY (post_id, tag_id)
    )
");

echo "✓ Schema created (users, posts, comments, tags, post_tags)\n\n";

// Scenario 1: Create users
echo "1. Creating blog authors...\n";
$authorId = $db->find()->table('users')->insert([
    'username' => 'johndoe',
    'email' => 'john@example.com'
]);

$editorId = $db->find()->table('users')->insert([
    'username' => 'janeeditor',
    'email' => 'jane@example.com'
]);

echo "✓ Created 2 users (author and editor)\n\n";

// Scenario 2: Create tags
echo "2. Creating tags...\n";
$tags = [
    ['name' => 'PHP', 'slug' => 'php'],
    ['name' => 'Database', 'slug' => 'database'],
    ['name' => 'Tutorial', 'slug' => 'tutorial'],
    ['name' => 'Best Practices', 'slug' => 'best-practices'],
];

$db->find()->table('tags')->insertMulti($tags);
echo "✓ Created 4 tags\n\n";

// Scenario 3: Create a blog post with metadata
echo "3. Creating a blog post with SEO metadata...\n";
$postId = $db->find()->table('posts')->insert([
    'title' => 'Getting Started with PDOdb',
    'slug' => 'getting-started-pdodb',
    'content' => 'PDOdb is a modern PHP database library that provides a unified API across MySQL, PostgreSQL, and SQLite...',
    'author_id' => $authorId,
    'meta' => Db::jsonObject([
        'seo_title' => 'PDOdb Tutorial - Complete Guide',
        'seo_description' => 'Learn how to use PDOdb for multi-database PHP applications',
        'featured_image' => '/images/pdodb-tutorial.jpg',
        'reading_time' => 8,
        'featured' => true
    ]),
    'status' => 'published',
    'published_at' => Db::now()
]);

echo "✓ Post created with ID: $postId\n\n";

// Add tags to post
$db->find()->table('post_tags')->insertMulti([
    ['post_id' => $postId, 'tag_id' => 1], // PHP
    ['post_id' => $postId, 'tag_id' => 2], // Database
    ['post_id' => $postId, 'tag_id' => 3], // Tutorial
]);

echo "✓ Added 3 tags to post\n\n";

// Scenario 4: Add comments
echo "4. Adding comments to the post...\n";
$comments = [
    ['post_id' => $postId, 'author_name' => 'Alice Reader', 'author_email' => 'alice@example.com', 'content' => 'Great article! Very helpful.', 'status' => 'approved'],
    ['post_id' => $postId, 'author_name' => 'Bob Commenter', 'author_email' => 'bob@example.com', 'content' => 'Thanks for sharing!', 'status' => 'approved'],
    ['post_id' => $postId, 'author_name' => 'Spammer', 'author_email' => 'spam@spam.com', 'content' => 'Buy cheap stuff!', 'status' => 'spam'],
];

$db->find()->table('comments')->insertMulti($comments);
echo "✓ Added 3 comments (2 approved, 1 spam)\n\n";

// Scenario 5: Increment view count
echo "5. Simulating page views...\n";
for ($i = 0; $i < 5; $i++) {
    $db->find()->table('posts')->where('id', $postId)->update([
        'view_count' => Db::inc()
    ]);
}
echo "✓ Incremented view count 5 times\n\n";

// Scenario 6: Display blog post with all related data
echo "6. Fetching complete post data...\n";
$post = $db->find()
    ->from('posts AS p')
    ->join('users AS u', 'u.id = p.author_id')
    ->select([
        'p.*',
        'author_username' => 'u.username',
        'comment_count' => Db::raw('(SELECT COUNT(*) FROM comments WHERE post_id = p.id AND status = "approved")'),
        'reading_time' => Db::jsonGet('p.meta', ['reading_time'])
    ])
    ->where('p.id', $postId)
    ->getOne();

$meta = json_decode($post['meta'], true);

echo "\n";
echo "  ┌─────────────────────────────────────────────────────┐\n";
echo "  │ " . str_pad($post['title'], 51) . " │\n";
echo "  ├─────────────────────────────────────────────────────┤\n";
echo "  │ By: " . str_pad($post['author_username'], 47) . " │\n";
echo "  │ Published: " . str_pad(substr($post['published_at'], 0, 16), 40) . " │\n";
echo "  │ Reading time: " . str_pad($post['reading_time'] . " minutes", 37) . " │\n";
echo "  │ Views: " . str_pad((string)$post['view_count'], 44) . " │\n";
echo "  │ Comments: " . str_pad((string)$post['comment_count'], 41) . " │\n";
echo "  └─────────────────────────────────────────────────────┘\n\n";

// Get tags
$postTags = $db->find()
    ->from('tags AS t')
    ->join('post_tags AS pt', 'pt.tag_id = t.id')
    ->select(['t.name'])
    ->where('pt.post_id', $postId)
    ->get();

echo "  Tags: " . implode(', ', array_column($postTags, 'name')) . "\n\n";

// Get comments
$postComments = $db->find()
    ->from('comments')
    ->where('post_id', $postId)
    ->where('status', 'approved')
    ->orderBy('created_at', 'ASC')
    ->get();

echo "  Comments:\n";
foreach ($postComments as $comment) {
    echo "  • {$comment['author_name']}: \"{$comment['content']}\"\n";
}
echo "\n";

// Scenario 7: Search posts by tag
echo "7. Finding all posts with 'PHP' tag...\n";
$phpPosts = $db->find()
    ->from('posts AS p')
    ->join('post_tags AS pt', 'pt.post_id = p.id')
    ->join('tags AS t', 't.id = pt.tag_id')
    ->select(['p.title', 'p.slug'])
    ->where('t.slug', 'php')
    ->where('p.status', 'published')
    ->get();

echo "  Found " . count($phpPosts) . " post(s) with PHP tag\n\n";

// Scenario 8: Get popular posts
echo "8. Getting most popular posts...\n";
$popular = $db->find()
    ->from('posts')
    ->select([
        'title',
        'view_count',
        'comment_count' => Db::raw('(SELECT COUNT(*) FROM comments WHERE post_id = posts.id AND status = "approved")')
    ])
    ->where('status', 'published')
    ->orderBy('view_count', 'DESC')
    ->limit(5)
    ->get();

echo "  Top posts:\n";
foreach ($popular as $p) {
    echo "  • {$p['title']} - {$p['view_count']} views, {$p['comment_count']} comments\n";
}
echo "\n";

// Scenario 9: Get featured posts using JSON query
echo "9. Finding featured posts (JSON query)...\n";
$featured = $db->find()
    ->from('posts')
    ->select(['title'])
    ->where('status', 'published')
    ->where(Db::jsonPath('meta', ['featured'], '=', true))
    ->get();

echo "  Found " . count($featured) . " featured post(s):\n";
foreach ($featured as $post) {
    echo "  • {$post['title']}\n";
}
echo "\n";

// Scenario 10: Statistics across all connections
echo "10. Blog statistics summary...\n";

$db->connection('users_db');
$userCount = $db->rawQueryValue('SELECT COUNT(*) FROM users');

$db->connection('users_db'); // posts are in users_db
$postCount = $db->rawQueryValue("SELECT COUNT(*) FROM posts WHERE status = 'published'");

$db->connection('analytics_db');
$eventCount = $db->rawQueryValue('SELECT COUNT(*) FROM events');

$db->connection('users_db');
$commentCount = $db->rawQueryValue("SELECT COUNT(*) FROM comments WHERE status = 'approved'");

echo "  📊 Blog Statistics:\n";
echo "     Users: $userCount\n";
echo "     Published Posts: $postCount\n";
echo "     Approved Comments: $commentCount\n";
echo "     Total Events: $eventCount\n";

echo "\nBlog system example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • Connection pooling allows organizing data across multiple databases\n";
echo "  • Easy switching between connections\n";
echo "  • Complex queries with JOINs work seamlessly\n";
echo "  • JSON metadata provides flexible post properties\n";

