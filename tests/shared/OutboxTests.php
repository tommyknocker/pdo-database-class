<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\exceptions\OutboxException;
use tommyknocker\pdodb\exceptions\OutboxPermanentFailureException;
use tommyknocker\pdodb\exceptions\OutboxRetryableException;
use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxMessage;
use tommyknocker\pdodb\outbox\OutboxStatuses;

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
}
