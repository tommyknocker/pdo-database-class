<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui;

use tommyknocker\pdodb\cli\CliEnvironment;
use tommyknocker\pdodb\cli\MonitorManager;
use tommyknocker\pdodb\cli\TableManager;
use tommyknocker\pdodb\cli\ui\actions\KillConnectionAction;
use tommyknocker\pdodb\cli\ui\actions\KillQueryAction;
use tommyknocker\pdodb\cli\ui\panes\ActiveQueriesPane;
use tommyknocker\pdodb\cli\ui\panes\CacheStatsPane;
use tommyknocker\pdodb\cli\ui\panes\ConnectionPoolPane;
use tommyknocker\pdodb\cli\ui\panes\MigrationManagerPane;
use tommyknocker\pdodb\cli\ui\panes\SchemaBrowserPane;
use tommyknocker\pdodb\cli\ui\panes\ServerMetricsPane;
use tommyknocker\pdodb\cli\ui\panes\ServerVariablesPane;
use tommyknocker\pdodb\cli\ui\panes\SqlScratchpadPane;
use tommyknocker\pdodb\migrations\MigrationRunner;
use tommyknocker\pdodb\PdoDb;

/**
 * Main dashboard for TUI.
 */
class Dashboard
{
    /**
     * Database instance.
     *
     * @var PdoDb
     */
    protected PdoDb $db;

    /**
     * Layout manager.
     *
     * @var Layout
     */
    protected Layout $layout;

    /** @var DashboardState What the dashboard is showing: active, fullscreen and detail panes, selections, cached data */
    protected DashboardState $state;

    /** @var SqlScratchpad SQL scratchpad (pane 8) */
    protected SqlScratchpad $scratchpad;

    /** @var DashboardViews Fullscreen and detail screens */
    protected DashboardViews $views;

    /**
     * Refresh interval in seconds.
     *
     * @var float
     */
    protected float $refreshInterval;

    /**
     * Last refresh time.
     *
     * @var float
     */
    protected float $lastRefresh = 0.0;

    /**
     * Original terminal settings (for restore).
     *
     * @var string|null
     */
    protected ?string $originalStty = null;

    /**
     * Refresh intervals in seconds (realtime, 0.5, 1, 2, 5).
     *
     * @var array<float>
     */
    protected array $refreshIntervals = [0.5, 0.5, 1.0, 2.0, 5.0];

    /**
     * Current refresh interval index.
     *
     * @var int
     */
    protected int $refreshIntervalIndex = 0;

    /**
     * Create dashboard.
     *
     * @param PdoDb $db Database instance
     */
    public function __construct(PdoDb $db)
    {
        $this->db = $db;
        $this->refreshIntervalIndex = 3; // Default to 2 seconds (index 3)
        $this->refreshInterval = $this->refreshIntervals[$this->refreshIntervalIndex];
        [$rows, $cols] = Terminal::getSize();
        $this->layout = new Layout($rows, $cols);
        $this->state = new DashboardState();
        $this->scratchpad = new SqlScratchpad(
            $db,
            fn (): int => $this->state->scrollOffset[Layout::PANE_SCRATCHPAD],
            function (int $offset): void {
                $this->state->scrollOffset[Layout::PANE_SCRATCHPAD] = $offset;
            },
            function (): void {
                $this->state->fullscreenPane = -1;
            },
            fn (string $message, int $color = Terminal::COLOR_WHITE) => $this->showMessage($message, $color),
        );
        $this->views = new DashboardViews($db, $this->state, $this->scratchpad);
        $this->updateScreenForPane(); // Initialize screen based on default active pane
    }

    /**
     * Run dashboard main loop.
     */
    public function run(): void
    {
        // Check if interactive
        $nonInteractive = CliEnvironment::isNonInteractive(STDOUT);

        if ($nonInteractive) {
            Stdout::writeln('TUI Dashboard requires an interactive terminal.');
            Stdout::writeln('Please run this command in a terminal with TTY support.');
            return;
        }

        // Setup signal handler for Ctrl+C (before terminal setup)
        // Setup terminal in raw mode for immediate character reading
        $this->setupTerminal();
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        }

        try {
            // Hide cursor and clear screen
            Terminal::hideCursor();
            Terminal::clear();

            // Initial data fetch
            $this->refresh();
            $this->lastRefresh = microtime(true);

            $this->runLoop();
        } finally {
            // Restore the terminal even when the loop ends with an exception
            $this->restoreTerminal();
            Terminal::showCursor();
            Terminal::clear();
            Terminal::reset();
        }
    }

    /**
     * Main loop: refresh, render, read a key, until exit is requested.
     */
    protected function runLoop(): void
    {
        while (true) {
            // Handle signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Refresh data if needed
            $now = microtime(true);
            $elapsed = $now - $this->lastRefresh;
            // Check if enough time has passed (use >= to handle floating point precision)
            if ($elapsed >= $this->refreshInterval) {
                $this->refresh();
                $this->lastRefresh = $now;
            }

            // Render dashboard
            if ($this->state->detailViewPane >= 0) {
                $this->renderDetailView();
            } elseif ($this->state->fullscreenPane >= 0) {
                $this->renderFullscreen();
            } else {
                $this->render();
            }

            // Flush output to ensure immediate display
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();

            // Handle input (non-blocking)
            $key = InputHandler::readKey(100000); // 0.1 seconds

            if ($key !== null) {
                $shouldExit = $this->handleKey($key);
                // Re-render immediately after key handling (for immediate updates like query execution)
                if ($this->state->detailViewPane >= 0) {
                    $this->renderDetailView();
                } elseif ($this->state->fullscreenPane >= 0) {
                    $this->renderFullscreen();
                } else {
                    $this->render();
                }
                flush();

                if ($shouldExit) {
                    break; // Exit requested
                }
            }
        }
    }

    /**
     * Setup terminal in raw mode.
     */
    protected function setupTerminal(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' && stream_isatty(STDIN)) {
            // Save current terminal settings
            $stty = @shell_exec('stty -g');
            if ($stty !== false && $stty !== null) {
                $this->originalStty = trim($stty);
            }

            // Set terminal to raw mode (immediate character input, no echo). Without ext-pcntl nothing can catch
            // SIGINT, so the default would kill the process with the terminal still raw; -isig turns Ctrl+C into
            // an ordinary key that the main loop handles.
            @shell_exec(function_exists('pcntl_signal') ? 'stty -icanon -echo' : 'stty -icanon -echo -isig');
        }
    }

    /**
     * Restore terminal settings.
     */
    protected function restoreTerminal(): void
    {
        if ($this->originalStty !== null && PHP_OS_FAMILY !== 'Windows') {
            @shell_exec("stty {$this->originalStty}");
            $this->originalStty = null;
        }
    }

    /**
     * Handle keyboard input.
     *
     * @param string $key Key pressed
     *
     * @return bool True if should exit
     */
    protected function handleKey(string $key): bool
    {
        if ($key === 'ctrl-c') {
            return true;
        }

        // Handle detail view mode
        if ($this->state->detailViewPane >= 0) {
            // In detail view, handle special keys
            if ($this->state->detailViewPane === Layout::PANE_MIGRATIONS) {
                if ($key === 'a') {
                    $this->handleApplyMigration();
                    return false;
                }
                if ($key === 'r') {
                    $this->handleRollbackMigration();
                    return false;
                }
            }
            // Any other key closes detail view
            $this->state->detailViewPane = -1;
            return false;
        }

        // Handle fullscreen mode
        if ($this->state->fullscreenPane >= 0) {
            // Handle scratchpad first to allow text input
            if ($this->state->fullscreenPane === Layout::PANE_SCRATCHPAD) {
                return $this->scratchpad->handleKey($key);
            }

            // Handle search mode for Schema Browser and Server Variables
            if (in_array($this->state->fullscreenPane, [Layout::PANE_SCHEMA, Layout::PANE_VARIABLES], true)) {
                // Check if we're in search mode
                if ($this->state->searchMode[$this->state->fullscreenPane] ?? false) {
                    if ($key === 'esc') {
                        // Cancel search
                        $this->state->searchMode[$this->state->fullscreenPane] = false;
                        $this->state->searchFilter[$this->state->fullscreenPane] = '';
                        $this->state->selectedIndex[$this->state->fullscreenPane] = 0;
                        $this->state->scrollOffset[$this->state->fullscreenPane] = 0;
                        return false;
                    }
                    if ($key === 'enter') {
                        // Confirm search
                        $this->state->searchMode[$this->state->fullscreenPane] = false;
                        $this->state->selectedIndex[$this->state->fullscreenPane] = 0;
                        $this->state->scrollOffset[$this->state->fullscreenPane] = 0;
                        return false;
                    }
                    if ($key === 'backspace') {
                        // Remove last character
                        $filter = $this->state->searchFilter[$this->state->fullscreenPane];
                        if (mb_strlen($filter, 'UTF-8') > 0) {
                            $this->state->searchFilter[$this->state->fullscreenPane] = mb_substr($filter, 0, -1, 'UTF-8');
                        }
                        $this->state->selectedIndex[$this->state->fullscreenPane] = 0;
                        $this->state->scrollOffset[$this->state->fullscreenPane] = 0;
                        return false;
                    }
                    // Regular text input
                    if (strlen($key) === 1 && ord($key) >= 32 && ord($key) <= 126) {
                        $this->state->searchFilter[$this->state->fullscreenPane] .= $key;
                        $this->state->selectedIndex[$this->state->fullscreenPane] = 0;
                        $this->state->scrollOffset[$this->state->fullscreenPane] = 0;
                        return false;
                    }
                    // Ignore other keys in search mode
                    return false;
                }

                // Activate search mode with "/"
                if ($key === '/') {
                    $this->state->searchMode[$this->state->fullscreenPane] = true;
                    $this->state->searchFilter[$this->state->fullscreenPane] = '';
                    return false;
                }
            }

            if ($key === 'esc') {
                // Exit search mode if active, otherwise exit fullscreen
                if (in_array($this->state->fullscreenPane, [Layout::PANE_SCHEMA, Layout::PANE_VARIABLES], true) && ($this->state->searchMode[$this->state->fullscreenPane] ?? false)) {
                    $this->state->searchMode[$this->state->fullscreenPane] = false;
                    $this->state->searchFilter[$this->state->fullscreenPane] = '';
                    return false;
                }
                // Reset search mode and filter when exiting fullscreen
                if (in_array($this->state->fullscreenPane, [Layout::PANE_SCHEMA, Layout::PANE_VARIABLES], true)) {
                    $this->state->searchMode[$this->state->fullscreenPane] = false;
                    $this->state->searchFilter[$this->state->fullscreenPane] = '';
                }
                $this->state->fullscreenPane = -1;
                return false;
            }
            if ($key === 'enter') {
                // Don't show detail view if in search mode
                if (in_array($this->state->fullscreenPane, [Layout::PANE_SCHEMA, Layout::PANE_VARIABLES], true) && ($this->state->searchMode[$this->state->fullscreenPane] ?? false)) {
                    // Search mode handles Enter itself
                    return false;
                }
                // Show detail view for selected item
                if ($this->state->fullscreenPane === Layout::PANE_QUERIES) {
                    $queries = $this->state->cachedQueries;
                    $selectedIdx = $this->state->selectedIndex[Layout::PANE_QUERIES];
                    if ($selectedIdx >= 0 && $selectedIdx < count($queries)) {
                        $this->state->detailViewPane = Layout::PANE_QUERIES;
                    }
                } elseif ($this->state->fullscreenPane === Layout::PANE_CONNECTIONS) {
                    $connectionsData = $this->state->cachedConnections;
                    $connections = $connectionsData['connections'] ?? [];
                    $selectedIdx = $this->state->selectedIndex[Layout::PANE_CONNECTIONS];
                    if ($selectedIdx >= 0 && $selectedIdx < count($connections)) {
                        $this->state->detailViewPane = Layout::PANE_CONNECTIONS;
                    }
                } elseif ($this->state->fullscreenPane === Layout::PANE_MIGRATIONS) {
                    // Show migration file content
                    $this->state->detailViewPane = Layout::PANE_MIGRATIONS;
                } elseif ($this->state->fullscreenPane === Layout::PANE_SCHEMA) {
                    // Show table details (Columns/Indexes/Foreign Keys)
                    $this->state->detailViewPane = Layout::PANE_SCHEMA;
                }
                return false;
            }
            if ($key === 'pageup') {
                $this->handlePageUp();
                return false;
            }
            if ($key === 'pagedown') {
                $this->handlePageDown();
                return false;
            }
            // In fullscreen, only allow navigation within the pane
            if ($key === 'up') {
                $this->handleUp();
                return false;
            }
            if ($key === 'down') {
                $this->handleDown();
                return false;
            }
            if ($key === 'x') {
                if ($this->state->fullscreenPane === Layout::PANE_QUERIES) {
                    $this->handleKillQuery();
                } elseif ($this->state->fullscreenPane === Layout::PANE_CONNECTIONS) {
                    $this->handleKillConnection();
                }
                return false;
            }
            if ($key === 'a') {
                if ($this->state->fullscreenPane === Layout::PANE_MIGRATIONS) {
                    $this->handleApplyMigration();
                }
                return false;
            }
            if ($key === 'r') {
                if ($this->state->fullscreenPane === Layout::PANE_MIGRATIONS) {
                    $this->handleRollbackMigration();
                }
                return false;
            }
            // Ignore other keys in fullscreen
            return false;
        }

        switch ($key) {
            case 'q':
                return true; // Exit

            case 'esc':
                return true; // Exit (only when not in fullscreen)

            case 'enter':
                // Toggle fullscreen for any pane
                $this->state->fullscreenPane = $this->state->activePane;
                break;

            case 'h':
                $this->showHelp();
                break;

            case 'r':
                $this->cycleRefreshInterval();
                break;

            case '1':
            case '2':
            case '3':
            case '4':
            case '5':
            case '6':
            case '7':
            case '8':
                $this->activatePane(((int)$key) - 1);
                break;

            case 'tab':
            case 'right':
                // Increment pane, wrap from 7→0 (cycle through screens)
                $this->activatePane(($this->state->activePane + 1) % Layout::PANE_COUNT);
                break;

            case 'left':
                // Decrement pane, wrap from 0→7
                $this->activatePane(($this->state->activePane - 1 + Layout::PANE_COUNT) % Layout::PANE_COUNT);
                break;

            case 'up':
                $this->handleUp();
                break;

            case 'down':
                $this->handleDown();
                break;

            case 'x':
                // Kill query or connection depending on active pane
                if ($this->state->activePane === Layout::PANE_QUERIES) {
                    $this->handleKillQuery();
                } elseif ($this->state->activePane === Layout::PANE_CONNECTIONS) {
                    $this->handleKillConnection();
                }
                break;
        }

        return false;
    }

    /**
     * Make a pane the active one, starting at the top of its list, on the screen it belongs to.
     */
    protected function activatePane(int $pane): void
    {
        $this->state->activePane = $pane;
        $this->state->selectedIndex[$pane] = 0;
        $this->state->scrollOffset[$pane] = 0;
        $this->updateScreenForPane();
    }

    /**
     * Update current screen based on active pane.
     */
    protected function updateScreenForPane(): void
    {
        if ($this->state->activePane >= 0 && $this->state->activePane <= 3) {
            $this->layout->setCurrentScreen(0);
        } elseif ($this->state->activePane >= 4 && $this->state->activePane <= 7) {
            $this->layout->setCurrentScreen(1);
        }
    }

    /**
     * Render the fullscreen pane.
     */
    protected function renderFullscreen(): void
    {
        $this->views->renderFullscreen();
    }

    /**
     * Render the detail view of the selected item.
     */
    protected function renderDetailView(): void
    {
        $this->views->renderDetailView();
    }

    /**
     * Render dashboard.
     */
    protected function render(): void
    {
        // Clear screen
        Terminal::clear();

        // Check if in fullscreen mode
        if ($this->state->fullscreenPane >= 0) {
            $this->renderFullscreen();
            return;
        }

        // Render header
        $this->renderHeader();

        // Determine which screen to render based on active pane
        $currentScreen = $this->layout->getCurrentScreen();
        $isScreen1 = ($currentScreen === 0);

        // Render panes for current screen only
        $isQueriesActive = $this->state->activePane === Layout::PANE_QUERIES;
        $isConnectionsActive = $this->state->activePane === Layout::PANE_CONNECTIONS;

        // Calculate scroll offset for queries pane
        if ($isQueriesActive) {
            $queries = $this->state->cachedQueries;
            $content = $this->layout->getContentArea(Layout::PANE_QUERIES);
            $visibleHeight = $content['height'] - 1;
            $selectedIdx = $this->state->selectedIndex[Layout::PANE_QUERIES];

            if ($selectedIdx >= 0 && count($queries) > 0) {
                $relativeIndex = $selectedIdx - $this->state->scrollOffset[Layout::PANE_QUERIES];
                if ($relativeIndex < 0) {
                    $this->state->scrollOffset[Layout::PANE_QUERIES] = $selectedIdx;
                } elseif ($relativeIndex >= $visibleHeight) {
                    $this->state->scrollOffset[Layout::PANE_QUERIES] = max(0, $selectedIdx - $visibleHeight + 1);
                }
            }
        }

        // Calculate scroll offset for connections pane
        if ($isConnectionsActive) {
            $connectionsData = $this->state->cachedConnections;
            $connections = $connectionsData['connections'] ?? [];
            $content = $this->layout->getContentArea(Layout::PANE_CONNECTIONS);
            $summaryHeight = 3;
            $visibleHeight = $content['height'] - $summaryHeight - 2;
            $selectedIdx = $this->state->selectedIndex[Layout::PANE_CONNECTIONS];

            if ($selectedIdx >= 0 && count($connections) > 0) {
                $relativeIndex = $selectedIdx - $this->state->scrollOffset[Layout::PANE_CONNECTIONS];
                if ($relativeIndex < 0) {
                    $this->state->scrollOffset[Layout::PANE_CONNECTIONS] = $selectedIdx;
                } elseif ($relativeIndex >= $visibleHeight) {
                    $this->state->scrollOffset[Layout::PANE_CONNECTIONS] = max(0, $selectedIdx - $visibleHeight + 1);
                }
            }
        }

        ActiveQueriesPane::render(
            $this->db,
            $this->layout,
            Layout::PANE_QUERIES,
            $isQueriesActive,
            $isQueriesActive ? $this->state->selectedIndex[Layout::PANE_QUERIES] : -1,
            $isQueriesActive ? $this->state->scrollOffset[Layout::PANE_QUERIES] : 0,
            false,
            $this->state->cachedQueries
        );

        ConnectionPoolPane::render(
            $this->db,
            $this->layout,
            Layout::PANE_CONNECTIONS,
            $isConnectionsActive,
            $isConnectionsActive ? $this->state->selectedIndex[Layout::PANE_CONNECTIONS] : -1,
            $isConnectionsActive ? $this->state->scrollOffset[Layout::PANE_CONNECTIONS] : 0,
            false,
            $this->state->cachedConnections
        );

        if ($isScreen1) {
            // Screen 1: Panes 0-3
            CacheStatsPane::render($this->db, $this->layout, Layout::PANE_CACHE, $this->state->activePane === Layout::PANE_CACHE);
            ServerMetricsPane::render($this->db, $this->layout, Layout::PANE_METRICS, $this->state->activePane === Layout::PANE_METRICS, $this->state->cachedServerMetrics);
        } else {
            // Screen 2: Panes 4-7
            $isSchemaActive = $this->state->activePane === Layout::PANE_SCHEMA;
            $isMigrationsActive = $this->state->activePane === Layout::PANE_MIGRATIONS;
            $isVariablesActive = $this->state->activePane === Layout::PANE_VARIABLES;
            $isScratchpadActive = $this->state->activePane === Layout::PANE_SCRATCHPAD;

            SchemaBrowserPane::render(
                $this->db,
                $this->layout,
                Layout::PANE_SCHEMA,
                $isSchemaActive,
                $isSchemaActive ? $this->state->selectedIndex[Layout::PANE_SCHEMA] : -1,
                $isSchemaActive ? $this->state->scrollOffset[Layout::PANE_SCHEMA] : 0
            );

            MigrationManagerPane::render(
                $this->db,
                $this->layout,
                Layout::PANE_MIGRATIONS,
                $isMigrationsActive,
                $isMigrationsActive ? $this->state->selectedIndex[Layout::PANE_MIGRATIONS] : -1,
                $isMigrationsActive ? $this->state->scrollOffset[Layout::PANE_MIGRATIONS] : 0
            );

            ServerVariablesPane::render(
                $this->db,
                $this->layout,
                Layout::PANE_VARIABLES,
                $isVariablesActive,
                $isVariablesActive ? $this->state->selectedIndex[Layout::PANE_VARIABLES] : -1,
                $isVariablesActive ? $this->state->scrollOffset[Layout::PANE_VARIABLES] : 0
            );

            $history = $this->scratchpad->history();
            $lastQuery = $history !== [] ? end($history) : null;
            SqlScratchpadPane::render(
                $this->db,
                $this->layout,
                Layout::PANE_SCRATCHPAD,
                $isScratchpadActive,
                $lastQuery,
                $history
            );
        }

        // Render footer
        $this->renderFooter();

        // Flush output
        flush();
    }

    /**
     * Render header.
     */
    protected function renderHeader(): void
    {
        [$rows, $cols] = Terminal::getSize();
        Terminal::moveTo(1, 1);
        Terminal::clearLine();

        $driver = $this->db->schema()->getDialect()->getDriverName();
        $driverUpper = strtoupper($driver);

        // Build header parts
        $leftText = 'PDOdb TUI Dashboard';
        $centerText = 'Updated: ' . date('H:i:s');
        $rightText = 'Driver: ' . $driverUpper;

        $leftLen = mb_strlen($leftText, 'UTF-8');
        $centerLen = mb_strlen($centerText, 'UTF-8');
        $rightLen = mb_strlen($rightText, 'UTF-8');

        // Calculate positions
        $leftPos = 1;
        $rightPos = $cols - $rightLen + 1;
        $centerPos = (int)(($cols - $centerLen) / 2) + 1;

        // Ensure no overlap
        if ($centerPos <= $leftLen + 1) {
            $centerPos = $leftLen + 2;
        }
        if ($centerPos + $centerLen > $rightPos) {
            $centerPos = $rightPos - $centerLen - 1;
        }

        // Left: Title
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_CYAN);
        }
        Terminal::moveTo(1, $leftPos);
        Stdout::write($leftText);
        Terminal::reset();

        // Center: Updated timestamp
        Terminal::moveTo(1, $centerPos);
        Stdout::write($centerText);

        // Right: Driver
        Terminal::moveTo(1, $rightPos);
        Stdout::write($rightText);
    }

    /**
     * Render footer with help.
     */
    protected function renderFooter(): void
    {
        [$rows] = Terminal::getSize();
        Terminal::moveTo($rows, 1);
        Terminal::clearLine();

        if (Terminal::supportsColors()) {
            Terminal::color(Terminal::BG_BLUE);
            Terminal::color(Terminal::COLOR_WHITE);
        }

        // Format refresh label
        $refreshValue = $this->refreshInterval;
        if ($this->refreshIntervalIndex === 0) {
            $refreshLabel = 'rt';
        } elseif ($refreshValue < 1.0) {
            $refreshLabel = (int)($refreshValue * 1000) . 'ms';
        } else {
            $refreshLabel = (int)$refreshValue . 's';
        }

        [$rows, $cols] = Terminal::getSize();
        $currentScreen = $this->layout->getCurrentScreen();
        $screenIndicator = $currentScreen === 0 ? '1-4' : '5-8';
        $help = '1-8:Switch | ↑↓:Select | Enter:Full | x:Kill | r:Ref(' . $refreshLabel . ') | h:Help | q:Quit | Screen:' . $screenIndicator;

        // Truncate if too long to fit in one line
        if (mb_strlen($help, 'UTF-8') > $cols) {
            $help = mb_substr($help, 0, $cols, 'UTF-8');
        }

        Stdout::write($help);

        Terminal::reset();
    }

    /**
     * Render placeholder pane (temporary until real panes are implemented).
     *
     * @param int $paneIndex Pane index
     * @param string $title Pane title
     */
    protected function renderPlaceholderPane(int $paneIndex, string $title): void
    {
        $this->layout->renderBorder($paneIndex, $title, $this->state->activePane === $paneIndex);
        $content = $this->layout->getContentArea($paneIndex);
        Terminal::moveTo($content['row'], $content['col']);
        Stdout::write('Coming soon...');
    }

    /**
     * Refresh data (fetch from database).
     */
    protected function refresh(): void
    {
        // Fetch fresh data from database
        $this->state->cachedQueries = MonitorManager::getActiveQueries($this->db);
        $this->state->cachedConnections = MonitorManager::getActiveConnections($this->db);
        $this->state->cachedServerMetrics = $this->db->schema()->getDialect()->getServerMetrics($this->db);
    }

    /**
     * Handle up arrow key.
     */
    protected function handleUp(): void
    {
        $pane = $this->state->fullscreenPane >= 0 ? $this->state->fullscreenPane : $this->state->activePane;
        if (in_array($pane, [Layout::PANE_QUERIES, Layout::PANE_CONNECTIONS, Layout::PANE_SCHEMA, Layout::PANE_MIGRATIONS, Layout::PANE_VARIABLES], true)) {
            if ($this->state->selectedIndex[$pane] > 0) {
                $this->state->selectedIndex[$pane]--;
            }
        }
    }

    /**
     * Handle down arrow key.
     */
    protected function handleDown(): void
    {
        $pane = $this->state->fullscreenPane >= 0 ? $this->state->fullscreenPane : $this->state->activePane;
        if ($pane === Layout::PANE_QUERIES) {
            $queries = $this->state->cachedQueries;
            if ($this->state->selectedIndex[Layout::PANE_QUERIES] < count($queries) - 1) {
                $this->state->selectedIndex[Layout::PANE_QUERIES]++;
            }
        } elseif ($pane === Layout::PANE_CONNECTIONS) {
            $connectionsData = $this->state->cachedConnections;
            $connections = $connectionsData['connections'] ?? [];
            if ($this->state->selectedIndex[Layout::PANE_CONNECTIONS] < count($connections) - 1) {
                $this->state->selectedIndex[Layout::PANE_CONNECTIONS]++;
            }
        } elseif ($pane === Layout::PANE_SCHEMA) {
            $tables = TableManager::listTables($this->db);
            if ($this->state->selectedIndex[Layout::PANE_SCHEMA] < count($tables) - 1) {
                $this->state->selectedIndex[Layout::PANE_SCHEMA]++;
            }
        } elseif ($pane === Layout::PANE_MIGRATIONS) {
            $migrationPath = getenv('PDODB_MIGRATION_PATH') ?: 'migrations';
            $runner = new MigrationRunner($this->db, $migrationPath);
            $newMigrations = $runner->getNewMigrations();
            $history = $runner->getMigrationHistory();
            $allMigrations = [];
            foreach ($newMigrations as $version) {
                $allMigrations[] = ['version' => $version, 'status' => 'pending'];
            }
            foreach ($history as $record) {
                $allMigrations[] = ['version' => $record['version'], 'status' => 'applied'];
            }
            if ($this->state->selectedIndex[Layout::PANE_MIGRATIONS] < count($allMigrations) - 1) {
                $this->state->selectedIndex[Layout::PANE_MIGRATIONS]++;
            }
        } elseif ($pane === Layout::PANE_VARIABLES) {
            $dialect = $this->db->schema()->getDialect();
            $variables = $dialect->getServerVariables($this->db);
            if ($this->state->selectedIndex[Layout::PANE_VARIABLES] < count($variables) - 1) {
                $this->state->selectedIndex[Layout::PANE_VARIABLES]++;
            }
        }
    }

    /**
     * Handle PageUp key (jump up by page).
     */
    protected function handlePageUp(): void
    {
        $pane = $this->state->fullscreenPane >= 0 ? $this->state->fullscreenPane : $this->state->activePane;
        [$rows] = Terminal::getSize();
        $pageSize = max(1, $rows - 4); // Visible items per page

        if (in_array($pane, [Layout::PANE_QUERIES, Layout::PANE_CONNECTIONS, Layout::PANE_SCHEMA, Layout::PANE_MIGRATIONS, Layout::PANE_VARIABLES], true)) {
            $newIndex = max(0, $this->state->selectedIndex[$pane] - $pageSize);
            $this->state->selectedIndex[$pane] = $newIndex;
            // Update scroll offset to keep selection visible
            // In fullscreen, scrollOffset should be set so that selectedIndex is visible
            $visibleHeight = $rows - 3; // Account for header and footer
            $this->state->scrollOffset[$pane] = max(0, $newIndex);
        }
    }

    /**
     * Handle PageDown key (jump down by page).
     */
    protected function handlePageDown(): void
    {
        $pane = $this->state->fullscreenPane >= 0 ? $this->state->fullscreenPane : $this->state->activePane;
        [$rows] = Terminal::getSize();
        $pageSize = max(1, $rows - 4); // Visible items per page
        $visibleHeight = $rows - 3; // Account for header and footer

        if ($pane === Layout::PANE_QUERIES) {
            $queries = $this->state->cachedQueries;
            $maxIndex = count($queries) - 1;
            $newIndex = min($maxIndex, $this->state->selectedIndex[Layout::PANE_QUERIES] + $pageSize);
            $this->state->selectedIndex[Layout::PANE_QUERIES] = $newIndex;
            // Update scroll offset to keep selection visible
            $this->state->scrollOffset[Layout::PANE_QUERIES] = max(0, $newIndex - $visibleHeight + 1);
        } elseif ($pane === Layout::PANE_CONNECTIONS) {
            $connectionsData = $this->state->cachedConnections;
            $connections = $connectionsData['connections'] ?? [];
            $maxIndex = count($connections) - 1;
            $newIndex = min($maxIndex, $this->state->selectedIndex[Layout::PANE_CONNECTIONS] + $pageSize);
            $this->state->selectedIndex[Layout::PANE_CONNECTIONS] = $newIndex;
            // Update scroll offset to keep selection visible
            $this->state->scrollOffset[Layout::PANE_CONNECTIONS] = max(0, $newIndex - $visibleHeight + 1);
        } elseif ($pane === Layout::PANE_SCHEMA) {
            $tables = TableManager::listTables($this->db);
            $maxIndex = count($tables) - 1;
            $newIndex = min($maxIndex, $this->state->selectedIndex[Layout::PANE_SCHEMA] + $pageSize);
            $this->state->selectedIndex[Layout::PANE_SCHEMA] = $newIndex;
            // Update scroll offset to keep selection visible
            $this->state->scrollOffset[Layout::PANE_SCHEMA] = max(0, $newIndex - $visibleHeight + 1);
        } elseif ($pane === Layout::PANE_MIGRATIONS) {
            $migrationPath = getenv('PDODB_MIGRATION_PATH') ?: 'migrations';
            $runner = new MigrationRunner($this->db, $migrationPath);
            $newMigrations = $runner->getNewMigrations();
            $history = $runner->getMigrationHistory();
            $allMigrations = [];
            foreach ($newMigrations as $version) {
                $allMigrations[] = ['version' => $version, 'status' => 'pending'];
            }
            foreach ($history as $record) {
                $allMigrations[] = ['version' => $record['version'], 'status' => 'applied'];
            }
            $maxIndex = count($allMigrations) - 1;
            $newIndex = min($maxIndex, $this->state->selectedIndex[Layout::PANE_MIGRATIONS] + $pageSize);
            $this->state->selectedIndex[Layout::PANE_MIGRATIONS] = $newIndex;
            // Update scroll offset to keep selection visible
            $this->state->scrollOffset[Layout::PANE_MIGRATIONS] = max(0, $newIndex - $visibleHeight + 1);
        } elseif ($pane === Layout::PANE_VARIABLES) {
            $dialect = $this->db->schema()->getDialect();
            $variables = $dialect->getServerVariables($this->db);
            $maxIndex = count($variables) - 1;
            $newIndex = min($maxIndex, $this->state->selectedIndex[Layout::PANE_VARIABLES] + $pageSize);
            $this->state->selectedIndex[Layout::PANE_VARIABLES] = $newIndex;
            // Update scroll offset to keep selection visible
            $this->state->scrollOffset[Layout::PANE_VARIABLES] = max(0, $newIndex - $visibleHeight + 1);
        }
    }

    /**
     * Handle kill query action.
     */
    protected function handleKillQuery(): void
    {
        // Can be called from queries pane or fullscreen queries
        $pane = $this->state->fullscreenPane >= 0 ? $this->state->fullscreenPane : $this->state->activePane;
        if ($pane !== Layout::PANE_QUERIES) {
            return;
        }

        $queries = $this->state->cachedQueries;
        if (empty($queries)) {
            return;
        }

        $selectedIdx = $this->state->selectedIndex[Layout::PANE_QUERIES];
        if ($selectedIdx < 0 || $selectedIdx >= count($queries)) {
            return;
        }

        $query = $queries[$selectedIdx];
        $queryId = $query['id'] ?? $query['pid'] ?? $query['session_id'] ?? null;

        if ($queryId === null) {
            return;
        }

        // Show confirmation
        if (!$this->showConfirmation('Kill query ' . $queryId . '?')) {
            return;
        }

        // Kill query
        $success = KillQueryAction::execute($this->db, $queryId);
        if ($success) {
            // Reset selection
            $this->state->selectedIndex[Layout::PANE_QUERIES] = 0;
            $this->state->scrollOffset[Layout::PANE_QUERIES] = 0;
        }
    }

    /**
     * Handle apply migration action.
     */
    protected function handleApplyMigration(): void
    {
        $migrationPath = getenv('PDODB_MIGRATION_PATH') ?: 'migrations';
        $runner = new MigrationRunner($this->db, $migrationPath);
        $newMigrations = $runner->getNewMigrations();
        $history = $runner->getMigrationHistory();

        // Combine all migrations
        $allMigrations = [];
        foreach ($newMigrations as $version) {
            $allMigrations[] = ['version' => $version, 'status' => 'pending'];
        }
        foreach ($history as $record) {
            $allMigrations[] = ['version' => $record['version'], 'status' => 'applied'];
        }
        usort($allMigrations, function ($a, $b) {
            return strcmp($a['version'], $b['version']);
        });

        $selectedIdx = $this->state->selectedIndex[Layout::PANE_MIGRATIONS];
        if ($selectedIdx < 0 || $selectedIdx >= count($allMigrations)) {
            $this->showMessage('No migration selected', Terminal::COLOR_RED);
            return;
        }

        $migration = $allMigrations[$selectedIdx];
        if ($migration['status'] !== 'pending') {
            $this->showMessage('Migration is already applied', Terminal::COLOR_YELLOW);
            return;
        }

        $version = $migration['version'];

        try {
            $runner->migrateUp($version);
            $this->showMessage("Migration {$version} applied successfully", Terminal::COLOR_GREEN);
        } catch (\Throwable $e) {
            $this->showMessage("Failed to apply migration {$version}: " . $e->getMessage(), Terminal::COLOR_RED);
        }
    }

    /**
     * Handle rollback migration action.
     */
    protected function handleRollbackMigration(): void
    {
        $migrationPath = getenv('PDODB_MIGRATION_PATH') ?: 'migrations';
        $runner = new MigrationRunner($this->db, $migrationPath);
        $newMigrations = $runner->getNewMigrations();
        $history = $runner->getMigrationHistory();

        // Combine all migrations
        $allMigrations = [];
        foreach ($newMigrations as $version) {
            $allMigrations[] = ['version' => $version, 'status' => 'pending'];
        }
        foreach ($history as $record) {
            $allMigrations[] = ['version' => $record['version'], 'status' => 'applied'];
        }
        usort($allMigrations, function ($a, $b) {
            return strcmp($a['version'], $b['version']);
        });

        $selectedIdx = $this->state->selectedIndex[Layout::PANE_MIGRATIONS];
        if ($selectedIdx < 0 || $selectedIdx >= count($allMigrations)) {
            $this->showMessage('No migration selected', Terminal::COLOR_RED);
            return;
        }

        $migration = $allMigrations[$selectedIdx];
        if ($migration['status'] !== 'applied') {
            $this->showMessage('Migration is not applied', Terminal::COLOR_YELLOW);
            return;
        }

        $version = $migration['version'];
        // Check if this is the last applied migration (can only rollback last one)
        $lastApplied = $history[0]['version'] ?? null;
        if ($lastApplied !== $version) {
            $this->showMessage("Can only rollback the last applied migration ({$lastApplied})", Terminal::COLOR_YELLOW);
            return;
        }

        try {
            $rolledBack = $runner->migrateDown(1);
            if (in_array($version, $rolledBack, true)) {
                $this->showMessage("Migration {$version} rolled back successfully", Terminal::COLOR_GREEN);
            } else {
                $this->showMessage("Migration {$version} rollback failed", Terminal::COLOR_RED);
            }
        } catch (\Throwable $e) {
            $this->showMessage("Failed to rollback migration {$version}: " . $e->getMessage(), Terminal::COLOR_RED);
        }
    }

    /**
     * Show a temporary message to the user.
     *
     * @param string $message Message to show
     * @param int $color Color code
     */
    protected function showMessage(string $message, int $color = Terminal::COLOR_WHITE): void
    {
        [$rows, $cols] = Terminal::getSize();
        $messageRow = (int)floor($rows / 2);
        $messageCol = (int)floor(($cols - mb_strlen($message, 'UTF-8')) / 2);

        // Clear line and show message
        Terminal::moveTo($messageRow, 1);
        Terminal::clearLine();
        Terminal::moveTo($messageRow, max(1, $messageCol));
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color($color);
        }
        Stdout::write($message);
        Terminal::reset();
        flush();

        // Wait a bit for user to see the message
        usleep(2000000); // 2 seconds

        // Clear message
        Terminal::moveTo($messageRow, 1);
        Terminal::clearLine();
    }

    /**
     * Handle kill connection action.
     */
    protected function handleKillConnection(): void
    {
        // Can be called from connections pane or fullscreen connections
        $pane = $this->state->fullscreenPane >= 0 ? $this->state->fullscreenPane : $this->state->activePane;
        if ($pane !== Layout::PANE_CONNECTIONS) {
            return;
        }

        $connectionsData = $this->state->cachedConnections;
        $connections = $connectionsData['connections'] ?? [];
        if (empty($connections)) {
            return;
        }

        $selectedIdx = $this->state->selectedIndex[Layout::PANE_CONNECTIONS];
        if ($selectedIdx < 0 || $selectedIdx >= count($connections)) {
            return;
        }

        $connection = $connections[$selectedIdx];
        $connectionId = $connection['id'] ?? $connection['pid'] ?? $connection['session_id'] ?? null;

        if ($connectionId === null) {
            return;
        }

        // Show confirmation
        if (!$this->showConfirmation('Kill connection ' . $connectionId . '?')) {
            return;
        }

        // Kill connection
        $success = KillConnectionAction::execute($this->db, $connectionId);
        if ($success) {
            // Reset selection
            $this->state->selectedIndex[Layout::PANE_CONNECTIONS] = 0;
            $this->state->scrollOffset[Layout::PANE_CONNECTIONS] = 0;
        }
    }

    /**
     * Show confirmation dialog.
     *
     * @param string $message Confirmation message
     *
     * @return bool True if confirmed
     */
    protected function showConfirmation(string $message): bool
    {
        [$rows, $cols] = Terminal::getSize();
        $dialogRow = (int)floor($rows / 2);
        $dialogCol = (int)floor(($cols - 50) / 2);

        // Draw dialog box
        Terminal::moveTo($dialogRow, $dialogCol);
        if (Terminal::supportsColors()) {
            Terminal::color(Terminal::BG_YELLOW);
            Terminal::color(Terminal::COLOR_BLACK);
        }
        Stdout::write(str_repeat(' ', 50));
        Terminal::moveTo($dialogRow + 1, $dialogCol);
        $messageLine = ' ' . substr($message, 0, 48) . ' ';
        Stdout::write(str_pad($messageLine, 50));
        Terminal::moveTo($dialogRow + 2, $dialogCol);
        Stdout::write(' ' . str_pad('Press Y to confirm, N to cancel', 48) . ' ');
        Terminal::moveTo($dialogRow + 3, $dialogCol);
        Stdout::write(str_repeat(' ', 50));
        Terminal::reset();
        flush();

        // Check for non-interactive mode
        $nonInteractive = CliEnvironment::isNonInteractive();

        if ($nonInteractive) {
            // In non-interactive mode, return default (false)
            return false;
        }

        // Wait for Y or N
        while (true) {
            $key = InputHandler::readKey(100000);
            if ($key === null) {
                continue;
            }

            if (strtolower($key) === 'y') {
                // Clear dialog
                for ($i = 0; $i < 4; $i++) {
                    Terminal::moveTo($dialogRow + $i, $dialogCol);
                    Terminal::clearLine();
                }
                return true;
            }

            if (strtolower($key) === 'n' || $key === 'esc') {
                // Clear dialog
                for ($i = 0; $i < 4; $i++) {
                    Terminal::moveTo($dialogRow + $i, $dialogCol);
                    Terminal::clearLine();
                }
                return false;
            }
        }
    }

    /**
     * Show help dialog.
     */
    protected function showHelp(): void
    {
        [$rows, $cols] = Terminal::getSize();
        $dialogRow = (int)floor($rows / 2) - 8;
        $dialogCol = (int)floor(($cols - 60) / 2);

        // Draw dialog box
        for ($i = 0; $i < 16; $i++) {
            Terminal::moveTo($dialogRow + $i, $dialogCol);
            if ($i === 0 || $i === 15) {
                if (Terminal::supportsColors()) {
                    Terminal::color(Terminal::BG_BLUE);
                    Terminal::color(Terminal::COLOR_WHITE);
                }
                Stdout::write(str_repeat(' ', 60));
            } else {
                if (Terminal::supportsColors()) {
                    Terminal::color(Terminal::BG_BLUE);
                    Terminal::color(Terminal::COLOR_WHITE);
                }
                Stdout::write(' ' . str_pad('', 58) . ' ');
            }
            Terminal::reset();
        }

        // Title
        Terminal::moveTo($dialogRow + 1, $dialogCol);
        if (Terminal::supportsColors()) {
            Terminal::color(Terminal::BG_BLUE);
            Terminal::color(Terminal::COLOR_WHITE);
            Terminal::bold();
        }
        Stdout::write(' ' . str_pad('PDOdb TUI Dashboard - Help', 58) . ' ');
        Terminal::reset();

        // Help content
        $helpLines = [
            '',
            ' Navigation:',
            '  1-8          Switch between panes',
            '  Tab/←→       Navigate between panes',
            '  ↑/↓         Select items in active pane',
            '  Enter        Toggle fullscreen for current pane',
            '  Esc          Exit fullscreen or quit',
            '',
            ' Actions:',
            '  x            Kill selected query/connection',
            '  r            Cycle refresh interval',
            '  h            Show this help',
            '  q            Quit dashboard',
            '',
            ' Refresh Intervals:',
            '  realtime (0.5s), 0.5s, 1s, 2s, 5s',
            '',
            ' Press any key to close...',
        ];

        $lineNum = 3;
        foreach ($helpLines as $line) {
            Terminal::moveTo($dialogRow + $lineNum, $dialogCol);
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::BG_BLUE);
                Terminal::color(Terminal::COLOR_WHITE);
            }
            Stdout::write(' ' . str_pad($line, 58) . ' ');
            Terminal::reset();
            $lineNum++;
        }

        flush();

        // Check for non-interactive mode
        $nonInteractive = CliEnvironment::isNonInteractive();

        if ($nonInteractive) {
            // In non-interactive mode, return immediately
            return;
        }

        // Wait for any key
        while (true) {
            $key = InputHandler::readKey(100000);
            if ($key !== null) {
                break;
            }
        }
    }

    /**
     * Cycle refresh interval.
     */
    protected function cycleRefreshInterval(): void
    {
        $this->refreshIntervalIndex = ($this->refreshIntervalIndex + 1) % count($this->refreshIntervals);
        $this->refreshInterval = $this->refreshIntervals[$this->refreshIntervalIndex];
        // Immediately refresh data and reset timer
        $this->refresh();
        $this->lastRefresh = microtime(true);
    }

    /**
     * Handle signal (Ctrl+C).
     *
     * @param int $signal Signal number
     */
    public function handleSignal(int $signal): void
    {
        if ($signal === SIGINT || $signal === SIGTERM) {
            $this->restoreTerminal();
            Terminal::showCursor();
            Terminal::clear();
            Terminal::reset();
            exit(0);
        }
    }
}
