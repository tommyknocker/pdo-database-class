<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\postgresql;

use tommyknocker\pdodb\tests\shared\MorphPost;
use tommyknocker\pdodb\tests\shared\MorphSupport;
use tommyknocker\pdodb\tests\shared\MorphVideo;

final class MorphRelationTests extends BasePostgreSQLTestCase
{
    public function testPolymorphicRelationsBehaveTheSameOnThisDatabase(): void
    {
        MorphSupport::prepare(self::$db);

        try {
            MorphSupport::seed(self::$db, MorphPost::class, MorphVideo::class);
            $this->assertEquals(MorphSupport::expected(), MorphSupport::scenario(self::$db));
        } finally {
            MorphSupport::drop(self::$db);
        }
    }
}
