<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\exceptions\OptimisticLockException;
use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\PdoDb;

final class VersionedOrder extends Model
{
    public static function tableName(): string
    {
        return 'orders';
    }
    public static function optimisticLock(): ?string
    {
        return 'version';
    }
}

final class OptimisticLockTests extends TestCase
{
    private PdoDb $db;

    protected function setUp(): void
    {
        $this->db = new PdoDb('sqlite', ['path' => ':memory:']);
        $this->db->rawQuery('CREATE TABLE orders (id INTEGER PRIMARY KEY, status TEXT, version INTEGER NOT NULL)');
        $this->db->rawQuery("INSERT INTO orders VALUES (1, 'pending', 1)");
        VersionedOrder::setDb($this->db);
    }

    public function testConcurrentUpdatesDoNotOverwriteChanges(): void
    {
        $first = VersionedOrder::findOne(1);
        $second = VersionedOrder::findOne(1);
        $first->status = 'paid';
        self::assertTrue($first->save());
        self::assertSame(2, $first->version);
        $second->status = 'cancelled';

        try {
            $second->save();
            self::fail('Expected optimistic lock conflict');
        } catch (OptimisticLockException) {
            self::assertSame('paid', VersionedOrder::findOne(1)->status);
            self::assertSame(2, VersionedOrder::findOne(1)->version);
        }
        self::assertTrue($second->refresh());
        $second->status = 'cancelled';
        self::assertTrue($second->save());
        self::assertSame(3, $second->version);
    }

    public function testStaleDeleteDoesNotRemoveRow(): void
    {
        $first = VersionedOrder::findOne(1);
        $second = VersionedOrder::findOne(1);
        $first->status = 'paid';
        $first->save();

        try {
            $second->delete();
            self::fail('Expected optimistic lock conflict');
        } catch (OptimisticLockException) {
            self::assertNotNull(VersionedOrder::findOne(1));
        }
    }

    public function testNewRecordStartsWithVersionOne(): void
    {
        $order = new VersionedOrder();
        $order->id = 2;
        $order->status = 'pending';
        self::assertTrue($order->save());
        self::assertSame(1, $order->version);
        self::assertSame(1, VersionedOrder::findOne(2)->version);
    }

    public function testMissingVersionCannotBeUsedForUpdate(): void
    {
        $order = VersionedOrder::find()->select(['id', 'status'])->one();
        $order->status = 'paid';
        $this->expectException(OptimisticLockException::class);
        $order->save();
    }

    public function testManuallyChangedVersionIsRejected(): void
    {
        $order = VersionedOrder::findOne(1);
        $order->version = 99;
        $this->expectException(OptimisticLockException::class);
        $order->save();
    }
}
