<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use tommyknocker\pdodb\cli\ui\Layout;
use tommyknocker\pdodb\cli\ui\panes\ActiveQueriesPane;
use tommyknocker\pdodb\cli\ui\panes\CacheStatsPane;
use tommyknocker\pdodb\cli\ui\panes\ConnectionPoolPane;
use tommyknocker\pdodb\cli\ui\panes\MigrationManagerPane;
use tommyknocker\pdodb\cli\ui\panes\SchemaBrowserPane;
use tommyknocker\pdodb\cli\ui\panes\ServerMetricsPane;
use tommyknocker\pdodb\cli\ui\panes\ServerVariablesPane;
use tommyknocker\pdodb\cli\ui\panes\SqlScratchpadPane;

/**
 * Tests for UI panes.
 */
final class UiPanesTests extends BaseSharedTestCase
{
    protected Layout $layout;

    protected function setUp(): void
    {
        parent::setUp();
        // Layout requires rows and cols parameters
        $this->layout = new Layout(24, 80);
    }

    public function testActiveQueriesPaneRender(): void
    {
        // Test that render method can be called without errors
        ob_start();
        ActiveQueriesPane::render(
            self::$db,
            $this->layout,
            Layout::PANE_QUERIES,
            true,
            0,
            0,
            false,
            []
        );
        $output = ob_get_clean();
        $this->assertIsString($output);
    }

    public function testCacheStatsPaneRender(): void
    {
        // Test that render method can be called without errors
        ob_start();
        CacheStatsPane::render(
            self::$db,
            $this->layout,
            Layout::PANE_CACHE,
            true
        );
        $output = ob_get_clean();
        $this->assertIsString($output);
    }

    public function testConnectionPoolPaneRender(): void
    {
        // Test that render method can be called without errors
        ob_start();
        ConnectionPoolPane::render(
            self::$db,
            $this->layout,
            Layout::PANE_CONNECTIONS,
            true,
            0,
            0,
            false
        );
        $output = ob_get_clean();
        $this->assertIsString($output);
    }

    public function testServerMetricsPaneRender(): void
    {
        // Test that render method can be called without errors
        ob_start();
        ServerMetricsPane::render(
            self::$db,
            $this->layout,
            Layout::PANE_METRICS,
            true,
            null
        );
        $output = ob_get_clean();
        $this->assertIsString($output);
    }

    public function testSchemaBrowserPaneRender(): void
    {
        // Test that render method can be called without errors
        ob_start();
        SchemaBrowserPane::render(
            self::$db,
            $this->layout,
            Layout::PANE_SCHEMA,
            true,
            0,
            0,
            false,
            null
        );
        $output = ob_get_clean();
        $this->assertIsString($output);
    }

    public function testSchemaBrowserPaneRenderWithSearchFilter(): void
    {
        // Test that render method can be called with search filter
        ob_start();
        SchemaBrowserPane::render(
            self::$db,
            $this->layout,
            Layout::PANE_SCHEMA,
            true,
            0,
            0,
            false,
            'test'
        );
        $output = ob_get_clean();
        $this->assertIsString($output);
    }

    public function testMigrationManagerPaneRender(): void
    {
        // Test that render method can be called without errors
        ob_start();
        MigrationManagerPane::render(
            self::$db,
            $this->layout,
            Layout::PANE_MIGRATIONS,
            true,
            0,
            0,
            false,
            null
        );
        $output = ob_get_clean();
        $this->assertIsString($output);
    }

    public function testServerVariablesPaneRender(): void
    {
        // Test that render method can be called without errors
        ob_start();
        ServerVariablesPane::render(
            self::$db,
            $this->layout,
            Layout::PANE_VARIABLES,
            true,
            0,
            0,
            false,
            null
        );
        $output = ob_get_clean();
        $this->assertIsString($output);
    }

    public function testServerVariablesPaneRenderWithSearchFilter(): void
    {
        // Test that render method can be called with search filter
        ob_start();
        ServerVariablesPane::render(
            self::$db,
            $this->layout,
            Layout::PANE_VARIABLES,
            true,
            0,
            0,
            false,
            'max'
        );
        $output = ob_get_clean();
        $this->assertIsString($output);
    }

    public function testSqlScratchpadPaneRender(): void
    {
        // Test that render method can be called without errors
        ob_start();
        SqlScratchpadPane::render(
            self::$db,
            $this->layout,
            Layout::PANE_SCRATCHPAD,
            true,
            null,
            null,
            false
        );
        $output = ob_get_clean();
        $this->assertIsString($output);
    }

    public function testSqlScratchpadPaneRenderWithQueryHistory(): void
    {
        // Test that render method can be called with query history
        ob_start();
        SqlScratchpadPane::render(
            self::$db,
            $this->layout,
            Layout::PANE_SCRATCHPAD,
            true,
            'SELECT 1',
            ['SELECT 1', 'SELECT 2'],
            false
        );
        $output = ob_get_clean();
        $this->assertIsString($output);
    }

    public function testSqlScratchpadPaneRenderFullscreen(): void
    {
        // Test fullscreen mode
        ob_start();
        SqlScratchpadPane::render(
            self::$db,
            $this->layout,
            Layout::PANE_SCRATCHPAD,
            true,
            'SELECT * FROM users',
            null,
            true
        );
        $output = ob_get_clean();
        $this->assertIsString($output);
        $this->assertStringContainsString('SQL Scratchpad', $output);
    }

    public function testServerMetricsPaneRenderWithMetrics(): void
    {
        // Test with provided metrics
        $metrics = [
            'version' => 'SQLite 3.40.0',
            'uptime_seconds' => 3600,
            'threads_connected' => 5,
        ];
        ob_start();
        ServerMetricsPane::render(
            self::$db,
            $this->layout,
            Layout::PANE_METRICS,
            true,
            $metrics
        );
        $output = ob_get_clean();
        $this->assertIsString($output);
    }

    public function testServerMetricsPaneRenderWithEmptyMetrics(): void
    {
        // Test with empty metrics
        ob_start();
        ServerMetricsPane::render(
            self::$db,
            $this->layout,
            Layout::PANE_METRICS,
            true,
            []
        );
        $output = ob_get_clean();
        $this->assertIsString($output);
        $this->assertStringContainsString('No metrics available', $output);
    }

    public function testServerMetricsPaneFormatUptime(): void
    {
        $reflection = new \ReflectionClass(ServerMetricsPane::class);
        $method = $reflection->getMethod('formatUptime');
        $method->setAccessible(true);

        $this->assertEquals('30s', $method->invoke(null, 30));
        $this->assertEquals('5m 30s', $method->invoke(null, 330));
        // For hours, formatUptime doesn't include seconds
        $this->assertEquals('1h 30m', $method->invoke(null, 5400));
        // For days, formatUptime doesn't include minutes or seconds
        $this->assertEquals('2d 3h', $method->invoke(null, 185400));
    }

    public function testServerMetricsPaneFormatBytes(): void
    {
        $reflection = new \ReflectionClass(ServerMetricsPane::class);
        $method = $reflection->getMethod('formatBytes');
        $method->setAccessible(true);

        // formatBytes uses number_format($size, 2), so 0 becomes "0.00 B"
        $this->assertEquals('0.00 B', $method->invoke(null, 0));
        $this->assertEquals('512.00 B', $method->invoke(null, 512));
        $this->assertEquals('1.00 KB', $method->invoke(null, 1024));
        $this->assertEquals('1.00 MB', $method->invoke(null, 1048576));
        $this->assertEquals('1.00 GB', $method->invoke(null, 1073741824));
    }

    public function testServerMetricsPaneTruncate(): void
    {
        $reflection = new \ReflectionClass(ServerMetricsPane::class);
        $method = $reflection->getMethod('truncate');
        $method->setAccessible(true);

        $this->assertEquals('test', $method->invoke(null, 'test', 10));
        $this->assertEquals('test...', $method->invoke(null, 'test string that is too long', 7));
        $this->assertEquals('', $method->invoke(null, 'test', 0));
    }

    public function testSqlScratchpadPaneRenderPreview(): void
    {
        $reflection = new \ReflectionClass(SqlScratchpadPane::class);
        $method = $reflection->getMethod('renderPreview');
        $method->setAccessible(true);

        $content = ['row' => 1, 'col' => 1, 'height' => 5, 'width' => 80];
        ob_start();
        $method->invoke(null, $content, 'SELECT * FROM users', null);
        $output = ob_get_clean();
        $this->assertStringContainsString('Last:', $output);
    }

    public function testSqlScratchpadPaneRenderPreviewWithHistory(): void
    {
        $reflection = new \ReflectionClass(SqlScratchpadPane::class);
        $method = $reflection->getMethod('renderPreview');
        $method->setAccessible(true);

        $content = ['row' => 1, 'col' => 1, 'height' => 5, 'width' => 80];
        ob_start();
        $method->invoke(null, $content, null, ['SELECT 1', 'SELECT 2']);
        $output = ob_get_clean();
        $this->assertStringContainsString('History:', $output);
    }

    public function testSqlScratchpadPaneRenderPreviewWithNoQueries(): void
    {
        $reflection = new \ReflectionClass(SqlScratchpadPane::class);
        $method = $reflection->getMethod('renderPreview');
        $method->setAccessible(true);

        $content = ['row' => 1, 'col' => 1, 'height' => 5, 'width' => 80];
        ob_start();
        $method->invoke(null, $content, null, null);
        $output = ob_get_clean();
        $this->assertStringContainsString('No queries executed', $output);
    }

    public function testSqlScratchpadPaneRenderFullscreenMethod(): void
    {
        $reflection = new \ReflectionClass(SqlScratchpadPane::class);
        $method = $reflection->getMethod('renderFullscreen');
        $method->setAccessible(true);

        $content = ['row' => 1, 'col' => 1, 'height' => 24, 'width' => 80];
        ob_start();
        $method->invoke(null, $content, 'SELECT * FROM users', null);
        $output = ob_get_clean();
        $this->assertStringContainsString('SQL Scratchpad', $output);
    }
}
