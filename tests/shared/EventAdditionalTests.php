<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\events\ModelBeforeDeleteEvent;
use tommyknocker\pdodb\events\ModelBeforeSaveEvent;
use tommyknocker\pdodb\events\ModelBeforeUpdateEvent;
use tommyknocker\pdodb\events\QueryErrorEvent;
use tommyknocker\pdodb\events\QueryExecutedEvent;
use tommyknocker\pdodb\events\TransactionCommittedEvent;
use tommyknocker\pdodb\events\TransactionRolledBackEvent;
use tommyknocker\pdodb\events\TransactionStartedEvent;
use tommyknocker\pdodb\exceptions\QueryException;
use tommyknocker\pdodb\orm\Model;

/**
 * Additional tests for Event classes to increase coverage.
 */
final class EventAdditionalTests extends BaseSharedTestCase
{
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
    public function testQueryExecutedEventGetters(): void
    {
        $event = new QueryExecutedEvent(
            'SELECT * FROM users',
            ['id' => 1],
            15.5,
            10,
            'mysql',
            true
        );

        $this->assertEquals('SELECT * FROM users', $event->getSql());
        $this->assertEquals(['id' => 1], $event->getParams());
        $this->assertEquals(15.5, $event->getExecutionTime());
        $this->assertEquals(10, $event->getRowsAffected());
        $this->assertEquals('mysql', $event->getDriver());
        $this->assertTrue($event->isFromCache());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testQueryExecutedEventNotFromCache(): void
    {
        $event = new QueryExecutedEvent(
            'SELECT * FROM users',
            [],
            10.0,
            5,
            'sqlite',
            false
        );

        $this->assertFalse($event->isFromCache());
    }

    public function testQueryErrorEventGetters(): void
    {
        $pdoException = new \PDOException('SQL error', 2002);
        $exception = new QueryException('SQL error', 'SQLSTATE[HY000]', $pdoException);
        $event = new QueryErrorEvent(
            'SELECT * FROM users',
            ['id' => 1],
            $exception,
            'mysql'
        );

        $this->assertEquals('SELECT * FROM users', $event->getSql());
        $this->assertEquals(['id' => 1], $event->getParams());
        $this->assertSame($exception, $event->getException());
        $this->assertEquals('mysql', $event->getDriver());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testTransactionStartedEvent(): void
    {
        $event = new TransactionStartedEvent('mysql');

        $this->assertEquals('mysql', $event->getDriver());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testTransactionCommittedEvent(): void
    {
        $event = new TransactionCommittedEvent('pgsql', 25.5);

        $this->assertEquals('pgsql', $event->getDriver());
        $this->assertEquals(25.5, $event->getDuration());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testTransactionRolledBackEventWithoutException(): void
    {
        $event = new TransactionRolledBackEvent('sqlite', 10.0);

        $this->assertEquals('sqlite', $event->getDriver());
        $this->assertEquals(10.0, $event->getDuration());
        $this->assertNull($event->getException());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testTransactionRolledBackEventWithException(): void
    {
        $exception = new \RuntimeException('Transaction failed');
        $event = new TransactionRolledBackEvent('mysql', 15.5, $exception);

        $this->assertEquals('mysql', $event->getDriver());
        $this->assertEquals(15.5, $event->getDuration());
        $this->assertSame($exception, $event->getException());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testModelBeforeDeleteEventStopPropagation(): void
    {
        $model = $this->createMockModel();
        $event = new ModelBeforeDeleteEvent($model);

        $this->assertFalse($event->isPropagationStopped());
        $event->stopPropagation();
        $this->assertTrue($event->isPropagationStopped());
    }

    public function testModelBeforeSaveEventGetters(): void
    {
        $model = $this->createMockModel();
        $event = new ModelBeforeSaveEvent($model, true);

        $this->assertSame($model, $event->getModel());
        $this->assertTrue($event->isNewRecord());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testModelBeforeSaveEventStopPropagation(): void
    {
        $model = $this->createMockModel();
        $event = new ModelBeforeSaveEvent($model, false);

        $this->assertFalse($event->isNewRecord());
        $this->assertFalse($event->isPropagationStopped());
        $event->stopPropagation();
        $this->assertTrue($event->isPropagationStopped());
    }

    public function testModelBeforeUpdateEventGetters(): void
    {
        $model = $this->createMockModel();
        $dirtyAttributes = ['name' => 'New Name', 'email' => 'new@example.com'];
        $event = new ModelBeforeUpdateEvent($model, $dirtyAttributes);

        $this->assertSame($model, $event->getModel());
        $this->assertEquals($dirtyAttributes, $event->getDirtyAttributes());
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testModelBeforeUpdateEventStopPropagation(): void
    {
        $model = $this->createMockModel();
        $event = new ModelBeforeUpdateEvent($model, []);

        $this->assertFalse($event->isPropagationStopped());
        $event->stopPropagation();
        $this->assertTrue($event->isPropagationStopped());
    }
}
