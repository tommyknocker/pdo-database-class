<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use Psr\EventDispatcher\EventDispatcherInterface;
use tommyknocker\pdodb\events\TransactionCommittedEvent;
use tommyknocker\pdodb\events\TransactionRolledBackEvent;
use tommyknocker\pdodb\events\TransactionStartedEvent;
use tommyknocker\pdodb\PdoDb;

/**
 * EventDispatcherTests tests for shared.
 */
final class EventDispatcherTests extends BaseSharedTestCase
{
    public function testTransactionEvents(): void
    {
        $events = [];
        $dispatcher = $this->createMockDispatcher($events);

        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->setEventDispatcher($dispatcher);

        $db->startTransaction();
        $db->commit();

        $startedEvents = array_filter($events, static fn ($e) => $e instanceof TransactionStartedEvent);
        $committedEvents = array_filter($events, static fn ($e) => $e instanceof TransactionCommittedEvent);

        $this->assertCount(1, $startedEvents);
        $this->assertCount(1, $committedEvents);

        /** @var TransactionStartedEvent $started */
        $started = array_values($startedEvents)[0];
        $this->assertEquals('sqlite', $started->getDriver());

        /** @var TransactionCommittedEvent $committed */
        $committed = array_values($committedEvents)[0];
        $this->assertEquals('sqlite', $committed->getDriver());
        $this->assertGreaterThanOrEqual(0, $committed->getDuration());
    }

    public function testTransactionRollbackEvent(): void
    {
        $events = [];
        $dispatcher = $this->createMockDispatcher($events);

        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->setEventDispatcher($dispatcher);

        $db->startTransaction();
        $db->rollback();

        $rollbackEvents = array_filter($events, static fn ($e) => $e instanceof TransactionRolledBackEvent);

        $this->assertCount(1, $rollbackEvents);

        /** @var TransactionRolledBackEvent $event */
        $event = array_values($rollbackEvents)[0];
        $this->assertEquals('sqlite', $event->getDriver());
        $this->assertGreaterThanOrEqual(0, $event->getDuration());
    }

    public function testEventDispatcherGetterSetter(): void
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $this->assertNull($db->getEventDispatcher());

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $db->setEventDispatcher($dispatcher);
        $this->assertSame($dispatcher, $db->getEventDispatcher());

        $db->setEventDispatcher(null);
        $this->assertNull($db->getEventDispatcher());
    }

    public function testNoDispatcherNoEvents(): void
    {
        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        // No dispatcher set

        // Should not throw or error
        $db->rawQuery('CREATE TABLE test (id INTEGER)');
        $db->find()->table('test')->insert(['id' => 1]);

        $this->assertTrue(true); // Test passes if no exceptions
    }
}
