<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

final class BuilderReuseTests extends BaseSharedTestCase
{
    public function testABuilderCanRunMoreThanOnce(): void
    {
        $this->assertSame(BuilderReuseSupport::expected(), BuilderReuseSupport::run(self::$db));
    }
}
