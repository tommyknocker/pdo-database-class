<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\outbox\Outbox;

final class OutboxFeatureTests extends BaseSharedTestCase
{
    public function testHeadersOrderingEventsAndSerializers(): void
    {
        self::$db->rawQuery('DROP TABLE IF EXISTS outbox_features');
        (new Outbox(self::$db, 'outbox_features'))->ensureSchema();

        try {
            $this->assertEquals(OutboxFeatureSupport::expected(), OutboxFeatureSupport::run(self::$db, 'outbox_features'));
        } finally {
            self::$db->rawQuery('DROP TABLE IF EXISTS outbox_features');
        }
    }
}
