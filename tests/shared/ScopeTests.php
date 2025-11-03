<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use RuntimeException;
use tommyknocker\pdodb\orm\Model;

/**
 * Test models for scopes.
 */
class TestPostWithScopes extends Model
{
    public static function tableName(): string
    {
        return 'posts';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }

    public static function globalScopes(): array
    {
        return [
            'notDeleted' => function ($query) {
                $query->whereRaw('deleted_at IS NULL');
                return $query;
            },
        ];
    }

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

class TestUserWithGlobalScopes extends Model
{
    public static function tableName(): string
    {
        return 'users';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }

    public static function globalScopes(): array
    {
        return [
            'active' => function ($query) {
                $query->where('is_active', 1);
                return $query;
            },
        ];
    }

    public static function scopes(): array
    {
        return [
            'verified' => function ($query) {
                $query->whereRaw('email_verified_at IS NOT NULL');
                return $query;
            },
        ];
    }
}

/**
 * Shared tests for Query Scopes.
 */
final class ScopeTests extends BaseSharedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $db = self::$db;

        // Clear scopes from previous tests
        $scopes = $db->getScopes();
        foreach (array_keys($scopes) as $scopeName) {
            $db->removeScope($scopeName);
        }

        // Create tables
        $db->rawQuery('DROP TABLE IF EXISTS posts');
        $db->rawQuery('DROP TABLE IF EXISTS users');

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
                is_active INTEGER DEFAULT 1,
                email_verified_at TEXT
            )
        ');

        // Set database for models
        TestPostWithScopes::setDb($db);
        TestUserWithGlobalScopes::setDb($db);
    }

    protected function tearDown(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS posts');
        $db->rawQuery('DROP TABLE IF EXISTS users');
        parent::tearDown();
    }

    /**
     * Test basic scope usage with callable.
     */
    public function testScopeWithCallable(): void
    {
        $db = self::$db;

        // Insert test data
        $db->find()->table('posts')->insert([
            'title' => 'Test Post',
            'status' => 'published',
            'view_count' => 1500,
        ]);

        $db->find()->table('posts')->insert([
            'title' => 'Draft Post',
            'status' => 'draft',
            'view_count' => 100,
        ]);

        // Apply scope using callable
        $result = $db->find()
            ->from('posts')
            ->scope(function ($query) {
                return $query->where('status', 'published');
            })
            ->get();

        $this->assertCount(1, $result);
        $this->assertEquals('Test Post', $result[0]['title']);
    }

    /**
     * Test local scope (named scope from model).
     */
    public function testLocalScope(): void
    {
        $db = self::$db;

        $db->find()->table('posts')->insert([
            'title' => 'Published Post',
            'status' => 'published',
        ]);

        $db->find()->table('posts')->insert([
            'title' => 'Draft Post',
            'status' => 'draft',
        ]);

        // Use named local scope
        $posts = TestPostWithScopes::find()->scope('published')->all();
        $this->assertCount(1, $posts);
        $this->assertEquals('Published Post', $posts[0]->title);
    }

    /**
     * Test chaining local scopes.
     */
    public function testChainingLocalScopes(): void
    {
        $db = self::$db;

        $db->find()->table('posts')->insert([
            'title' => 'Popular Published',
            'status' => 'published',
            'view_count' => 2000,
        ]);

        $db->find()->table('posts')->insert([
            'title' => 'Draft Post',
            'status' => 'draft',
            'view_count' => 100,
        ]);

        $db->find()->table('posts')->insert([
            'title' => 'Published Not Popular',
            'status' => 'published',
            'view_count' => 500,
        ]);

        // Chain multiple scopes
        $posts = TestPostWithScopes::find()
            ->scope('published')
            ->scope('popular')
            ->all();

        $this->assertCount(1, $posts);
        $this->assertEquals('Popular Published', $posts[0]->title);
    }

    /**
     * Test scope with parameters.
     */
    public function testScopeWithParameters(): void
    {
        $db = self::$db;

        $now = time();
        $db->find()->table('posts')->insert([
            'title' => 'Old Post',
            'status' => 'published',
            'created_at' => date('Y-m-d H:i:s', $now - 10 * 24 * 3600), // 10 days ago
        ]);

        $db->find()->table('posts')->insert([
            'title' => 'New Post',
            'status' => 'published',
            'created_at' => date('Y-m-d H:i:s', $now - 2 * 24 * 3600), // 2 days ago
        ]);

        // Use scope with parameter
        $posts = TestPostWithScopes::find()
            ->scope('published')
            ->scope('recent', 5) // last 5 days
            ->all();

        $this->assertCount(1, $posts);
        $this->assertEquals('New Post', $posts[0]->title);
    }

    /**
     * Test global scope automatically applied.
     */
    public function testGlobalScopeAutomaticApplication(): void
    {
        $db = self::$db;

        $db->find()->table('posts')->insert([
            'title' => 'Active Post',
            'status' => 'published',
            'deleted_at' => null,
        ]);

        $db->find()->table('posts')->insert([
            'title' => 'Deleted Post',
            'status' => 'published',
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);

        // Global scope should automatically filter out deleted posts
        $posts = TestPostWithScopes::find()->all();
        $this->assertCount(1, $posts);
        $this->assertEquals('Active Post', $posts[0]->title);
    }

    /**
     * Test disabling global scope.
     */
    public function testDisableGlobalScope(): void
    {
        $db = self::$db;

        $db->find()->table('posts')->insert([
            'title' => 'Active Post',
            'status' => 'published',
            'deleted_at' => null,
        ]);

        $db->find()->table('posts')->insert([
            'title' => 'Deleted Post',
            'status' => 'published',
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);

        // Disable global scope to get all posts including deleted
        $posts = TestPostWithScopes::find()
            ->withoutGlobalScope('notDeleted')
            ->all();

        $this->assertCount(2, $posts);
    }

    /**
     * Test disabling multiple global scopes.
     */
    public function testDisableMultipleGlobalScopes(): void
    {
        $db = self::$db;

        $db->find()->table('users')->insert([
            'name' => 'Active User',
            'email' => 'active@example.com',
            'is_active' => 1,
        ]);

        $db->find()->table('users')->insert([
            'name' => 'Inactive User',
            'email' => 'inactive@example.com',
            'is_active' => 0,
        ]);

        // Without disabling, only active users should be returned
        $activeUsers = TestUserWithGlobalScopes::find()->all();
        $this->assertCount(1, $activeUsers);

        // Disable global scope
        $allUsers = TestUserWithGlobalScopes::find()
            ->withoutGlobalScope('active')
            ->all();

        $this->assertCount(2, $allUsers);
    }

    /**
     * Test combining global and local scopes.
     */
    public function testCombiningGlobalAndLocalScopes(): void
    {
        $db = self::$db;

        $db->find()->table('posts')->insert([
            'title' => 'Published Active',
            'status' => 'published',
            'deleted_at' => null,
        ]);

        $db->find()->table('posts')->insert([
            'title' => 'Draft Active',
            'status' => 'draft',
            'deleted_at' => null,
        ]);

        $db->find()->table('posts')->insert([
            'title' => 'Published Deleted',
            'status' => 'published',
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);

        // Global scope filters deleted, local scope filters published
        $posts = TestPostWithScopes::find()
            ->scope('published')
            ->all();

        $this->assertCount(1, $posts);
        $this->assertEquals('Published Active', $posts[0]->title);
    }

    /**
     * Test scope with QueryBuilder directly.
     */
    public function testScopeWithQueryBuilder(): void
    {
        $db = self::$db;

        $db->find()->table('posts')->insert([
            'title' => 'Post 1',
            'status' => 'published',
            'author_id' => 1,
        ]);

        $db->find()->table('posts')->insert([
            'title' => 'Post 2',
            'status' => 'published',
            'author_id' => 2,
        ]);

        // Use scope directly with QueryBuilder
        $result = $db->find()
            ->from('posts')
            ->scope(function ($query, $authorId) {
                return $query->where('author_id', $authorId);
            }, 1)
            ->get();

        $this->assertCount(1, $result);
        $this->assertEquals('Post 1', $result[0]['title']);
    }

    /**
     * Test error when scope name not found.
     */
    public function testErrorOnUnknownScopeName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Scope 'unknown' not found");

        TestPostWithScopes::find()->scope('unknown')->all();
    }

    /**
     * Test scope() without arguments throws error.
     */
    public function testScopeWithoutArguments(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('scope() method requires at least one argument');

        TestPostWithScopes::find()->scope()->all();
    }

    /**
     * Test multiple scopes with different parameters.
     */
    public function testMultipleScopesWithDifferentParameters(): void
    {
        $db = self::$db;

        $authorId = 1;
        $db->find()->table('posts')->insert([
            'title' => 'Author 1 Popular',
            'status' => 'published',
            'author_id' => $authorId,
            'view_count' => 2000,
        ]);

        $db->find()->table('posts')->insert([
            'title' => 'Author 1 Not Popular',
            'status' => 'published',
            'author_id' => $authorId,
            'view_count' => 500,
        ]);

        $db->find()->table('posts')->insert([
            'title' => 'Author 2 Popular',
            'status' => 'published',
            'author_id' => 2,
            'view_count' => 2000,
        ]);

        // Chain scopes with parameters
        $posts = TestPostWithScopes::find()
            ->scope('published')
            ->scope('byAuthor', $authorId)
            ->scope('popular')
            ->all();

        $this->assertCount(1, $posts);
        $this->assertEquals('Author 1 Popular', $posts[0]->title);
    }

    /**
     * Test scopes in PdoDb (QueryBuilder level).
     */
    public function testGlobalScopesInPdoDb(): void
    {
        $db = self::$db;

        // Create table
        $db->rawQuery('DROP TABLE IF EXISTS test_global_scopes');
        $db->rawQuery('
            CREATE TABLE test_global_scopes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                is_active INTEGER DEFAULT 1,
                deleted_at TEXT
            )
        ');

        // Insert test data
        $db->find()->table('test_global_scopes')->insert([
            'name' => 'Active Item',
            'is_active' => 1,
            'deleted_at' => null,
        ]);

        $db->find()->table('test_global_scopes')->insert([
            'name' => 'Inactive Item',
            'is_active' => 0,
            'deleted_at' => null,
        ]);

        $db->find()->table('test_global_scopes')->insert([
            'name' => 'Deleted Item',
            'is_active' => 1,
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);

        // Add scopes
        $db->addScope('active', function ($query) {
            return $query->where('is_active', 1);
        });

        $db->addScope('notDeleted', function ($query) {
            return $query->whereRaw('deleted_at IS NULL');
        });

        // Scopes should be applied automatically
        $items = $db->find()->from('test_global_scopes')->get();
        $this->assertCount(1, $items);
        $this->assertEquals('Active Item', $items[0]['name']);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS test_global_scopes');
    }

    /**
     * Test disabling scope in QueryBuilder.
     */
    public function testDisableGlobalScopeInQueryBuilder(): void
    {
        $db = self::$db;

        // Create table
        $db->rawQuery('DROP TABLE IF EXISTS test_disable_scope');
        $db->rawQuery('
            CREATE TABLE test_disable_scope (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                is_active INTEGER DEFAULT 1
            )
        ');

        // Insert test data
        $db->find()->table('test_disable_scope')->insert(['name' => 'Active', 'is_active' => 1]);
        $db->find()->table('test_disable_scope')->insert(['name' => 'Inactive', 'is_active' => 0]);

        // Add scope
        $db->addScope('active', function ($query) {
            return $query->where('is_active', 1);
        });

        // Without disabling - only active items
        $activeItems = $db->find()->from('test_disable_scope')->get();
        $this->assertCount(1, $activeItems);

        // Disable scope - get all items
        $allItems = $db->find()
            ->from('test_disable_scope')
            ->withoutGlobalScope('active')
            ->get();
        $this->assertCount(2, $allItems);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS test_disable_scope');
    }

    /**
     * Test removing scope from PdoDb.
     */
    public function testRemoveGlobalScopeFromPdoDb(): void
    {
        $db = self::$db;

        // Create table
        $db->rawQuery('DROP TABLE IF EXISTS test_remove_scope');
        $db->rawQuery('
            CREATE TABLE test_remove_scope (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                is_active INTEGER DEFAULT 1
            )
        ');

        // Insert test data
        $db->find()->table('test_remove_scope')->insert(['name' => 'Active', 'is_active' => 1]);
        $db->find()->table('test_remove_scope')->insert(['name' => 'Inactive', 'is_active' => 0]);

        // Add scope
        $db->addScope('active', function ($query) {
            return $query->where('is_active', 1);
        });

        // Verify scope is applied
        $items = $db->find()->from('test_remove_scope')->get();
        $this->assertCount(1, $items);

        // Remove scope
        $db->removeScope('active');

        // Verify scope is no longer applied
        $allItems = $db->find()->from('test_remove_scope')->get();
        $this->assertCount(2, $allItems);

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS test_remove_scope');
    }

    /**
     * Test multiple scopes in PdoDb.
     */
    public function testMultipleGlobalScopesInPdoDb(): void
    {
        $db = self::$db;

        // Create table
        $db->rawQuery('DROP TABLE IF EXISTS test_multiple_scopes');
        $db->rawQuery('
            CREATE TABLE test_multiple_scopes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                status TEXT DEFAULT \'pending\',
                is_active INTEGER DEFAULT 1
            )
        ');

        // Insert test data
        $db->find()->table('test_multiple_scopes')->insert([
            'name' => 'Active Approved',
            'status' => 'approved',
            'is_active' => 1,
        ]);
        $db->find()->table('test_multiple_scopes')->insert([
            'name' => 'Active Pending',
            'status' => 'pending',
            'is_active' => 1,
        ]);
        $db->find()->table('test_multiple_scopes')->insert([
            'name' => 'Inactive Approved',
            'status' => 'approved',
            'is_active' => 0,
        ]);

        // Add multiple scopes
        $db->addScope('active', function ($query) {
            return $query->where('is_active', 1);
        });

        $db->addScope('approved', function ($query) {
            return $query->where('status', 'approved');
        });

        // Both scopes should be applied
        $items = $db->find()->from('test_multiple_scopes')->get();
        $this->assertCount(1, $items);
        $this->assertEquals('Active Approved', $items[0]['name']);

        // Disable one scope
        $items = $db->find()
            ->from('test_multiple_scopes')
            ->withoutGlobalScope('approved')
            ->get();
        $this->assertCount(2, $items); // Active items only

        // Cleanup
        $db->rawQuery('DROP TABLE IF EXISTS test_multiple_scopes');
    }
}
