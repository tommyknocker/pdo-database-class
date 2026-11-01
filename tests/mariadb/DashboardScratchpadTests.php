<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\mariadb;

use tommyknocker\pdodb\tests\shared\DashboardScratchpadSupport;

final class DashboardScratchpadTests extends BaseMariaDBTestCase
{
    public function testScratchpadReportsRowsAndAffectedRows(): void
    {
        $result = DashboardScratchpadSupport::run(self::$db, 'CREATE TABLE dash_scratch (id INT PRIMARY KEY, name VARCHAR(50))');

        $this->assertNull($result['error']);
        $this->assertSame(2, $result['affectedAfterUpdate']);
        $this->assertSame(3, $result['affectedAfterSelect']);
        $this->assertEquals([['id' => 1, 'name' => 'x'], ['id' => 2, 'name' => 'x'], ['id' => 3, 'name' => 'c']], $result['rows']);
    }
}
