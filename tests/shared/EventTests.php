<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\events\ConnectionOpenedEvent;
use tommyknocker\pdodb\events\ModelAfterDeleteEvent;
use tommyknocker\pdodb\events\ModelAfterInsertEvent;
use tommyknocker\pdodb\events\ModelAfterSaveEvent;
use tommyknocker\pdodb\events\ModelAfterUpdateEvent;

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

    /**
     * Create a mock model for testing.
     *
     * @return \tommyknocker\pdodb\orm\Model
     */
    protected function createMockModel(): \tommyknocker\pdodb\orm\Model
    {
        return new class () extends \tommyknocker\pdodb\orm\Model {
            protected string $table = 'test_table';
        };
    }
}
