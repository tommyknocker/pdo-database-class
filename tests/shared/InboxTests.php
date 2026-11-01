<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use RuntimeException;
use tommyknocker\pdodb\exceptions\InboxException;
use tommyknocker\pdodb\inbox\Inbox;

final class InboxTests extends BaseSharedTestCase
{
    private const string TABLE = 'shared_inbox';

    protected function setUp(): void
    {
        parent::setUp();
        self::$db->rawQuery('DROP TABLE IF EXISTS ' . self::TABLE);
        self::$db->rawQuery('DROP TABLE IF EXISTS inbox_effects');
        self::$db->rawQuery('CREATE TABLE inbox_effects (id INTEGER PRIMARY KEY AUTOINCREMENT, note TEXT)');
        (new Inbox(self::$db, 'billing', self::TABLE))->ensureSchema();
    }

    private function effects(): int
    {
        return (int) self::$db->rawQueryValue('SELECT COUNT(*) FROM inbox_effects');
    }

    public function testDuplicateDeliveryRunsTheHandlerOnce(): void
    {
        $inbox = new Inbox(self::$db, 'billing', self::TABLE);
        $calls = 0;
        $handler = function () use (&$calls): void {
            $calls++;
            self::$db->rawQuery("INSERT INTO inbox_effects (note) VALUES ('done')");
        };

        $this->assertTrue($inbox->once('m-1', $handler));
        $this->assertFalse($inbox->once('m-1', $handler));
        $this->assertSame(1, $calls);
        $this->assertSame(1, $this->effects());
        $this->assertTrue($inbox->processed('m-1'));
        $this->assertFalse($inbox->processed('m-2'));
    }

    public function testFailingHandlerRollsBackTheClaimAndItsWrites(): void
    {
        $inbox = new Inbox(self::$db, 'billing', self::TABLE);

        try {
            $inbox->once('m-1', function (): void {
                self::$db->rawQuery("INSERT INTO inbox_effects (note) VALUES ('half')");

                throw new RuntimeException('handler failed');
            });
            $this->fail('The handler exception should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('handler failed', $exception->getMessage());
        }

        $this->assertFalse($inbox->processed('m-1'));
        $this->assertSame(0, $this->effects());
        $this->assertTrue($inbox->once('m-1', static function (): void {
        }));
    }

    public function testConsumersAreIsolated(): void
    {
        $billing = new Inbox(self::$db, 'billing', self::TABLE);
        $shipping = new Inbox(self::$db, 'shipping', self::TABLE);
        $calls = [];

        $this->assertTrue($billing->once('m-1', function () use (&$calls): void {
            $calls[] = 'billing';
        }));
        $this->assertTrue($shipping->once('m-1', function () use (&$calls): void {
            $calls[] = 'shipping';
        }));
        $this->assertFalse($billing->once('m-1', static function (): void {
        }));

        $this->assertSame(['billing', 'shipping'], $calls);
        $this->assertTrue($shipping->processed('m-1'));
    }

    public function testDuplicateInsideAnOuterTransactionKeepsTheOuterWork(): void
    {
        $inbox = new Inbox(self::$db, 'billing', self::TABLE);
        $inbox->once('m-1', static function (): void {
        });

        self::$db->transaction(function () use ($inbox): void {
            self::$db->rawQuery("INSERT INTO inbox_effects (note) VALUES ('outer')");
            $this->assertFalse($inbox->once('m-1', static function (): void {
            }));
            $this->assertTrue($inbox->once('m-2', function (): void {
                self::$db->rawQuery("INSERT INTO inbox_effects (note) VALUES ('inner')");
            }));
        });

        $this->assertSame(2, $this->effects());
        $this->assertTrue($inbox->processed('m-2'));
    }

    public function testEmptyIdentifiersAreRejected(): void
    {
        $this->expectException(InboxException::class);
        (new Inbox(self::$db, 'billing', self::TABLE))->once('', static function (): void {
        });
    }

    public function testEmptyConsumerIsRejected(): void
    {
        $this->expectException(InboxException::class);
        (new Inbox(self::$db, '', self::TABLE))->once('m-1', static function (): void {
        });
    }
}
