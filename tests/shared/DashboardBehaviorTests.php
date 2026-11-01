<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\tests\shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tommyknocker\pdodb\cli\BaseCliCommand;
use tommyknocker\pdodb\cli\CliOutput;
use tommyknocker\pdodb\cli\ui\Dashboard;
use tommyknocker\pdodb\cli\ui\Layout;
use tommyknocker\pdodb\PdoDb;

/**
 * Pins what the Dashboard does for the keys a user presses: which pane is active, what fullscreen, detail, search and
 * the SQL scratchpad do, and what each view draws. These tests exist so the Dashboard can be taken apart safely.
 */
final class DashboardBehaviorTests extends TestCase
{
    private PdoDb $db;

    private string|false $previousMigrationPath;

    private string $migrationDir;

    protected function setUp(): void
    {
        $this->db = new PdoDb('sqlite', ['path' => ':memory:']);
        $this->db->rawQuery('CREATE TABLE dash_users (id INTEGER PRIMARY KEY, name TEXT)');

        // The migration pane reads this directory; keep it away from whatever the working directory holds
        $this->previousMigrationPath = getenv('PDODB_MIGRATION_PATH');
        $this->migrationDir = sys_get_temp_dir() . '/dash_migrations_' . bin2hex(random_bytes(4));
        mkdir($this->migrationDir);
        putenv('PDODB_MIGRATION_PATH=' . $this->migrationDir);
    }

    protected function tearDown(): void
    {
        if ($this->previousMigrationPath === false) {
            putenv('PDODB_MIGRATION_PATH');
        } else {
            putenv('PDODB_MIGRATION_PATH=' . $this->previousMigrationPath);
        }
        rmdir($this->migrationDir);
        BaseCliCommand::resetIo();
    }

    private function dashboard(): DashboardHarness
    {
        return new DashboardHarness($this->db);
    }

    /**
     * @return list<array<string, string>>
     */
    private function queries(): array
    {
        return [
            ['id' => '11', 'user' => 'alice', 'time' => '5', 'query' => 'SELECT one'],
            ['id' => '12', 'user' => 'bob', 'time' => '6', 'query' => 'SELECT two'],
            ['id' => '13', 'user' => 'carol', 'time' => '7', 'query' => 'SELECT three'],
        ];
    }

    /**
     * @return array<string, array{string, int, int}>
     */
    public static function paneKeys(): array
    {
        return [
            '1 queries' => ['1', Layout::PANE_QUERIES, 0],
            '2 connections' => ['2', Layout::PANE_CONNECTIONS, 0],
            '3 cache' => ['3', Layout::PANE_CACHE, 0],
            '4 metrics' => ['4', Layout::PANE_METRICS, 0],
            '5 schema' => ['5', Layout::PANE_SCHEMA, 1],
            '6 migrations' => ['6', Layout::PANE_MIGRATIONS, 1],
            '7 variables' => ['7', Layout::PANE_VARIABLES, 1],
            '8 scratchpad' => ['8', Layout::PANE_SCRATCHPAD, 1],
        ];
    }

    // ---- start state and navigation ----------------------------------------------------------------------

    public function testStartsOnTheQueriesPaneRefreshingEveryTwoSeconds(): void
    {
        $dashboard = $this->dashboard();

        $this->assertSame(Layout::PANE_QUERIES, $dashboard->prop('activePane'));
        $this->assertSame(0, $dashboard->screen());
        $this->assertSame(2.0, $dashboard->prop('refreshInterval'));
        $this->assertSame(-1, $dashboard->prop('fullscreenPane'));
        $this->assertSame(-1, $dashboard->prop('detailViewPane'));
    }

    #[DataProvider('paneKeys')]
    public function testNumberKeysSelectAPaneAndItsScreen(string $key, int $pane, int $screen): void
    {
        $dashboard = $this->dashboard();
        $dashboard->set('selectedIndex', array_fill(0, Layout::PANE_COUNT, 5));

        $dashboard->press($key);

        $this->assertSame($pane, $dashboard->prop('activePane'));
        $this->assertSame($screen, $dashboard->screen());
        $this->assertSame(0, $dashboard->prop('selectedIndex')[$pane], 'switching resets the selection');
        $this->assertSame(0, $dashboard->prop('scrollOffset')[$pane]);
    }

    public function testTabAndRightArrowGoForwardAndWrapToTheFirstPane(): void
    {
        $dashboard = $this->dashboard();
        $dashboard->press('7');

        $dashboard->press('tab');
        $this->assertSame(Layout::PANE_SCRATCHPAD, $dashboard->prop('activePane'));
        $this->assertSame(1, $dashboard->screen());

        $dashboard->press('right');
        $this->assertSame(Layout::PANE_QUERIES, $dashboard->prop('activePane'));
        $this->assertSame(0, $dashboard->screen());
    }

    public function testLeftArrowGoesBackAndWrapsToTheLastPane(): void
    {
        $dashboard = $this->dashboard();

        $dashboard->press('left');
        $this->assertSame(Layout::PANE_SCRATCHPAD, $dashboard->prop('activePane'));
        $this->assertSame(1, $dashboard->screen());

        $dashboard->press('5', 'left');
        $this->assertSame(Layout::PANE_METRICS, $dashboard->prop('activePane'));
        $this->assertSame(0, $dashboard->screen());
    }

    public function testRefreshIntervalCyclesThroughItsFiveSettings(): void
    {
        $dashboard = $this->dashboard();

        $seen = [];
        for ($i = 0; $i < 5; $i++) {
            $dashboard->press('r');
            $seen[] = $dashboard->prop('refreshInterval');
        }

        $this->assertSame([5.0, 0.5, 0.5, 1.0, 2.0], $seen);
        $this->assertStringContainsString('r:Ref(2s)', $dashboard->frame());
    }

    public function testRefreshLoadsMonitoringDataFromTheDatabase(): void
    {
        $dashboard = $this->dashboard();
        $dashboard->refreshData();

        $this->assertSame([], $dashboard->prop('cachedQueries'));
        $this->assertSame(['current' => 0, 'max' => 0, 'usage_percent' => 0], $dashboard->prop('cachedConnections')['summary']);
        $this->assertStringStartsWith('SQLite', (string)$dashboard->prop('cachedServerMetrics')['version']);
    }

    // ---- leaving ---------------------------------------------------------------------------------------

    public function testQuitKeysExitFromTheOverview(): void
    {
        foreach (['q', 'esc', 'ctrl-c'] as $key) {
            $this->assertTrue($this->dashboard()->press($key), $key);
        }
        $this->assertFalse($this->dashboard()->press('z'));
    }

    public function testQKeyDoesNotQuitFromFullscreenAndEscLeavesIt(): void
    {
        $dashboard = $this->dashboard();

        $this->assertFalse($dashboard->press('enter'));
        $this->assertSame(Layout::PANE_QUERIES, $dashboard->prop('fullscreenPane'));
        $this->assertFalse($dashboard->press('q'));
        $this->assertFalse($dashboard->press('esc'));
        $this->assertSame(-1, $dashboard->prop('fullscreenPane'));
        $this->assertTrue($dashboard->press('ctrl-c'), 'Ctrl+C quits from anywhere');
    }

    // ---- selection and detail view ---------------------------------------------------------------------

    public function testArrowKeysMoveTheSelectionWithinTheRowsAndStopAtTheEnds(): void
    {
        $dashboard = $this->dashboard();
        $dashboard->set('cachedQueries', $this->queries());

        $positions = [];
        foreach (['up', 'down', 'down', 'down', 'down', 'up'] as $key) {
            $dashboard->press($key);
            $positions[] = $dashboard->prop('selectedIndex')[Layout::PANE_QUERIES];
        }

        $this->assertSame([0, 1, 2, 2, 2, 1], $positions);
    }

    public function testEnterOnAQueryOpensItsDetailsAndAnyKeyClosesThem(): void
    {
        $dashboard = $this->dashboard();
        $dashboard->set('cachedQueries', $this->queries());
        $dashboard->press('enter', 'down', 'enter');

        $this->assertSame(Layout::PANE_QUERIES, $dashboard->prop('detailViewPane'));
        $details = $dashboard->frame('renderDetailView');
        $this->assertStringContainsString('Query Details', $details);
        $this->assertStringContainsString('ID: 12', $details);
        $this->assertStringContainsString('User: bob', $details);
        $this->assertStringContainsString('SELECT two', $details);

        $this->assertFalse($dashboard->press('z'));
        $this->assertSame(-1, $dashboard->prop('detailViewPane'));
        $this->assertSame(Layout::PANE_QUERIES, $dashboard->prop('fullscreenPane'), 'closing the details returns to fullscreen');
    }

    public function testEnterWithoutQueriesDoesNotOpenDetails(): void
    {
        $dashboard = $this->dashboard();
        $dashboard->press('enter', 'enter');

        $this->assertSame(-1, $dashboard->prop('detailViewPane'));
    }

    // ---- search ----------------------------------------------------------------------------------------

    public function testSlashStartsASearchInSchemaFullscreenAndTypingBuildsTheFilter(): void
    {
        $dashboard = $this->dashboard();
        $dashboard->press('5', 'enter', '/', 'd', 'a');

        $this->assertTrue($dashboard->prop('searchMode')[Layout::PANE_SCHEMA]);
        $this->assertSame('da', $dashboard->prop('searchFilter')[Layout::PANE_SCHEMA]);

        $dashboard->press('backspace');
        $this->assertSame('d', $dashboard->prop('searchFilter')[Layout::PANE_SCHEMA]);
    }

    public function testEscInSearchCancelsItBeforeLeavingFullscreen(): void
    {
        $dashboard = $this->dashboard();
        $dashboard->press('7', 'enter', '/', 'x', 'esc');

        $this->assertFalse($dashboard->prop('searchMode')[Layout::PANE_VARIABLES]);
        $this->assertSame('', $dashboard->prop('searchFilter')[Layout::PANE_VARIABLES]);
        $this->assertSame(Layout::PANE_VARIABLES, $dashboard->prop('fullscreenPane'));

        $dashboard->press('esc');
        $this->assertSame(-1, $dashboard->prop('fullscreenPane'));
    }

    public function testSearchFilterNarrowsTheSchemaList(): void
    {
        $dashboard = $this->dashboard();
        $dashboard->press('5', 'enter');
        $this->assertStringContainsString('dash_users', $dashboard->frame('renderFullscreen'));

        $dashboard->press('/', 'z', 'z', 'z', 'enter');
        $this->assertStringNotContainsString('dash_users', $dashboard->frame('renderFullscreen'));
    }

    public function testSlashIsIgnoredOutsideSearchablePanes(): void
    {
        $dashboard = $this->dashboard();
        $dashboard->press('1', 'enter', '/');

        $this->assertFalse($dashboard->prop('searchMode')[Layout::PANE_SCHEMA]);
        $this->assertSame(Layout::PANE_QUERIES, $dashboard->prop('fullscreenPane'));
    }

    // ---- SQL scratchpad --------------------------------------------------------------------------------

    private function scratchpad(): DashboardHarness
    {
        $dashboard = $this->dashboard();
        $dashboard->press('8', 'enter');

        return $dashboard;
    }

    public function testTypingInTheScratchpadBuildsTheQueryAndTabSwitchesToResults(): void
    {
        $dashboard = $this->scratchpad();
        $dashboard->press(...str_split('SELECT 1'));

        $this->assertSame('SELECT 1', $dashboard->prop('scratchpadQuery'));
        $this->assertSame('editor', $dashboard->prop('scratchpadMode'));

        $dashboard->press('tab');
        $this->assertSame('results', $dashboard->prop('scratchpadMode'));
        $dashboard->press('tab');
        $this->assertSame('editor', $dashboard->prop('scratchpadMode'));
    }

    public function testF5RunsTheQueryAndShowsItsRows(): void
    {
        $dashboard = $this->scratchpad();
        $dashboard->set('scratchpadQuery', 'SELECT 40 + 2 AS answer');
        $dashboard->press('f5');

        $this->assertNull($dashboard->prop('scratchpadLastError'));
        $this->assertSame([['answer' => 42]], $dashboard->prop('scratchpadLastResult'));
        $this->assertSame('results', $dashboard->prop('scratchpadMode'));

        $screen = $dashboard->frame('renderFullscreen');
        $this->assertStringContainsString('SQL Scratchpad', $screen);
        $this->assertStringContainsString('answer', $screen);
        $this->assertStringContainsString('42', $screen);
        $this->assertStringContainsString('Rows: 1', $screen);
    }

    public function testAFailingQueryIsShownAsAnError(): void
    {
        $dashboard = $this->scratchpad();
        $dashboard->set('scratchpadQuery', 'SELEC nope');
        $dashboard->press('f5');

        $this->assertStringContainsString('syntax error', (string)$dashboard->prop('scratchpadLastError'));
        $this->assertNull($dashboard->prop('scratchpadLastResult'));
        $this->assertStringContainsString('Error:', $dashboard->frame('renderFullscreen'));
    }

    public function testAWriteRunsAndIsKeptWithoutTransactionMode(): void
    {
        $dashboard = $this->scratchpad();
        $dashboard->set('scratchpadQuery', "INSERT INTO dash_users (name) VALUES ('kept')");
        $dashboard->press('f5');

        $this->assertNull($dashboard->prop('scratchpadLastError'));
        $this->assertSame(1, $dashboard->prop('scratchpadAffectedRows'));
        $this->assertSame(1, (int)$this->db->rawQueryValue('SELECT COUNT(*) FROM dash_users'));
    }

    public function testAffectedRowsCountTheRowsAStatementChanged(): void
    {
        $this->db->rawQuery("INSERT INTO dash_users (name) VALUES ('a'), ('b'), ('c')");
        $dashboard = $this->scratchpad();

        $dashboard->set('scratchpadQuery', "UPDATE dash_users SET name = 'x' WHERE id < 3");
        $dashboard->press('f5');
        $this->assertSame(2, $dashboard->prop('scratchpadAffectedRows'));
        $this->assertStringContainsString('Affected: 2', $dashboard->frame('renderFullscreen'));

        $dashboard->press('tab');
        $dashboard->set('scratchpadQuery', 'SELECT * FROM dash_users');
        $dashboard->press('f5');
        $this->assertSame(3, $dashboard->prop('scratchpadAffectedRows'));

        $dashboard->press('tab');
        $dashboard->set('scratchpadQuery', "UPDATE dash_users SET name = 'y' WHERE id = 1; DELETE FROM dash_users");
        $dashboard->press('f5');
        $this->assertSame(3, $dashboard->prop('scratchpadAffectedRows'), 'a script reports its last statement');
    }

    public function testHelpMentionsAllEightPanes(): void
    {
        $this->assertStringContainsString('1-8', $this->dashboard()->frame('showHelp'));
    }

    public function testTransactionModeCommitsASuccessfulStatementAndNeverLeavesATransactionOpen(): void
    {
        $dashboard = $this->scratchpad();
        $dashboard->press('f2');
        $this->assertTrue($dashboard->prop('scratchpadTransactionMode'));

        $dashboard->set('scratchpadQuery', "INSERT INTO dash_users (name) VALUES ('saved')");
        $dashboard->press('f5');
        $this->assertSame(1, (int)$this->db->rawQueryValue('SELECT COUNT(*) FROM dash_users'));
        $this->assertFalse($this->db->inTransaction());

        $dashboard->press('tab');
        $dashboard->set('scratchpadQuery', 'SELEC nope');
        $dashboard->press('f5');
        $this->assertNotNull($dashboard->prop('scratchpadLastError'));
        $this->assertFalse($this->db->inTransaction());
        $this->assertStringContainsString('[TX]', $dashboard->frame('renderFullscreen'));
    }

    public function testSeveralStatementsRunInOrder(): void
    {
        $dashboard = $this->scratchpad();
        $dashboard->set('scratchpadQuery', "INSERT INTO dash_users (name) VALUES ('a'); SELECT COUNT(*) AS n FROM dash_users");
        $dashboard->press('f5');

        $this->assertNull($dashboard->prop('scratchpadLastError'));
        $this->assertSame([['n' => 1]], $dashboard->prop('scratchpadLastResult'));
    }

    public function testExecutedQueriesJoinTheHistory(): void
    {
        $dashboard = $this->scratchpad();
        $dashboard->set('scratchpadQuery', 'SELECT 1');
        $dashboard->press('f5');

        $this->assertSame(['SELECT 1'], $dashboard->prop('scratchpadHistory'));
    }

    public function testF5IsIgnoredWhileLookingAtResults(): void
    {
        $dashboard = $this->scratchpad();
        $dashboard->set('scratchpadQuery', 'SELEC nope');
        $dashboard->press('f5');
        $dashboard->set('scratchpadQuery', "INSERT INTO dash_users (name) VALUES ('ignored')");
        $dashboard->press('f5');

        $this->assertSame(0, (int)$this->db->rawQueryValue('SELECT COUNT(*) FROM dash_users'));
    }

    public function testEscInTheScratchpadLeavesFullscreen(): void
    {
        $dashboard = $this->scratchpad();

        $this->assertFalse($dashboard->press('esc'));
        $this->assertSame(-1, $dashboard->prop('fullscreenPane'));
    }

    // ---- what each view draws --------------------------------------------------------------------------

    public function testFirstScreenDrawsHeaderFourPanesAndFooter(): void
    {
        $dashboard = $this->dashboard();
        $dashboard->refreshData();
        $frame = $dashboard->frame();

        foreach (['PDOdb TUI Dashboard', 'Driver: SQLITE', 'Active Queries', 'Connection Pool', 'Cache Stats', 'Server Metrics', 'No active queries', 'Cache not enabled', 'Version: SQLite'] as $text) {
            $this->assertStringContainsString($text, $frame);
        }
        $this->assertStringContainsString('1-8:Switch', $frame);
        $this->assertStringNotContainsString('Schema Browser', $frame);
    }

    public function testSecondScreenDrawsTheOtherFourPanes(): void
    {
        $dashboard = $this->dashboard();
        $dashboard->refreshData();
        $dashboard->press('5');
        $frame = $dashboard->frame();

        foreach (['Schema Browser', 'Migration Manager', 'Server Variables', 'SQL Scratchpad', 'Tables: 1'] as $text) {
            $this->assertStringContainsString($text, $frame);
        }
        $this->assertStringNotContainsString('Cache Stats', $frame);
    }

    public function testOverviewListsTheActiveQueries(): void
    {
        $dashboard = $this->dashboard();
        $dashboard->set('cachedQueries', $this->queries());
        $frame = $dashboard->frame();

        // the list is cut to the pane width, so only the leading part of a query is certain to show
        $this->assertMatchesRegularExpression('/\b11\s+5\s+SELECT/', $frame);
        $this->assertMatchesRegularExpression('/\b13\s+7\s+SELECT/', $frame);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function fullscreenTitles(): array
    {
        return [
            'queries' => ['1', 'Active Queries (Fullscreen)'],
            'connections' => ['2', 'Summary:'],
            'cache' => ['3', 'Cache not enabled'],
            'metrics' => ['4', 'Key Metrics:'],
            'schema' => ['5', 'Schema Browser (Fullscreen)'],
            'migrations' => ['6', 'Migration Manager (Fullscreen)'],
            'variables' => ['7', 'Server Variables (Fullscreen)'],
            'scratchpad' => ['8', 'SQL Scratchpad (Fullscreen)'],
        ];
    }

    #[DataProvider('fullscreenTitles')]
    public function testFullscreenDrawsThePaneAndItsKeyHints(string $key, string $text): void
    {
        $dashboard = $this->dashboard();
        $dashboard->refreshData();
        $dashboard->press($key, 'enter');
        $frame = $dashboard->frame('renderFullscreen');

        $this->assertStringContainsString($text, $frame);
        $this->assertStringContainsString('Esc:', $frame);
    }

    public function testHelpListsTheKeys(): void
    {
        $help = $this->dashboard()->frame('showHelp');

        foreach (['Help', 'Navigation:', 'Actions:', 'Esc', 'Quit dashboard'] as $text) {
            $this->assertStringContainsString($text, $help);
        }
    }

    public function testRunRefusesToStartWithoutAnInteractiveTerminal(): void
    {
        $stream = fopen('php://memory', 'w+');
        BaseCliCommand::setIo(new CliOutput($stream));

        $this->dashboard()->run();

        rewind($stream);
        $this->assertStringContainsString('requires an interactive terminal', (string)stream_get_contents($stream));
    }
}
