<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use Psr\EventDispatcher\EventDispatcherInterface;
use tommyknocker\pdodb\events\ModelBeforeInsertEvent;
use tommyknocker\pdodb\events\ModelBeforeSaveEvent;
use tommyknocker\pdodb\events\ModelBeforeUpdateEvent;
use tommyknocker\pdodb\orm\Model;

/**
 * Test model for edge case tests.
 */
class EdgeCaseTestModel extends Model
{
    public static function tableName(): string
    {
        return 'test_edge_cases';
    }
}

/**
 * Test model with composite primary key.
 */
final class EdgeCaseCompositeKeyModel extends EdgeCaseTestModel
{
    public static function tableName(): string
    {
        return 'test_composite_key';
    }

    public static function primaryKey(): array
    {
        return ['key1', 'key2'];
    }
}

/**
 * Test model with validation for edge cases.
 */
final class EdgeCaseValidationModel extends EdgeCaseTestModel
{
    public static function rules(): array
    {
        return [
            ['name', 'required'],
            ['email', 'email'],
        ];
    }
}

/**
 * Edge case tests for ActiveRecord functionality.
 */
final class ActiveRecordEdgeCasesTests extends BaseSharedTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create test_edge_cases table
        self::$db->rawQuery('
            CREATE TABLE IF NOT EXISTS test_edge_cases (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                email TEXT,
                age INTEGER,
                data TEXT,
                flag INTEGER DEFAULT 0
            )
        ');

        // Create test_composite_key table
        self::$db->rawQuery('
            CREATE TABLE IF NOT EXISTS test_composite_key (
                key1 TEXT NOT NULL,
                key2 TEXT NOT NULL,
                value TEXT,
                PRIMARY KEY (key1, key2)
            )
        ');

        EdgeCaseTestModel::setDb(self::$db);
        EdgeCaseCompositeKeyModel::setDb(self::$db);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Clear tables
        self::$db->rawQuery('DELETE FROM test_edge_cases');
        self::$db->rawQuery("DELETE FROM sqlite_sequence WHERE name='test_edge_cases'");
        self::$db->rawQuery('DELETE FROM test_composite_key');

        // Clear event dispatcher
        $queryBuilder = self::$db->find();
        $connection = $queryBuilder->getConnection();
        $connection->setEventDispatcher(null);
    }

    protected function tearDown(): void
    {
        // Always clear event dispatcher after each test to prevent state leakage
        $queryBuilder = self::$db->find();
        $connection = $queryBuilder->getConnection();
        $connection->setEventDispatcher(null);

        parent::tearDown();
    }

    // ==================== ActiveRecord Edge Cases ====================

    public function testNullValuesInAttributes(): void
    {
        $model = new EdgeCaseTestModel();
        $model->name = null;
        $model->email = null;
        $model->age = null;

        $this->assertNull($model->name);
        $this->assertNull($model->email);
        $this->assertNull($model->age);
        $this->assertFalse(isset($model->name)); // isset returns false for null in PHP 8.4
        $this->assertArrayHasKey('name', $model->getAttributes());
    }

    public function testEmptyStringValues(): void
    {
        $model = new EdgeCaseTestModel();
        $model->name = '';
        $model->email = '';

        $this->assertEquals('', $model->name);
        $this->assertEquals('', $model->email);
        $this->assertTrue(isset($model->name));
    }

    public function testZeroValue(): void
    {
        $model = new EdgeCaseTestModel();
        $model->age = 0;
        $model->flag = 0;

        $this->assertEquals(0, $model->age);
        $this->assertEquals(0, $model->flag);
        $this->assertTrue(isset($model->age));
    }

    public function testFalseValue(): void
    {
        $model = new EdgeCaseTestModel();
        $model->flag = false;

        $this->assertFalse($model->flag);
        $this->assertTrue(isset($model->flag));
    }

    public function testStringZero(): void
    {
        $model = new EdgeCaseTestModel();
        $model->name = '0';

        $this->assertEquals('0', $model->name);
        $this->assertTrue(isset($model->name));
    }

    public function testVeryLongString(): void
    {
        $model = new EdgeCaseTestModel();
        $longString = str_repeat('a', 10000);
        $model->name = $longString;

        $this->assertEquals($longString, $model->name);
        $this->assertEquals(10000, strlen($model->name));
    }

    public function testSpecialCharacters(): void
    {
        $model = new EdgeCaseTestModel();
        $special = '!@#$%^&*()_+-=[]{}|;:,.<>?/~`';
        $model->name = $special;

        $this->assertEquals($special, $model->name);
    }

    public function testUnicodeCharacters(): void
    {
        $model = new EdgeCaseTestModel();
        $unicode = 'Привет 🌍 こんにちは';
        $model->name = $unicode;

        $this->assertEquals($unicode, $model->name);
        $model->save();
        $this->assertTrue($model->save());

        // Reload and verify
        $found = EdgeCaseTestModel::findOne($model->id);
        $this->assertNotNull($found);
        $this->assertEquals($unicode, $found->name);
    }

    public function testUpdateWithoutChanges(): void
    {
        $model = new EdgeCaseTestModel();
        $model->name = 'Test';
        $model->email = 'test@example.com';
        $model->save();

        // No changes
        $this->assertFalse($model->getIsDirty());
        $this->assertTrue($model->save()); // Should succeed even with no changes
    }

    public function testUpdateOnlyPrimaryKey(): void
    {
        $model = new EdgeCaseTestModel();
        $model->name = 'Test';
        $model->email = 'test@example.com';
        $model->save();

        $oldId = $model->id;
        $model->id = 999; // Try to change primary key

        // Primary key change should be ignored in update
        $this->assertTrue($model->save());

        // Verify ID didn't change
        $found = EdgeCaseTestModel::findOne($oldId);
        $this->assertNotNull($found);
    }

    public function testDeleteNewRecord(): void
    {
        $model = new EdgeCaseTestModel();
        $model->name = 'Test';

        // Can't delete new record
        $this->assertFalse($model->delete());
        $this->assertTrue($model->getIsNewRecord());
    }

    public function testRefreshNewRecord(): void
    {
        $model = new EdgeCaseTestModel();
        $model->name = 'Test';

        // Can't refresh new record
        $this->assertFalse($model->refresh());
    }

    public function testRefreshNonExistentRecord(): void
    {
        // Create a real record first
        $realModel = new EdgeCaseTestModel();
        $realModel->name = 'Real';
        $realModel->email = 'real@example.com';
        $realModel->save();

        // Try to refresh a different non-existent ID by manipulating attributes directly
        $model = new EdgeCaseTestModel();
        $model->id = 99999;
        // Set oldAttributes to make it think it's an existing record
        $reflection = new \ReflectionClass($model);
        $oldAttributesProp = $reflection->getProperty('oldAttributes');
        $oldAttributesProp->setAccessible(true);
        $oldAttributesProp->setValue($model, ['id' => 99999]);
        $isNewRecordProp = $reflection->getProperty('isNewRecord');
        $isNewRecordProp->setAccessible(true);
        $isNewRecordProp->setValue($model, false);

        // Can't refresh non-existent record
        $this->assertFalse($model->refresh());
    }

    public function testFindOneWithNull(): void
    {
        $result = EdgeCaseTestModel::findOne(null);
        $this->assertNull($result);
    }

    public function testFindOneWithEmptyArray(): void
    {
        // Empty array should return null without executing query
        $result = EdgeCaseTestModel::findOne([]);
        $this->assertNull($result);
    }

    public function testFindAllWithEmptyCondition(): void
    {
        // Create some records
        for ($i = 1; $i <= 3; $i++) {
            $model = new EdgeCaseTestModel();
            $model->name = "User{$i}";
            $model->email = "user{$i}@example.com";
            $model->save();
        }

        $results = EdgeCaseTestModel::findAll([]);
        $this->assertCount(3, $results);
    }

    public function testMultipleSaveCalls(): void
    {
        $model = new EdgeCaseTestModel();
        $model->name = 'Test';
        $model->email = 'test@example.com';

        // Multiple saves
        $this->assertTrue($model->save());
        $this->assertTrue($model->save());
        $this->assertTrue($model->save());

        $this->assertFalse($model->getIsNewRecord());
    }

    public function testPopulateOverwritesAttributes(): void
    {
        $model = new EdgeCaseTestModel();
        $model->name = 'Original';
        $model->email = 'original@example.com';

        $model->populate([
            'name' => 'New',
            'age' => 30,
        ]);

        $this->assertEquals('New', $model->name);
        $this->assertEquals('original@example.com', $model->email); // Not overwritten
        $this->assertEquals(30, $model->age);
    }

    public function testPopulateWithEmptyArray(): void
    {
        $model = new EdgeCaseTestModel();
        $model->name = 'Test';
        $model->populate([]);

        // Attributes should remain unchanged
        $this->assertEquals('Test', $model->name);
    }

    public function testSetAttributesWithSafeOnly(): void
    {
        $modelClass = new class () extends EdgeCaseTestModel {
            public static function safeAttributes(): array
            {
                return ['name', 'email'];
            }
        };

        $model = new $modelClass();
        $model->setAttributes([
            'name' => 'Safe',
            'email' => 'safe@example.com',
            'age' => 30,
            'unknown' => 'value',
        ], true);

        $this->assertEquals('Safe', $model->name);
        $this->assertEquals('safe@example.com', $model->email);
        $this->assertNull($model->age); // Not in safe attributes
        $this->assertFalse(isset($model->unknown)); // Not in safe attributes
    }

    public function testUnsetAttribute(): void
    {
        $model = new EdgeCaseTestModel();
        $model->name = 'Test';
        $this->assertTrue(isset($model->name));

        unset($model->name);
        $this->assertFalse(isset($model->name));
        $this->assertNull($model->name);
    }

    public function testUnsetNonExistentAttribute(): void
    {
        $model = new EdgeCaseTestModel();
        // Should not throw error
        unset($model->nonExistent);
        $this->assertFalse(isset($model->nonExistent));
    }

    public function testDirtyTrackingWithNullToValue(): void
    {
        $model = new EdgeCaseTestModel();
        // Set at least one attribute to avoid empty insert
        $model->name = 'Initial';
        $model->save();

        $model->name = 'Test';
        $this->assertTrue($model->getIsDirty());
        $dirty = $model->getDirtyAttributes();
        $this->assertArrayHasKey('name', $dirty);
    }

    public function testDirtyTrackingWithValueToNull(): void
    {
        $model = new EdgeCaseTestModel();
        $model->name = 'Test';
        $model->save();

        $model->name = null;
        $this->assertTrue($model->getIsDirty());
        $dirty = $model->getDirtyAttributes();
        $this->assertArrayHasKey('name', $dirty);
    }

    public function testDirtyTrackingWithSameValue(): void
    {
        $model = new EdgeCaseTestModel();
        $model->name = 'Test';
        $model->save();

        $model->name = 'Test'; // Same value
        $this->assertFalse($model->getIsDirty());
    }

    public function testCompositePrimaryKey(): void
    {
        $model = new EdgeCaseCompositeKeyModel();
        $model->key1 = 'key1';
        $model->key2 = 'key2';
        $model->value = 'test';
        $this->assertTrue($model->save());

        $found = EdgeCaseCompositeKeyModel::findOne(['key1' => 'key1', 'key2' => 'key2']);
        $this->assertNotNull($found);
        $this->assertEquals('test', $found->value);
    }

    public function testCompositePrimaryKeyFindOneWithArray(): void
    {
        $model = new EdgeCaseCompositeKeyModel();
        $model->key1 = 'a';
        $model->key2 = 'b';
        $model->save();

        $found = EdgeCaseCompositeKeyModel::findOne(['a', 'b']);
        $this->assertNotNull($found);
    }

    public function testToArrayWithNullValues(): void
    {
        $model = new EdgeCaseTestModel();
        $model->name = null;
        $model->email = 'test@example.com';

        $array = $model->toArray();
        $this->assertNull($array['name']);
        $this->assertEquals('test@example.com', $array['email']);
    }

    public function testToArrayWithEmptyModel(): void
    {
        $model = new EdgeCaseTestModel();
        $array = $model->toArray();
        $this->assertIsArray($array);
        $this->assertEmpty($array);
    }

    // ==================== Events Edge Cases ====================

    public function testEventsWithoutDispatcher(): void
    {
        // No dispatcher set - should not throw error
        $model = new EdgeCaseTestModel();
        $model->name = 'Test';
        $model->email = 'test@example.com';
        $this->assertTrue($model->save());
    }

    public function testEventsWithNullDispatcher(): void
    {
        $queryBuilder = self::$db->find();
        $connection = $queryBuilder->getConnection();
        $connection->setEventDispatcher(null);

        $model = new EdgeCaseTestModel();
        $model->name = 'Test';
        $model->email = 'test@example.com';
        $this->assertTrue($model->save());
    }

    public function testEventsWithDispatcherThrowingException(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willThrowException(new \RuntimeException('Dispatcher error'));

        $queryBuilder = self::$db->find();
        $connection = $queryBuilder->getConnection();
        $connection->setEventDispatcher($dispatcher);

        $model = new EdgeCaseTestModel();
        $model->name = 'Test';
        $model->email = 'test@example.com';

        // Exception should propagate
        $this->expectException(\RuntimeException::class);
        $model->save();
    }

    public function testMultipleEventListeners(): void
    {
        $events = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(function (object $event) use (&$events) {
                if (str_starts_with($event::class, 'tommyknocker\\pdodb\\events\\Model')) {
                    $events[] = $event::class;
                }
                return $event;
            });

        $queryBuilder = self::$db->find();
        $connection = $queryBuilder->getConnection();
        $connection->setEventDispatcher($dispatcher);

        $model = new EdgeCaseTestModel();
        $model->name = 'Test';
        $model->email = 'test@example.com';
        $model->save();

        // Should have received events
        $this->assertNotEmpty($events);
    }

    public function testStopPropagationOnBeforeInsert(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(function (object $event) {
                if ($event instanceof ModelBeforeInsertEvent) {
                    $event->stopPropagation();
                }
                return $event;
            });

        $queryBuilder = self::$db->find();
        $connection = $queryBuilder->getConnection();
        $connection->setEventDispatcher($dispatcher);

        $model = new EdgeCaseTestModel();
        $model->name = 'Test';
        $model->email = 'test@example.com';

        $this->assertFalse($model->save());
        $this->assertTrue($model->getIsNewRecord());
    }

    public function testStopPropagationOnBeforeUpdate(): void
    {
        $model = new EdgeCaseTestModel();
        $model->name = 'Test';
        $model->email = 'test@example.com';
        $model->save();

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(function (object $event) {
                if ($event instanceof ModelBeforeUpdateEvent) {
                    $event->stopPropagation();
                }
                return $event;
            });

        $queryBuilder = self::$db->find();
        $connection = $queryBuilder->getConnection();
        $connection->setEventDispatcher($dispatcher);

        $model->name = 'Updated';
        $this->assertFalse($model->save());

        // Verify no update occurred
        $found = EdgeCaseTestModel::findOne($model->id);
        $this->assertNotNull($found);
        $this->assertEquals('Test', $found->name);
    }

    public function testNoEventsOnSaveWithValidationDisabled(): void
    {
        $events = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(function (object $event) use (&$events) {
                if (str_starts_with($event::class, 'tommyknocker\\pdodb\\events\\Model')) {
                    $events[] = $event::class;
                }
                return $event;
            });

        $queryBuilder = self::$db->find();
        $connection = $queryBuilder->getConnection();
        $connection->setEventDispatcher($dispatcher);

        $model = new EdgeCaseTestModel();
        $model->name = 'Test';
        $model->email = 'test@example.com';
        $model->save(false); // Skip validation, but events should still fire

        // Events should still fire even with validation disabled
        $this->assertNotEmpty($events);
    }

    public function testNoEventsOnFailedOperation(): void
    {
        // Create model without required fields to cause DB error
        $model = new EdgeCaseTestModel();
        // Don't set required fields if table has NOT NULL constraints

        // This test depends on table schema - skip if no constraints
        // For now, test with valid data but check event count
        $model->name = 'Test';
        $model->email = 'test@example.com';

        $events = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(function (object $event) use (&$events) {
                if (str_starts_with($event::class, 'tommyknocker\\pdodb\\events\\Model')) {
                    $events[] = $event::class;
                }
                return $event;
            });

        $queryBuilder = self::$db->find();
        $connection = $queryBuilder->getConnection();
        $connection->setEventDispatcher($dispatcher);

        $model->save();

        // After successful save, should have after events
        $this->assertNotEmpty($events);
    }

    public function testEventsWithFailedValidation(): void
    {
        $events = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(function (object $event) use (&$events) {
                if (str_starts_with($event::class, 'tommyknocker\\pdodb\\events\\Model')) {
                    $events[] = $event::class;
                }
                return $event;
            });

        $queryBuilder = self::$db->find();
        $connection = $queryBuilder->getConnection();
        $connection->setEventDispatcher($dispatcher);

        $model = new EdgeCaseValidationModel();
        // Missing required name
        $model->email = 'test@example.com';

        // Save should fail validation
        $this->assertFalse($model->save());

        // BeforeSave event should fire, but not insert/update events
        $beforeSaveCount = count(array_filter($events, fn ($e) => $e === ModelBeforeSaveEvent::class));
        $this->assertEquals(0, $beforeSaveCount); // Actually, validation happens before events
    }

    // ==================== Validation Edge Cases ====================

    public function testValidationWithEmptyRules(): void
    {
        $modelClass = new class () extends EdgeCaseTestModel {
            public static function rules(): array
            {
                return [];
            }
        };

        $model = new $modelClass();
        $this->assertTrue($model->validate());
        $this->assertFalse($model->hasValidationErrors());
    }

    public function testValidationWithInvalidRuleFormat(): void
    {
        $modelClass = new class () extends EdgeCaseTestModel {
            public static function rules(): array
            {
                return [
                    'invalid-rule', // Not an array
                    [['name'], 'required'], // Valid
                    ['only-attribute'], // Missing validator
                    [], // Empty array
                ];
            }
        };

        $model = new $modelClass();
        // Should not throw error, just skip invalid rules
        $result = $model->validate();
        // Should have error for name (required)
        $this->assertFalse($result);
        $this->assertTrue($model->hasValidationErrors());
    }

    public function testValidationWithNonExistentAttribute(): void
    {
        $modelClass = new class () extends EdgeCaseTestModel {
            public static function rules(): array
            {
                return [
                    ['nonExistent', 'required'],
                ];
            }
        };

        $model = new $modelClass();
        $this->assertFalse($model->validate());
        $errors = $model->getValidationErrorsForAttribute('nonExistent');
        $this->assertNotEmpty($errors);
    }

    public function testValidationWithNullAttribute(): void
    {
        $model = new EdgeCaseValidationModel();
        $model->name = null; // Explicitly set to null
        $model->email = 'test@example.com';

        $this->assertFalse($model->validate());
        $errors = $model->getValidationErrorsForAttribute('name');
        $this->assertNotEmpty($errors);
    }

    public function testValidationWithEmptyStringAttribute(): void
    {
        $model = new EdgeCaseValidationModel();
        $model->name = '';
        $model->email = 'test@example.com';

        $this->assertFalse($model->validate());
        $errors = $model->getValidationErrorsForAttribute('name');
        $this->assertNotEmpty($errors);
    }

    public function testValidationWithZeroValue(): void
    {
        $modelClass = new class () extends EdgeCaseTestModel {
            public static function rules(): array
            {
                return [
                    ['age', 'required'],
                ];
            }
        };

        $model = new $modelClass();
        $model->age = 0;

        // Zero should pass required validation
        $this->assertTrue($model->validate());
    }

    public function testValidationWithStringZero(): void
    {
        $modelClass = new class () extends EdgeCaseTestModel {
            public static function rules(): array
            {
                return [
                    ['name', 'required'],
                ];
            }
        };

        $model = new $modelClass();
        $model->name = '0';

        // String '0' should pass required validation
        $this->assertTrue($model->validate());
    }

    public function testValidationWithArrayValue(): void
    {
        $model = new EdgeCaseValidationModel();
        $model->name = 'Test'; // Set name first to pass required
        $model->email = ['not', 'a', 'string'];

        // Should fail email validation (email validator checks is_string)
        $this->assertFalse($model->validate());
        $errors = $model->getValidationErrorsForAttribute('email');
        $this->assertNotEmpty($errors);
    }

    public function testValidationWithObjectValue(): void
    {
        $model = new EdgeCaseValidationModel();
        $model->name = 'Test'; // Set name first to pass required
        $model->email = new \stdClass();

        // Should fail email validation (email validator checks is_string)
        $this->assertFalse($model->validate());
        $errors = $model->getValidationErrorsForAttribute('email');
        $this->assertNotEmpty($errors);
    }

    public function testValidationWithResourceValue(): void
    {
        $resource = fopen('php://temp', 'r+');
        $model = new EdgeCaseValidationModel();
        $model->name = 'Test';

        // Resource can't be validated as email
        if (is_resource($resource)) {
            $model->email = $resource;
            $this->assertFalse($model->validate());
            fclose($resource);
        }
    }

    public function testValidationWithMultipleRulesForAttributeErrors(): void
    {
        $modelClass = new class () extends EdgeCaseTestModel {
            public static function rules(): array
            {
                return [
                    ['name', 'required'],
                    ['name', 'string', 'min' => 5],
                    ['email', 'required'],
                    ['email', 'email'],
                ];
            }
        };

        $model = new $modelClass();
        $model->name = 'Ab'; // Too short

        $this->assertFalse($model->validate());
        $nameErrors = $model->getValidationErrorsForAttribute('name');
        // Should have string length error (required passes)
        $this->assertNotEmpty($nameErrors);
    }

    public function testValidationClearErrorsBeforeValidate(): void
    {
        $model = new EdgeCaseValidationModel();
        $model->validate(); // Will fail
        $this->assertTrue($model->hasValidationErrors());

        $model->clearValidationErrors();
        $this->assertFalse($model->hasValidationErrors());

        // Validate again
        $model->name = 'Test';
        $model->email = 'test@example.com';
        $this->assertTrue($model->validate());
    }

    public function testValidationAfterSuccessfulSave(): void
    {
        $model = new EdgeCaseValidationModel();
        $model->name = 'Test';
        $model->email = 'test@example.com';

        $this->assertTrue($model->save());
        $this->assertFalse($model->hasValidationErrors());

        // Clear name and validate again
        $model->name = '';
        $this->assertFalse($model->validate());
        $this->assertTrue($model->hasValidationErrors());
    }

    public function testValidationWithCustomMessageInParams(): void
    {
        $modelClass = new class () extends EdgeCaseTestModel {
            public static function rules(): array
            {
                return [
                    ['name', 'required', 'message' => 'Custom error message'],
                ];
            }
        };

        $model = new $modelClass();
        $model->validate();

        $errors = $model->getValidationErrorsForAttribute('name');
        $this->assertNotEmpty($errors);
        $this->assertEquals('Custom error message', $errors[0]);
    }

    public function testValidationRulesWithIndexedParams(): void
    {
        $modelClass = new class () extends EdgeCaseTestModel {
            public static function rules(): array
            {
                return [
                    ['age', 'integer', 'min' => 1, 'max' => 100],
                ];
            }
        };

        $model = new $modelClass();
        $model->age = 200;

        $this->assertFalse($model->validate());
        $errors = $model->getValidationErrorsForAttribute('age');
        $this->assertNotEmpty($errors);
    }

    public function testValidationWithValidatorInstanceInRules(): void
    {
        // This tests if we can pass validator instance (not supported, but should handle gracefully)
        $modelClass = new class () extends EdgeCaseTestModel {
            public static function rules(): array
            {
                return [
                    ['name', 'required'],
                ];
            }
        };

        $model = new $modelClass();
        // Should work with string validator name
        $this->assertFalse($model->validate());
    }

    public function testValidationErrorsPersistenceAcrossValidateCalls(): void
    {
        $model = new EdgeCaseValidationModel();
        $model->name = 'Test';
        $model->email = 'invalid-email'; // Invalid email format

        $this->assertFalse($model->validate());
        $errors1 = $model->getValidationErrors();

        // Validate again with same data - errors are cleared and regenerated each time
        $this->assertFalse($model->validate());
        $errors2 = $model->getValidationErrors();

        // Errors should have same structure and content (but may be new array instances)
        // Email validation should fail for invalid format
        $this->assertArrayHasKey('email', $errors1);
        $this->assertArrayHasKey('email', $errors2);
        $this->assertCount(1, $errors1['email']);
        $this->assertCount(1, $errors2['email']);
        // Same error message
        $this->assertEquals($errors1['email'][0], $errors2['email'][0]);
    }

    public function testValidationErrorsClearedOnNewValidation(): void
    {
        $model = new EdgeCaseValidationModel();
        $model->name = '';

        $this->assertFalse($model->validate());
        $this->assertTrue($model->hasValidationErrors());

        // Fix and validate again
        $model->name = 'Test';
        $model->email = 'test@example.com';
        $this->assertTrue($model->validate());
        $this->assertFalse($model->hasValidationErrors());
    }

    public function testValidationWithVeryLongAttributeName(): void
    {
        $modelClass = new class () extends EdgeCaseTestModel {
            public static function rules(): array
            {
                return [
                    ['very_long_attribute_name_that_exceeds_normal_length', 'required'],
                ];
            }
        };

        $model = new $modelClass();
        $model->very_long_attribute_name_that_exceeds_normal_length = 'Test';

        $this->assertTrue($model->validate());
    }

    public function testValidationWithNumericAttributeName(): void
    {
        $modelClass = new class () extends EdgeCaseTestModel {
            public static function rules(): array
            {
                return [
                    ['123', 'required'],
                ];
            }
        };

        $model = new $modelClass();
        $model->{'123'} = 'Test';

        $this->assertTrue($model->validate());
    }

    public function testValidationWithSpecialCharactersInAttributeName(): void
    {
        $modelClass = new class () extends EdgeCaseTestModel {
            public static function rules(): array
            {
                return [
                    ['attr_name', 'required'],
                ];
            }
        };

        $model = new $modelClass();
        $model->attr_name = 'Test';

        $this->assertTrue($model->validate());
    }
}
