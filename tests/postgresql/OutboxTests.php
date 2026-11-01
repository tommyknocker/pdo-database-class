<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxMessage;
use tommyknocker\pdodb\outbox\OutboxStatuses;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\tests\shared\OutboxTestSupport;

final class OutboxTests extends BasePostgreSQLTestCase
{
    private const string TABLE = 'pdodb_outbox_probe';

    public function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    public function testAddCommitsWithBusinessWriteAndWorkerPublishes(): void
    {
        $outbox = new Outbox(self::$db, self::TABLE);
        $outbox->ensureSchema();

        self::$db->rawQuery('DROP TABLE IF EXISTS pdodb_outbox_orders');
        self::$db->rawQuery('CREATE TABLE pdodb_outbox_orders (id VARCHAR(36) PRIMARY KEY, status VARCHAR(32) NOT NULL)');

        self::$db->transaction(function () use ($outbox): void {
            self::$db->rawQuery(
                'INSERT INTO pdodb_outbox_orders (id, status) VALUES (:id, :status)',
                ['id' => 'order-1', 'status' => 'new']
            );
            $outbox->add('order.created', ['id' => 'order-1']);
        });

        $this->assertSame(1, (int) self::$db->rawQueryValue('SELECT COUNT(*) FROM pdodb_outbox_orders'));
        $this->assertSame(1, (int) self::$db->rawQueryValue('SELECT COUNT(*) FROM ' . self::TABLE));

        $result = $outbox->worker('pgsql')->runOnce(static function (OutboxMessage $message): void {
            if ($message->type !== 'order.created') {
                throw new \RuntimeException('unexpected type');
            }
        });

        $this->assertSame(1, $result->published);
        self::$db->rawQuery('DROP TABLE IF EXISTS pdodb_outbox_orders');
    }

    public function testUuidInsertInsideTransactionIsNotRolledBack(): void
    {
        self::$db->rawQuery('DROP TABLE IF EXISTS pdodb_uuid_insert');
        self::$db->rawQuery('CREATE TABLE pdodb_uuid_insert (id VARCHAR(36) PRIMARY KEY, name VARCHAR(32) NOT NULL)');

        self::$db->transaction(function (): void {
            self::$db->find()->table('pdodb_uuid_insert')->insert([
                'id' => '11111111-1111-4111-8111-111111111111',
                'name' => 'kept',
            ]);
        });

        $this->assertSame(1, (int) self::$db->rawQueryValue('SELECT COUNT(*) FROM pdodb_uuid_insert'));
        self::$db->rawQuery('DROP TABLE IF EXISTS pdodb_uuid_insert');
    }

    public function testIndependentConnectionsPublishEachMessageOnce(): void
    {
        $outbox = new Outbox(self::$db, self::TABLE);
        $outbox->ensureSchema();

        $expectedIds = OutboxTestSupport::enqueueMany(self::$db, self::TABLE, 20);
        $published = OutboxTestSupport::publishWithIndependentWorkers(
            fn (): PdoDb => $this->createIndependentConnection(),
            self::TABLE,
            workerCount: 4,
            batchSize: 5,
        );

        $this->assertCount(20, $expectedIds);
        $this->assertEqualsCanonicalizing($expectedIds, $published);
        $this->assertSame(
            20,
            (int) self::$db->rawQueryValue(
                'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE status = ?',
                [OutboxStatuses::PUBLISHED]
            )
        );
    }

    private function createIndependentConnection(): PdoDb
    {
        return new PdoDb(
            'pgsql',
            [
                'host' => self::DB_HOST,
                'port' => self::DB_PORT,
                'username' => self::DB_USER,
                'password' => self::DB_PASSWORD,
                'dbname' => self::DB_NAME,
            ]
        );
    }

    public function testNativeTimestampColumnsSupportPurgeLeaseAndRequeue(): void
    {
        (new Outbox(self::$db, self::TABLE))->ensureSchema();

        $result = OutboxTestSupport::exerciseTimestampColumns(self::$db, self::TABLE);

        $this->assertSame(1, $result['purged']);
        $this->assertSame(1, $result['listed']);
        $this->assertTrue($result['requeued']);
        $this->assertSame(2, $result['published']);
    }
}
