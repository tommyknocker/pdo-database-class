<?php
/**
 * Example: Query Scopes.
 *
 * Demonstrates global and local scopes for query building.
 *
 * Usage:
 *   php examples/28-query-scopes/01-scopes-examples.php
 *   PDODB_DRIVER=mysql php examples/28-query-scopes/01-scopes-examples.php
 *   PDODB_DRIVER=pgsql php examples/28-query-scopes/01-scopes-examples.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\orm\Model;

// Define models with scopes
class Post extends Model
{
    public static function tableName(): string
    {
        return 'posts';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }

    /**
     * Global scopes are automatically applied to all queries.
     */
    public static function globalScopes(): array
    {
        return [
            'notDeleted' => function ($query) {
                $query->whereRaw('deleted_at IS NULL');
                return $query;
            },
        ];
    }

    /**
     * Local scopes are applied only when explicitly called.
     */
    public static function scopes(): array
    {
        return [
            'published' => function ($query) {
                $query->where('status', 'published');
                return $query;
            },
            'draft' => function ($query) {
                $query->where('status', 'draft');
                return $query;
            },
            'popular' => function ($query) {
                $query->where('view_count', 1000, '>');
                return $query;
            },
            'recent' => function ($query, $days = 7) {
                $date = date('Y-m-d H:i:s', strtotime("-$days days"));
                $query->where('created_at', $date, '>=');
                return $query;
            },
            'byAuthor' => function ($query, $authorId) {
                $query->where('author_id', $authorId);
                return $query;
            },
        ];
    }
}

class User extends Model
{
    public static function tableName(): string
    {
        return 'users';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }

    /**
     * Global scope: only active users.
     */
    public static function globalScopes(): array
    {
        return [
            'active' => function ($query) {
                $query->where('is_active', 1);
                return $query;
            },
        ];
    }

    /**
     * Local scopes for user queries.
     */
    public static function scopes(): array
    {
        return [
            'verified' => function ($query) {
                $query->whereRaw('email_verified_at IS NOT NULL');
                return $query;
            },
            'withRole' => function ($query, $role) {
                $query->where('role', $role);
                return $query;
            },
        ];
    }
}

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Query Scopes Example (on $driver) ===\n\n";

// Create tables
$db->schema()->dropTableIfExists('posts');
$db->schema()->dropTableIfExists('users');

// Create tables using dialect-specific SQL
$driver = getCurrentDriver($db);

$db->rawQuery('DROP TABLE IF EXISTS posts');
$db->rawQuery('DROP TABLE IF EXISTS users');

if ($driver === 'sqlite') {
    $db->rawQuery('
        CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            status TEXT DEFAULT \'draft\',
            author_id INTEGER,
            view_count INTEGER DEFAULT 0,
            deleted_at TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $db->rawQuery('
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            role TEXT DEFAULT \'user\',
            is_active INTEGER DEFAULT 1,
            email_verified_at TEXT
        )
    ');
} elseif ($driver === 'pgsql') {
    $db->rawQuery('
        CREATE TABLE posts (
            id SERIAL PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            status VARCHAR(50) DEFAULT \'draft\',
            author_id INTEGER,
            view_count INTEGER DEFAULT 0,
            deleted_at TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $db->rawQuery('
        CREATE TABLE users (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            role VARCHAR(50) DEFAULT \'user\',
            is_active INTEGER DEFAULT 1,
            email_verified_at TIMESTAMP
        )
    ');
} elseif ($driver === 'sqlsrv') {
    $db->rawQuery('
        CREATE TABLE posts (
            id INT IDENTITY(1,1) PRIMARY KEY,
            title NVARCHAR(255) NOT NULL,
            status NVARCHAR(50) DEFAULT \'draft\',
            author_id INT,
            view_count INT DEFAULT 0,
            deleted_at DATETIME NULL,
            created_at DATETIME DEFAULT GETDATE()
        )
    ');

    $db->rawQuery('
        CREATE TABLE users (
            id INT IDENTITY(1,1) PRIMARY KEY,
            name NVARCHAR(255) NOT NULL,
            email NVARCHAR(255) NOT NULL,
            role NVARCHAR(50) DEFAULT \'user\',
            is_active INT DEFAULT 1,
            email_verified_at DATETIME NULL
        )
    ');
} else {
    // MySQL/MariaDB
    $db->rawQuery('
        CREATE TABLE posts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            status VARCHAR(50) DEFAULT \'draft\',
            author_id INT,
            view_count INT DEFAULT 0,
            deleted_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $db->rawQuery('
        CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            role VARCHAR(50) DEFAULT \'user\',
            is_active INT DEFAULT 1,
            email_verified_at TIMESTAMP NULL
        )
    ');
}

Post::setDb($db);
User::setDb($db);

echo "✓ Tables created\n\n";

// Example 1: Local Scopes
echo "1. Local Scopes (Applied On-Demand)\n";
echo "-------------------------------------\n";

// Insert test data
$postId1 = $db->find()->table('posts')->insert([
    'title' => 'Published Post 1',
    'status' => 'published',
    'view_count' => 1500,
    'author_id' => 1,
]);

$postId2 = $db->find()->table('posts')->insert([
    'title' => 'Draft Post',
    'status' => 'draft',
    'view_count' => 100,
    'author_id' => 1,
]);

$postId3 = $db->find()->table('posts')->insert([
    'title' => 'Published Post 2',
    'status' => 'published',
    'view_count' => 500,
    'author_id' => 2,
]);

// Use local scope
$publishedPosts = Post::find()->scope('published')->all();
echo "Published posts: " . count($publishedPosts) . "\n";
foreach ($publishedPosts as $post) {
    echo "  - {$post->title}\n";
}

// Chain multiple scopes
$popularPublished = Post::find()
    ->scope('published')
    ->scope('popular')
    ->all();
echo "\nPopular published posts: " . count($popularPublished) . "\n";
foreach ($popularPublished as $post) {
    echo "  - {$post->title} (views: {$post->view_count})\n";
}

echo "\n";

// Example 2: Scope with Parameters
echo "2. Scope with Parameters\n";
echo "-------------------------\n";

$now = time();
$db->find()->table('posts')->insert([
    'title' => 'Recent Post',
    'status' => 'published',
    'created_at' => date('Y-m-d H:i:s', $now - 2 * 24 * 3600), // 2 days ago
]);

$db->find()->table('posts')->insert([
    'title' => 'Old Post',
    'status' => 'published',
    'created_at' => date('Y-m-d H:i:s', $now - 10 * 24 * 3600), // 10 days ago
]);

// Use scope with parameter
$recentPosts = Post::find()
    ->scope('published')
    ->scope('recent', 5) // last 5 days
    ->all();

echo "Recent posts (last 5 days): " . count($recentPosts) . "\n";
foreach ($recentPosts as $post) {
    echo "  - {$post->title}\n";
}

echo "\n";

// Example 3: Global Scopes (Automatic)
echo "3. Global Scopes (Automatically Applied)\n";
echo "-----------------------------------------\n";

$db->find()->table('posts')->insert([
    'title' => 'Deleted Post',
    'status' => 'published',
    'deleted_at' => date('Y-m-d H:i:s'),
]);

// Global scope automatically filters out deleted posts
$allActivePosts = Post::find()->all();
echo "Active posts (excluding deleted): " . count($allActivePosts) . "\n";
foreach ($allActivePosts as $post) {
    if (isset($post->deleted_at) && $post->deleted_at !== null) {
        echo "  ERROR: Deleted post found!\n";
    } else {
        echo "  - {$post->title}\n";
    }
}

echo "\n";

// Example 4: Disable Global Scope
echo "4. Disable Global Scope\n";
echo "------------------------\n";

// Temporarily disable global scope to get all posts including deleted
$allPostsIncludingDeleted = Post::find()
    ->withoutGlobalScope('notDeleted')
    ->all();

echo "All posts (including deleted): " . count($allPostsIncludingDeleted) . "\n";
foreach ($allPostsIncludingDeleted as $post) {
    $deleted = isset($post->deleted_at) && $post->deleted_at !== null ? ' [DELETED]' : '';
    echo "  - {$post->title}{$deleted}\n";
}

echo "\n";

// Example 5: Combining Scopes
echo "5. Combining Global and Local Scopes\n";
echo "-------------------------------------\n";

// Insert users
$userId1 = $db->find()->table('users')->insert([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'role' => 'admin',
    'is_active' => 1,
    'email_verified_at' => date('Y-m-d H:i:s'),
]);

$userId2 = $db->find()->table('users')->insert([
    'name' => 'Jane Smith',
    'email' => 'jane@example.com',
    'role' => 'user',
    'is_active' => 1,
    'email_verified_at' => null,
]);

$userId3 = $db->find()->table('users')->insert([
    'name' => 'Inactive User',
    'email' => 'inactive@example.com',
    'role' => 'user',
    'is_active' => 0,
]);

// Global scope filters active users, local scope filters verified
$activeVerifiedUsers = User::find()
    ->scope('verified')
    ->all();

echo "Active and verified users: " . count($activeVerifiedUsers) . "\n";
foreach ($activeVerifiedUsers as $user) {
    echo "  - {$user->name} ({$user->email})\n";
}

echo "\n";

// Example 6: Scope with QueryBuilder Directly
echo "6. Scope with QueryBuilder (Without Model)\n";
echo "-------------------------------------------\n";

// Use scope directly with QueryBuilder
$result = $db->find()
    ->from('posts')
    ->scope(function ($query) {
        return $query->where('status', 'published')
                    ->andWhere('view_count', 1000, '>');
    })
    ->limit(5)
    ->get();

echo "Posts using direct QueryBuilder scope: " . count($result) . "\n";
foreach ($result as $row) {
    echo "  - {$row['title']} (views: {$row['view_count']})\n";
}

echo "\n";

// Example 7: Scopes at PdoDb Level
echo "7. Scopes at PdoDb Level (QueryBuilder)\n";
echo "-----------------------------------------------\n";

// Clear any existing scopes
$scopes = $db->getScopes();
foreach (array_keys($scopes) as $scopeName) {
    $db->removeScope($scopeName);
}

// Drop and recreate a simple table for this example
$db->rawQuery('DROP TABLE IF EXISTS items');
$driver = getCurrentDriver($db);
if ($driver === 'sqlite') {
    $db->rawQuery('
        CREATE TABLE items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            is_active INTEGER DEFAULT 1,
            tenant_id INTEGER
        )
    ');
} elseif ($driver === 'pgsql') {
    $db->rawQuery('
        CREATE TABLE items (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            is_active INTEGER DEFAULT 1,
            tenant_id INTEGER
        )
    ');
} elseif ($driver === 'sqlsrv') {
    $db->rawQuery('
        CREATE TABLE items (
            id INT IDENTITY(1,1) PRIMARY KEY,
            name NVARCHAR(255) NOT NULL,
            is_active INT DEFAULT 1,
            tenant_id INT
        )
    ');
} else {
    // MySQL/MariaDB
    $db->rawQuery('
        CREATE TABLE items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            is_active INT DEFAULT 1,
            tenant_id INT
        )
    ');
}

// Insert test data
$db->find()->table('items')->insert(['name' => 'Item 1', 'is_active' => 1, 'tenant_id' => 1]);
$db->find()->table('items')->insert(['name' => 'Item 2', 'is_active' => 0, 'tenant_id' => 1]);
$db->find()->table('items')->insert(['name' => 'Item 3', 'is_active' => 1, 'tenant_id' => 2]);

// Add scopes to PdoDb (applies to all queries)
$db->addScope('active', function ($query) {
    return $query->where('is_active', 1);
});

$db->addScope('tenant', function ($query) {
    $tenantId = 1; // Simulate current tenant
    return $query->where('tenant_id', $tenantId);
});

// All queries automatically apply scopes
$items = $db->find()->from('items')->get();
echo "Items with scopes: " . count($items) . "\n";
foreach ($items as $item) {
    echo "  - {$item['name']}\n";
}

// Temporarily disable a scope
$allActiveItems = $db->find()
    ->from('items')
    ->withoutGlobalScope('tenant')
    ->get();
echo "\nAll active items (tenant scope disabled): " . count($allActiveItems) . "\n";

// Remove scope completely
$db->removeScope('tenant');
$itemsAfterRemoval = $db->find()->from('items')->get();
echo "Items after removing tenant scope: " . count($itemsAfterRemoval) . "\n";

// Cleanup
echo "\nCleaning up...\n";
$db->rawQuery('DROP TABLE IF EXISTS items');
$db->schema()->dropTableIfExists('posts');
$db->schema()->dropTableIfExists('users');
echo "✓ Done\n";

