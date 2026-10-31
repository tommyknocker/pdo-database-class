<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use Throwable;
use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\outbox\OutboxMessage;

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
}
