<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxMessage;

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
}
