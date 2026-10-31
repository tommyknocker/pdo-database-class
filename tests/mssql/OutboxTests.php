<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mssql;

use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxMessage;

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
}
