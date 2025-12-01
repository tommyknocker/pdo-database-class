<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\events\CacheClearedEvent;
use tommyknocker\pdodb\events\CacheDeleteEvent;
use tommyknocker\pdodb\events\CacheHitEvent;
use tommyknocker\pdodb\events\CacheMissEvent;
use tommyknocker\pdodb\events\CacheSetEvent;
use tommyknocker\pdodb\events\ConnectionClosedEvent;
use tommyknocker\pdodb\events\ConnectionOpenedEvent;
use tommyknocker\pdodb\events\DdlOperationEvent;
use tommyknocker\pdodb\events\MigrationCompletedEvent;
use tommyknocker\pdodb\events\MigrationRolledBackEvent;
use tommyknocker\pdodb\events\MigrationStartedEvent;
use tommyknocker\pdodb\events\ModelAfterDeleteEvent;
use tommyknocker\pdodb\events\ModelAfterInsertEvent;
use tommyknocker\pdodb\events\ModelAfterSaveEvent;
use tommyknocker\pdodb\events\ModelAfterUpdateEvent;
use tommyknocker\pdodb\events\QueryBeforeExecuteEvent;
use tommyknocker\pdodb\events\SavepointCreatedEvent;
use tommyknocker\pdodb\events\SavepointReleasedEvent;
use tommyknocker\pdodb\events\SavepointRolledBackEvent;
use tommyknocker\pdodb\events\SeedCompletedEvent;
use tommyknocker\pdodb\events\SeedStartedEvent;
use tommyknocker\pdodb\orm\Model;

/**
 * Tests for Event classes.
 */
final class EventTests extends BaseSharedTestCase
{
    public function testConnectionOpenedEvent(): void
    {
        $event = new ConnectionOpenedEvent('mysql', 'mysql:host=localhost;dbname=test', ['charset' => 'utf8mb4']);

        $this->assertEquals('mysql', $event->getDriver());
        $this->assertEquals('mysql:host=localhost;dbname=test', $event->getDsn());
        $this->assertEquals(['charset' => 'utf8mb4'], $event->getOptions());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testModelAfterInsertEvent(): void
    {
        // Create a mock model
        $model = $this->createMockModel();
        $insertId = 123;

        $event = new ModelAfterInsertEvent($model, $insertId);

        $this->assertSame($model, $event->getModel());
        $this->assertEquals($insertId, $event->getInsertId());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testModelAfterInsertEventWithNullId(): void
    {
        $model = $this->createMockModel();
        $event = new ModelAfterInsertEvent($model, null);

        $this->assertNull($event->getInsertId());
    }

    public function testModelAfterUpdateEvent(): void
    {
        $model = $this->createMockModel();
        $changedAttributes = ['name' => 'New Name', 'email' => 'new@example.com'];
        $rowsAffected = 1;

        $event = new ModelAfterUpdateEvent($model, $changedAttributes, $rowsAffected);

        $this->assertSame($model, $event->getModel());
        $this->assertEquals($changedAttributes, $event->getChangedAttributes());
        $this->assertEquals($rowsAffected, $event->getRowsAffected());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testModelAfterUpdateEventWithZeroRowsAffected(): void
    {
        $model = $this->createMockModel();
        $event = new ModelAfterUpdateEvent($model, [], 0);

        $this->assertEquals(0, $event->getRowsAffected());
    }

    public function testModelAfterSaveEventWithNewRecord(): void
    {
        $model = $this->createMockModel();
        $event = new ModelAfterSaveEvent($model, true);

        $this->assertSame($model, $event->getModel());
        $this->assertTrue($event->isNewRecord());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testModelAfterSaveEventWithExistingRecord(): void
    {
        $model = $this->createMockModel();
        $event = new ModelAfterSaveEvent($model, false);

        $this->assertFalse($event->isNewRecord());
    }

    public function testModelAfterDeleteEvent(): void
    {
        $model = $this->createMockModel();
        $rowsAffected = 1;

        $event = new ModelAfterDeleteEvent($model, $rowsAffected);

        $this->assertSame($model, $event->getModel());
        $this->assertEquals($rowsAffected, $event->getRowsAffected());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testModelAfterDeleteEventWithZeroRowsAffected(): void
    {
        $model = $this->createMockModel();
        $event = new ModelAfterDeleteEvent($model, 0);

        $this->assertEquals(0, $event->getRowsAffected());
    }

    public function testConnectionClosedEvent(): void
    {
        $event = new ConnectionClosedEvent('mysql', 'mysql:host=localhost;dbname=test', 1.5);

        $this->assertEquals('mysql', $event->getDriver());
        $this->assertEquals('mysql:host=localhost;dbname=test', $event->getDsn());
        $this->assertEquals(1.5, $event->getDuration());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testQueryBeforeExecuteEvent(): void
    {
        $event = new QueryBeforeExecuteEvent('SELECT * FROM users', ['id' => 1], 'mysql');

        $this->assertEquals('SELECT * FROM users', $event->getSql());
        $this->assertEquals(['id' => 1], $event->getParams());
        $this->assertEquals('mysql', $event->getDriver());
        $this->assertFalse($event->isPropagationStopped());

        // Test modification
        $event->setSql('SELECT * FROM users WHERE id = ?');
        $event->setParams(['id' => 2]);
        $this->assertEquals('SELECT * FROM users WHERE id = ?', $event->getSql());
        $this->assertEquals(['id' => 2], $event->getParams());

        // Test stoppable
        $event->stopPropagation();
        $this->assertTrue($event->isPropagationStopped());
    }

    public function testSavepointCreatedEvent(): void
    {
        $event = new SavepointCreatedEvent('sp1', 'mysql');

        $this->assertEquals('sp1', $event->getName());
        $this->assertEquals('mysql', $event->getDriver());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testSavepointRolledBackEvent(): void
    {
        $event = new SavepointRolledBackEvent('sp1', 'mysql');

        $this->assertEquals('sp1', $event->getName());
        $this->assertEquals('mysql', $event->getDriver());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testSavepointReleasedEvent(): void
    {
        $event = new SavepointReleasedEvent('sp1', 'mysql');

        $this->assertEquals('sp1', $event->getName());
        $this->assertEquals('mysql', $event->getDriver());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testCacheHitEvent(): void
    {
        $event = new CacheHitEvent('key1', 'value1', 3600);

        $this->assertEquals('key1', $event->getKey());
        $this->assertEquals('value1', $event->getValue());
        $this->assertEquals(3600, $event->getTtl());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testCacheHitEventWithNullTtl(): void
    {
        $event = new CacheHitEvent('key1', 'value1', null);

        $this->assertNull($event->getTtl());
    }

    public function testCacheMissEvent(): void
    {
        $event = new CacheMissEvent('key1');

        $this->assertEquals('key1', $event->getKey());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testCacheSetEvent(): void
    {
        $event = new CacheSetEvent('key1', 'value1', 3600, ['users']);

        $this->assertEquals('key1', $event->getKey());
        $this->assertEquals('value1', $event->getValue());
        $this->assertEquals(3600, $event->getTtl());
        $this->assertEquals(['users'], $event->getTables());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testCacheSetEventWithNullTables(): void
    {
        $event = new CacheSetEvent('key1', 'value1', 3600, null);

        $this->assertNull($event->getTables());
    }

    public function testCacheDeleteEvent(): void
    {
        $event = new CacheDeleteEvent('key1', true);

        $this->assertEquals('key1', $event->getKey());
        $this->assertTrue($event->isSuccess());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testCacheClearedEvent(): void
    {
        $event = new CacheClearedEvent(true);

        $this->assertTrue($event->isSuccess());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testMigrationStartedEvent(): void
    {
        $event = new MigrationStartedEvent('m20231111_120000', 'mysql');

        $this->assertEquals('m20231111_120000', $event->getVersion());
        $this->assertEquals('mysql', $event->getDriver());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testMigrationCompletedEvent(): void
    {
        $event = new MigrationCompletedEvent('m20231111_120000', 'mysql', 150.5);

        $this->assertEquals('m20231111_120000', $event->getVersion());
        $this->assertEquals('mysql', $event->getDriver());
        $this->assertEquals(150.5, $event->getDuration());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testMigrationRolledBackEvent(): void
    {
        $event = new MigrationRolledBackEvent('m20231111_120000', 'mysql');

        $this->assertEquals('m20231111_120000', $event->getVersion());
        $this->assertEquals('mysql', $event->getDriver());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testDdlOperationEvent(): void
    {
        $event = new DdlOperationEvent('CREATE_TABLE', 'users', 'CREATE TABLE users (id INT)', 'mysql');

        $this->assertEquals('CREATE_TABLE', $event->getOperation());
        $this->assertEquals('users', $event->getTable());
        $this->assertEquals('CREATE TABLE users (id INT)', $event->getSql());
        $this->assertEquals('mysql', $event->getDriver());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testSeedStartedEvent(): void
    {
        $event = new SeedStartedEvent('s20231111_120000', 'mysql');

        $this->assertEquals('s20231111_120000', $event->getName());
        $this->assertEquals('mysql', $event->getDriver());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testSeedCompletedEvent(): void
    {
        $event = new SeedCompletedEvent('s20231111_120000', 'mysql', 150.5);

        $this->assertEquals('s20231111_120000', $event->getName());
        $this->assertEquals('mysql', $event->getDriver());
        $this->assertEquals(150.5, $event->getDuration());
        $this->assertFalse($event->isPropagationStopped());
    }

    /**
     * Create a mock model for testing.
     *
     * @return Model
     */
    protected function createMockModel(): Model
    {
        return new class () extends Model {
            protected string $table = 'test_table';
        };
    }
}
