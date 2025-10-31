<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use tommyknocker\pdodb\events\ModelAfterDeleteEvent;
use tommyknocker\pdodb\events\ModelAfterInsertEvent;
use tommyknocker\pdodb\events\ModelAfterSaveEvent;
use tommyknocker\pdodb\events\ModelAfterUpdateEvent;
use tommyknocker\pdodb\events\ModelBeforeDeleteEvent;
use tommyknocker\pdodb\events\ModelBeforeInsertEvent;
use tommyknocker\pdodb\events\ModelBeforeSaveEvent;
use tommyknocker\pdodb\events\ModelBeforeUpdateEvent;
use tommyknocker\pdodb\orm\ActiveRecord;
use tommyknocker\pdodb\orm\Model;

/**
 * Test model for ActiveRecord tests.
 */
final class TestUserModel extends Model
{
    public static function tableName(): string
    {
        return 'test_users';
    }
}

/**
 * ActiveRecordTests tests for ActiveRecord functionality.
 */
final class ActiveRecordTests extends BaseSharedTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create test_users table
        self::$db->rawQuery('
            CREATE TABLE IF NOT EXISTS test_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                age INTEGER,
                status TEXT DEFAULT "active",
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        TestUserModel::setDb(self::$db);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Clear test_users table
        self::$db->rawQuery('DELETE FROM test_users');
        self::$db->rawQuery("DELETE FROM sqlite_sequence WHERE name='test_users'");

        // Clear event dispatcher to ensure clean state between tests
        $queryBuilder = self::$db->find();
        $connection = $queryBuilder->getConnection();
        $connection->setEventDispatcher(null);

        // Ensure model has db connection set
        TestUserModel::setDb(self::$db);
    }

    public function testModelCreation(): void
    {
        $user = new TestUserModel();
        $this->assertTrue($user->getIsNewRecord());
        $this->assertFalse($user->getIsDirty()); // Empty attributes are not dirty

        // Set attribute makes it dirty
        $user->name = 'Test';
        $this->assertTrue($user->getIsDirty());
    }

    public function testAttributeAccess(): void
    {
        $user = new TestUserModel();
        $user->name = 'John';
        $user->email = 'john@example.com';
        $user->age = 30;

        $this->assertEquals('John', $user->name);
        $this->assertEquals('john@example.com', $user->email);
        $this->assertEquals(30, $user->age);
        $this->assertTrue(isset($user->name));
        $this->assertFalse(isset($user->nonExistent));
    }

    public function testInsert(): void
    {
        $user = new TestUserModel();
        $user->name = 'Alice';
        $user->email = 'alice@example.com';
        $user->age = 25;

        $this->assertTrue($user->save());
        $this->assertFalse($user->getIsNewRecord());
        $this->assertNotNull($user->id);
        $this->assertEquals(1, $user->id);
    }

    public function testFindOne(): void
    {
        // Create user
        $user = new TestUserModel();
        $user->name = 'Bob';
        $user->email = 'bob@example.com';
        $user->save();

        // Find by ID
        $found = TestUserModel::findOne($user->id);
        $this->assertNotNull($found);
        $this->assertEquals('Bob', $found->name);
        $this->assertEquals('bob@example.com', $found->email);
        $this->assertFalse($found->getIsNewRecord());

        // Find non-existent
        $notFound = TestUserModel::findOne(999);
        $this->assertNull($notFound);
    }

    public function testFindOneByCondition(): void
    {
        // Create user
        $user = new TestUserModel();
        $user->name = 'Charlie';
        $user->email = 'charlie@example.com';
        $user->status = 'active';
        $user->save();

        // Find by condition
        $found = TestUserModel::findOne(['email' => 'charlie@example.com']);
        $this->assertNotNull($found);
        $this->assertEquals('Charlie', $found->name);

        // Find by multiple conditions
        $found2 = TestUserModel::findOne(['email' => 'charlie@example.com', 'status' => 'active']);
        $this->assertNotNull($found2);
    }

    public function testFindAll(): void
    {
        // Create multiple users
        $users = [
            ['name' => 'User1', 'email' => 'user1@example.com', 'age' => 20],
            ['name' => 'User2', 'email' => 'user2@example.com', 'age' => 25],
            ['name' => 'User3', 'email' => 'user3@example.com', 'age' => 30],
        ];

        foreach ($users as $data) {
            $user = new TestUserModel();
            $user->populate($data);
            $user->save();
        }

        // Find all
        $found = TestUserModel::findAll(['age' => 25]);
        $this->assertCount(1, $found);
        $this->assertEquals('User2', $found[0]->name);

        // Find all with multiple conditions
        $found2 = TestUserModel::findAll([]);
        $this->assertCount(3, $found2);
    }

    public function testFind(): void
    {
        // Create users
        $user1 = new TestUserModel();
        $user1->name = 'Find1';
        $user1->email = 'find1@example.com';
        $user1->save();

        $user2 = new TestUserModel();
        $user2->name = 'Find2';
        $user2->email = 'find2@example.com';
        $user2->save();

        // Use find() query builder
        $results = TestUserModel::find()->where('name', 'Find1')->all();
        $this->assertCount(1, $results);
        $this->assertEquals('Find1', $results[0]->name);

        // Get raw data
        $rawData = TestUserModel::find()->where('name', 'Find1')->get();
        $this->assertCount(1, $rawData);
        $this->assertIsArray($rawData[0]);
        $this->assertEquals('Find1', $rawData[0]['name']);
    }

    public function testUpdate(): void
    {
        // Create user
        $user = new TestUserModel();
        $user->name = 'Original';
        $user->email = 'original@example.com';
        $user->save();

        $id = $user->id;

        // Update
        $user->name = 'Updated';
        $this->assertTrue($user->getIsDirty());
        $this->assertTrue($user->save());

        // Reload and verify
        $updated = TestUserModel::findOne($id);
        $this->assertNotNull($updated);
        $this->assertEquals('Updated', $updated->name);
        $this->assertEquals('original@example.com', $updated->email);
    }

    public function testDelete(): void
    {
        // Create user
        $user = new TestUserModel();
        $user->name = 'ToDelete';
        $user->email = 'delete@example.com';
        $user->save();

        $id = $user->id;

        // Delete
        $this->assertTrue($user->delete());
        $this->assertTrue($user->getIsNewRecord());

        // Verify deleted
        $deleted = TestUserModel::findOne($id);
        $this->assertNull($deleted);
    }

    public function testRefresh(): void
    {
        // Create user
        $user = new TestUserModel();
        $user->name = 'RefreshTest';
        $user->email = 'refresh@example.com';
        $user->save();

        // Modify directly in database
        self::$db->find()->table('test_users')->where('id', $user->id)->update(['name' => 'Refreshed']);

        // Refresh
        $this->assertTrue($user->refresh());
        $this->assertEquals('Refreshed', $user->name);
    }

    public function testDirtyAttributes(): void
    {
        $user = new TestUserModel();
        $user->name = 'DirtyTest';
        $user->email = 'dirty@example.com';
        $user->save();

        // After save, should not be dirty
        $this->assertFalse($user->getIsDirty());

        // Modify attribute
        $user->name = 'Modified';
        $this->assertTrue($user->getIsDirty());

        $dirty = $user->getDirtyAttributes();
        $this->assertArrayHasKey('name', $dirty);
        $this->assertEquals('Modified', $dirty['name']);
    }

    public function testPopulate(): void
    {
        $user = new TestUserModel();
        $user->populate([
            'name' => 'Populated',
            'email' => 'populated@example.com',
            'age' => 35,
        ]);

        $this->assertEquals('Populated', $user->name);
        $this->assertEquals('populated@example.com', $user->email);
        $this->assertEquals(35, $user->age);
    }

    public function testToArray(): void
    {
        $user = new TestUserModel();
        $user->name = 'ArrayTest';
        $user->email = 'array@example.com';
        $user->age = 40;

        $array = $user->toArray();
        $this->assertIsArray($array);
        $this->assertEquals('ArrayTest', $array['name']);
        $this->assertEquals('array@example.com', $array['email']);
        $this->assertEquals(40, $array['age']);
    }

    public function testActiveQueryMethods(): void
    {
        // Create users
        for ($i = 1; $i <= 3; $i++) {
            $user = new TestUserModel();
            $user->name = "QueryUser{$i}";
            $user->email = "query{$i}@example.com";
            $user->age = 20 + $i;
            $user->save();
        }

        // Test getColumn
        $names = TestUserModel::find()->select('name')->getColumn();
        $this->assertCount(3, $names);
        $this->assertContains('QueryUser1', $names);

        // Test getValue
        $count = TestUserModel::find()->select('COUNT(*)')->getValue();
        $this->assertEquals(3, (int)$count);
    }

    public function testDatabaseConnectionRequired(): void
    {
        // Reset database connection
        $originalDb = TestUserModel::getDb();
        TestUserModel::setDb(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database connection not set');

        TestUserModel::find();

        // Restore
        TestUserModel::setDb($originalDb);
    }

    public function testSaveWithoutConnection(): void
    {
        $user = new TestUserModel();
        $user->name = 'Test';
        $user->email = 'test@example.com';

        // Reset database connection
        $originalDb = TestUserModel::getDb();
        TestUserModel::setDb(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database connection not set');

        $user->save();

        // Restore
        TestUserModel::setDb($originalDb);
    }

    public function testLifecycleEvents(): void
    {
        // Create mock event dispatcher that filters only model events
        $modelEvents = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(function (object $event) use (&$modelEvents) {
                // Only track model lifecycle events
                if (str_starts_with($event::class, 'tommyknocker\\pdodb\\events\\Model')) {
                    $modelEvents[] = $event;
                }
                return $event;
            });

        // Set dispatcher on connection
        $queryBuilder = self::$db->find();
        $connection = $queryBuilder->getConnection();
        $connection->setEventDispatcher($dispatcher);

        // Test insert events
        $user = new TestUserModel();
        $user->name = 'EventTest';
        $user->email = 'event@example.com';
        $user->save();

        $this->assertCount(4, $modelEvents);
        $this->assertInstanceOf(ModelBeforeSaveEvent::class, $modelEvents[0]);
        $this->assertInstanceOf(ModelBeforeInsertEvent::class, $modelEvents[1]);
        $this->assertInstanceOf(ModelAfterInsertEvent::class, $modelEvents[2]);
        $this->assertInstanceOf(ModelAfterSaveEvent::class, $modelEvents[3]);

        $this->assertTrue($modelEvents[0]->isNewRecord());
        $this->assertTrue($modelEvents[1]->getModel() === $user);
        $this->assertNotNull($modelEvents[2]->getInsertId());

        // Test update events
        $modelEvents = [];
        $user->name = 'UpdatedEvent';
        $user->save();

        $this->assertCount(4, $modelEvents);
        $this->assertInstanceOf(ModelBeforeSaveEvent::class, $modelEvents[0]);
        $this->assertInstanceOf(ModelBeforeUpdateEvent::class, $modelEvents[1]);
        $this->assertInstanceOf(ModelAfterUpdateEvent::class, $modelEvents[2]);
        $this->assertInstanceOf(ModelAfterSaveEvent::class, $modelEvents[3]);

        $this->assertFalse($modelEvents[0]->isNewRecord());
        $this->assertArrayHasKey('name', $modelEvents[1]->getDirtyAttributes());

        // Test delete events
        $modelEvents = [];
        $user->delete();

        $this->assertCount(2, $modelEvents);
        $this->assertInstanceOf(ModelBeforeDeleteEvent::class, $modelEvents[0]);
        $this->assertInstanceOf(ModelAfterDeleteEvent::class, $modelEvents[1]);
        $this->assertEquals(1, $modelEvents[1]->getRowsAffected());

        // Clean up
        $connection->setEventDispatcher(null);
    }

    public function testEventStopPropagation(): void
    {
        // Create mock event dispatcher that stops propagation on beforeSave
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(function (object $event) {
                if ($event instanceof ModelBeforeSaveEvent) {
                    $event->stopPropagation();
                }
                return $event;
            });

        // Set dispatcher on connection
        $queryBuilder = self::$db->find();
        $connection = $queryBuilder->getConnection();
        $connection->setEventDispatcher($dispatcher);

        $user = new TestUserModel();
        $user->name = 'StopTest';
        $user->email = 'stop@example.com';

        // Save should fail due to stopped propagation
        $result = $user->save();
        $this->assertFalse($result);
        $this->assertTrue($user->getIsNewRecord()); // Still new, save was prevented

        // Clean up
        $connection->setEventDispatcher(null);
    }

    public function testEventStopPropagationOnDelete(): void
    {
        // Ensure model has db connection set
        TestUserModel::setDb(self::$db);

        // Create user first (without event dispatcher to ensure it's created)
        $user = new TestUserModel();
        $user->name = 'DeleteStopTest';
        $user->email = 'deletestop@example.com';
        $result = $user->save();
        $this->assertTrue($result, 'User should be created successfully');

        $userId = $user->id;
        $this->assertNotNull($userId, 'User ID should be set');

        // Create mock event dispatcher that stops propagation on beforeDelete
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(function (object $event) {
                if ($event instanceof ModelBeforeDeleteEvent) {
                    $event->stopPropagation();
                }
                return $event;
            });

        // Set dispatcher on connection
        $queryBuilder = self::$db->find();
        $connection = $queryBuilder->getConnection();
        $connection->setEventDispatcher($dispatcher);

        // Delete should fail due to stopped propagation
        $result = $user->delete();
        $this->assertFalse($result);

        // Verify user still exists
        $found = TestUserModel::findOne($userId);
        $this->assertNotNull($found);

        // Clean up
        $connection->setEventDispatcher(null);
    }
}
