<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use tommyknocker\pdodb\inbox\Inbox;

final class InboxTests extends BasePostgreSQLTestCase
{
    private const string TABLE = 'pdodb_inbox_probe';

    public function testProcessedAtIsFilledByDatabaseClock(): void
    {
        self::$db->rawQuery('DROP TABLE IF EXISTS ' . self::TABLE);
        $inbox = new Inbox(self::$db, 'billing', self::TABLE);
        $inbox->ensureSchema();

        $this->assertTrue($inbox->once('message-1', static function (): void {
        }));
        $this->assertFalse($inbox->once('message-1', static function (): void {
        }));
        $this->assertTrue($inbox->processed('message-1'));
        $this->assertNotEmpty(self::$db->find()->from(self::TABLE)->select(['processed_at'])->getValue());

        self::$db->rawQuery('DROP TABLE IF EXISTS ' . self::TABLE);
    }
}
