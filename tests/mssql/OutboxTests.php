<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxMessage;
use tommyknocker\pdodb\outbox\OutboxStatuses;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\tests\shared\OutboxTestSupport;

final class OutboxTests extends BaseMSSQLTestCase
{
    private const string TABLE = 'pdodb_outbox_probe';

    public function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    public function testSchemaAddAndPublish(): void
    {
        $outbox = new Outbox(self::$db, self::TABLE);
        $outbox->ensureSchema();

        self::$db->transaction(function () use ($outbox): void {
            $outbox->add('mssql.event', ['ok' => true]);
        });

        $result = $outbox->worker('mssql')->runOnce(static function (OutboxMessage $message): void {
            if ($message->payload !== ['ok' => true]) {
                throw new \RuntimeException('unexpected payload');
            }
        });

        $this->assertSame(1, $result->claimed);
        $this->assertSame(1, $result->published);
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
        $username = getenv('PDODB_USERNAME');
        if ($username === false || $username === '') {
            $username = self::DB_USER;
        }
        $password = getenv('PDODB_PASSWORD');
        if ($password === false || $password === '') {
            $password = self::DB_PASSWORD;
        }

        return new PdoDb(
            'sqlsrv',
            [
                'host' => self::host(),
                'port' => self::port(),
                'username' => $username,
                'password' => $password,
                'dbname' => self::DB_NAME,
                'trust_server_certificate' => true,
                'encrypt' => true,
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

    public function testUnreadablePayloadIsFailedWithoutBlockingTheBatch(): void
    {
        (new Outbox(self::$db, self::TABLE))->ensureSchema();

        $result = OutboxTestSupport::exercisePoisonPayload(self::$db, self::TABLE);

        $this->assertSame(3, $result['claimed']);
        $this->assertSame(2, $result['published']);
        $this->assertSame(1, $result['markedFailed']);
        $this->assertSame(['poison-a', 'poison-c'], $result['seen']);
        $this->assertSame(OutboxStatuses::FAILED, $result['status']);
        $this->assertStringContainsString('Unreadable payload', (string) $result['error']);
    }

    public function testDelayedMessageIsNotClaimedBeforeItsTime(): void
    {
        (new Outbox(self::$db, self::TABLE))->ensureSchema();

        $result = OutboxTestSupport::exerciseDelayedMessage(self::$db, self::TABLE);

        $this->assertSame(['delay.now'], $result['seen']);
        $this->assertSame(1, $result['pending']);
    }
}
