<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use Throwable;
use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxMessage;
use tommyknocker\pdodb\outbox\OutboxStatuses;
use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\tests\shared\OutboxTestSupport;

final class OutboxTests extends BaseOracleTestCase
{
    private const string TABLE = 'pdodb_outbox_probe';

    public function setUp(): void
    {
        parent::setUp();
        try {
            self::$db->rawQuery('DROP TABLE ' . self::TABLE . ' CASCADE CONSTRAINTS');
        } catch (Throwable) {
            // Table is absent on the first run.
        }
    }

    public function testSchemaAddAndPublish(): void
    {
        $outbox = new Outbox(self::$db, self::TABLE);
        $outbox->ensureSchema();

        self::$db->transaction(function () use ($outbox): void {
            $outbox->add('oracle.event', ['ok' => true]);
        });

        $result = $outbox->worker('oracle')->runOnce(static function (OutboxMessage $message): void {
            if ($message->type !== 'oracle.event') {
                throw new \RuntimeException('unexpected type');
            }
        });

        $this->assertSame(1, $result->claimed);
        $this->assertSame(1, $result->published);
    }

    public function testSecondWorkerSkipsLockedRow(): void
    {
        $outbox = new Outbox(self::$db, self::TABLE);
        $outbox->ensureSchema();
        self::$db->transaction(function () use ($outbox): void {
            $outbox->add('first', ['n' => 1]);
            $outbox->add('second', ['n' => 2]);
        });

        $dialect = self::$db->schema()->getDialect();
        $pending = $dialect->buildOutboxPendingIdsSql(self::TABLE);
        $lock = $dialect->buildOutboxLockCandidateSql(self::TABLE);
        $this->assertNotNull($pending);
        $this->assertNotNull($lock);

        [$listSql, $listParams] = $pending;
        $listParams['now'] = gmdate('Y-m-d\TH:i:s\Z');
        $ids = self::$db->rawQuery($listSql, $listParams);
        $this->assertCount(2, $ids);
        $heldId = (string) (array_change_key_case($ids[0], CASE_LOWER)['id'] ?? '');

        self::$db->startTransaction();
        [$sql, $params] = $lock;
        $params['id'] = $heldId;
        $held = self::$db->rawQueryOne($sql, $params);
        $this->assertIsArray($held);

        $other = $this->createIndependentConnection();

        $seen = [];
        $result = (new Outbox($other, self::TABLE))->worker('other', batchSize: 10)->runOnce(
            static function (OutboxMessage $message) use (&$seen): void {
                $seen[] = $message->id;
            }
        );

        self::$db->rollback();

        $this->assertSame(1, $result->published);
        $this->assertNotContains($heldId, $seen);
        $this->assertSame(1, (int) self::$db->rawQueryValue(
            'SELECT COUNT(*) FROM ' . self::TABLE . " WHERE status = 'pending'"
        ));
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
        return new PdoDb('oci', [
            'host' => getenv('PDODB_HOST') ?: self::DB_HOST,
            'port' => (int) (getenv('PDODB_PORT') ?: (string) self::DB_PORT),
            'username' => getenv('PDODB_USERNAME') ?: self::DB_USER,
            'password' => getenv('PDODB_PASSWORD') ?: self::DB_PASSWORD,
            'service_name' => getenv('PDODB_SERVICE_NAME') ?: getenv('PDODB_SID') ?: self::DB_SERVICE_NAME,
            'charset' => getenv('PDODB_CHARSET') ?: self::DB_CHARSET,
        ]);
    }
}
