<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxMessage;
use tommyknocker\pdodb\outbox\OutboxStatuses;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\tests\shared\OutboxTestSupport;

final class OutboxTests extends BaseMariaDBTestCase
{
    private const string TABLE = 'pdodb_outbox_probe';

    public function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    public function testSchemaAddAndBatchPublish(): void
    {
        $outbox = new Outbox(self::$db, self::TABLE);
        $outbox->ensureSchema();

        self::$db->transaction(function () use ($outbox): void {
            $outbox->add('a', ['n' => 1]);
            $outbox->add('b', ['n' => 2]);
        });

        $this->assertSame(2, (int) self::$db->rawQueryValue('SELECT COUNT(*) FROM ' . self::TABLE));

        $seen = [];
        $result = $outbox->worker('mariadb', batchSize: 10)->runOnce(function (OutboxMessage $message) use (&$seen): void {
            $seen[] = $message->type;
        });

        sort($seen);
        $this->assertSame(2, $result->claimed);
        $this->assertSame(2, $result->published);
        $this->assertSame(['a', 'b'], $seen);
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
            'mariadb',
            [
                'host' => self::DB_HOST,
                'port' => self::DB_PORT,
                'username' => self::DB_USER,
                'password' => self::DB_PASSWORD,
                'dbname' => self::DB_NAME,
                'charset' => self::DB_CHARSET,
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

    public function testClaimBatchTakesOldestDueRowsUpToTheLimit(): void
    {
        (new Outbox(self::$db, self::TABLE))->ensureSchema();

        $result = OutboxTestSupport::exerciseBatchClaim(self::$db, self::TABLE);

        $this->assertSame(['batch-e', 'batch-d', 'batch-c'], $result['first']);
        $this->assertSame(['batch-b', 'batch-a'], $result['second']);
        $this->assertSame([], $result['third']);
        $this->assertSame(['batch-worker-1'], $result['locked']);
    }
}
