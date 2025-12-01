<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use Psr\EventDispatcher\EventDispatcherInterface;
use tommyknocker\pdodb\cache\CacheManager;
use tommyknocker\pdodb\events\CacheClearedEvent;
use tommyknocker\pdodb\events\CacheDeleteEvent;
use tommyknocker\pdodb\events\CacheHitEvent;
use tommyknocker\pdodb\events\CacheMissEvent;
use tommyknocker\pdodb\events\CacheSetEvent;
use tommyknocker\pdodb\events\DdlOperationEvent;
use tommyknocker\pdodb\events\QueryBeforeExecuteEvent;
use tommyknocker\pdodb\events\SavepointCreatedEvent;
use tommyknocker\pdodb\events\SavepointReleasedEvent;
use tommyknocker\pdodb\events\SavepointRolledBackEvent;
use tommyknocker\pdodb\events\TransactionCommittedEvent;
use tommyknocker\pdodb\events\TransactionRolledBackEvent;
use tommyknocker\pdodb\events\TransactionStartedEvent;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\tests\fixtures\ArrayCache;

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

    public function testQueryBeforeExecuteEvent(): void
    {
        $events = [];
        $dispatcher = $this->createMockDispatcher($events);

        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->setEventDispatcher($dispatcher);

        // Create table
        $db->rawQuery('CREATE TABLE test (id INTEGER)');

        // Execute a query
        $db->rawQuery('INSERT INTO test (id) VALUES (1)');

        $beforeExecuteEvents = array_filter($events, static fn ($e) => $e instanceof QueryBeforeExecuteEvent);

        $this->assertGreaterThan(0, count($beforeExecuteEvents));
    }

    public function testQueryBeforeExecuteEventModification(): void
    {
        $events = [];
        $dispatcher = $this->createMockDispatcher($events);

        // Modify dispatcher to change SQL
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')
            ->willReturnCallback(function (object $event) use (&$events) {
                $events[] = $event;
                if ($event instanceof QueryBeforeExecuteEvent) {
                    // Modify SQL to add WHERE clause
                    $sql = $event->getSql();
                    if (str_contains($sql, 'SELECT')) {
                        $event->setSql(str_replace('SELECT', 'SELECT', $sql) . ' WHERE 1=1');
                    }
                }
                return $event;
            });

        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->setEventDispatcher($dispatcher);

        $db->rawQuery('CREATE TABLE test (id INTEGER)');
        $db->rawQuery('SELECT * FROM test');

        $beforeExecuteEvents = array_filter($events, static fn ($e) => $e instanceof QueryBeforeExecuteEvent);
        $this->assertGreaterThan(0, count($beforeExecuteEvents));
    }

    public function testSavepointEvents(): void
    {
        $events = [];
        $dispatcher = $this->createMockDispatcher($events);

        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->setEventDispatcher($dispatcher);

        $db->startTransaction();
        $db->savepoint('sp1');
        $db->rollbackToSavepoint('sp1');
        $db->startTransaction();
        $db->savepoint('sp2');
        $db->releaseSavepoint('sp2');
        $db->commit();

        $createdEvents = array_filter($events, static fn ($e) => $e instanceof SavepointCreatedEvent);
        $rolledBackEvents = array_filter($events, static fn ($e) => $e instanceof SavepointRolledBackEvent);
        $releasedEvents = array_filter($events, static fn ($e) => $e instanceof SavepointReleasedEvent);

        $this->assertCount(2, $createdEvents);
        $this->assertCount(1, $rolledBackEvents);
        $this->assertCount(1, $releasedEvents);
    }

    public function testCacheEvents(): void
    {
        $events = [];
        $dispatcher = $this->createMockDispatcher($events);

        $cache = new ArrayCache();
        $cacheManager = new CacheManager($cache, ['enabled' => true, 'default_ttl' => 60]);
        $cacheManager->setEventDispatcher($dispatcher);

        // Test cache set
        $cacheManager->set('key1', 'value1', 3600, ['users']);
        $setEvents = array_filter($events, static fn ($e) => $e instanceof CacheSetEvent);
        $this->assertCount(1, $setEvents);

        // Test cache hit
        $cacheManager->get('key1');
        $hitEvents = array_filter($events, static fn ($e) => $e instanceof CacheHitEvent);
        $this->assertCount(1, $hitEvents);

        // Test cache miss
        $cacheManager->get('key2');
        $missEvents = array_filter($events, static fn ($e) => $e instanceof CacheMissEvent);
        $this->assertCount(1, $missEvents);

        // Test cache delete
        $cacheManager->delete('key1');
        $deleteEvents = array_filter($events, static fn ($e) => $e instanceof CacheDeleteEvent);
        $this->assertCount(1, $deleteEvents);

        // Test cache clear
        $cacheManager->set('key3', 'value3');
        $cacheManager->clear();
        $clearedEvents = array_filter($events, static fn ($e) => $e instanceof CacheClearedEvent);
        $this->assertCount(1, $clearedEvents);
    }

    public function testDdlEvents(): void
    {
        $events = [];
        $dispatcher = $this->createMockDispatcher($events);

        $db = new PdoDb('sqlite', ['path' => ':memory:']);
        $db->setEventDispatcher($dispatcher);

        // Test create table
        $db->schema()->createTable('test_table', [
            'id' => 'INTEGER PRIMARY KEY',
            'name' => 'TEXT',
        ]);

        $ddlEvents = array_filter($events, static fn ($e) => $e instanceof DdlOperationEvent);
        $this->assertGreaterThan(0, count($ddlEvents));

        $createTableEvents = array_filter($ddlEvents, static fn ($e) => $e->getOperation() === 'CREATE_TABLE');
        $this->assertGreaterThan(0, count($createTableEvents));

        // Test drop table
        $db->schema()->dropTable('test_table');
        $dropTableEvents = array_filter($events, static fn ($e) =>
            $e instanceof DdlOperationEvent && $e->getOperation() === 'DROP_TABLE');
        $this->assertGreaterThan(0, count($dropTableEvents));
    }
}
