<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use tommyknocker\pdodb\tests\shared\RetentionSupport;

final class RetentionTests extends BasePostgreSQLTestCase
{
    public function testInboxPurgeDeletesOldRowsInBatchesAndKeepsRecentOnes(): void
    {
        $result = RetentionSupport::purgeInbox(self::$db, 'retention_inbox');

        $this->assertSame(5, $result['dryRun']);
        $this->assertSame(5, $result['deleted']);
        $this->assertSame(1, $result['left']);
    }

    public function testOutboxPurgeDeletesInBatches(): void
    {
        $result = RetentionSupport::purgeOutbox(self::$db, 'retention_outbox');

        $this->assertSame(5, $result['deleted']);
        $this->assertSame(1, $result['left']);
    }
}
