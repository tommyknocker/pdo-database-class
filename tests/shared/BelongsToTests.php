<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use tommyknocker\pdodb\orm\ActiveQuery;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\orm\relations\BelongsTo;
use tommyknocker\pdodb\PdoDb;

/**
 * Test models for BelongsTo relationship tests.
 */
class BelongsToTestUserModel extends Model
{
    public static function tableName(): string
    {
        return 'belongs_to_users';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }
}

class BelongsToTestPostModel extends Model
{
    public static function tableName(): string
    {
        return 'belongs_to_posts';
    }

    public static function primaryKey(): array
    {
        return ['id'];
    }
}

/**
 * Tests for BelongsTo relationship.
 */
final class BelongsToTests extends TestCase
{
    protected static PdoDb $db;

    public static function setUpBeforeClass(): void
    {
        self::$db = new PdoDb('sqlite', ['path' => ':memory:']);

        // Create test tables
        self::$db->rawQuery('
            CREATE TABLE belongs_to_users (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL
            )
        ');

        self::$db->rawQuery('
            CREATE TABLE belongs_to_posts (
                id INTEGER PRIMARY KEY,
                belongs_to_user_id INTEGER,
                title TEXT NOT NULL,
                FOREIGN KEY (belongs_to_user_id) REFERENCES belongs_to_users(id)
            )
        ');

        BelongsToTestUserModel::setDb(self::$db);
        BelongsToTestPostModel::setDb(self::$db);
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('DELETE FROM belongs_to_posts');
        self::$db->rawQuery('DELETE FROM belongs_to_users');
    }

    /**
     * Test constructor with default foreign key.
     */
    public function testConstructorWithDefaultForeignKey(): void
    {
        $relation = new BelongsTo(BelongsToTestUserModel::class);
        // Foreign key is auto-detected from table name: 'belongs_to_users' -> 'belongs_to_user' -> 'belongs_to_user_id'
        $this->assertEquals('belongs_to_user_id', $relation->getForeignKey());
        $this->assertEquals('id', $relation->getOwnerKey());
    }

    /**
     * Test constructor with custom foreign key.
     */
    public function testConstructorWithCustomForeignKey(): void
    {
        $relation = new BelongsTo(BelongsToTestUserModel::class, ['foreignKey' => 'author_id']);
        $this->assertEquals('author_id', $relation->getForeignKey());
    }

    /**
     * Test constructor with custom owner key.
     */
    public function testConstructorWithCustomOwnerKey(): void
    {
        $relation = new BelongsTo(BelongsToTestUserModel::class, ['ownerKey' => 'user_id']);
        $this->assertEquals('user_id', $relation->getOwnerKey());
    }

    /**
     * Test getModelClass.
     */
    public function testGetModelClass(): void
    {
        $relation = new BelongsTo(BelongsToTestUserModel::class);
        $this->assertEquals(BelongsToTestUserModel::class, $relation->getModelClass());
    }

    /**
     * Test getForeignKey.
     */
    public function testGetForeignKey(): void
    {
        $relation = new BelongsTo(BelongsToTestUserModel::class, ['foreignKey' => 'custom_fk']);
        $this->assertEquals('custom_fk', $relation->getForeignKey());
    }

    /**
     * Test getOwnerKey.
     */
    public function testGetOwnerKey(): void
    {
        $relation = new BelongsTo(BelongsToTestUserModel::class, ['ownerKey' => 'custom_pk']);
        $this->assertEquals('custom_pk', $relation->getOwnerKey());
    }

    /**
     * Test setModel and getModel.
     */
    public function testSetModelAndGetModel(): void
    {
        $post = new BelongsToTestPostModel();
        $post->id = 1;
        $post->belongs_to_user_id = 1; // Foreign key is auto-detected from table name
        $post->title = 'Test Post';

        $relation = new BelongsTo(BelongsToTestUserModel::class);
        $relation->setModel($post);

        $this->assertEquals($post, $relation->getModel());
    }

    /**
     * Test setModel resolves owner key from primary key.
     */
    public function testSetModelResolvesOwnerKey(): void
    {
        $post = new BelongsToTestPostModel();
        $relation = new BelongsTo(BelongsToTestUserModel::class);
        $relation->setModel($post);

        // Owner key should be resolved from related model's primary key
        $this->assertEquals('id', $relation->getOwnerKey());
    }

    /**
     * Test getValue throws exception when model is not set.
     */
    public function testGetValueThrowsExceptionWhenModelNotSet(): void
    {
        $relation = new BelongsTo(BelongsToTestUserModel::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Model instance must be set');
        $relation->getValue();
    }

    /**
     * Test getValue returns null when foreign key is null.
     */
    public function testGetValueReturnsNullWhenForeignKeyIsNull(): void
    {
        $post = new BelongsToTestPostModel();
        $post->id = 1;
        $post->belongs_to_user_id = null; // Foreign key is auto-detected from table name
        $post->title = 'Test Post';

        $relation = new BelongsTo(BelongsToTestUserModel::class);
        $relation->setModel($post);

        $this->assertNull($relation->getValue());
    }

    /**
     * Test getValue returns related model.
     */
    public function testGetValueReturnsRelatedModel(): void
    {
        // Create user
        $user = new BelongsToTestUserModel();
        $user->id = 1;
        $user->name = 'John';
        $user->save();

        // Create post with correct foreign key (auto-detected from table name)
        $post = new BelongsToTestPostModel();
        $post->id = 1;
        $post->belongs_to_user_id = 1; // Foreign key is auto-detected from table name
        $post->title = 'Test Post';
        $post->save();

        $relation = new BelongsTo(BelongsToTestUserModel::class);
        $relation->setModel($post);

        $relatedUser = $relation->getValue();
        $this->assertInstanceOf(BelongsToTestUserModel::class, $relatedUser);
        $this->assertEquals(1, $relatedUser->id);
        $this->assertEquals('John', $relatedUser->name);
    }

    /**
     * Test getEagerValue with null.
     */
    public function testGetEagerValueWithNull(): void
    {
        $relation = new BelongsTo(BelongsToTestUserModel::class);
        $this->assertNull($relation->getEagerValue(null));
    }

    /**
     * Test getEagerValue with Model instance.
     */
    public function testGetEagerValueWithModelInstance(): void
    {
        $user = new BelongsToTestUserModel();
        $user->id = 1;
        $user->name = 'John';

        $relation = new BelongsTo(BelongsToTestUserModel::class);
        $result = $relation->getEagerValue($user);

        $this->assertEquals($user, $result);
    }

    /**
     * Test getEagerValue with array.
     */
    public function testGetEagerValueWithArray(): void
    {
        $data = ['id' => 1, 'name' => 'John'];

        $relation = new BelongsTo(BelongsToTestUserModel::class);
        $result = $relation->getEagerValue($data);

        $this->assertInstanceOf(BelongsToTestUserModel::class, $result);
        $this->assertEquals(1, $result->id);
        $this->assertEquals('John', $result->name);
    }

    /**
     * Test getEagerValue throws exception with invalid data.
     */
    public function testGetEagerValueThrowsExceptionWithInvalidData(): void
    {
        $relation = new BelongsTo(BelongsToTestUserModel::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Eager-loaded data for BelongsTo must be a Model instance, array, or null');
        $relation->getEagerValue('invalid');
    }

    /**
     * Test prepareQuery throws exception when model is not set.
     */
    public function testPrepareQueryThrowsExceptionWhenModelNotSet(): void
    {
        $relation = new BelongsTo(BelongsToTestUserModel::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Model instance must be set');
        $relation->prepareQuery();
    }

    /**
     * Test prepareQuery with foreign key value.
     */
    public function testPrepareQueryWithForeignKeyValue(): void
    {
        $post = new BelongsToTestPostModel();
        $post->id = 1;
        $post->belongs_to_user_id = 1; // Foreign key is auto-detected from table name
        $post->title = 'Test Post';

        $relation = new BelongsTo(BelongsToTestUserModel::class);
        $relation->setModel($post);

        $query = $relation->prepareQuery();
        $this->assertInstanceOf(ActiveQuery::class, $query);
    }

    /**
     * Test prepareQuery with null foreign key.
     */
    public function testPrepareQueryWithNullForeignKey(): void
    {
        $post = new BelongsToTestPostModel();
        $post->id = 1;
        $post->belongs_to_user_id = null; // Foreign key is auto-detected from table name
        $post->title = 'Test Post';

        $relation = new BelongsTo(BelongsToTestUserModel::class);
        $relation->setModel($post);

        $query = $relation->prepareQuery();
        $this->assertInstanceOf(ActiveQuery::class, $query);
    }
}
