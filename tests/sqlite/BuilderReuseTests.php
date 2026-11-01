<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\sqlite;

use tommyknocker\pdodb\tests\shared\BuilderReuseSupport;

final class BuilderReuseTests extends BaseSqliteTestCase
{
    public function testABuilderCanRunMoreThanOnce(): void
    {
        $this->assertSame(BuilderReuseSupport::expected(), BuilderReuseSupport::run(self::$db));
    }
}
