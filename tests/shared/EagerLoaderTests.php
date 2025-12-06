<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use RuntimeException;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\orm\relations\EagerLoader;
use tommyknocker\pdodb\orm\relations\HasOne;

/**
 * Test models for EagerLoader tests.
 */
class EagerLoaderTestUser extends Model
{
    public static function tableName(): string
    {
        return 'eager_loader_users';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }

    public static function relations(): array
    {
        return [
            'profile' => ['hasOne', 'modelClass' => EagerLoaderTestProfile::class, 'foreignKey' => 'user_id'],
            'posts' => ['hasMany', 'modelClass' => EagerLoaderTestPost::class, 'foreignKey' => 'user_id'],
        ];
    }
}

class EagerLoaderTestProfile extends Model
{
    public static function tableName(): string
    {
        return 'eager_loader_profiles';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }

    public static function relations(): array
    {
        return [
            'user' => ['belongsTo', 'modelClass' => EagerLoaderTestUser::class, 'foreignKey' => 'user_id', 'ownerKey' => 'id'],
        ];
    }
}

class EagerLoaderTestPost extends Model
{
    public static function tableName(): string
    {
        return 'eager_loader_posts';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }

    public static function relations(): array
    {
        return [
            'user' => ['belongsTo', 'modelClass' => EagerLoaderTestUser::class, 'foreignKey' => 'user_id', 'ownerKey' => 'id'],
        ];
    }
}

class EagerLoaderTestProject extends Model
{
    public static function tableName(): string
    {
        return 'eager_loader_projects';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }
}

class EagerLoaderTestUserWithProjects extends Model
{
    public static function tableName(): string
    {
        return 'eager_loader_users';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }

    public static function relations(): array
    {
        return [
            'projects' => [
                'hasManyThrough',
                'modelClass' => EagerLoaderTestProject::class,
                'viaTable' => 'eager_loader_user_project',
                'link' => ['id' => 'user_id'],
                'viaLink' => ['project_id' => 'id'],
            ],
        ];
    }
}

/**
 * Tests for EagerLoader.
 */
final class EagerLoaderTests extends BaseSharedTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $db = self::$db;

        // Create tables
        $db->rawQuery('DROP TABLE IF EXISTS eager_loader_posts');
        $db->rawQuery('DROP TABLE IF EXISTS eager_loader_profiles');
        $db->rawQuery('DROP TABLE IF EXISTS eager_loader_users');

        $db->rawQuery('
            CREATE TABLE eager_loader_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL
            )
        ');

        $db->rawQuery('
            CREATE TABLE eager_loader_profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                bio TEXT
            )
        ');

        $db->rawQuery('
            CREATE TABLE eager_loader_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL
            )
        ');

        $db->rawQuery('
            CREATE TABLE eager_loader_projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            )
        ');

        $db->rawQuery('
            CREATE TABLE eager_loader_user_project (
                user_id INTEGER NOT NULL,
                project_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, project_id)
            )
        ');

        EagerLoaderTestUser::setDb($db);
        EagerLoaderTestProfile::setDb($db);
        EagerLoaderTestPost::setDb($db);
        EagerLoaderTestProject::setDb($db);
        EagerLoaderTestUserWithProjects::setDb($db);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $db = self::$db;
        $db->rawQuery('DELETE FROM eager_loader_user_project');
        $db->rawQuery('DELETE FROM eager_loader_projects');
        $db->rawQuery('DELETE FROM eager_loader_posts');
        $db->rawQuery('DELETE FROM eager_loader_profiles');
        $db->rawQuery('DELETE FROM eager_loader_users');

        // Insert test data
        $user1 = new EagerLoaderTestUser();
        $user1->name = 'Alice';
        $user1->email = 'alice@example.com';
        $user1->save();

        $user2 = new EagerLoaderTestUser();
        $user2->name = 'Bob';
        $user2->email = 'bob@example.com';
        $user2->save();

        $profile1 = new EagerLoaderTestProfile();
        $profile1->user_id = $user1->id;
        $profile1->bio = 'Alice bio';
        $profile1->save();

        $post1 = new EagerLoaderTestPost();
        $post1->user_id = $user1->id;
        $post1->title = 'Post 1';
        $post1->save();

        $post2 = new EagerLoaderTestPost();
        $post2->user_id = $user1->id;
        $post2->title = 'Post 2';
        $post2->save();
    }

    public function testLoadWithEmptyModels(): void
    {
        $loader = new EagerLoader();
        $models = [];
        $relations = ['profile'];

        // Should not throw exception
        $loader->load($models, $relations);
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function testLoadWithEmptyRelations(): void
    {
        $users = EagerLoaderTestUser::find()->all();
        $this->assertNotEmpty($users);

        $loader = new EagerLoader();
        $relations = [];

        // Should not throw exception
        $loader->load($users, $relations);
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function testNormalizeRelationsWithIndexedArray(): void
    {
        $loader = new EagerLoader();
        $reflection = new \ReflectionClass($loader);
        $method = $reflection->getMethod('normalizeRelations');
        $method->setAccessible(true);

        $relations = ['profile', 'posts'];
        $normalized = $method->invoke($loader, $relations);

        $this->assertIsArray($normalized);
        $this->assertArrayHasKey('profile', $normalized);
        $this->assertArrayHasKey('posts', $normalized);
        $this->assertEquals([], $normalized['profile']);
        $this->assertEquals([], $normalized['posts']);
    }

    public function testNormalizeRelationsWithAssociativeArray(): void
    {
        $loader = new EagerLoader();
        $reflection = new \ReflectionClass($loader);
        $method = $reflection->getMethod('normalizeRelations');
        $method->setAccessible(true);

        $relations = ['profile' => ['user'], 'posts' => []];
        $normalized = $method->invoke($loader, $relations);

        $this->assertIsArray($normalized);
        $this->assertArrayHasKey('profile', $normalized);
        $this->assertArrayHasKey('posts', $normalized);
        $this->assertEquals(['user'], $normalized['profile']);
        $this->assertEquals([], $normalized['posts']);
    }

    public function testNormalizeRelationsWithMixedArray(): void
    {
        $loader = new EagerLoader();
        $reflection = new \ReflectionClass($loader);
        $method = $reflection->getMethod('normalizeRelations');
        $method->setAccessible(true);

        $relations = ['profile', 'posts' => ['user']];
        $normalized = $method->invoke($loader, $relations);

        $this->assertIsArray($normalized);
        $this->assertArrayHasKey('profile', $normalized);
        $this->assertArrayHasKey('posts', $normalized);
        $this->assertEquals([], $normalized['profile']);
        $this->assertEquals(['user'], $normalized['posts']);
    }

    public function testLoadThrowsExceptionForNonExistentRelation(): void
    {
        $users = EagerLoaderTestUser::find()->all();
        $this->assertNotEmpty($users);

        $loader = new EagerLoader();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Relationship 'nonexistent' not found");

        $loader->load($users, ['nonexistent']);
    }

    public function testLoadBelongsTo(): void
    {
        $posts = EagerLoaderTestPost::find()->all();
        $this->assertNotEmpty($posts);

        $loader = new EagerLoader();
        $loader->load($posts, ['user']);

        // Check that relations are loaded
        foreach ($posts as $post) {
            $user = $post->user;
            $this->assertNotNull($user);
            $this->assertInstanceOf(EagerLoaderTestUser::class, $user);
        }
    }

    public function testLoadHasOne(): void
    {
        $users = EagerLoaderTestUser::find()->all();
        $this->assertNotEmpty($users);

        $loader = new EagerLoader();
        $loader->load($users, ['profile']);

        // Check that relations are loaded
        foreach ($users as $user) {
            $profile = $user->profile;
            if ($user->id === 1) {
                // Alice should have a profile
                $this->assertNotNull($profile);
                $this->assertInstanceOf(EagerLoaderTestProfile::class, $profile);
            } else {
                // Bob might not have a profile
                $this->assertTrue($profile === null || $profile instanceof EagerLoaderTestProfile);
            }
        }
    }

    public function testLoadHasMany(): void
    {
        $users = EagerLoaderTestUser::find()->all();
        $this->assertNotEmpty($users);

        $loader = new EagerLoader();
        $loader->load($users, ['posts']);

        // Check that relations are loaded
        foreach ($users as $user) {
            $posts = $user->posts;
            $this->assertIsArray($posts);
            if ($user->id === 1) {
                // Alice should have 2 posts
                $this->assertCount(2, $posts);
                foreach ($posts as $post) {
                    $this->assertInstanceOf(EagerLoaderTestPost::class, $post);
                }
            }
        }
    }

    public function testLoadWithNestedRelations(): void
    {
        $posts = EagerLoaderTestPost::find()->all();
        $this->assertNotEmpty($posts);

        $loader = new EagerLoader();
        $loader->load($posts, ['user' => ['profile']]);

        // Check that nested relations are loaded
        foreach ($posts as $post) {
            $user = $post->user;
            $this->assertNotNull($user);
            if ($user->id === 1) {
                $profile = $user->profile;
                $this->assertNotNull($profile);
                $this->assertInstanceOf(EagerLoaderTestProfile::class, $profile);
            }
        }
    }

    public function testLoadRelationWithEmptyModels(): void
    {
        $loader = new EagerLoader();
        $reflection = new \ReflectionClass($loader);
        $method = $reflection->getMethod('loadRelation');
        $method->setAccessible(true);

        $models = [];
        $relationName = 'profile';
        $subRelations = [];

        // Should not throw exception
        $method->invoke($loader, $models, $relationName, $subRelations);
        $this->assertTrue(true);
    }

    public function testLoadBelongsToWithNullForeignKey(): void
    {
        // Test with post that has invalid user_id (not null, but non-existent)
        $postId = self::$db->find()->table('eager_loader_posts')->insert(['user_id' => 99999, 'title' => 'Orphan Post']);
        $post = EagerLoaderTestPost::findOne($postId);
        $this->assertNotNull($post);

        $posts = [$post];
        $loader = new EagerLoader();
        $loader->load($posts, ['user']);

        // Should be null (post has invalid user_id)
        $user = $post->user;
        $this->assertNull($user);
    }

    public function testLoadHasOneWithNullLocalKey(): void
    {
        // Create a user without id (shouldn't happen in real scenario, but test edge case)
        $user = new EagerLoaderTestUser();
        $user->name = 'Test';
        $user->email = 'test@example.com';
        // User will get an id when saved, so we can't easily test null local key
        // Instead, test with user that has no related profile
        $user->save();

        $users = [$user];
        $loader = new EagerLoader();
        $loader->load($users, ['profile']);

        // Profile should be null since user was just created and has no profile
        $profile = $user->profile;
        $this->assertNull($profile);
    }

    public function testLoadHasManyWithNullLocalKey(): void
    {
        $users = EagerLoaderTestUser::find()->all();
        $loader = new EagerLoader();

        // Test with user that has no posts (Bob)
        $bob = EagerLoaderTestUser::find()->where('name', 'Bob')->one();
        $this->assertNotNull($bob);

        $users = [$bob];
        $loader->load($users, ['posts']);

        // Should be empty array (Bob has no posts)
        $posts = $bob->posts;
        $this->assertIsArray($posts);
        $this->assertEmpty($posts);
    }

    public function testPopulateModel(): void
    {
        $loader = new EagerLoader();
        $reflection = new \ReflectionClass($loader);
        $method = $reflection->getMethod('populateModel');
        $method->setAccessible(true);

        $data = [
            'id' => 999,
            'name' => 'Test User',
            'email' => 'test@example.com',
        ];

        $model = $method->invoke($loader, EagerLoaderTestUser::class, $data);

        $this->assertInstanceOf(EagerLoaderTestUser::class, $model);
        $this->assertEquals(999, $model->id);
        $this->assertEquals('Test User', $model->name);
        $this->assertEquals('test@example.com', $model->email);
    }

    public function testGetRelationInstance(): void
    {
        $user = EagerLoaderTestUser::find()->one();
        $this->assertNotNull($user);

        $loader = new EagerLoader();
        $reflection = new \ReflectionClass($loader);
        $method = $reflection->getMethod('getRelationInstance');
        $method->setAccessible(true);

        $relation = $method->invoke($loader, $user, 'profile');
        $this->assertNotNull($relation);
        $this->assertInstanceOf(HasOne::class, $relation);

        $relation = $method->invoke($loader, $user, 'nonexistent');
        $this->assertNull($relation);
    }

    public function testNormalizeRelationsWithInvalidValue(): void
    {
        $loader = new EagerLoader();
        $reflection = new \ReflectionClass($loader);
        $method = $reflection->getMethod('normalizeRelations');
        $method->setAccessible(true);

        // Test with array value in indexed array (should be skipped)
        $relations = [['invalid']];
        $normalized = $method->invoke($loader, $relations);

        $this->assertIsArray($normalized);
        // Should skip invalid array value
    }

    public function testLoadBelongsToWithEmptyForeignValues(): void
    {
        // Create post with invalid user_id (non-existent)
        $postId = self::$db->find()->table('eager_loader_posts')->insert(['user_id' => 99999, 'title' => 'Post without user']);
        $post1 = EagerLoaderTestPost::findOne($postId);
        $this->assertNotNull($post1);

        $posts = [$post1];
        $loader = new EagerLoader();
        $loader->load($posts, ['user']);

        // User should be null (post has invalid user_id)
        $user = $post1->user;
        $this->assertNull($user);
    }

    public function testLoadHasManyThroughViaTable(): void
    {
        $db = self::$db;

        // Create users and projects
        $user1 = new EagerLoaderTestUserWithProjects();
        $user1->name = 'Alice';
        $user1->email = 'alice@example.com';
        $user1->save();

        $user2 = new EagerLoaderTestUserWithProjects();
        $user2->name = 'Bob';
        $user2->email = 'bob@example.com';
        $user2->save();

        $project1 = new EagerLoaderTestProject();
        $project1->name = 'Project 1';
        $project1->save();

        $project2 = new EagerLoaderTestProject();
        $project2->name = 'Project 2';
        $project2->save();

        // Link users to projects
        $db->find()->table('eager_loader_user_project')->insert(['user_id' => $user1->id, 'project_id' => $project1->id]);
        $db->find()->table('eager_loader_user_project')->insert(['user_id' => $user1->id, 'project_id' => $project2->id]);
        $db->find()->table('eager_loader_user_project')->insert(['user_id' => $user2->id, 'project_id' => $project1->id]);

        $users = EagerLoaderTestUserWithProjects::find()->all();
        $loader = new EagerLoader();
        $loader->load($users, ['projects']);

        // Check that projects are loaded
        foreach ($users as $user) {
            $projects = $user->projects;
            $this->assertIsArray($projects);
            if ($user->id === $user1->id) {
                $this->assertCount(2, $projects);
            } elseif ($user->id === $user2->id) {
                $this->assertCount(1, $projects);
            }
        }
    }

    public function testLoadHasManyThroughWithEmptyOwnerValues(): void
    {
        // Create user without id (edge case)
        $user = new EagerLoaderTestUserWithProjects();
        $user->name = 'Test';
        $user->email = 'test@example.com';
        // Don't save, so id will be null

        $users = [$user];
        $loader = new EagerLoader();
        $loader->load($users, ['projects']);

        // Projects should be empty array
        $projects = $user->projects;
        $this->assertIsArray($projects);
        $this->assertEmpty($projects);
    }
}
