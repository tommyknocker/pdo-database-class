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
}
