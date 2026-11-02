<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

use tommyknocker\pdodb\outbox\Outbox;
use tommyknocker\pdodb\tests\shared\OutboxFeatureSupport;

final class OutboxFeatureTests extends BaseMariaDBTestCase
{
    public function testHeadersOrderingEventsAndSerializersOnThisDatabase(): void
    {
        try {
            $this->assertEquals(OutboxFeatureSupport::expected(), OutboxFeatureSupport::run(self::$db, 'outbox_features'));
        } finally {
            self::$db->schema()->dropTableIfExists('outbox_features');
        }
    }

    public function testAnOlderTableIsUpgradedInPlace(): void
    {
        $this->assertSame([
            'old_row_type' => 'old.event',
            'old_row_headers' => [],
            'old_row_key' => null,
            'new_row_headers' => ['trace' => 't'],
            'new_row_key' => 'key-1',
        ], OutboxFeatureSupport::upgradeOldTable(self::$db, 'outbox_old_layout'));
    }

    public function testAnOlderTableGetsItsPartitionIndex(): void
    {
        OutboxFeatureSupport::createOldLayout(self::$db, 'outbox_old_index');

        try {
            (new Outbox(self::$db, 'outbox_old_index'))->ensureSchema();

            $this->assertSame(1, (int)self::$db->rawQueryValue(
                "SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'outbox_old_index' AND index_name = 'outbox_old_index_partition_idx'"
            ));
        } finally {
            self::$db->schema()->dropTableIfExists('outbox_old_index');
        }
    }
}
