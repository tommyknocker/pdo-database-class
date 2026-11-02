<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\oracle;

use tommyknocker\pdodb\tests\shared\OutboxFeatureSupport;

final class OutboxFeatureTests extends BaseOracleTestCase
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
}
