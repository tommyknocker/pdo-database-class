<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cli\ui\Dashboard;

/**
 * Tests for Dashboard class.
 */
final class DashboardTests extends BaseSharedTestCase
{
    public function testDashboardConstructor(): void
    {
        // Dashboard constructor should not throw
        $dashboard = new Dashboard(self::$db);
        $this->assertInstanceOf(Dashboard::class, $dashboard);
    }

    public function testDashboardCanBeInstantiated(): void
    {
        // Test that Dashboard can be created with a database instance
        $dashboard = new Dashboard(self::$db);
        $this->assertInstanceOf(Dashboard::class, $dashboard);
    }
}
