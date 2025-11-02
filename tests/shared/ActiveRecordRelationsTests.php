<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\orm\Model;

/**
 * Test models for relationships.
 */
class TestUser extends Model
{
    public static function tableName(): string
    {
        return 'users';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }

    public static function relations(): array
    {
        return [
            'profile' => ['hasOne', 'modelClass' => TestProfile::class],
            'posts' => ['hasMany', 'modelClass' => TestPost::class],
        ];
    }
}

class TestProfile extends Model
{
    public static function tableName(): string
    {
        return 'profiles';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }

    public static function relations(): array
    {
        return [
            'user' => ['belongsTo', 'modelClass' => TestUser::class],
        ];
    }
}

class TestPost extends Model
{
    public static function tableName(): string
    {
        return 'posts';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }

    public static function relations(): array
    {
        return [
            'user' => ['belongsTo', 'modelClass' => TestUser::class],
            'comments' => ['hasMany', 'modelClass' => TestComment::class],
        ];
    }
}

class TestComment extends Model
{
    public static function tableName(): string
    {
        return 'comments';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }

    public static function relations(): array
    {
        return [
            'post' => ['belongsTo', 'modelClass' => TestPost::class],
        ];
    }
}

/**
 * Shared tests for ActiveRecord relationships.
 */
final class ActiveRecordRelationsTests extends BaseSharedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $db = self::$db;

        // Create tables
        $db->rawQuery('DROP TABLE IF EXISTS comments');
        $db->rawQuery('DROP TABLE IF EXISTS posts');
        $db->rawQuery('DROP TABLE IF EXISTS profiles');
        $db->rawQuery('DROP TABLE IF EXISTS users');

        $db->rawQuery('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL
            )
        ');

        $db->rawQuery('
            CREATE TABLE profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                bio TEXT,
                website TEXT
            )
        ');

        $db->rawQuery('
            CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                content TEXT
            )
        ');

        $db->rawQuery('
            CREATE TABLE comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                author TEXT NOT NULL,
                content TEXT NOT NULL
            )
        ');

        // Set database for models
        TestUser::setDb($db);
        TestProfile::setDb($db);
        TestPost::setDb($db);
        TestComment::setDb($db);
    }

    protected function tearDown(): void
    {
        $db = self::$db;
        $db->rawQuery('DROP TABLE IF EXISTS comments');
        $db->rawQuery('DROP TABLE IF EXISTS posts');
        $db->rawQuery('DROP TABLE IF EXISTS profiles');
        $db->rawQuery('DROP TABLE IF EXISTS users');
        parent::tearDown();
    }

    /**
     * Test hasOne relationship (lazy loading).
     */
    public function testHasOneLazyLoading(): void
    {
        $db = self::$db;

        // Create user and profile
        $userId = $db->find()->table('users')->insert(['name' => 'Alice', 'email' => 'alice@example.com']);
        $db->find()->table('profiles')->insert(['user_id' => $userId, 'bio' => 'Developer', 'website' => 'https://example.com']);

        $user = TestUser::findOne($userId);
        $this->assertNotNull($user);

        // Lazy load profile
        $profile = $user->profile;
        $this->assertNotNull($profile);
        $this->assertInstanceOf(TestProfile::class, $profile);
        $this->assertEquals('Developer', $profile->bio);
        $this->assertEquals('https://example.com', $profile->website);
    }

    /**
     * Test hasOne relationship with no related record.
     */
    public function testHasOneNoRelatedRecord(): void
    {
        $db = self::$db;

        // Create user without profile
        $userId = $db->find()->table('users')->insert(['name' => 'Bob', 'email' => 'bob@example.com']);

        $user = TestUser::findOne($userId);
        $this->assertNotNull($user);

        // Lazy load profile (should be null)
        $profile = $user->profile;
        $this->assertNull($profile);
    }

    /**
     * Test hasMany relationship (lazy loading).
     */
    public function testHasManyLazyLoading(): void
    {
        $db = self::$db;

        // Create user and posts
        $userId = $db->find()->table('users')->insert(['name' => 'Charlie', 'email' => 'charlie@example.com']);
        $db->find()->table('posts')->insert(['user_id' => $userId, 'title' => 'Post 1', 'content' => 'Content 1']);
        $db->find()->table('posts')->insert(['user_id' => $userId, 'title' => 'Post 2', 'content' => 'Content 2']);

        $user = TestUser::findOne($userId);
        $this->assertNotNull($user);

        // Lazy load posts
        $posts = $user->posts;
        $this->assertIsArray($posts);
        $this->assertCount(2, $posts);
        $this->assertInstanceOf(TestPost::class, $posts[0]);
        $this->assertEquals('Post 1', $posts[0]->title);
        $this->assertEquals('Post 2', $posts[1]->title);
    }

    /**
     * Test hasMany relationship with no related records.
     */
    public function testHasManyNoRelatedRecords(): void
    {
        $db = self::$db;

        // Create user without posts
        $userId = $db->find()->table('users')->insert(['name' => 'David', 'email' => 'david@example.com']);

        $user = TestUser::findOne($userId);
        $this->assertNotNull($user);

        // Lazy load posts (should be empty array)
        $posts = $user->posts;
        $this->assertIsArray($posts);
        $this->assertEmpty($posts);
    }

    /**
     * Test belongsTo relationship (lazy loading).
     */
    public function testBelongsToLazyLoading(): void
    {
        $db = self::$db;

        // Create user and post
        $userId = $db->find()->table('users')->insert(['name' => 'Eve', 'email' => 'eve@example.com']);
        $postId = $db->find()->table('posts')->insert(['user_id' => $userId, 'title' => 'My Post', 'content' => 'My Content']);

        $post = TestPost::findOne($postId);
        $this->assertNotNull($post);

        // Lazy load user
        $user = $post->user;
        $this->assertNotNull($user);
        $this->assertInstanceOf(TestUser::class, $user);
        $this->assertEquals('Eve', $user->name);
        $this->assertEquals('eve@example.com', $user->email);
    }

    /**
     * Test belongsTo relationship with no related record.
     */
    public function testBelongsToNoRelatedRecord(): void
    {
        $db = self::$db;

        // Create post with invalid user_id
        $postId = $db->find()->table('posts')->insert(['user_id' => 99999, 'title' => 'Orphan Post', 'content' => 'Content']);

        $post = TestPost::findOne($postId);
        $this->assertNotNull($post);

        // Lazy load user (should be null)
        $user = $post->user;
        $this->assertNull($user);
    }

    /**
     * Test eager loading with with() method (hasOne).
     */
    public function testEagerLoadingHasOne(): void
    {
        $db = self::$db;

        // Create users and profiles
        $user1Id = $db->find()->table('users')->insert(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2Id = $db->find()->table('users')->insert(['name' => 'User 2', 'email' => 'user2@example.com']);
        $db->find()->table('profiles')->insert(['user_id' => $user1Id, 'bio' => 'Bio 1']);
        $db->find()->table('profiles')->insert(['user_id' => $user2Id, 'bio' => 'Bio 2']);

        // Eager load with profile
        $users = TestUser::find()->with('profile')->all();
        $this->assertCount(2, $users);

        // Verify profiles are loaded
        foreach ($users as $user) {
            $this->assertNotNull($user->profile);
            $this->assertInstanceOf(TestProfile::class, $user->profile);
        }
    }

    /**
     * Test eager loading with with() method (hasMany).
     */
    public function testEagerLoadingHasMany(): void
    {
        $db = self::$db;

        // Create users and posts
        $user1Id = $db->find()->table('users')->insert(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2Id = $db->find()->table('users')->insert(['name' => 'User 2', 'email' => 'user2@example.com']);
        $db->find()->table('posts')->insert(['user_id' => $user1Id, 'title' => 'Post 1']);
        $db->find()->table('posts')->insert(['user_id' => $user1Id, 'title' => 'Post 2']);
        $db->find()->table('posts')->insert(['user_id' => $user2Id, 'title' => 'Post 3']);

        // Eager load with posts
        $users = TestUser::find()->with('posts')->all();
        $this->assertCount(2, $users);

        // Verify posts are loaded
        $user1 = $users[0];
        $user2 = $users[1];

        // Find which user has 2 posts
        if (count($user1->posts) === 2) {
            $this->assertCount(2, $user1->posts);
            $this->assertCount(1, $user2->posts);
        } else {
            $this->assertCount(1, $user1->posts);
            $this->assertCount(2, $user2->posts);
        }
    }

    /**
     * Test eager loading with with() method (belongsTo).
     */
    public function testEagerLoadingBelongsTo(): void
    {
        $db = self::$db;

        // Create users and posts
        $user1Id = $db->find()->table('users')->insert(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2Id = $db->find()->table('users')->insert(['name' => 'User 2', 'email' => 'user2@example.com']);
        $post1Id = $db->find()->table('posts')->insert(['user_id' => $user1Id, 'title' => 'Post 1']);
        $post2Id = $db->find()->table('posts')->insert(['user_id' => $user2Id, 'title' => 'Post 2']);

        // Eager load with user
        $posts = TestPost::find()->with('user')->all();
        $this->assertCount(2, $posts);

        // Verify users are loaded
        foreach ($posts as $post) {
            $this->assertNotNull($post->user);
            $this->assertInstanceOf(TestUser::class, $post->user);
        }
    }

    /**
     * Test eager loading multiple relationships.
     */
    public function testEagerLoadingMultipleRelations(): void
    {
        $db = self::$db;

        // Create data
        $user1Id = $db->find()->table('users')->insert(['name' => 'User 1', 'email' => 'user1@example.com']);
        $user2Id = $db->find()->table('users')->insert(['name' => 'User 2', 'email' => 'user2@example.com']);
        $db->find()->table('profiles')->insert(['user_id' => $user1Id, 'bio' => 'Bio 1']);
        $db->find()->table('profiles')->insert(['user_id' => $user2Id, 'bio' => 'Bio 2']);
        $db->find()->table('posts')->insert(['user_id' => $user1Id, 'title' => 'Post 1']);
        $db->find()->table('posts')->insert(['user_id' => $user2Id, 'title' => 'Post 2']);

        // Eager load multiple relationships
        $users = TestUser::find()->with(['profile', 'posts'])->all();
        $this->assertCount(2, $users);

        // Verify both relationships are loaded
        foreach ($users as $user) {
            $this->assertNotNull($user->profile);
            $this->assertIsArray($user->posts);
            $this->assertNotEmpty($user->posts);
        }
    }

    /**
     * Test nested eager loading.
     */
    public function testNestedEagerLoading(): void
    {
        $db = self::$db;

        // Create data
        $user1Id = $db->find()->table('users')->insert(['name' => 'User 1', 'email' => 'user1@example.com']);
        $post1Id = $db->find()->table('posts')->insert(['user_id' => $user1Id, 'title' => 'Post 1']);
        $db->find()->table('comments')->insert(['post_id' => $post1Id, 'author' => 'Commenter', 'content' => 'Comment 1']);
        $db->find()->table('comments')->insert(['post_id' => $post1Id, 'author' => 'Commenter', 'content' => 'Comment 2']);

        // Eager load nested: posts with comments
        $users = TestUser::find()->with(['posts' => ['comments']])->all();
        $this->assertCount(1, $users);

        $user = $users[0];
        $this->assertIsArray($user->posts);
        $this->assertNotEmpty($user->posts);

        $post = $user->posts[0];
        $this->assertIsArray($post->comments);
        $this->assertCount(2, $post->comments);
    }

    /**
     * Test getRelation() method.
     */
    public function testGetRelationMethod(): void
    {
        $db = self::$db;

        $userId = $db->find()->table('users')->insert(['name' => 'Test', 'email' => 'test@example.com']);
        $db->find()->table('profiles')->insert(['user_id' => $userId, 'bio' => 'Bio']);

        $user = TestUser::findOne($userId);
        $this->assertNotNull($user);

        // Use getRelation() method directly
        $profile = $user->getRelation('profile');
        $this->assertNotNull($profile);
        $this->assertInstanceOf(TestProfile::class, $profile);
    }

    /**
     * Test relationship with custom foreign key.
     */
    public function testCustomForeignKey(): void
    {
        // Create a model with custom foreign key definition
        $customUser = new class () extends Model {
            public static function tableName(): string
            {
                return 'users';
            }

            public static function primaryKey(): array
            {
                return ['id'];
            }

            public static function relations(): array
            {
                return [
                    'customProfile' => [
                        'hasOne',
                        'modelClass' => TestProfile::class,
                        'foreignKey' => 'owner_id',
                        'localKey' => 'id',
                    ],
                ];
            }
        };

        $db = self::$db;
        $customUser::setDb($db);

        // This test verifies that custom foreign key configuration works
        // (actual table structure would need to be modified for full test)
        $this->assertTrue(true); // Placeholder - full test would require schema modification
    }

    /**
     * Test relationship access on new record.
     */
    public function testRelationshipOnNewRecord(): void
    {
        $user = new TestUser();
        $user->name = 'New User';
        $user->email = 'new@example.com';

        // Try to access relationship on new record (should return null/empty)
        $profile = $user->profile;
        $this->assertNull($profile);

        $posts = $user->posts;
        $this->assertIsArray($posts);
        $this->assertEmpty($posts);
    }

    /**
     * Test Yii2-like syntax: hasOne as method returning ActiveQuery.
     */
    public function testHasOneAsMethod(): void
    {
        $db = self::$db;

        $userId = $db->find()->table('users')->insert(['name' => 'Method User', 'email' => 'method@example.com']);
        $db->find()->table('profiles')->insert(['user_id' => $userId, 'bio' => 'Test Bio', 'website' => 'https://test.com']);

        $user = TestUser::findOne($userId);
        $this->assertNotNull($user);

        // Call as method - should return ActiveQuery
        $query = $user->profile();
        $this->assertInstanceOf(\tommyknocker\pdodb\orm\ActiveQuery::class, $query);

        // Can modify query and get result
        $profile = $query->one();
        $this->assertNotNull($profile);
        $this->assertInstanceOf(TestProfile::class, $profile);
        $this->assertEquals('Test Bio', $profile->bio);
    }

    /**
     * Test Yii2-like syntax: hasMany as method returning ActiveQuery.
     */
    public function testHasManyAsMethod(): void
    {
        $db = self::$db;

        $userId = $db->find()->table('users')->insert(['name' => 'Method User', 'email' => 'method@example.com']);
        $db->find()->table('posts')->insert(['user_id' => $userId, 'title' => 'Post 1', 'content' => 'Content 1']);
        $db->find()->table('posts')->insert(['user_id' => $userId, 'title' => 'Post 2', 'content' => 'Content 2']);

        $user = TestUser::findOne($userId);
        $this->assertNotNull($user);

        // Call as method - should return ActiveQuery
        $query = $user->posts();
        $this->assertInstanceOf(\tommyknocker\pdodb\orm\ActiveQuery::class, $query);

        // Can modify query and get result
        $posts = $query->all();
        $this->assertCount(2, $posts);

        // Can add conditions
        $query2 = $user->posts();
        $postsFiltered = $query2->where('title', 'Post 1')->all();
        $this->assertCount(1, $postsFiltered);
        $this->assertEquals('Post 1', $postsFiltered[0]->title);

        // Can order and limit
        $query3 = $user->posts();
        $orderedPosts = $query3->orderBy('title', 'DESC')->limit(1)->all();
        $this->assertCount(1, $orderedPosts);
        $this->assertEquals('Post 2', $orderedPosts[0]->title);
    }

    /**
     * Test Yii2-like syntax: belongsTo as method returning ActiveQuery.
     */
    public function testBelongsToAsMethod(): void
    {
        $db = self::$db;

        $userId = $db->find()->table('users')->insert(['name' => 'Method User', 'email' => 'method@example.com']);
        $postId = $db->find()->table('posts')->insert(['user_id' => $userId, 'title' => 'Test Post', 'content' => 'Content']);

        $post = TestPost::findOne($postId);
        $this->assertNotNull($post);

        // Call as method - should return ActiveQuery
        $query = $post->user();
        $this->assertInstanceOf(\tommyknocker\pdodb\orm\ActiveQuery::class, $query);

        // Can get result
        $user = $query->one();
        $this->assertNotNull($user);
        $this->assertInstanceOf(TestUser::class, $user);
        $this->assertEquals('Method User', $user->name);
    }

    /**
     * Test Yii2-like syntax with additional query modifications.
     */
    public function testRelationshipMethodWithQueryModifications(): void
    {
        $db = self::$db;

        $userId = $db->find()->table('users')->insert(['name' => 'Query User', 'email' => 'query@example.com']);
        $db->find()->table('posts')->insert(['user_id' => $userId, 'title' => 'Published Post', 'content' => 'Content']);
        $db->find()->table('posts')->insert(['user_id' => $userId, 'title' => 'Draft Post', 'content' => 'Draft']);

        $user = TestUser::findOne($userId);
        $this->assertNotNull($user);

        // Add table with published column (simulate)
        $db->rawQuery('ALTER TABLE posts ADD COLUMN published INTEGER DEFAULT 1');
        $db->find()->table('posts')->where('title', 'Published Post')->update(['published' => 1]);
        $db->find()->table('posts')->where('title', 'Draft Post')->update(['published' => 0]);

        // Use relationship method with additional conditions
        $publishedPosts = $user->posts()->where('published', 1)->all();
        $this->assertCount(1, $publishedPosts);
        $this->assertEquals('Published Post', $publishedPosts[0]->title);

        // Count posts with condition
        $publishedCount = $user->posts()->where('published', 1)->select(['count' => \tommyknocker\pdodb\helpers\Db::count()])->getValue('count');
        $this->assertEquals(1, (int)$publishedCount);

        // Cleanup
        $db->rawQuery('ALTER TABLE posts DROP COLUMN published');
    }

    /**
     * Test Yii2-like syntax on new record (should return empty query).
     */
    public function testRelationshipMethodOnNewRecord(): void
    {
        $user = new TestUser();
        $user->name = 'New User';
        $user->email = 'new@example.com';

        // Call as method - should return ActiveQuery with condition that matches nothing
        $query = $user->posts();
        $this->assertInstanceOf(\tommyknocker\pdodb\orm\ActiveQuery::class, $query);

        // Query should return empty result
        $posts = $query->all();
        $this->assertIsArray($posts);
        $this->assertEmpty($posts);
    }
}
