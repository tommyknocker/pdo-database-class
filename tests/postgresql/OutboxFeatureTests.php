<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use tommyknocker\pdodb\tests\shared\OutboxFeatureSupport;

final class OutboxFeatureTests extends BasePostgreSQLTestCase
{
    public function testHeadersOrderingEventsAndSerializersOnThisDatabase(): void
    {
        try {
            $this->assertEquals(OutboxFeatureSupport::expected(), OutboxFeatureSupport::run(self::$db, 'outbox_features'));
        } finally {
            self::$db->schema()->dropTableIfExists('outbox_features');
        }
    }
}
