<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\exceptions\OutboxException;
use tommyknocker\pdodb\exceptions\OutboxPermanentFailureException;
use tommyknocker\pdodb\exceptions\OutboxRetryableException;
use tommyknocker\pdodb\exceptions\TransactionException;
use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxDuration;
use tommyknocker\pdodb\outbox\OutboxMessage;
use tommyknocker\pdodb\outbox\OutboxStatuses;
use tommyknocker\pdodb\outbox\OutboxTransientError;
use tommyknocker\pdodb\outbox\OutboxUuid;

/**
 * Transactional outbox tests (shared flow; dialect-specific claim SQL tested per driver where needed).
 */
final class OutboxTests extends BaseSharedTestCase
{
    private const TABLE = 'test_outbox';

    protected function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('DROP TABLE IF EXISTS ' . self::TABLE);
        $outbox = new Outbox(self::$db, self::TABLE);
        $outbox->ensureSchema();
    }

    public function testAddRequiresActiveTransaction(): void
    {
        $outbox = new Outbox(self::$db, self::TABLE);

        $this->expectException(OutboxException::class);
        $outbox->add('order.created', ['id' => 1]);
    }

    public function testAddAndPublishInWorker(): void
    {
        $outbox = new Outbox(self::$db, self::TABLE);
        $published = [];

        self::$db->transaction(function () use ($outbox): void {
            $outbox->add('order.created', ['order_id' => 42]);
        });

        $result = $outbox->worker('test')->runOnce(function (OutboxMessage $message) use (&$published): void {
            $published[] = $message->type;
            $this->assertSame('order.created', $message->type);
            $this->assertSame(['order_id' => 42], $message->payload);
        });

        $this->assertSame(1, $result->claimed);
        $this->assertSame(1, $result->published);
        $this->assertSame(['order.created'], $published);

        $row = self::$db->find()->table(self::TABLE)->where('status', OutboxStatuses::PUBLISHED)->getOne();
        $this->assertIsArray($row);
        $this->assertNotEmpty($row['published_at']);
    }

    public function testRetryableFailureSchedulesRetry(): void
    {
        $outbox = new Outbox(self::$db, self::TABLE);

        self::$db->transaction(function () use ($outbox): void {
            $outbox->add('ping', ['n' => 1]);
        });

        $first = $outbox->worker('test', batchSize: 1, maxAttempts: 5)->runOnce(
            static function (): void {
                throw new OutboxRetryableException('temporary');
            }
        );

        $this->assertSame(1, $first->claimed);
        $this->assertSame(0, $first->published);
        $this->assertSame(1, $first->scheduledRetry);

        $row = self::$db->find()->table(self::TABLE)->getOne();
        $this->assertIsArray($row);
        $this->assertSame(OutboxStatuses::PENDING, $row['status']);
        $this->assertSame('temporary', $row['last_error']);
    }

    public function testPermanentFailureMarksFailed(): void
    {
        $outbox = new Outbox(self::$db, self::TABLE);

        self::$db->transaction(function () use ($outbox): void {
            $outbox->add('bad', []);
        });

        $result = $outbox->worker('test')->runOnce(
            static function (): void {
                throw new OutboxPermanentFailureException('invalid payload');
            }
        );

        $this->assertSame(1, $result->markedFailed);
        $row = self::$db->find()->table(self::TABLE)->getOne();
        $this->assertIsArray($row);
        $this->assertSame(OutboxStatuses::FAILED, $row['status']);
    }

    public function testNestedTransactionAddStaysPendingUntilOuterCommit(): void
    {
        $outbox = new Outbox(self::$db, self::TABLE);

        self::$db->transaction(function () use ($outbox): void {
            self::$db->transaction(function () use ($outbox): void {
                $outbox->add('nested', ['ok' => true]);
            });

            $pendingCount = (int) self::$db->rawQueryValue(
                'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE status = ?',
                [OutboxStatuses::PENDING]
            );
            $this->assertSame(1, $pendingCount);
        });

        $result = $outbox->worker('test')->runOnce(static function (): void {
        });

        $this->assertSame(1, $result->published);
    }

    public function testDurationParsing(): void
    {
        $this->assertSame(86400, OutboxDuration::toSeconds('1d'));
        $this->assertSame(3600, OutboxDuration::toSeconds('1h'));
    }

    public function testPurgeRemovesOnlyOldPublishedRows(): void
    {
        $outbox = new Outbox(self::$db, self::TABLE);
        $old = gmdate('Y-m-d H:i:s', time() - 86400 * 10);
        $recent = gmdate('Y-m-d H:i:s');

        self::$db->find()->table(self::TABLE)->insert([
            'id' => OutboxUuid::v4(),
            'event_type' => 'a',
            'payload' => '{}',
            'status' => OutboxStatuses::PUBLISHED,
            'attempts' => 1,
            'available_at' => $old,
            'created_at' => $old,
            'published_at' => $old,
        ]);
        self::$db->find()->table(self::TABLE)->insert([
            'id' => OutboxUuid::v4(),
            'event_type' => 'b',
            'payload' => '{}',
            'status' => OutboxStatuses::PENDING,
            'attempts' => 0,
            'available_at' => $recent,
            'created_at' => $recent,
        ]);

        $result = $outbox->purge('7d');
        $this->assertSame(1, $result->deleted);
        $this->assertSame(1, (int) self::$db->rawQueryValue('SELECT COUNT(*) FROM ' . self::TABLE));
    }

    public function testWorkerRefusesActiveTransaction(): void
    {
        $outbox = new Outbox(self::$db, self::TABLE);
        $published = false;

        try {
            self::$db->transaction(function () use ($outbox, &$published): void {
                $outbox->add('order.created', ['id' => 1]);
                $outbox->worker('test')->runOnce(static function () use (&$published): void {
                    $published = true;
                });
            });
            $this->fail('Worker must not run inside an open transaction.');
        } catch (OutboxException $e) {
            $this->assertStringContainsString('transaction', $e->getMessage());
        }

        $this->assertFalse($published);
        $this->assertSame(0, (int) self::$db->rawQueryValue('SELECT COUNT(*) FROM ' . self::TABLE));
    }

    public function testExpiredSqliteLockIsReleased(): void
    {
        $expired = gmdate('Y-m-d H:i:s', time() - 120);
        self::$db->find()->table(self::TABLE)->insert([
            'id' => OutboxUuid::v4(),
            'event_type' => 'stale',
            'payload' => '{}',
            'status' => OutboxStatuses::PROCESSING,
            'attempts' => 1,
            'available_at' => $expired,
            'created_at' => $expired,
            'locked_by' => 'dead-worker',
            'locked_until' => $expired,
        ]);

        $result = (new Outbox(self::$db, self::TABLE))->worker('test')->runOnce(static function (): void {
        });

        $this->assertSame(1, $result->published);
    }

    public function testExpiredLockPastMaxAttemptsIsFailedWithoutPublishing(): void
    {
        $expired = gmdate('Y-m-d H:i:s', time() - 120);
        $id = OutboxUuid::v4();
        self::$db->find()->table(self::TABLE)->insert([
            'id' => $id,
            'event_type' => 'poison',
            'payload' => '{}',
            'status' => OutboxStatuses::PROCESSING,
            'attempts' => 3,
            'available_at' => $expired,
            'created_at' => $expired,
            'locked_by' => 'dead-worker',
            'locked_until' => $expired,
        ]);

        $published = false;
        $result = (new Outbox(self::$db, self::TABLE))->worker('test', maxAttempts: 3)->runOnce(
            static function () use (&$published): void {
                $published = true;
            }
        );

        $this->assertFalse($published);
        $this->assertSame(1, $result->claimed);
        $this->assertSame(1, $result->markedFailed);
        $row = self::$db->find()->table(self::TABLE)->where('id', $id)->getOne();
        $this->assertIsArray($row);
        $this->assertSame(OutboxStatuses::FAILED, $row['status']);
    }

    public function testBatchPublishesEachMessageOnce(): void
    {
        $outbox = new Outbox(self::$db, self::TABLE);
        self::$db->transaction(function () use ($outbox): void {
            $outbox->add('a', ['n' => 1]);
            $outbox->add('b', ['n' => 2]);
        });

        $seen = [];
        $result = $outbox->worker('test', batchSize: 10)->runOnce(function (OutboxMessage $message) use (&$seen): void {
            $seen[] = $message->id;
        });

        $this->assertSame(2, $result->claimed);
        $this->assertSame(2, $result->published);
        $this->assertCount(2, array_unique($seen));
    }

    public function testLostLockIsReportedAsUnconfirmed(): void
    {
        $outbox = new Outbox(self::$db, self::TABLE);
        self::$db->transaction(function () use ($outbox): void {
            $outbox->add('order.created', ['id' => 1]);
        });

        $published = false;
        $result = $outbox->worker('test')->runOnce(function (OutboxMessage $message) use (&$published): void {
            $published = true;
            self::$db->find()->table(self::TABLE)->where('id', $message->id)->update([
                'locked_by' => 'replaced-token',
            ]);
        });

        $this->assertTrue($published);
        $this->assertSame(1, $result->claimed);
        $this->assertSame(0, $result->published);
        $this->assertSame(1, $result->unconfirmed);
        $row = self::$db->find()->table(self::TABLE)->getOne();
        $this->assertIsArray($row);
        $this->assertSame(OutboxStatuses::PROCESSING, $row['status']);
    }

    public function testDeadlockIsRetryable(): void
    {
        $deadlock = new TransactionException('SQLSTATE[40001]: Deadlock found when trying to get lock; try restarting transaction', 1213);
        $this->assertTrue(OutboxTransientError::isRetryable($deadlock));
        $this->assertFalse(OutboxTransientError::isRetryable(new OutboxException('missing publisher')));
    }

    public function testCountByStatus(): void
    {
        $outbox = new Outbox(self::$db, self::TABLE);
        self::$db->transaction(function () use ($outbox): void {
            $outbox->add('pending.event', []);
        });
        self::$db->find()->table(self::TABLE)->insert([
            'id' => OutboxUuid::v4(),
            'event_type' => 'failed.event',
            'payload' => '{}',
            'status' => OutboxStatuses::FAILED,
            'attempts' => 3,
            'available_at' => gmdate('Y-m-d H:i:s'),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'last_error' => 'boom',
        ]);

        $counts = $outbox->countByStatus();
        $this->assertSame(1, $counts->pending);
        $this->assertSame(1, $counts->failed);
        $this->assertSame(0, $counts->published);
    }

    public function testRequeueFailedThenPublish(): void
    {
        $outbox = new Outbox(self::$db, self::TABLE);
        $id = OutboxUuid::v4();
        self::$db->find()->table(self::TABLE)->insert([
            'id' => $id,
            'event_type' => 'fix.me',
            'payload' => '{}',
            'status' => OutboxStatuses::FAILED,
            'attempts' => 5,
            'available_at' => gmdate('Y-m-d H:i:s'),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'last_error' => 'permanent',
        ]);

        $this->assertTrue($outbox->requeueFailed($id));
        $counts = $outbox->countByStatus();
        $this->assertSame(1, $counts->pending);
        $this->assertSame(0, $counts->failed);

        $result = $outbox->worker('test')->runOnce(static function (): void {
        });
        $this->assertSame(1, $result->published);
    }

    public function testRequeueAllFailedDryRun(): void
    {
        $outbox = new Outbox(self::$db, self::TABLE);
        self::$db->find()->table(self::TABLE)->insert([
            'id' => OutboxUuid::v4(),
            'event_type' => 'x',
            'payload' => '{}',
            'status' => OutboxStatuses::FAILED,
            'attempts' => 1,
            'available_at' => gmdate('Y-m-d H:i:s'),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $result = $outbox->requeueAllFailed(10, true, true);
        $this->assertSame(1, $result->matched);
        $this->assertSame(0, $result->requeued);
        $this->assertTrue($result->dryRun);
        $this->assertSame(1, $outbox->countByStatus()->failed);
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
