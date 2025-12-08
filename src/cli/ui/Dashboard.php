<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui;

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
use tommyknocker\pdodb\helpers\exporters\CsvExporter;
use tommyknocker\pdodb\helpers\exporters\JsonExporter;
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

    /**
     * Active pane index.
     *
     * @var int
     */
    protected int $activePane = Layout::PANE_QUERIES;

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
     * Selected item index per pane.
     *
     * @var array<int, int>
     */
    protected array $selectedIndex = [
        Layout::PANE_QUERIES => 0,
        Layout::PANE_CONNECTIONS => 0,
        Layout::PANE_CACHE => 0,
        Layout::PANE_METRICS => 0,
        Layout::PANE_SCHEMA => 0,
        Layout::PANE_MIGRATIONS => 0,
        Layout::PANE_VARIABLES => 0,
        Layout::PANE_SCRATCHPAD => 0,
    ];

    /**
     * Scroll offset per pane.
     *
     * @var array<int, int>
     */
    protected array $scrollOffset = [
        Layout::PANE_QUERIES => 0,
        Layout::PANE_CONNECTIONS => 0,
        Layout::PANE_CACHE => 0,
        Layout::PANE_METRICS => 0,
        Layout::PANE_SCHEMA => 0,
        Layout::PANE_MIGRATIONS => 0,
        Layout::PANE_VARIABLES => 0,
        Layout::PANE_SCRATCHPAD => 0,
    ];

    /**
     * Search filter per pane.
     *
     * @var array<int, string>
     */
    protected array $searchFilter = [
        Layout::PANE_QUERIES => '',
        Layout::PANE_CONNECTIONS => '',
        Layout::PANE_CACHE => '',
        Layout::PANE_METRICS => '',
        Layout::PANE_SCHEMA => '',
        Layout::PANE_MIGRATIONS => '',
        Layout::PANE_VARIABLES => '',
        Layout::PANE_SCRATCHPAD => '',
    ];

    /**
     * Search mode per pane (true when user is typing search query).
     *
     * @var array<int, bool>
     */
    protected array $searchMode = [
        Layout::PANE_QUERIES => false,
        Layout::PANE_CONNECTIONS => false,
        Layout::PANE_CACHE => false,
        Layout::PANE_METRICS => false,
        Layout::PANE_SCHEMA => false,
        Layout::PANE_MIGRATIONS => false,
        Layout::PANE_VARIABLES => false,
        Layout::PANE_SCRATCHPAD => false,
    ];

    /**
     * Fullscreen pane index (-1 if none).
     *
     * @var int
     */
    protected int $fullscreenPane = -1;

    /**
     * Detail view pane index (-1 if none).
     *
     * @var int
     */
    protected int $detailViewPane = -1;

    /**
     * SQL Scratchpad query history (last 50 queries).
     *
     * @var array<int, string>
     */
    protected array $scratchpadHistory = [];

    /**
     * Current SQL query in editor.
     *
     * @var string
     */
    protected string $scratchpadQuery = '';

    /**
     * Cursor position in SQL editor (line, column).
     *
     * @var array{line: int, col: int}
     */
    protected array $scratchpadCursor = ['line' => 0, 'col' => 0];

    /**
     * History navigation index (-1 when not navigating).
     *
     * @var int
     */
    protected int $scratchpadHistoryIndex = -1;

    /**
     * Autocomplete active state.
     *
     * @var bool
     */
    protected bool $scratchpadAutocompleteActive = false;

    /**
     * Autocomplete options list.
     *
     * @var array<int, string>
     */
    protected array $scratchpadAutocompleteOptions = [];

    /**
     * Autocomplete selected index.
     *
     * @var int
     */
    protected int $scratchpadAutocompleteIndex = 0;

    /**
     * Autocomplete prefix (text to match).
     *
     * @var string
     */
    protected string $scratchpadAutocompletePrefix = '';

    /**
     * Cached table list for autocomplete.
     *
     * @var array<int, string>|null
     */
    protected ?array $scratchpadTableCache = null;

    /**
     * Cached columns for tables (table => columns).
     *
     * @var array<string, array<int, string>>
     */
    protected array $scratchpadColumnCache = [];

    /**
     * Scratchpad mode: 'editor' or 'results'.
     *
     * @var string
     */
    protected string $scratchpadMode = 'editor';

    /**
     * Transaction mode (auto-commit if false).
     *
     * @var bool
     */
    protected bool $scratchpadTransactionMode = false;

    /**
     * Save history to file mode.
     *
     * @var bool
     */
    protected bool $scratchpadSaveHistory = false;

    /**
     * Export results mode.
     *
     * @var bool
     */
    protected bool $scratchpadExportMode = false;

    /**
     * Last query result.
     *
     * @var array<int, array<string, mixed>>|null
     */
    protected ?array $scratchpadLastResult = null;

    /**
     * Last query execution time (seconds).
     *
     * @var float
     */
    protected float $scratchpadLastQueryTime = 0.0;

    /**
     * Last query error message.
     *
     * @var string|null
     */
    protected ?string $scratchpadLastError = null;

    /**
     * Last query affected rows count.
     *
     * @var int
     */
    protected int $scratchpadAffectedRows = 0;

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
     * Cached queries data.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $cachedQueries = [];

    /**
     * Cached connections data.
     *
     * @var array<string, mixed>
     */
    protected array $cachedConnections = [];

    /**
     * Cached server metrics data.
     *
     * @var array<string, mixed>
     */
    protected array $cachedServerMetrics = [];

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
        $this->updateScreenForPane(); // Initialize screen based on default active pane
    }

    /**
     * Run dashboard main loop.
     */
    public function run(): void
    {
        // Check if interactive
        // Check for PDODB_NON_INTERACTIVE and PHPUNIT first (fastest check)
        $nonInteractive = getenv('PDODB_NON_INTERACTIVE') !== false
            || getenv('PHPUNIT') !== false;

        // Only check stream_isatty if not already in non-interactive mode (expensive check)
        if (!$nonInteractive) {
            $nonInteractive = !stream_isatty(STDOUT);
        }

        if ($nonInteractive) {
            echo "TUI Dashboard requires an interactive terminal.\n";
            echo "Please run this command in a terminal with TTY support.\n";
            return;
        }

        // Setup signal handler for Ctrl+C (before terminal setup)
        // Setup terminal in raw mode for immediate character reading
        $this->setupTerminal();
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        }

        // Hide cursor and clear screen
        Terminal::hideCursor();
        Terminal::clear();

        // Initial data fetch
        $this->refresh();
        $this->lastRefresh = microtime(true);

        // Main loop
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
            if ($this->detailViewPane >= 0) {
                $this->renderDetailView();
            } elseif ($this->fullscreenPane >= 0) {
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
                if ($this->detailViewPane >= 0) {
                    $this->renderDetailView();
                } elseif ($this->fullscreenPane >= 0) {
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

        // Cleanup
        $this->restoreTerminal();
        Terminal::showCursor();
        Terminal::clear();
        Terminal::reset();
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

            // Set terminal to raw mode (immediate character input, no echo)
            @shell_exec('stty -icanon -echo');
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
        // Handle detail view mode
        if ($this->detailViewPane >= 0) {
            // In detail view, handle special keys
            if ($this->detailViewPane === Layout::PANE_MIGRATIONS) {
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
            $this->detailViewPane = -1;
            return false;
        }

        // Handle fullscreen mode
        if ($this->fullscreenPane >= 0) {
            // Handle scratchpad first to allow text input
            if ($this->fullscreenPane === Layout::PANE_SCRATCHPAD) {
                return $this->handleScratchpadKey($key);
            }

            // Handle search mode for Schema Browser and Server Variables
            if (in_array($this->fullscreenPane, [Layout::PANE_SCHEMA, Layout::PANE_VARIABLES], true)) {
                // Check if we're in search mode
                if ($this->searchMode[$this->fullscreenPane] ?? false) {
                    if ($key === 'esc') {
                        // Cancel search
                        $this->searchMode[$this->fullscreenPane] = false;
                        $this->searchFilter[$this->fullscreenPane] = '';
                        $this->selectedIndex[$this->fullscreenPane] = 0;
                        $this->scrollOffset[$this->fullscreenPane] = 0;
                        return false;
                    }
                    if ($key === 'enter') {
                        // Confirm search
                        $this->searchMode[$this->fullscreenPane] = false;
                        $this->selectedIndex[$this->fullscreenPane] = 0;
                        $this->scrollOffset[$this->fullscreenPane] = 0;
                        return false;
                    }
                    if ($key === 'backspace') {
                        // Remove last character
                        $filter = $this->searchFilter[$this->fullscreenPane];
                        if (mb_strlen($filter, 'UTF-8') > 0) {
                            $this->searchFilter[$this->fullscreenPane] = mb_substr($filter, 0, -1, 'UTF-8');
                        }
                        $this->selectedIndex[$this->fullscreenPane] = 0;
                        $this->scrollOffset[$this->fullscreenPane] = 0;
                        return false;
                    }
                    // Regular text input
                    if (strlen($key) === 1 && ord($key) >= 32 && ord($key) <= 126) {
                        $this->searchFilter[$this->fullscreenPane] .= $key;
                        $this->selectedIndex[$this->fullscreenPane] = 0;
                        $this->scrollOffset[$this->fullscreenPane] = 0;
                        return false;
                    }
                    // Ignore other keys in search mode
                    return false;
                }

                // Activate search mode with "/"
                if ($key === '/') {
                    $this->searchMode[$this->fullscreenPane] = true;
                    $this->searchFilter[$this->fullscreenPane] = '';
                    return false;
                }
            }

            if ($key === 'esc') {
                // Exit search mode if active, otherwise exit fullscreen
                if (in_array($this->fullscreenPane, [Layout::PANE_SCHEMA, Layout::PANE_VARIABLES], true) && ($this->searchMode[$this->fullscreenPane] ?? false)) {
                    $this->searchMode[$this->fullscreenPane] = false;
                    $this->searchFilter[$this->fullscreenPane] = '';
                    return false;
                }
                // Reset search mode and filter when exiting fullscreen
                if (in_array($this->fullscreenPane, [Layout::PANE_SCHEMA, Layout::PANE_VARIABLES], true)) {
                    $this->searchMode[$this->fullscreenPane] = false;
                    $this->searchFilter[$this->fullscreenPane] = '';
                }
                $this->fullscreenPane = -1;
                return false;
            }
            if ($key === 'enter') {
                // Don't show detail view if in search mode
                if (in_array($this->fullscreenPane, [Layout::PANE_SCHEMA, Layout::PANE_VARIABLES], true) && ($this->searchMode[$this->fullscreenPane] ?? false)) {
                    // Search mode handles Enter itself
                    return false;
                }
                // Show detail view for selected item
                if ($this->fullscreenPane === Layout::PANE_QUERIES) {
                    $queries = $this->cachedQueries;
                    $selectedIdx = $this->selectedIndex[Layout::PANE_QUERIES];
                    if ($selectedIdx >= 0 && $selectedIdx < count($queries)) {
                        $this->detailViewPane = Layout::PANE_QUERIES;
                    }
                } elseif ($this->fullscreenPane === Layout::PANE_CONNECTIONS) {
                    $connectionsData = $this->cachedConnections;
                    $connections = $connectionsData['connections'] ?? [];
                    $selectedIdx = $this->selectedIndex[Layout::PANE_CONNECTIONS];
                    if ($selectedIdx >= 0 && $selectedIdx < count($connections)) {
                        $this->detailViewPane = Layout::PANE_CONNECTIONS;
                    }
                } elseif ($this->fullscreenPane === Layout::PANE_MIGRATIONS) {
                    // Show migration file content
                    $this->detailViewPane = Layout::PANE_MIGRATIONS;
                } elseif ($this->fullscreenPane === Layout::PANE_SCHEMA) {
                    // Show table details (Columns/Indexes/Foreign Keys)
                    $this->detailViewPane = Layout::PANE_SCHEMA;
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
                if ($this->fullscreenPane === Layout::PANE_QUERIES) {
                    $this->handleKillQuery();
                } elseif ($this->fullscreenPane === Layout::PANE_CONNECTIONS) {
                    $this->handleKillConnection();
                }
                return false;
            }
            if ($key === 'a') {
                if ($this->fullscreenPane === Layout::PANE_MIGRATIONS) {
                    $this->handleApplyMigration();
                }
                return false;
            }
            if ($key === 'r') {
                if ($this->fullscreenPane === Layout::PANE_MIGRATIONS) {
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
                $this->fullscreenPane = $this->activePane;
                break;

            case 'h':
                $this->showHelp();
                break;

            case 'r':
                $this->cycleRefreshInterval();
                break;

            case '1':
                $this->activePane = Layout::PANE_QUERIES;
                $this->selectedIndex[$this->activePane] = 0;
                $this->scrollOffset[$this->activePane] = 0;
                $this->updateScreenForPane();
                break;

            case '2':
                $this->activePane = Layout::PANE_CONNECTIONS;
                $this->selectedIndex[$this->activePane] = 0;
                $this->scrollOffset[$this->activePane] = 0;
                $this->updateScreenForPane();
                break;

            case '3':
                $this->activePane = Layout::PANE_CACHE;
                $this->selectedIndex[$this->activePane] = 0;
                $this->scrollOffset[$this->activePane] = 0;
                $this->updateScreenForPane();
                break;

            case '4':
                $this->activePane = Layout::PANE_METRICS;
                $this->selectedIndex[$this->activePane] = 0;
                $this->scrollOffset[$this->activePane] = 0;
                $this->updateScreenForPane();
                break;

            case '5':
                $this->activePane = Layout::PANE_SCHEMA;
                $this->selectedIndex[$this->activePane] = 0;
                $this->scrollOffset[$this->activePane] = 0;
                $this->updateScreenForPane();
                break;

            case '6':
                $this->activePane = Layout::PANE_MIGRATIONS;
                $this->selectedIndex[$this->activePane] = 0;
                $this->scrollOffset[$this->activePane] = 0;
                $this->updateScreenForPane();
                break;

            case '7':
                $this->activePane = Layout::PANE_VARIABLES;
                $this->selectedIndex[$this->activePane] = 0;
                $this->scrollOffset[$this->activePane] = 0;
                $this->updateScreenForPane();
                break;

            case '8':
                $this->activePane = Layout::PANE_SCRATCHPAD;
                $this->selectedIndex[$this->activePane] = 0;
                $this->scrollOffset[$this->activePane] = 0;
                $this->updateScreenForPane();
                break;

            case 'tab':
            case 'right':
                // Increment pane, wrap from 7→0 (cycle through screens)
                $this->activePane = ($this->activePane + 1) % Layout::PANE_COUNT;
                $this->selectedIndex[$this->activePane] = 0;
                $this->scrollOffset[$this->activePane] = 0;
                $this->updateScreenForPane();
                break;

            case 'left':
                // Decrement pane, wrap from 0→7
                $this->activePane = ($this->activePane - 1 + Layout::PANE_COUNT) % Layout::PANE_COUNT;
                $this->selectedIndex[$this->activePane] = 0;
                $this->scrollOffset[$this->activePane] = 0;
                $this->updateScreenForPane();
                break;

            case 'up':
                $this->handleUp();
                break;

            case 'down':
                $this->handleDown();
                break;

            case 'x':
                // Kill query or connection depending on active pane
                if ($this->activePane === Layout::PANE_QUERIES) {
                    $this->handleKillQuery();
                } elseif ($this->activePane === Layout::PANE_CONNECTIONS) {
                    $this->handleKillConnection();
                }
                break;
        }

        return false;
    }

    /**
     * Update current screen based on active pane.
     */
    protected function updateScreenForPane(): void
    {
        if ($this->activePane >= 0 && $this->activePane <= 3) {
            $this->layout->setCurrentScreen(0);
        } elseif ($this->activePane >= 4 && $this->activePane <= 7) {
            $this->layout->setCurrentScreen(1);
        }
    }

    /**
     * Render dashboard.
     */
    protected function render(): void
    {
        // Clear screen
        Terminal::clear();

        // Check if in fullscreen mode
        if ($this->fullscreenPane >= 0) {
            $this->renderFullscreen();
            return;
        }

        // Render header
        $this->renderHeader();

        // Determine which screen to render based on active pane
        $currentScreen = $this->layout->getCurrentScreen();
        $isScreen1 = ($currentScreen === 0);

        // Render panes for current screen only
        $isQueriesActive = $this->activePane === Layout::PANE_QUERIES;
        $isConnectionsActive = $this->activePane === Layout::PANE_CONNECTIONS;

        // Calculate scroll offset for queries pane
        if ($isQueriesActive) {
            $queries = $this->cachedQueries;
            $content = $this->layout->getContentArea(Layout::PANE_QUERIES);
            $visibleHeight = $content['height'] - 1;
            $selectedIdx = $this->selectedIndex[Layout::PANE_QUERIES];

            if ($selectedIdx >= 0 && count($queries) > 0) {
                $relativeIndex = $selectedIdx - $this->scrollOffset[Layout::PANE_QUERIES];
                if ($relativeIndex < 0) {
                    $this->scrollOffset[Layout::PANE_QUERIES] = $selectedIdx;
                } elseif ($relativeIndex >= $visibleHeight) {
                    $this->scrollOffset[Layout::PANE_QUERIES] = max(0, $selectedIdx - $visibleHeight + 1);
                }
            }
        }

        // Calculate scroll offset for connections pane
        if ($isConnectionsActive) {
            $connectionsData = $this->cachedConnections;
            $connections = $connectionsData['connections'] ?? [];
            $content = $this->layout->getContentArea(Layout::PANE_CONNECTIONS);
            $summaryHeight = 3;
            $visibleHeight = $content['height'] - $summaryHeight - 2;
            $selectedIdx = $this->selectedIndex[Layout::PANE_CONNECTIONS];

            if ($selectedIdx >= 0 && count($connections) > 0) {
                $relativeIndex = $selectedIdx - $this->scrollOffset[Layout::PANE_CONNECTIONS];
                if ($relativeIndex < 0) {
                    $this->scrollOffset[Layout::PANE_CONNECTIONS] = $selectedIdx;
                } elseif ($relativeIndex >= $visibleHeight) {
                    $this->scrollOffset[Layout::PANE_CONNECTIONS] = max(0, $selectedIdx - $visibleHeight + 1);
                }
            }
        }

        ActiveQueriesPane::render(
            $this->db,
            $this->layout,
            Layout::PANE_QUERIES,
            $isQueriesActive,
            $isQueriesActive ? $this->selectedIndex[Layout::PANE_QUERIES] : -1,
            $isQueriesActive ? $this->scrollOffset[Layout::PANE_QUERIES] : 0,
            false,
            $this->cachedQueries
        );

        ConnectionPoolPane::render(
            $this->db,
            $this->layout,
            Layout::PANE_CONNECTIONS,
            $isConnectionsActive,
            $isConnectionsActive ? $this->selectedIndex[Layout::PANE_CONNECTIONS] : -1,
            $isConnectionsActive ? $this->scrollOffset[Layout::PANE_CONNECTIONS] : 0,
            false,
            $this->cachedConnections
        );

        if ($isScreen1) {
            // Screen 1: Panes 0-3
            CacheStatsPane::render($this->db, $this->layout, Layout::PANE_CACHE, $this->activePane === Layout::PANE_CACHE);
            ServerMetricsPane::render($this->db, $this->layout, Layout::PANE_METRICS, $this->activePane === Layout::PANE_METRICS, $this->cachedServerMetrics);
        } else {
            // Screen 2: Panes 4-7
            $isSchemaActive = $this->activePane === Layout::PANE_SCHEMA;
            $isMigrationsActive = $this->activePane === Layout::PANE_MIGRATIONS;
            $isVariablesActive = $this->activePane === Layout::PANE_VARIABLES;
            $isScratchpadActive = $this->activePane === Layout::PANE_SCRATCHPAD;

            SchemaBrowserPane::render(
                $this->db,
                $this->layout,
                Layout::PANE_SCHEMA,
                $isSchemaActive,
                $isSchemaActive ? $this->selectedIndex[Layout::PANE_SCHEMA] : -1,
                $isSchemaActive ? $this->scrollOffset[Layout::PANE_SCHEMA] : 0
            );

            MigrationManagerPane::render(
                $this->db,
                $this->layout,
                Layout::PANE_MIGRATIONS,
                $isMigrationsActive,
                $isMigrationsActive ? $this->selectedIndex[Layout::PANE_MIGRATIONS] : -1,
                $isMigrationsActive ? $this->scrollOffset[Layout::PANE_MIGRATIONS] : 0
            );

            ServerVariablesPane::render(
                $this->db,
                $this->layout,
                Layout::PANE_VARIABLES,
                $isVariablesActive,
                $isVariablesActive ? $this->selectedIndex[Layout::PANE_VARIABLES] : -1,
                $isVariablesActive ? $this->scrollOffset[Layout::PANE_VARIABLES] : 0
            );

            $lastQuery = !empty($this->scratchpadHistory) ? end($this->scratchpadHistory) : null;
            SqlScratchpadPane::render(
                $this->db,
                $this->layout,
                Layout::PANE_SCRATCHPAD,
                $isScratchpadActive,
                $lastQuery,
                $this->scratchpadHistory
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
        echo $leftText;
        Terminal::reset();

        // Center: Updated timestamp
        Terminal::moveTo(1, $centerPos);
        echo $centerText;

        // Right: Driver
        Terminal::moveTo(1, $rightPos);
        echo $rightText;
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

        echo $help;

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
        $this->layout->renderBorder($paneIndex, $title, $this->activePane === $paneIndex);
        $content = $this->layout->getContentArea($paneIndex);
        Terminal::moveTo($content['row'], $content['col']);
        echo 'Coming soon...';
    }

    /**
     * Render footer for fullscreen mode.
     */
    protected function renderFullscreenFooter(): void
    {
        [$rows, $cols] = Terminal::getSize();
        Terminal::moveTo($rows, 1);
        Terminal::clearLine();

        if (Terminal::supportsColors()) {
            Terminal::color(Terminal::BG_BLUE);
            Terminal::color(Terminal::COLOR_WHITE);
        }

        // Build fullscreen footer help text
        $pane = $this->fullscreenPane;
        $help = '';

        if ($pane === Layout::PANE_QUERIES) {
            $help = '↑↓:Select | PgUp/PgDn:Page | Enter:Details | x:Kill | Esc:Exit';
        } elseif ($pane === Layout::PANE_CONNECTIONS) {
            $help = '↑↓:Select | PgUp/PgDn:Page | Enter:Details | x:Kill | Esc:Exit';
        } elseif ($pane === Layout::PANE_CACHE || $pane === Layout::PANE_METRICS) {
            $help = 'Esc:Exit';
        } elseif ($pane === Layout::PANE_SCHEMA) {
            $help = '↑↓:Select | PgUp/PgDn:Page | Enter:Table Details | /:Search | Esc:Exit';
        } elseif ($pane === Layout::PANE_MIGRATIONS) {
            // Get current migration status to show appropriate commands
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
            usort($allMigrations, function ($a, $b) {
                return strcmp($a['version'], $b['version']);
            });
            $selectedIdx = $this->selectedIndex[Layout::PANE_MIGRATIONS];
            $help = '↑↓:Select | PgUp/PgDn:Page | Enter:View';
            if ($selectedIdx >= 0 && $selectedIdx < count($allMigrations)) {
                $migration = $allMigrations[$selectedIdx];
                if ($migration['status'] === 'pending') {
                    $help .= ' | a:Apply';
                } else {
                    $help .= ' | r:Rollback';
                }
            }
            $help .= ' | Esc:Exit';
        } elseif ($pane === Layout::PANE_VARIABLES) {
            $help = '↑↓:Select | PgUp/PgDn:Page | /:Search | Esc:Exit';
        } elseif ($pane === Layout::PANE_SCRATCHPAD) {
            $help = 'F5:Execute | Tab:Switch | F2:TX | F3:Save | F4:Export | Esc:Exit';
        } else {
            $help = 'Esc:Exit';
        }

        // Truncate if too long to fit in one line
        if (mb_strlen($help, 'UTF-8') > $cols) {
            $help = mb_substr($help, 0, $cols, 'UTF-8');
        }

        echo $help;
        Terminal::reset();
    }

    /**
     * Refresh data (fetch from database).
     */
    protected function refresh(): void
    {
        // Fetch fresh data from database
        $this->cachedQueries = MonitorManager::getActiveQueries($this->db);
        $this->cachedConnections = MonitorManager::getActiveConnections($this->db);
        $this->cachedServerMetrics = $this->db->schema()->getDialect()->getServerMetrics($this->db);
    }

    /**
     * Handle up arrow key.
     */
    protected function handleUp(): void
    {
        $pane = $this->fullscreenPane >= 0 ? $this->fullscreenPane : $this->activePane;
        if (in_array($pane, [Layout::PANE_QUERIES, Layout::PANE_CONNECTIONS, Layout::PANE_SCHEMA, Layout::PANE_MIGRATIONS, Layout::PANE_VARIABLES], true)) {
            if ($this->selectedIndex[$pane] > 0) {
                $this->selectedIndex[$pane]--;
            }
        }
    }

    /**
     * Handle down arrow key.
     */
    protected function handleDown(): void
    {
        $pane = $this->fullscreenPane >= 0 ? $this->fullscreenPane : $this->activePane;
        if ($pane === Layout::PANE_QUERIES) {
            $queries = $this->cachedQueries;
            if ($this->selectedIndex[Layout::PANE_QUERIES] < count($queries) - 1) {
                $this->selectedIndex[Layout::PANE_QUERIES]++;
            }
        } elseif ($pane === Layout::PANE_CONNECTIONS) {
            $connectionsData = $this->cachedConnections;
            $connections = $connectionsData['connections'] ?? [];
            if ($this->selectedIndex[Layout::PANE_CONNECTIONS] < count($connections) - 1) {
                $this->selectedIndex[Layout::PANE_CONNECTIONS]++;
            }
        } elseif ($pane === Layout::PANE_SCHEMA) {
            $tables = TableManager::listTables($this->db);
            if ($this->selectedIndex[Layout::PANE_SCHEMA] < count($tables) - 1) {
                $this->selectedIndex[Layout::PANE_SCHEMA]++;
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
            if ($this->selectedIndex[Layout::PANE_MIGRATIONS] < count($allMigrations) - 1) {
                $this->selectedIndex[Layout::PANE_MIGRATIONS]++;
            }
        } elseif ($pane === Layout::PANE_VARIABLES) {
            $dialect = $this->db->schema()->getDialect();
            $variables = $dialect->getServerVariables($this->db);
            if ($this->selectedIndex[Layout::PANE_VARIABLES] < count($variables) - 1) {
                $this->selectedIndex[Layout::PANE_VARIABLES]++;
            }
        }
    }

    /**
     * Handle PageUp key (jump up by page).
     */
    protected function handlePageUp(): void
    {
        $pane = $this->fullscreenPane >= 0 ? $this->fullscreenPane : $this->activePane;
        [$rows] = Terminal::getSize();
        $pageSize = max(1, $rows - 4); // Visible items per page

        if (in_array($pane, [Layout::PANE_QUERIES, Layout::PANE_CONNECTIONS, Layout::PANE_SCHEMA, Layout::PANE_MIGRATIONS, Layout::PANE_VARIABLES], true)) {
            $newIndex = max(0, $this->selectedIndex[$pane] - $pageSize);
            $this->selectedIndex[$pane] = $newIndex;
            // Update scroll offset to keep selection visible
            // In fullscreen, scrollOffset should be set so that selectedIndex is visible
            $visibleHeight = $rows - 3; // Account for header and footer
            $this->scrollOffset[$pane] = max(0, $newIndex);
        }
    }

    /**
     * Handle PageDown key (jump down by page).
     */
    protected function handlePageDown(): void
    {
        $pane = $this->fullscreenPane >= 0 ? $this->fullscreenPane : $this->activePane;
        [$rows] = Terminal::getSize();
        $pageSize = max(1, $rows - 4); // Visible items per page
        $visibleHeight = $rows - 3; // Account for header and footer

        if ($pane === Layout::PANE_QUERIES) {
            $queries = $this->cachedQueries;
            $maxIndex = count($queries) - 1;
            $newIndex = min($maxIndex, $this->selectedIndex[Layout::PANE_QUERIES] + $pageSize);
            $this->selectedIndex[Layout::PANE_QUERIES] = $newIndex;
            // Update scroll offset to keep selection visible
            $this->scrollOffset[Layout::PANE_QUERIES] = max(0, $newIndex - $visibleHeight + 1);
        } elseif ($pane === Layout::PANE_CONNECTIONS) {
            $connectionsData = $this->cachedConnections;
            $connections = $connectionsData['connections'] ?? [];
            $maxIndex = count($connections) - 1;
            $newIndex = min($maxIndex, $this->selectedIndex[Layout::PANE_CONNECTIONS] + $pageSize);
            $this->selectedIndex[Layout::PANE_CONNECTIONS] = $newIndex;
            // Update scroll offset to keep selection visible
            $this->scrollOffset[Layout::PANE_CONNECTIONS] = max(0, $newIndex - $visibleHeight + 1);
        } elseif ($pane === Layout::PANE_SCHEMA) {
            $tables = TableManager::listTables($this->db);
            $maxIndex = count($tables) - 1;
            $newIndex = min($maxIndex, $this->selectedIndex[Layout::PANE_SCHEMA] + $pageSize);
            $this->selectedIndex[Layout::PANE_SCHEMA] = $newIndex;
            // Update scroll offset to keep selection visible
            $this->scrollOffset[Layout::PANE_SCHEMA] = max(0, $newIndex - $visibleHeight + 1);
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
            $newIndex = min($maxIndex, $this->selectedIndex[Layout::PANE_MIGRATIONS] + $pageSize);
            $this->selectedIndex[Layout::PANE_MIGRATIONS] = $newIndex;
            // Update scroll offset to keep selection visible
            $this->scrollOffset[Layout::PANE_MIGRATIONS] = max(0, $newIndex - $visibleHeight + 1);
        } elseif ($pane === Layout::PANE_VARIABLES) {
            $dialect = $this->db->schema()->getDialect();
            $variables = $dialect->getServerVariables($this->db);
            $maxIndex = count($variables) - 1;
            $newIndex = min($maxIndex, $this->selectedIndex[Layout::PANE_VARIABLES] + $pageSize);
            $this->selectedIndex[Layout::PANE_VARIABLES] = $newIndex;
            // Update scroll offset to keep selection visible
            $this->scrollOffset[Layout::PANE_VARIABLES] = max(0, $newIndex - $visibleHeight + 1);
        }
    }

    /**
     * Handle kill query action.
     */
    protected function handleKillQuery(): void
    {
        // Can be called from queries pane or fullscreen queries
        $pane = $this->fullscreenPane >= 0 ? $this->fullscreenPane : $this->activePane;
        if ($pane !== Layout::PANE_QUERIES) {
            return;
        }

        $queries = $this->cachedQueries;
        if (empty($queries)) {
            return;
        }

        $selectedIdx = $this->selectedIndex[Layout::PANE_QUERIES];
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
            $this->selectedIndex[Layout::PANE_QUERIES] = 0;
            $this->scrollOffset[Layout::PANE_QUERIES] = 0;
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

        $selectedIdx = $this->selectedIndex[Layout::PANE_MIGRATIONS];
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

        $selectedIdx = $this->selectedIndex[Layout::PANE_MIGRATIONS];
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
        echo $message;
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
        $pane = $this->fullscreenPane >= 0 ? $this->fullscreenPane : $this->activePane;
        if ($pane !== Layout::PANE_CONNECTIONS) {
            return;
        }

        $connectionsData = $this->cachedConnections;
        $connections = $connectionsData['connections'] ?? [];
        if (empty($connections)) {
            return;
        }

        $selectedIdx = $this->selectedIndex[Layout::PANE_CONNECTIONS];
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
            $this->selectedIndex[Layout::PANE_CONNECTIONS] = 0;
            $this->scrollOffset[Layout::PANE_CONNECTIONS] = 0;
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
        echo str_repeat(' ', 50);
        Terminal::moveTo($dialogRow + 1, $dialogCol);
        $messageLine = ' ' . substr($message, 0, 48) . ' ';
        echo str_pad($messageLine, 50);
        Terminal::moveTo($dialogRow + 2, $dialogCol);
        echo ' ' . str_pad('Press Y to confirm, N to cancel', 48) . ' ';
        Terminal::moveTo($dialogRow + 3, $dialogCol);
        echo str_repeat(' ', 50);
        Terminal::reset();
        flush();

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
     * Render fullscreen pane.
     */
    protected function renderFullscreen(): void
    {
        [$rows, $cols] = Terminal::getSize();

        if ($this->fullscreenPane === Layout::PANE_QUERIES) {
            // Don't clear entire screen for queries pane - only clear header and footer
            // Content area is cleared by ActiveQueriesPane::render() to avoid flickering

            // Calculate scroll offset for fullscreen
            $queries = $this->cachedQueries;
            // Header: row 1, Table header: row 2, Footer: row $rows
            // Content area: row 2, height = $rows - 2
            // Visible height for queries list = $rows - 2 - 1 (table header)
            $visibleHeight = $rows - 3;
            $selectedIdx = $this->selectedIndex[Layout::PANE_QUERIES];

            if ($selectedIdx >= 0 && count($queries) > 0) {
                // Clamp selected index
                if ($selectedIdx >= count($queries)) {
                    $selectedIdx = count($queries) - 1;
                    $this->selectedIndex[Layout::PANE_QUERIES] = $selectedIdx;
                }

                // Only adjust scrollOffset if selected item is not visible
                $relativeIndex = $selectedIdx - $this->scrollOffset[Layout::PANE_QUERIES];
                if ($relativeIndex < 0) {
                    // Selected item is above visible area - scroll up
                    $this->scrollOffset[Layout::PANE_QUERIES] = $selectedIdx;
                } elseif ($relativeIndex >= $visibleHeight) {
                    // Selected item is below visible area - scroll down
                    $this->scrollOffset[Layout::PANE_QUERIES] = max(0, $selectedIdx - $visibleHeight + 1);
                }
                // If relativeIndex is in range [0, visibleHeight), keep current scrollOffset
            }

            // Render header (clear only header line)
            Terminal::moveTo(1, 1);
            Terminal::clearLine();
            if (Terminal::supportsColors()) {
                Terminal::bold();
                Terminal::color(Terminal::COLOR_CYAN);
            }
            echo 'Active Queries (Fullscreen)';
            Terminal::reset();

            // Render pane in fullscreen
            $dummyLayout = new Layout($rows, $cols);
            ActiveQueriesPane::render(
                $this->db,
                $dummyLayout,
                Layout::PANE_QUERIES,
                true,
                $this->selectedIndex[Layout::PANE_QUERIES],
                $this->scrollOffset[Layout::PANE_QUERIES],
                true,
                $this->cachedQueries
            );

            // Render footer
            $this->renderFullscreenFooter();
        } elseif ($this->fullscreenPane === Layout::PANE_CONNECTIONS) {
            // Calculate scroll offset for fullscreen
            $connectionsData = $this->cachedConnections;
            $connections = $connectionsData['connections'] ?? [];
            // Header: row 1
            // Summary: rows 2-4 (3 rows)
            // Spacing: row 5
            // "Connections:" label: row 6
            // Table header: row 7
            // Connections list starts: row 8
            // Footer: row $rows
            // Visible height for connections list = $rows - 1 (footer) - 7 (header + summary + spacing + label + table header) = $rows - 8
            $summaryHeight = 3;
            $spacingHeight = 2; // Spacing before "Connections:" label
            $labelHeight = 1;
            $tableHeaderHeight = 1;
            $visibleHeight = $rows - 1 - ($summaryHeight + $spacingHeight + $labelHeight + $tableHeaderHeight);
            $selectedIdx = $this->selectedIndex[Layout::PANE_CONNECTIONS];

            if ($selectedIdx >= 0 && count($connections) > 0) {
                // Clamp selected index
                if ($selectedIdx >= count($connections)) {
                    $selectedIdx = count($connections) - 1;
                    $this->selectedIndex[Layout::PANE_CONNECTIONS] = $selectedIdx;
                }

                // Only adjust scrollOffset if selected item is not visible
                $relativeIndex = $selectedIdx - $this->scrollOffset[Layout::PANE_CONNECTIONS];
                if ($relativeIndex < 0) {
                    // Selected item is above visible area - scroll up
                    $this->scrollOffset[Layout::PANE_CONNECTIONS] = $selectedIdx;
                } elseif ($relativeIndex >= $visibleHeight) {
                    // Selected item is below visible area - scroll down
                    $this->scrollOffset[Layout::PANE_CONNECTIONS] = max(0, $selectedIdx - $visibleHeight + 1);
                }
                // If relativeIndex is in range [0, visibleHeight), keep current scrollOffset
            }

            // Render header
            Terminal::moveTo(1, 1);
            if (Terminal::supportsColors()) {
                Terminal::bold();
                Terminal::color(Terminal::COLOR_CYAN);
            }
            echo 'Connection Pool (Fullscreen)';
            Terminal::reset();

            // Render pane in fullscreen
            $dummyLayout = new Layout($rows, $cols);
            ConnectionPoolPane::render(
                $this->db,
                $dummyLayout,
                Layout::PANE_CONNECTIONS,
                true,
                $this->selectedIndex[Layout::PANE_CONNECTIONS],
                $this->scrollOffset[Layout::PANE_CONNECTIONS],
                true,
                $this->cachedConnections
            );

            // Render footer
            $this->renderFullscreenFooter();
        } elseif ($this->fullscreenPane === Layout::PANE_CACHE) {
            // Render header
            Terminal::moveTo(1, 1);
            if (Terminal::supportsColors()) {
                Terminal::bold();
                Terminal::color(Terminal::COLOR_CYAN);
            }
            echo 'Cache Stats (Fullscreen)';
            Terminal::reset();

            // Render cache stats in fullscreen
            $this->renderCacheStatsFullscreen();

            // Render footer
            $this->renderFullscreenFooter();
        } elseif ($this->fullscreenPane === Layout::PANE_METRICS) {
            // Render header
            Terminal::moveTo(1, 1);
            if (Terminal::supportsColors()) {
                Terminal::bold();
                Terminal::color(Terminal::COLOR_CYAN);
            }
            echo 'Server Metrics (Fullscreen)';
            Terminal::reset();

            // Render server metrics in fullscreen
            $this->renderServerMetricsFullscreen();

            // Render footer
            $this->renderFullscreenFooter();
        } elseif ($this->fullscreenPane === Layout::PANE_SCHEMA) {
            $this->renderSchemaBrowserFullscreen();
        } elseif ($this->fullscreenPane === Layout::PANE_MIGRATIONS) {
            $this->renderMigrationsFullscreen();
        } elseif ($this->fullscreenPane === Layout::PANE_VARIABLES) {
            $this->renderVariablesFullscreen();
        } elseif ($this->fullscreenPane === Layout::PANE_SCRATCHPAD) {
            $this->renderScratchpadFullscreen();
        }

        flush();
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
                echo str_repeat(' ', 60);
            } else {
                if (Terminal::supportsColors()) {
                    Terminal::color(Terminal::BG_BLUE);
                    Terminal::color(Terminal::COLOR_WHITE);
                }
                echo ' ' . str_pad('', 58) . ' ';
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
        echo ' ' . str_pad('PDOdb TUI Dashboard - Help', 58) . ' ';
        Terminal::reset();

        // Help content
        $helpLines = [
            '',
            ' Navigation:',
            '  1-4          Switch between panes',
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
            echo ' ' . str_pad($line, 58) . ' ';
            Terminal::reset();
            $lineNum++;
        }

        flush();

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

    /**
     * Render detail view for selected query or connection.
     */
    protected function renderDetailView(): void
    {
        [$rows, $cols] = Terminal::getSize();
        Terminal::clear();

        if ($this->detailViewPane === Layout::PANE_QUERIES) {
            $queries = $this->cachedQueries;
            $selectedIdx = $this->selectedIndex[Layout::PANE_QUERIES];

            if ($selectedIdx >= 0 && $selectedIdx < count($queries)) {
                $query = $queries[$selectedIdx];

                // Render header
                Terminal::moveTo(1, 1);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                    Terminal::color(Terminal::COLOR_CYAN);
                }
                echo 'Query Details (Press any key to close)';
                Terminal::reset();

                // Render query details
                $row = 3;
                $col = 1;
                $width = $cols - 2;

                // ID
                Terminal::moveTo($row, $col);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                }
                echo 'ID: ';
                Terminal::reset();
                echo $query['id'] ?? $query['pid'] ?? $query['session_id'] ?? 'N/A';
                $row++;

                // User
                if (isset($query['user']) || isset($query['usename']) || isset($query['login'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    echo 'User: ';
                    Terminal::reset();
                    echo $query['user'] ?? $query['usename'] ?? $query['login'] ?? 'N/A';
                    $row++;
                }

                // Database
                if (isset($query['db']) || isset($query['database']) || isset($query['datname'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    echo 'Database: ';
                    Terminal::reset();
                    echo $query['db'] ?? $query['database'] ?? $query['datname'] ?? 'N/A';
                    $row++;
                }

                // Time/Duration
                if (isset($query['time']) || isset($query['duration'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    echo 'Time: ';
                    Terminal::reset();
                    echo $query['time'] ?? $query['duration'] ?? 'N/A';
                    $row++;
                }

                // State (if available)
                if (isset($query['state']) || isset($query['status'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    echo 'State: ';
                    Terminal::reset();
                    echo $query['state'] ?? $query['status'] ?? 'N/A';
                    $row++;
                }

                $row++;

                // Full query text
                Terminal::moveTo($row, $col);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                }
                echo 'Query:';
                Terminal::reset();
                $row++;

                $queryText = (string)($query['query'] ?? '');
                if (!empty($queryText)) {
                    // Word wrap query text
                    $lines = explode("\n", wordwrap($queryText, $width, "\n", true));
                    foreach ($lines as $line) {
                        if ($row >= $rows - 1) {
                            break; // Don't overwrite footer
                        }
                        Terminal::moveTo($row, $col);
                        echo $line;
                        $row++;
                    }
                } else {
                    Terminal::moveTo($row, $col);
                    echo '(No query text available)';
                }
            }
        } elseif ($this->detailViewPane === Layout::PANE_CONNECTIONS) {
            $connectionsData = $this->cachedConnections;
            $connections = $connectionsData['connections'] ?? [];
            $selectedIdx = $this->selectedIndex[Layout::PANE_CONNECTIONS];

            if ($selectedIdx >= 0 && $selectedIdx < count($connections)) {
                $conn = $connections[$selectedIdx];

                // Render header
                Terminal::moveTo(1, 1);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                    Terminal::color(Terminal::COLOR_CYAN);
                }
                echo 'Connection Details (Press any key to close)';
                Terminal::reset();

                // Render connection details
                $row = 3;
                $col = 1;
                $width = $cols - 2;

                // ID
                Terminal::moveTo($row, $col);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                }
                echo 'ID: ';
                Terminal::reset();
                echo $conn['id'] ?? $conn['pid'] ?? $conn['session_id'] ?? 'N/A';
                $row++;

                // User
                if (isset($conn['user']) || isset($conn['usename']) || isset($conn['login'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    echo 'User: ';
                    Terminal::reset();
                    echo $conn['user'] ?? $conn['usename'] ?? $conn['login'] ?? 'N/A';
                    $row++;
                }

                // Database
                if (isset($conn['db']) || isset($conn['database']) || isset($conn['datname'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    echo 'Database: ';
                    Terminal::reset();
                    echo $conn['db'] ?? $conn['database'] ?? $conn['datname'] ?? 'N/A';
                    $row++;
                }

                // Host (if available)
                if (isset($conn['host']) || isset($conn['client_addr'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    echo 'Host: ';
                    Terminal::reset();
                    echo $conn['host'] ?? $conn['client_addr'] ?? 'N/A';
                    $row++;
                }

                // State (if available)
                if (isset($conn['state']) || isset($conn['status'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    echo 'State: ';
                    Terminal::reset();
                    echo $conn['state'] ?? $conn['status'] ?? 'N/A';
                    $row++;
                }

                // Application name (if available, e.g., PostgreSQL)
                if (isset($conn['application_name'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    echo 'Application: ';
                    Terminal::reset();
                    echo $conn['application_name'];
                    $row++;
                }

                // Backend start (if available, e.g., PostgreSQL)
                if (isset($conn['backend_start'])) {
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                    }
                    echo 'Backend Start: ';
                    Terminal::reset();
                    echo $conn['backend_start'];
                    $row++;
                }

                // Show all other available fields
                $shownFields = ['id', 'pid', 'session_id', 'user', 'usename', 'login', 'db', 'database', 'datname', 'host', 'client_addr', 'state', 'status', 'application_name', 'backend_start'];
                foreach ($conn as $key => $value) {
                    if (!in_array($key, $shownFields, true) && $value !== null && $value !== '') {
                        if ($row >= $rows - 1) {
                            break; // Don't overwrite footer
                        }
                        Terminal::moveTo($row, $col);
                        if (Terminal::supportsColors()) {
                            Terminal::bold();
                        }
                        echo ucfirst(str_replace('_', ' ', $key)) . ': ';
                        Terminal::reset();
                        echo is_array($value) ? json_encode($value) : (string)$value;
                        $row++;
                    }
                }
            }
        } elseif ($this->detailViewPane === Layout::PANE_MIGRATIONS) {
            $this->renderMigrationDetailView();
        } elseif ($this->detailViewPane === Layout::PANE_SCHEMA) {
            $this->renderTableDetailView();
        }

        // Render footer
        Terminal::moveTo($rows, 1);
        Terminal::clearLine();
        if (Terminal::supportsColors()) {
            Terminal::color(Terminal::BG_BLUE);
            Terminal::color(Terminal::COLOR_WHITE);
        }
        if ($this->detailViewPane === Layout::PANE_MIGRATIONS) {
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
            usort($allMigrations, function ($a, $b) {
                return strcmp($a['version'], $b['version']);
            });
            $selectedIdx = $this->selectedIndex[Layout::PANE_MIGRATIONS];
            if ($selectedIdx >= 0 && $selectedIdx < count($allMigrations)) {
                $migration = $allMigrations[$selectedIdx];
                $status = $migration['status'];
                if ($status === 'pending') {
                    echo 'a:Apply | Any key:Close';
                } else {
                    echo 'r:Rollback | Any key:Close';
                }
            } else {
                echo 'Press any key to close';
            }
        } else {
            echo 'Press any key to close';
        }
        Terminal::reset();

        flush();
    }

    /**
     * Render cache stats in fullscreen mode with additional details.
     */
    protected function renderCacheStatsFullscreen(): void
    {
        [$rows, $cols] = Terminal::getSize();

        // Clear content area (skip header row 1 and footer row $rows)
        for ($i = 2; $i < $rows; $i++) {
            Terminal::moveTo($i, 1);
            Terminal::clearLine();
        }

        $cacheManager = $this->db->getCacheManager();

        if ($cacheManager === null) {
            Terminal::moveTo(3, 1);
            echo 'Cache not enabled';
            return;
        }

        $stats = $cacheManager->getStats();
        $row = 3;
        $col = 1;
        $width = $cols - 2;

        // Status
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        echo 'Status: ';
        Terminal::reset();
        echo ($stats['enabled'] ?? false) ? 'Enabled' : 'Disabled';
        $row++;

        // Type
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        echo 'Type: ';
        Terminal::reset();
        echo $stats['type'] ?? 'unknown';
        $row++;

        // Hits
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        echo 'Hits: ';
        Terminal::reset();
        $hits = (int)($stats['hits'] ?? 0);
        echo number_format($hits);
        $row++;

        // Misses
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        echo 'Misses: ';
        Terminal::reset();
        $misses = (int)($stats['misses'] ?? 0);
        echo number_format($misses);
        $row++;

        // Hit Rate
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        echo 'Hit Rate: ';
        Terminal::reset();
        $hitRate = (float)($stats['hit_rate'] ?? 0);

        // Color code hit rate
        if (Terminal::supportsColors()) {
            if ($hitRate >= 80) {
                Terminal::color(Terminal::COLOR_GREEN);
            } elseif ($hitRate >= 50) {
                Terminal::color(Terminal::COLOR_YELLOW);
            } else {
                Terminal::color(Terminal::COLOR_RED);
            }
        }

        echo number_format($hitRate, 2) . '%';
        Terminal::reset();
        $row++;

        // Total Requests
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        echo 'Total Requests: ';
        Terminal::reset();
        $total = (int)($stats['total_requests'] ?? 0);
        echo number_format($total);
        $row++;

        // Sets
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        echo 'Sets: ';
        Terminal::reset();
        $sets = (int)($stats['sets'] ?? 0);
        echo number_format($sets);
        $row++;

        // Deletes
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        echo 'Deletes: ';
        Terminal::reset();
        $deletes = (int)($stats['deletes'] ?? 0);
        echo number_format($deletes);
        $row++;

        // TTL
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
        }
        echo 'Default TTL: ';
        Terminal::reset();
        $ttl = (int)($stats['default_ttl'] ?? 0);
        echo $ttl . 's';
        $row++;

        // Additional details if available
        $row++;
        if (isset($stats['size']) || isset($stats['memory_usage']) || isset($stats['keys_count'])) {
            Terminal::moveTo($row, $col);
            if (Terminal::supportsColors()) {
                Terminal::bold();
                Terminal::color(Terminal::COLOR_CYAN);
            }
            echo 'Additional Information:';
            Terminal::reset();
            $row++;

            // Size/Memory Usage
            if (isset($stats['size']) || isset($stats['memory_usage'])) {
                Terminal::moveTo($row, $col);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                }
                echo 'Size/Memory: ';
                Terminal::reset();
                $size = $stats['size'] ?? $stats['memory_usage'] ?? 0;
                if (is_numeric($size)) {
                    echo ServerMetricsPane::formatBytes((int)$size);
                } else {
                    echo (string)$size;
                }
                $row++;
            }

            // Keys Count
            if (isset($stats['keys_count'])) {
                Terminal::moveTo($row, $col);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                }
                echo 'Keys Count: ';
                Terminal::reset();
                echo number_format((int)$stats['keys_count']);
                $row++;
            }
        }

        // Show all other available stats
        $shownFields = ['enabled', 'type', 'hits', 'misses', 'hit_rate', 'total_requests', 'sets', 'deletes', 'default_ttl', 'size', 'memory_usage', 'keys_count'];
        foreach ($stats as $key => $value) {
            if (!in_array($key, $shownFields, true) && $value !== null && $value !== '') {
                if ($row >= $rows - 1) {
                    break; // Don't overwrite footer
                }
                Terminal::moveTo($row, $col);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                }
                echo ucfirst(str_replace('_', ' ', $key)) . ': ';
                Terminal::reset();
                echo is_array($value) ? json_encode($value) : (string)$value;
                $row++;
            }
        }
    }

    /**
     * Render server metrics in fullscreen mode with additional details.
     */
    protected function renderServerMetricsFullscreen(): void
    {
        [$rows, $cols] = Terminal::getSize();

        // Clear content area (skip header row 1 and footer row $rows)
        for ($i = 2; $i < $rows; $i++) {
            Terminal::moveTo($i, 1);
            Terminal::clearLine();
        }

        $metrics = $this->cachedServerMetrics;

        if (empty($metrics)) {
            Terminal::moveTo(3, 1);
            echo 'No metrics available';
            return;
        }

        $row = 3;
        $col = 1;
        $width = $cols - 2;

        // Version
        if (isset($metrics['version'])) {
            Terminal::moveTo($row, $col);
            if (Terminal::supportsColors()) {
                Terminal::bold();
            }
            echo 'Version: ';
            Terminal::reset();
            $version = (string)$metrics['version'];
            echo $version;
            $row++;
        }

        // Uptime
        if (isset($metrics['uptime_seconds'])) {
            Terminal::moveTo($row, $col);
            if (Terminal::supportsColors()) {
                Terminal::bold();
            }
            echo 'Uptime: ';
            Terminal::reset();
            $uptime = (int)$metrics['uptime_seconds'];
            echo ServerMetricsPane::formatUptime($uptime);
            $row++;
        }

        $row++;

        // Key metrics section
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_CYAN);
        }
        echo 'Key Metrics:';
        Terminal::reset();
        $row++;

        $keyMetrics = [
            'threads_connected' => 'Connections',
            'threads_running' => 'Running',
            'questions' => 'Questions (total)',
            'queries' => 'Queries (total)',
            'slow_queries' => 'Slow Queries',
            'connections' => 'Connections',
            'commits' => 'Commits',
            'rollbacks' => 'Rollbacks',
            'sessions' => 'Sessions',
            'user_connections' => 'User Connections',
        ];

        foreach ($keyMetrics as $key => $label) {
            if (isset($metrics[$key])) {
                Terminal::moveTo($row, $col);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                }
                echo $label . ': ';
                Terminal::reset();
                $value = (int)$metrics[$key];
                echo number_format($value);
                $row++;

                if ($row >= $rows - 2) {
                    break; // Don't overwrite footer
                }
            }
        }

        // File size for SQLite
        if (isset($metrics['file_size'])) {
            $row++;
            Terminal::moveTo($row, $col);
            if (Terminal::supportsColors()) {
                Terminal::bold();
            }
            echo 'File Size: ';
            Terminal::reset();
            $size = (int)$metrics['file_size'];
            echo ServerMetricsPane::formatBytes($size);
            $row++;
        }

        // Show all other available metrics
        $row++;
        $shownFields = ['version', 'uptime_seconds', 'file_size'];
        $shownFields = array_merge($shownFields, array_keys($keyMetrics));

        $hasAdditional = false;
        foreach ($metrics as $key => $value) {
            if (!in_array($key, $shownFields, true) && $key !== 'error' && $value !== null && $value !== '') {
                if (!$hasAdditional) {
                    $row++;
                    Terminal::moveTo($row, $col);
                    if (Terminal::supportsColors()) {
                        Terminal::bold();
                        Terminal::color(Terminal::COLOR_CYAN);
                    }
                    echo 'Additional Metrics:';
                    Terminal::reset();
                    $row++;
                    $hasAdditional = true;
                }

                if ($row >= $rows - 2) {
                    break; // Don't overwrite footer
                }

                Terminal::moveTo($row, $col);
                if (Terminal::supportsColors()) {
                    Terminal::bold();
                }
                echo ucfirst(str_replace('_', ' ', $key)) . ': ';
                Terminal::reset();
                echo is_array($value) ? json_encode($value) : (is_numeric($value) ? number_format((float)$value) : (string)$value);
                $row++;
            }
        }

        // Error message if present
        if (isset($metrics['error'])) {
            $row++;
            Terminal::moveTo($row, $col);
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_RED);
            }
            echo 'Error: ' . (string)$metrics['error'];
            Terminal::reset();
        }

        flush();
    }

    /**
     * Render schema browser fullscreen.
     */
    protected function renderSchemaBrowserFullscreen(): void
    {
        Terminal::clear();
        [$rows, $cols] = Terminal::getSize();

        // Get tables and apply filter
        $tables = TableManager::listTables($this->db);
        $searchFilter = $this->searchFilter[Layout::PANE_SCHEMA];
        $searchFilter = $searchFilter !== '' ? $searchFilter : null;
        if ($searchFilter !== null) {
            $tables = array_filter($tables, function ($table) use ($searchFilter) {
                return stripos($table, $searchFilter) !== false;
            });
            $tables = array_values($tables); // Re-index
        }

        // Calculate scroll offset for fullscreen
        $visibleHeight = $rows - 3;
        $selectedIdx = $this->selectedIndex[Layout::PANE_SCHEMA];

        if ($selectedIdx >= 0 && count($tables) > 0) {
            if ($selectedIdx >= count($tables)) {
                $selectedIdx = count($tables) - 1;
                $this->selectedIndex[Layout::PANE_SCHEMA] = $selectedIdx;
            }

            $relativeIndex = $selectedIdx - $this->scrollOffset[Layout::PANE_SCHEMA];
            if ($relativeIndex < 0) {
                $this->scrollOffset[Layout::PANE_SCHEMA] = $selectedIdx;
            } elseif ($relativeIndex >= $visibleHeight) {
                $this->scrollOffset[Layout::PANE_SCHEMA] = max(0, $selectedIdx - $visibleHeight + 1);
            }
        }

        // Render pane in fullscreen
        $dummyLayout = new Layout($rows, $cols);
        SchemaBrowserPane::render(
            $this->db,
            $dummyLayout,
            Layout::PANE_SCHEMA,
            true,
            $this->selectedIndex[Layout::PANE_SCHEMA],
            $this->scrollOffset[Layout::PANE_SCHEMA],
            true,
            $searchFilter
        );

        // Render search prompt if in search mode, otherwise render footer
        if ($this->searchMode[Layout::PANE_SCHEMA] ?? false) {
            Terminal::moveTo($rows, 1);
            Terminal::clearLine();
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_YELLOW);
                Terminal::bold();
            }
            echo '/';
            if (Terminal::supportsColors()) {
                Terminal::reset();
            }
            echo $this->searchFilter[Layout::PANE_SCHEMA];
            echo '_'; // Cursor
            Terminal::reset();
        } else {
            $this->renderFullscreenFooter();
        }
    }

    /**
     * Render migrations fullscreen.
     */
    protected function renderMigrationsFullscreen(): void
    {
        Terminal::clear();
        [$rows, $cols] = Terminal::getSize();

        // Get migration path
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

        // Calculate scroll offset
        $visibleHeight = $rows - 3;
        $selectedIdx = $this->selectedIndex[Layout::PANE_MIGRATIONS];

        if ($selectedIdx >= 0 && count($allMigrations) > 0) {
            if ($selectedIdx >= count($allMigrations)) {
                $selectedIdx = count($allMigrations) - 1;
                $this->selectedIndex[Layout::PANE_MIGRATIONS] = $selectedIdx;
            }

            $relativeIndex = $selectedIdx - $this->scrollOffset[Layout::PANE_MIGRATIONS];
            if ($relativeIndex < 0) {
                $this->scrollOffset[Layout::PANE_MIGRATIONS] = $selectedIdx;
            } elseif ($relativeIndex >= $visibleHeight) {
                $this->scrollOffset[Layout::PANE_MIGRATIONS] = max(0, $selectedIdx - $visibleHeight + 1);
            }
        }

        // Render header
        Terminal::moveTo(1, 1);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_CYAN);
        }
        echo 'Migration Manager (Fullscreen)';
        Terminal::reset();

        // Render pane in fullscreen
        $dummyLayout = new Layout($rows, $cols);
        MigrationManagerPane::render(
            $this->db,
            $dummyLayout,
            Layout::PANE_MIGRATIONS,
            true,
            $this->selectedIndex[Layout::PANE_MIGRATIONS],
            $this->scrollOffset[Layout::PANE_MIGRATIONS],
            true,
            $migrationPath
        );

        // Render footer
        $this->renderFullscreenFooter();
    }

    /**
     * Render server variables fullscreen.
     */
    protected function renderVariablesFullscreen(): void
    {
        Terminal::clear();
        [$rows, $cols] = Terminal::getSize();

        // Get variables (filtering is handled in ServerVariablesPane)
        $dialect = $this->db->schema()->getDialect();
        $searchFilter = $this->searchFilter[Layout::PANE_VARIABLES];
        $searchFilter = $searchFilter !== '' ? $searchFilter : null;

        // Calculate scroll offset
        $variables = $dialect->getServerVariables($this->db);
        // Apply filter for scroll calculation
        if ($searchFilter !== null) {
            $variables = array_filter($variables, function ($var) use ($searchFilter) {
                $name = $var['name'] ?? '';
                return stripos($name, $searchFilter) !== false;
            });
            $variables = array_values($variables); // Re-index
        }

        $visibleHeight = $rows - 3;
        $selectedIdx = $this->selectedIndex[Layout::PANE_VARIABLES];

        if ($selectedIdx >= 0 && count($variables) > 0) {
            if ($selectedIdx >= count($variables)) {
                $selectedIdx = count($variables) - 1;
                $this->selectedIndex[Layout::PANE_VARIABLES] = $selectedIdx;
            }

            $relativeIndex = $selectedIdx - $this->scrollOffset[Layout::PANE_VARIABLES];
            if ($relativeIndex < 0) {
                $this->scrollOffset[Layout::PANE_VARIABLES] = $selectedIdx;
            } elseif ($relativeIndex >= $visibleHeight) {
                $this->scrollOffset[Layout::PANE_VARIABLES] = max(0, $selectedIdx - $visibleHeight + 1);
            }
        }

        // Render pane in fullscreen
        $dummyLayout = new Layout($rows, $cols);
        ServerVariablesPane::render(
            $this->db,
            $dummyLayout,
            Layout::PANE_VARIABLES,
            true,
            $this->selectedIndex[Layout::PANE_VARIABLES],
            $this->scrollOffset[Layout::PANE_VARIABLES],
            true,
            $searchFilter
        );

        // Render search prompt if in search mode, otherwise render footer
        if ($this->searchMode[Layout::PANE_VARIABLES] ?? false) {
            Terminal::moveTo($rows, 1);
            Terminal::clearLine();
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_YELLOW);
                Terminal::bold();
            }
            echo '/';
            if (Terminal::supportsColors()) {
                Terminal::reset();
            }
            echo $this->searchFilter[Layout::PANE_VARIABLES];
            echo '_'; // Cursor
            Terminal::reset();
        } else {
            $this->renderFullscreenFooter();
        }
    }

    /**
     * Render SQL scratchpad fullscreen.
     */
    protected function renderScratchpadFullscreen(): void
    {
        Terminal::clear();
        [$rows, $cols] = Terminal::getSize();

        // Render header
        Terminal::moveTo(1, 1);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_CYAN);
        }
        $status = [];
        if ($this->scratchpadTransactionMode) {
            $status[] = 'TX';
        }
        if ($this->scratchpadSaveHistory) {
            $status[] = 'SAVE';
        }
        if ($this->scratchpadExportMode) {
            $status[] = 'EXPORT';
        }
        $statusText = !empty($status) ? ' [' . implode(', ', $status) . ']' : '';
        echo 'SQL Scratchpad (Fullscreen)' . $statusText;
        Terminal::reset();

        // Calculate editor and results areas (40% editor, 60% results)
        $editorHeight = (int)floor(($rows - 3) * 0.4);
        $resultsHeight = $rows - 3 - $editorHeight;
        $editorStartRow = 3;
        $resultsStartRow = $editorStartRow + $editorHeight + 1;

        // Render editor
        $this->renderScratchpadEditor($editorStartRow, $editorHeight, $cols);

        // Render separator
        Terminal::moveTo($resultsStartRow - 1, 1);
        echo str_repeat('─', $cols);

        // Render results
        $this->renderScratchpadResults($resultsStartRow, $resultsHeight, $cols);

        // Render autocomplete overlay if active (after everything else, so it's on top)
        if ($this->scratchpadAutocompleteActive && !empty($this->scratchpadAutocompleteOptions)) {
            // Calculate cursor position in editor
            $editorScrollOffset = $this->scrollOffset[Layout::PANE_SCRATCHPAD];
            $cursorLineInEditor = $this->scratchpadCursor['line'] - $editorScrollOffset;
            $cursorRow = $editorStartRow + 1 + $cursorLineInEditor;

            // Try to render autocomplete below cursor line in editor
            $autocompleteStartRow = $cursorRow + 1;

            // If not enough space below in editor, render in results area
            if ($autocompleteStartRow + 11 > $resultsStartRow - 1) {
                // Render in results area instead
                $autocompleteStartRow = $resultsStartRow + 1;
            }

            // Make sure it fits on screen
            if ($autocompleteStartRow + 11 > $rows - 1) {
                $autocompleteStartRow = max(1, $rows - 12);
            }

            $this->renderAutocomplete($autocompleteStartRow, $cols);
        }

        // Render footer
        $this->renderScratchpadFooter($rows, $cols);
    }

    /**
     * Render SQL editor.
     *
     * @param int $startRow Start row
     * @param int $height Height
     * @param int $width Width
     */
    protected function renderScratchpadEditor(int $startRow, int $height, int $width): void
    {
        Terminal::moveTo($startRow, 1);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_YELLOW);
        }
        echo 'SQL Editor:';
        Terminal::reset();

        $lines = explode("\n", $this->scratchpadQuery);
        $numLines = count($lines);

        // Calculate scroll offset for editor
        $editorScrollOffset = $this->scrollOffset[Layout::PANE_SCRATCHPAD];
        if ($this->scratchpadMode === 'editor') {
            // Auto-scroll to cursor line
            $cursorLine = $this->scratchpadCursor['line'];
            $visibleHeight = $height - 1;
            if ($cursorLine < $editorScrollOffset) {
                $editorScrollOffset = max(0, $cursorLine);
            } elseif ($cursorLine >= $editorScrollOffset + $visibleHeight) {
                $editorScrollOffset = max(0, $cursorLine - $visibleHeight + 1);
            }
            $this->scrollOffset[Layout::PANE_SCRATCHPAD] = $editorScrollOffset;
        }

        $startLine = $editorScrollOffset;
        $endLine = min($startLine + $height - 1, $numLines);
        $displayLines = array_slice($lines, $startLine, $endLine - $startLine);

        for ($i = 0; $i < $height - 1; $i++) {
            Terminal::moveTo($startRow + 1 + $i, 1);
            Terminal::clearLine();
            $lineIndex = $startLine + $i;
            if ($lineIndex < $numLines && isset($displayLines[$i])) {
                $line = $displayLines[$i];
                $displayLine = mb_substr($line, 0, $width - 1, 'UTF-8');
                if ($this->scratchpadMode === 'editor' && $lineIndex === $this->scratchpadCursor['line']) {
                    // Highlight current line
                    if (Terminal::supportsColors()) {
                        Terminal::color(Terminal::BG_BLUE);
                        Terminal::color(Terminal::COLOR_WHITE);
                    }
                }
                echo $displayLine;
                Terminal::reset();
            } elseif ($lineIndex >= $numLines && $i === 0 && $numLines > 0) {
                // Show continuation indicator if there are more lines above
                if ($startLine > 0) {
                    if (Terminal::supportsColors()) {
                        Terminal::color(Terminal::COLOR_YELLOW);
                    }
                    echo '... (more lines above)';
                    Terminal::reset();
                }
            }
        }

        // Autocomplete is now rendered in renderScratchpadFullscreen() after results
    }

    /**
     * Render query results.
     *
     * @param int $startRow Start row
     * @param int $height Height
     * @param int $width Width
     */
    protected function renderScratchpadResults(int $startRow, int $height, int $width): void
    {
        Terminal::moveTo($startRow, 1);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_YELLOW);
        }
        echo 'Results:';
        Terminal::reset();

        if ($this->scratchpadLastError !== null) {
            Terminal::moveTo($startRow + 1, 1);
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_RED);
                Terminal::bold();
            }
            echo 'Error: ' . mb_substr($this->scratchpadLastError, 0, $width - 8, 'UTF-8');
            Terminal::reset();
            return;
        }

        if ($this->scratchpadLastResult === null) {
            Terminal::moveTo($startRow + 1, 1);
            echo 'No query executed yet. Press F5 or Enter to execute.';
            return;
        }

        if (empty($this->scratchpadLastResult)) {
            Terminal::moveTo($startRow + 1, 1);
            echo 'Query returned no results.';
            return;
        }

        // Display results in table format
        $scrollOffset = $this->scrollOffset[Layout::PANE_SCRATCHPAD];
        $visibleRows = $height - 2; // Reserve space for header and footer
        $displayRows = array_slice($this->scratchpadLastResult, $scrollOffset, $visibleRows);

        if (empty($displayRows)) {
            return;
        }

        // Get column names
        $columns = array_keys($displayRows[0]);
        $numColumns = count($columns);

        // Calculate column widths
        $colWidths = [];
        foreach ($columns as $col) {
            $colName = is_string($col) ? $col : (string)$col;
            $colWidths[$col] = mb_strlen($colName, 'UTF-8');
        }
        foreach ($displayRows as $row) {
            foreach ($columns as $col) {
                $cellValue = $row[$col] ?? null;
                if (is_array($cellValue)) {
                    $jsonResult = json_encode($cellValue);
                    $valueStr = $jsonResult !== false ? $jsonResult : '[array]';
                } elseif (is_object($cellValue)) {
                    try {
                        if (method_exists($cellValue, '__toString')) {
                            $valueStr = (string)$cellValue;
                        } else {
                            $valueStr = get_class($cellValue);
                        }
                    } catch (\Throwable $e) {
                        $valueStr = get_class($cellValue);
                    }
                } elseif (is_bool($cellValue)) {
                    $valueStr = $cellValue ? '1' : '0';
                } elseif ($cellValue === null) {
                    $valueStr = 'NULL';
                } else {
                    $valueStr = (string)$cellValue;
                }
                $len = mb_strlen($valueStr, 'UTF-8');
                if ($len > $colWidths[$col]) {
                    $colWidths[$col] = min($len, 30); // Max 30 chars per column
                }
            }
        }

        // Adjust column widths to fit screen
        $totalWidth = array_sum($colWidths) + ($numColumns - 1) * 3; // 3 for separators
        if ($totalWidth > $width - 2) {
            $scale = ($width - 2) / $totalWidth;
            foreach ($colWidths as $col => $w) {
                $colWidths[$col] = max(5, (int)floor($w * $scale));
            }
        }

        // Render header
        $headerRow = $startRow + 1;
        Terminal::moveTo($headerRow, 1);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_CYAN);
        }
        $headerParts = [];
        foreach ($columns as $col) {
            $colName = is_string($col) ? $col : (string)$col;
            $headerParts[] = str_pad(mb_substr($colName, 0, $colWidths[$col], 'UTF-8'), $colWidths[$col], ' ', STR_PAD_RIGHT);
        }
        echo implode(' │ ', $headerParts);
        Terminal::reset();

        // Render separator
        Terminal::moveTo($headerRow + 1, 1);
        echo str_repeat('─', min($width - 2, $totalWidth));

        // Render data rows
        $dataStartRow = $headerRow + 2;
        for ($i = 0; $i < count($displayRows) && $i < $visibleRows - 3; $i++) {
            $row = $displayRows[$i];
            Terminal::moveTo($dataStartRow + $i, 1);
            $rowParts = [];
            foreach ($columns as $col) {
                $cellValue = $row[$col] ?? null;
                if (is_array($cellValue)) {
                    $jsonResult = json_encode($cellValue);
                    $valueStr = $jsonResult !== false ? $jsonResult : '[array]';
                } elseif (is_object($cellValue)) {
                    try {
                        if (method_exists($cellValue, '__toString')) {
                            $valueStr = (string)$cellValue;
                        } else {
                            $valueStr = get_class($cellValue);
                        }
                    } catch (\Throwable $e) {
                        $valueStr = get_class($cellValue);
                    }
                } elseif (is_bool($cellValue)) {
                    $valueStr = $cellValue ? '1' : '0';
                } elseif ($cellValue === null) {
                    $valueStr = 'NULL';
                } else {
                    $valueStr = (string)$cellValue;
                }
                $displayValue = mb_substr($valueStr, 0, $colWidths[$col], 'UTF-8');
                if (mb_strlen($valueStr, 'UTF-8') > $colWidths[$col]) {
                    $displayValue = mb_substr($displayValue, 0, $colWidths[$col] - 3, 'UTF-8') . '...';
                }
                $rowParts[] = str_pad($displayValue, $colWidths[$col], ' ', STR_PAD_RIGHT);
            }
            echo implode(' │ ', $rowParts);
        }

        // Show stats
        $statsRow = $startRow + $height - 1;
        Terminal::moveTo($statsRow, 1);
        if (Terminal::supportsColors()) {
            Terminal::color(Terminal::COLOR_YELLOW);
        }
        $stats = sprintf(
            'Rows: %d | Time: %.3fs | Affected: %d',
            count($this->scratchpadLastResult),
            $this->scratchpadLastQueryTime,
            $this->scratchpadAffectedRows
        );
        if ($scrollOffset > 0 || count($this->scratchpadLastResult) > $visibleRows) {
            $stats .= sprintf(' | Scroll: %d-%d/%d', $scrollOffset + 1, min($scrollOffset + $visibleRows, count($this->scratchpadLastResult)), count($this->scratchpadLastResult));
        }
        echo mb_substr($stats, 0, $width - 1, 'UTF-8');
        Terminal::reset();
    }

    /**
     * Render scratchpad footer.
     *
     * @param int $rows Terminal rows
     * @param int $cols Terminal columns
     */
    protected function renderScratchpadFooter(int $rows, int $cols): void
    {
        Terminal::moveTo($rows, 1);
        Terminal::clearLine();

        if (Terminal::supportsColors()) {
            Terminal::color(Terminal::BG_BLUE);
            Terminal::color(Terminal::COLOR_WHITE);
        }

        if ($this->scratchpadMode === 'editor') {
            $help = 'F5:Execute | ↑↓:History | Tab:Results | F2:TX | F3:Save | F4:Export | Esc:Exit';
        } else {
            $help = '↑↓:Scroll | PgUp/PgDn:Page | Tab:Editor | F4:Export | Esc:Exit';
        }

        // Truncate if too long
        if (mb_strlen($help, 'UTF-8') > $cols) {
            $help = mb_substr($help, 0, $cols, 'UTF-8');
        }

        echo $help;
        Terminal::reset();
    }

    /**
     * Render migration file content with syntax highlighting.
     */
    protected function renderMigrationDetailView(): void
    {
        [$rows, $cols] = Terminal::getSize();
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

        $selectedIdx = $this->selectedIndex[Layout::PANE_MIGRATIONS];
        if ($selectedIdx < 0 || $selectedIdx >= count($allMigrations)) {
            return;
        }

        $migration = $allMigrations[$selectedIdx];
        $version = $migration['version'];

        // Find migration file
        $files = glob($migrationPath . '/m*.php');
        if ($files === false) {
            $files = [];
        }
        $migrationFile = null;
        foreach ($files as $file) {
            $basename = basename($file, '.php');
            if (str_starts_with($basename, 'm')) {
                $versionPart = substr($basename, 1);
                if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6}|\d{14})_[a-z0-9_]+$/i', $versionPart) === 1 && $versionPart === $version) {
                    $migrationFile = $file;
                    break;
                }
            }
        }

        if ($migrationFile === null || !file_exists($migrationFile)) {
            Terminal::moveTo(1, 1);
            echo 'Migration file not found';
            return;
        }

        // Read migration file
        $content = file_get_contents($migrationFile);
        if ($content === false) {
            Terminal::moveTo(1, 1);
            echo 'Failed to read migration file';
            return;
        }
        $lines = explode("\n", $content);

        // Extract migration name from class name or comment
        $migrationName = $version;
        foreach ($lines as $line) {
            // Try to find class name
            if (preg_match('/class\s+(\w+)\s+extends/', $line, $matches)) {
                $migrationName = $matches[1];
                break;
            }
            // Try to find comment with migration name
            if (preg_match('/Migration:\s*(.+)/', $line, $matches)) {
                $migrationName = trim($matches[1]);
                break;
            }
        }

        // Parse up() and down() methods
        $upMethod = self::extractMethod($content, 'up');
        $downMethod = self::extractMethod($content, 'down');

        // Render header
        Terminal::moveTo(1, 1);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_CYAN);
        }
        echo 'Migration: ' . $migrationName . ' (Press any key to close)';
        Terminal::reset();

        $row = 3;
        $col = 1;
        $width = $cols - 2;
        $maxRows = $rows - 2;

        // Display migration name
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_MAGENTA);
        }
        echo 'Name: ' . $migrationName;
        Terminal::reset();
        $row += 2;

        // Display up() method
        if ($row > $maxRows) {
            return;
        }
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_GREEN);
        }
        echo 'up() method:';
        Terminal::reset();
        $row++;

        if (!empty($upMethod)) {
            $upLines = explode("\n", $upMethod);
            foreach ($upLines as $line) {
                if ($row > $maxRows) {
                    break;
                }
                Terminal::moveTo($row, $col + 2);
                $displayLine = mb_substr($line, 0, $width - 2, 'UTF-8');
                self::renderHighlightedLine($displayLine);
                $row++;
            }
        } else {
            Terminal::moveTo($row, $col + 2);
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_YELLOW);
            }
            echo '(empty)';
            Terminal::reset();
            $row++;
        }

        $row++;

        // Display down() method
        if ($row > $maxRows) {
            return;
        }
        Terminal::moveTo($row, $col);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_RED);
        }
        echo 'down() method:';
        Terminal::reset();
        $row++;

        if (!empty($downMethod)) {
            $downLines = explode("\n", $downMethod);
            foreach ($downLines as $line) {
                if ($row > $maxRows) {
                    break;
                }
                Terminal::moveTo($row, $col + 2);
                $displayLine = mb_substr($line, 0, $width - 2, 'UTF-8');
                self::renderHighlightedLine($displayLine);
                $row++;
            }
        } else {
            Terminal::moveTo($row, $col + 2);
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_YELLOW);
            }
            echo '(empty)';
            Terminal::reset();
            $row++;
        }
    }

    /**
     * Render table details (Columns/Indexes/Foreign Keys).
     */
    protected function renderTableDetailView(): void
    {
        [$rows, $cols] = Terminal::getSize();
        $tables = TableManager::listTables($this->db);
        $selectedIdx = $this->selectedIndex[Layout::PANE_SCHEMA];

        if ($selectedIdx < 0 || $selectedIdx >= count($tables)) {
            return;
        }

        $tableName = $tables[$selectedIdx];

        // Render header
        Terminal::moveTo(1, 1);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_CYAN);
        }
        echo 'Table: ' . $tableName . ' (Press any key to close)';
        Terminal::reset();

        $row = 3;
        $col = 1;
        $width = $cols - 2;

        try {
            // Get columns
            $columns = TableManager::describe($this->db, $tableName);
            $dialect = $this->db->schema()->getDialect();
            $indexSql = $dialect->buildShowIndexesSql($tableName);
            $indexes = $this->db->rawQuery($indexSql);
            $foreignKeys = $this->db->schema()->getForeignKeys($tableName);

            // Columns section
            Terminal::moveTo($row, $col);
            if (Terminal::supportsColors()) {
                Terminal::bold();
                Terminal::color(Terminal::COLOR_YELLOW);
            }
            echo 'Columns:';
            Terminal::reset();
            $row++;

            foreach ($columns as $column) {
                if ($row > $rows - 10) {
                    break;
                }
                $colName = $column['Field'] ?? $column['column_name'] ?? $column['name'] ?? 'N/A';
                $colType = $column['Type'] ?? $column['data_type'] ?? $column['type'] ?? 'N/A';
                $colNull = ($column['Null'] ?? $column['is_nullable'] ?? '') === 'YES' ? 'NULL' : 'NOT NULL';
                Terminal::moveTo($row, $col + 2);
                echo $colName . ' (' . $colType . ', ' . $colNull . ')';
                $row++;
            }

            $row++;

            // Indexes section
            Terminal::moveTo($row, $col);
            if (Terminal::supportsColors()) {
                Terminal::bold();
                Terminal::color(Terminal::COLOR_YELLOW);
            }
            echo 'Indexes:';
            Terminal::reset();
            $row++;

            foreach ($indexes as $index) {
                if ($row > $rows - 5) {
                    break;
                }
                $idxName = $index['Key_name'] ?? $index['indexname'] ?? $index['name'] ?? 'N/A';
                $idxColumns = $index['Column_name'] ?? $index['column_name'] ?? 'N/A';
                Terminal::moveTo($row, $col + 2);
                echo $idxName . ' (' . $idxColumns . ')';
                $row++;
            }

            $row++;

            // Foreign Keys section
            Terminal::moveTo($row, $col);
            if (Terminal::supportsColors()) {
                Terminal::bold();
                Terminal::color(Terminal::COLOR_YELLOW);
            }
            echo 'Foreign Keys:';
            Terminal::reset();
            $row++;

            foreach ($foreignKeys as $fk) {
                if ($row > $rows - 2) {
                    break;
                }
                $fkName = $fk['constraint_name'] ?? $fk['name'] ?? 'N/A';
                $fkColumn = $fk['column_name'] ?? 'N/A';
                $fkRefTable = $fk['referenced_table_name'] ?? $fk['referenced_table'] ?? 'N/A';
                $fkRefColumn = $fk['referenced_column_name'] ?? $fk['referenced_column'] ?? 'N/A';
                Terminal::moveTo($row, $col + 2);
                echo $fkName . ': ' . $fkColumn . ' -> ' . $fkRefTable . '.' . $fkRefColumn;
                $row++;
            }
        } catch (\Throwable $e) {
            Terminal::moveTo($row, $col);
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_RED);
            }
            echo 'Error: ' . $e->getMessage();
            Terminal::reset();
        }
    }

    /**
     * Extract method body from PHP code.
     *
     * @param string $content PHP file content
     * @param string $methodName Method name (e.g., 'up', 'down')
     *
     * @return string Method body (without method signature)
     */
    protected static function extractMethod(string $content, string $methodName): string
    {
        // Find method signature - more flexible pattern
        // Matches: public function up(): void { or public function up() { or public function up()\n{
        $pattern = '/public\s+function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*(?::\s*void)?\s*\{/';
        if (!preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $startPos = $matches[0][1] + strlen($matches[0][0]);
        $braceCount = 1;
        $pos = $startPos;
        $len = strlen($content);
        $inString = false;
        $stringChar = null;

        // Find matching closing brace, handling strings and comments
        while ($pos < $len && $braceCount > 0) {
            $char = $content[$pos];
            $nextChar = $pos + 1 < $len ? $content[$pos + 1] : '';

            // Handle strings
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
            } elseif ($inString && $char === $stringChar && $content[$pos - 1] !== '\\') {
                $inString = false;
                $stringChar = null;
            }

            // Skip string contents
            if ($inString) {
                $pos++;
                continue;
            }

            // Handle comments
            if ($char === '/' && $nextChar === '/') {
                // Single-line comment - skip to end of line
                while ($pos < $len && $content[$pos] !== "\n") {
                    $pos++;
                }
                continue;
            }
            if ($char === '/' && $nextChar === '*') {
                // Multi-line comment - skip until */
                $pos += 2;
                while ($pos < $len - 1) {
                    if ($content[$pos] === '*' && $content[$pos + 1] === '/') {
                        $pos += 2;
                        break;
                    }
                    $pos++;
                }
                continue;
            }

            // Count braces
            if ($char === '{') {
                $braceCount++;
            } elseif ($char === '}') {
                $braceCount--;
            }
            $pos++;
        }

        if ($braceCount === 0) {
            // Extract method body (without the closing brace)
            $body = substr($content, $startPos, $pos - $startPos - 1);
            // Remove leading/trailing whitespace and trim each line
            $lines = explode("\n", $body);
            $trimmedLines = array_map('trim', $lines);
            // Remove empty lines at start and end
            while (!empty($trimmedLines) && trim($trimmedLines[0]) === '') {
                array_shift($trimmedLines);
            }
            while (!empty($trimmedLines) && trim($trimmedLines[count($trimmedLines) - 1]) === '') {
                array_pop($trimmedLines);
            }
            return implode("\n", $trimmedLines);
        }

        return '';
    }

    /**
     * Render a line with syntax highlighting.
     *
     * @param string $line Line to render
     */
    protected static function renderHighlightedLine(string $line): void
    {
        if (Terminal::supportsColors()) {
            // PHP keywords for highlighting
            $phpKeywords = [
                'abstract', 'and', 'array', 'as', 'break', 'case', 'catch', 'class', 'clone', 'const',
                'continue', 'declare', 'default', 'do', 'else', 'elseif', 'enddeclare', 'endfor',
                'endforeach', 'endif', 'endswitch', 'endwhile', 'extends', 'final', 'for', 'foreach',
                'function', 'global', 'goto', 'if', 'implements', 'interface', 'instanceof', 'namespace',
                'new', 'or', 'private', 'protected', 'public', 'static', 'switch', 'throw', 'trait',
                'try', 'use', 'var', 'while', 'xor', 'return', 'void', 'string', 'int', 'bool', 'float',
                'null', 'true', 'false', 'self', 'parent', 'static', 'void',
            ];

            $result = '';
            $pos = 0;
            $len = mb_strlen($line, 'UTF-8');

            while ($pos < $len) {
                // Check for comments first
                if (mb_substr($line, $pos, 2, 'UTF-8') === '//') {
                    Terminal::color(Terminal::COLOR_YELLOW);
                    $result .= mb_substr($line, $pos, null, 'UTF-8');
                    Terminal::reset();
                    break;
                }

                // Check for strings
                $char = mb_substr($line, $pos, 1, 'UTF-8');
                if ($char === '"' || $char === "'") {
                    $endPos = mb_strpos($line, $char, $pos + 1, 'UTF-8');
                    if ($endPos !== false) {
                        Terminal::color(Terminal::COLOR_GREEN);
                        $result .= mb_substr($line, $pos, $endPos - $pos + 1, 'UTF-8');
                        Terminal::reset();
                        $pos = $endPos + 1;
                        continue;
                    }
                }

                // Check for keywords (word boundaries)
                $matched = false;
                foreach ($phpKeywords as $keyword) {
                    $keywordLen = mb_strlen($keyword, 'UTF-8');
                    if (mb_substr($line, $pos, $keywordLen, 'UTF-8') === $keyword) {
                        // Check word boundaries
                        $before = $pos > 0 ? mb_substr($line, $pos - 1, 1, 'UTF-8') : ' ';
                        $after = mb_substr($line, $pos + $keywordLen, 1, 'UTF-8');
                        if (!ctype_alnum($before) && $before !== '_' && !ctype_alnum($after) && $after !== '_') {
                            Terminal::color(Terminal::COLOR_BLUE);
                            Terminal::bold();
                            $result .= $keyword;
                            Terminal::reset();
                            $pos += $keywordLen;
                            $matched = true;
                            break;
                        }
                    }
                }

                if (!$matched) {
                    $result .= $char;
                    $pos++;
                }
            }
            echo $result;
        } else {
            echo $line;
        }
    }

    /**
     * Handle keyboard input for SQL Scratchpad.
     *
     * @param string $key Key pressed
     *
     * @return bool True if should exit
     */
    protected function handleScratchpadKey(string $key): bool
    {
        // Handle ESC to exit fullscreen
        if ($key === 'esc') {
            $this->fullscreenPane = -1;
            return false;
        }

        // Toggle modes (use F-keys to avoid conflicts with text input)
        if ($key === 'f2') {
            $this->scratchpadTransactionMode = !$this->scratchpadTransactionMode;
            return false;
        }
        if ($key === 'f3') {
            $this->scratchpadSaveHistory = !$this->scratchpadSaveHistory;
            if ($this->scratchpadSaveHistory) {
                $this->loadScratchpadHistory();
            }
            return false;
        }
        if ($key === 'f4') {
            $this->scratchpadExportMode = !$this->scratchpadExportMode;
            if ($this->scratchpadExportMode && $this->scratchpadLastResult !== null) {
                $this->exportScratchpadResults();
            }
            return false;
        }

        // Switch between editor and results (only if autocomplete is not active)
        if ($key === 'tab') {
            if ($this->scratchpadMode === 'editor' && $this->scratchpadAutocompleteActive) {
                // In autocomplete mode, Tab selects current option
                $this->selectAutocompleteOption();
                return false;
            }
            $this->scratchpadMode = $this->scratchpadMode === 'editor' ? 'results' : 'editor';
            $this->scratchpadHistoryIndex = -1; // Reset history navigation
            $this->scratchpadAutocompleteActive = false; // Close autocomplete when switching modes
            return false;
        }

        if ($this->scratchpadMode === 'editor') {
            return $this->handleScratchpadEditorKey($key);
        } else {
            return $this->handleScratchpadResultsKey($key);
        }
    }

    /**
     * Handle keyboard input in SQL editor mode.
     *
     * @param string $key Key pressed
     *
     * @return bool True if should exit
     */
    protected function handleScratchpadEditorKey(string $key): bool
    {
        // Handle autocomplete navigation
        if ($this->scratchpadAutocompleteActive) {
            if ($key === 'up') {
                if ($this->scratchpadAutocompleteIndex > 0) {
                    $this->scratchpadAutocompleteIndex--;
                } else {
                    $this->scratchpadAutocompleteIndex = count($this->scratchpadAutocompleteOptions) - 1;
                }
                return false;
            }
            if ($key === 'down') {
                if ($this->scratchpadAutocompleteIndex < count($this->scratchpadAutocompleteOptions) - 1) {
                    $this->scratchpadAutocompleteIndex++;
                } else {
                    $this->scratchpadAutocompleteIndex = 0;
                }
                return false;
            }
            if ($key === 'enter' || $key === 'tab') {
                $this->selectAutocompleteOption();
                return false;
            }
            if ($key === 'esc') {
                $this->scratchpadAutocompleteActive = false;
                return false;
            }
        }

        // Execute query (F5 or Enter when query is not empty)
        if ($key === 'f5') {
            $this->executeScratchpadQuery();
            $this->scratchpadMode = 'results';
            $this->scratchpadAutocompleteActive = false;
            // Return false - the main loop will re-render immediately after handleKey returns
            return false;
        }

        // Navigation in editor (up/down arrows move cursor between lines, unless autocomplete is active)
        if ($key === 'up') {
            if (!$this->scratchpadAutocompleteActive && $this->scratchpadCursor['line'] > 0) {
                $this->scratchpadCursor['line']--;
                $lines = explode("\n", $this->scratchpadQuery);
                $currentLine = $lines[$this->scratchpadCursor['line']] ?? '';
                $this->scratchpadCursor['col'] = min($this->scratchpadCursor['col'], mb_strlen($currentLine, 'UTF-8'));
            }
            $this->scratchpadHistoryIndex = -1; // Reset history navigation
            return false;
        }
        if ($key === 'down') {
            if (!$this->scratchpadAutocompleteActive) {
                $lines = explode("\n", $this->scratchpadQuery);
                if ($this->scratchpadCursor['line'] < count($lines) - 1) {
                    $this->scratchpadCursor['line']++;
                    $currentLine = $lines[$this->scratchpadCursor['line']] ?? '';
                    $this->scratchpadCursor['col'] = min($this->scratchpadCursor['col'], mb_strlen($currentLine, 'UTF-8'));
                }
            }
            $this->scratchpadHistoryIndex = -1; // Reset history navigation
            return false;
        }
        if ($key === 'left') {
            if ($this->scratchpadCursor['col'] > 0) {
                $this->scratchpadCursor['col']--;
            }
            return false;
        }
        if ($key === 'right') {
            $lines = explode("\n", $this->scratchpadQuery);
            $currentLine = $lines[$this->scratchpadCursor['line']] ?? '';
            if ($this->scratchpadCursor['col'] < mb_strlen($currentLine, 'UTF-8')) {
                $this->scratchpadCursor['col']++;
            }
            return false;
        }

        // Clear editor
        if (($key === 'l' || $key === 'k') && $this->isCtrlPressed()) {
            $this->scratchpadQuery = '';
            $this->scratchpadCursor = ['line' => 0, 'col' => 0];
            $this->scratchpadHistoryIndex = -1;
            return false;
        }

        // Regular text input
        if (strlen($key) === 1 && ord($key) >= 32 && ord($key) <= 126) {
            $this->insertScratchpadChar($key);
            $this->scratchpadHistoryIndex = -1; // Reset history navigation when typing
            // Trigger autocomplete after typing
            $this->triggerAutocomplete();
            return false;
        }

        // Backspace
        if ($key === 'backspace') {
            $this->deleteScratchpadChar();
            // Update autocomplete after deletion
            $this->triggerAutocomplete();
            return false;
        }

        // Enter (new line)
        if ($key === 'enter') {
            $this->insertScratchpadChar("\n");
            return false;
        }

        return false;
    }

    /**
     * Handle keyboard input in results mode.
     *
     * @param string $key Key pressed
     *
     * @return bool True if should exit
     */
    protected function handleScratchpadResultsKey(string $key): bool
    {
        if ($key === 'up') {
            if ($this->scrollOffset[Layout::PANE_SCRATCHPAD] > 0) {
                $this->scrollOffset[Layout::PANE_SCRATCHPAD]--;
            }
            return false;
        }
        if ($key === 'down') {
            if ($this->scratchpadLastResult !== null) {
                $maxScroll = max(0, count($this->scratchpadLastResult) - 10);
                if ($this->scrollOffset[Layout::PANE_SCRATCHPAD] < $maxScroll) {
                    $this->scrollOffset[Layout::PANE_SCRATCHPAD]++;
                }
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
        if ($key === 'home') {
            $this->scrollOffset[Layout::PANE_SCRATCHPAD] = 0;
            return false;
        }
        if ($key === 'end') {
            if ($this->scratchpadLastResult !== null) {
                $this->scrollOffset[Layout::PANE_SCRATCHPAD] = max(0, count($this->scratchpadLastResult) - 10);
            }
            return false;
        }

        return false;
    }

    /**
     * Check if Ctrl key is pressed (simplified - in real TUI this is complex).
     *
     * @return bool
     */
    protected function isCtrlPressed(): bool
    {
        // In TUI, we can't easily detect Ctrl key without escape sequences
        // For now, we'll use a different approach: detect special key combinations
        // This is a placeholder - actual implementation would need InputHandler support
        return false;
    }

    /**
     * Insert character at cursor position in SQL editor.
     *
     * @param string $char Character to insert
     */
    protected function insertScratchpadChar(string $char): void
    {
        $lines = explode("\n", $this->scratchpadQuery);
        $line = $this->scratchpadCursor['line'];
        $col = $this->scratchpadCursor['col'];

        if ($char === "\n") {
            // Insert new line
            $currentLine = $lines[$line] ?? '';
            $before = mb_substr($currentLine, 0, $col, 'UTF-8');
            $after = mb_substr($currentLine, $col, null, 'UTF-8');
            $lines[$line] = $before;
            array_splice($lines, $line + 1, 0, $after);
            $this->scratchpadCursor['line']++;
            $this->scratchpadCursor['col'] = 0;
        } else {
            // Insert character
            $currentLine = $lines[$line] ?? '';
            $before = mb_substr($currentLine, 0, $col, 'UTF-8');
            $after = mb_substr($currentLine, $col, null, 'UTF-8');
            $lines[$line] = $before . $char . $after;
            $this->scratchpadCursor['col']++;
        }

        $this->scratchpadQuery = implode("\n", $lines);
    }

    /**
     * Delete character at cursor position in SQL editor.
     */
    protected function deleteScratchpadChar(): void
    {
        if ($this->scratchpadQuery === '') {
            return;
        }

        $lines = explode("\n", $this->scratchpadQuery);
        $line = $this->scratchpadCursor['line'];
        $col = $this->scratchpadCursor['col'];

        if ($col > 0) {
            // Delete character before cursor
            $currentLine = $lines[$line] ?? '';
            $before = mb_substr($currentLine, 0, $col - 1, 'UTF-8');
            $after = mb_substr($currentLine, $col, null, 'UTF-8');
            $lines[$line] = $before . $after;
            $this->scratchpadCursor['col']--;
        } elseif ($line > 0) {
            // Merge with previous line
            $prevLine = $lines[$line - 1] ?? '';
            $currentLine = $lines[$line] ?? '';
            $lines[$line - 1] = $prevLine . $currentLine;
            array_splice($lines, $line, 1);
            $this->scratchpadCursor['line']--;
            $this->scratchpadCursor['col'] = mb_strlen($prevLine, 'UTF-8');
        }

        $this->scratchpadQuery = implode("\n", $lines);
    }

    /**
     * Navigate query history.
     *
     * @param int $direction Direction (-1 for up, 1 for down)
     */
    protected function navigateScratchpadHistory(int $direction): void
    {
        if (empty($this->scratchpadHistory)) {
            return;
        }

        if ($this->scratchpadHistoryIndex === -1) {
            // Start navigating from current query
            $this->scratchpadHistoryIndex = count($this->scratchpadHistory) - 1;
        } else {
            $this->scratchpadHistoryIndex += $direction;
        }

        if ($this->scratchpadHistoryIndex < 0) {
            $this->scratchpadHistoryIndex = -1;
            return; // Don't load anything, keep current query
        }

        if ($this->scratchpadHistoryIndex >= count($this->scratchpadHistory)) {
            $this->scratchpadHistoryIndex = count($this->scratchpadHistory) - 1;
        }

        if ($this->scratchpadHistoryIndex >= 0) {
            $this->scratchpadQuery = $this->scratchpadHistory[$this->scratchpadHistoryIndex];
            $lines = explode("\n", $this->scratchpadQuery);
            $lastLine = end($lines);
            $this->scratchpadCursor = ['line' => count($lines) - 1, 'col' => $lastLine !== false ? mb_strlen($lastLine, 'UTF-8') : 0];
        }
    }

    /**
     * Execute SQL query from scratchpad.
     */
    protected function executeScratchpadQuery(): void
    {
        $query = $this->scratchpadQuery;
        // Remove trailing whitespace but keep structure
        $query = rtrim($query);
        if ($query === '') {
            return;
        }

        // Split query by semicolons (but be careful with semicolons in strings/comments)
        // For now, simple approach: split by semicolon and execute each non-empty statement
        $statements = $this->splitSqlStatements($query);

        // If multiple statements, execute them all (or just the first one for SELECT)
        if (count($statements) > 1) {
            // Execute all statements, but only show results from the last SELECT
            $lastResult = null;
            $lastError = null;
            $totalAffected = 0;

            foreach ($statements as $stmt) {
                $stmt = trim($stmt);
                if ($stmt === '') {
                    continue;
                }

                try {
                    $result = $this->db->rawQuery($stmt);
                    if (!empty($result) && isset($result[0]) && is_array($result[0])) {
                        $lastResult = $result;
                        $totalAffected = count($result);
                    } else {
                        // DML/DDL - try to get affected rows
                        $totalAffected = 0; // Can't easily get this from rawQuery
                    }
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                    // Continue with next statement or stop?
                    // For now, stop on first error
                    break;
                }
            }

            if ($lastError !== null) {
                $this->scratchpadLastError = $lastError;
                $this->scratchpadLastResult = null;
            } else {
                $this->scratchpadLastResult = $lastResult;
                $this->scratchpadAffectedRows = $totalAffected;
            }

            // Add to history
            if (!in_array($query, $this->scratchpadHistory, true)) {
                $this->scratchpadHistory[] = $query;
                if (count($this->scratchpadHistory) > 50) {
                    array_shift($this->scratchpadHistory);
                }
            }

            if ($this->scratchpadSaveHistory) {
                $this->saveScratchpadHistory();
            }

            return;
        }

        // Single statement - execute normally

        // Add to history
        if (!in_array($query, $this->scratchpadHistory, true)) {
            $this->scratchpadHistory[] = $query;
            // Keep only last 50 queries
            if (count($this->scratchpadHistory) > 50) {
                array_shift($this->scratchpadHistory);
            }
        }

        // Save history if enabled
        if ($this->scratchpadSaveHistory) {
            $this->saveScratchpadHistory();
        }

        $this->scratchpadLastError = null;
        $this->scratchpadLastResult = null;
        $this->scratchpadAffectedRows = 0;
        $this->scrollOffset[Layout::PANE_SCRATCHPAD] = 0;

        $startTime = microtime(true);

        try {
            // Check if transaction mode
            if ($this->scratchpadTransactionMode && !$this->db->connection->inTransaction()) {
                $this->db->startTransaction();
            }

            // Execute query
            $result = $this->db->rawQuery($query);

            // Check if it's a SELECT query (returns array of rows)
            if (!empty($result) && isset($result[0]) && is_array($result[0])) {
                $this->scratchpadLastResult = $result;
                $this->scratchpadAffectedRows = count($result);
            } else {
                // DML/DDL query - rawQuery returns empty array for non-SELECT
                // For affected rows, we need to check the statement
                // Since rawQuery doesn't expose statement, we'll use 0 as default
                // User can see actual affected rows in the query result message
                $this->scratchpadAffectedRows = 0;
                $this->scratchpadLastResult = [];
            }

            // Commit if in transaction mode
            if ($this->scratchpadTransactionMode && $this->db->connection->inTransaction()) {
                $this->db->commit();
            }

            $this->scratchpadLastQueryTime = microtime(true) - $startTime;
        } catch (\Throwable $e) {
            $this->scratchpadLastError = $e->getMessage();
            $this->scratchpadLastQueryTime = microtime(true) - $startTime;

            // If error contains "multiple statements" or similar, suggest splitting
            if (stripos($e->getMessage(), 'multiple statements') !== false ||
                stripos($e->getMessage(), 'syntax error') !== false) {
                // Check if query has multiple semicolons
                $semicolonCount = substr_count($query, ';');
                if ($semicolonCount > 1) {
                    $this->scratchpadLastError .= ' (Hint: Multiple statements detected. Each statement should be executed separately or use transaction mode)';
                }
            }

            // Rollback if in transaction
            if ($this->scratchpadTransactionMode && $this->db->connection->inTransaction()) {
                try {
                    $this->db->rollback();
                } catch (\Throwable $rollbackError) {
                    // Ignore rollback errors
                }
            }
        }
    }

    /**
     * Load scratchpad history from file.
     */
    protected function loadScratchpadHistory(): void
    {
        $historyFile = $this->getScratchpadHistoryFile();
        if (!file_exists($historyFile)) {
            return;
        }

        $content = @file_get_contents($historyFile);
        if ($content === false) {
            return;
        }

        $history = json_decode($content, true);
        if (is_array($history)) {
            $this->scratchpadHistory = $history;
        }
    }

    /**
     * Save scratchpad history to file.
     */
    protected function saveScratchpadHistory(): void
    {
        $historyFile = $this->getScratchpadHistoryFile();
        $dir = dirname($historyFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($historyFile, json_encode($this->scratchpadHistory, JSON_PRETTY_PRINT));
    }

    /**
     * Split SQL query into individual statements.
     *
     * @param string $query SQL query (may contain multiple statements)
     *
     * @return array<int, string> Array of SQL statements
     */
    protected function splitSqlStatements(string $query): array
    {
        // Simple splitting by semicolon (doesn't handle semicolons in strings/comments perfectly)
        // For now, this is a basic implementation
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = null;
        $len = strlen($query);

        for ($i = 0; $i < $len; $i++) {
            $char = $query[$i];
            $nextChar = $i + 1 < $len ? $query[$i + 1] : '';

            // Handle strings
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
                $current .= $char;
            } elseif ($inString && $char === $stringChar && ($i === 0 || $query[$i - 1] !== '\\')) {
                $inString = false;
                $stringChar = null;
                $current .= $char;
            } elseif ($char === ';' && !$inString) {
                // End of statement
                $stmt = trim($current);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $current = '';
            } else {
                $current .= $char;
            }
        }

        // Add remaining statement
        $stmt = trim($current);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }

        return $statements;
    }

    /**
     * Get scratchpad history file path.
     *
     * @return string
     */
    protected function getScratchpadHistoryFile(): string
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());
        return $home . '/.pdodb_scratchpad_history.json';
    }

    /**
     * Export scratchpad results to CSV or JSON.
     */
    protected function exportScratchpadResults(): void
    {
        if ($this->scratchpadLastResult === null || empty($this->scratchpadLastResult)) {
            return;
        }

        $exportDir = sys_get_temp_dir() . '/pdodb_exports';
        if (!is_dir($exportDir)) {
            @mkdir($exportDir, 0755, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $csvFile = $exportDir . '/scratchpad_' . $timestamp . '.csv';
        $jsonFile = $exportDir . '/scratchpad_' . $timestamp . '.json';

        try {
            // Export to CSV
            $csvExporter = new CsvExporter();
            $csvContent = $csvExporter->export($this->scratchpadLastResult, ',', '"', '\\');
            @file_put_contents($csvFile, $csvContent);

            // Export to JSON
            $jsonExporter = new JsonExporter();
            $jsonContent = $jsonExporter->export($this->scratchpadLastResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE, 512);
            @file_put_contents($jsonFile, $jsonContent);

            $this->showMessage("Results exported to:\nCSV: {$csvFile}\nJSON: {$jsonFile}", Terminal::COLOR_GREEN);
        } catch (\Throwable $e) {
            $this->showMessage('Export failed: ' . $e->getMessage(), Terminal::COLOR_RED);
        }
    }

    /**
     * Trigger autocomplete based on current cursor position.
     */
    protected function triggerAutocomplete(): void
    {
        // Force reset autocomplete state first
        $this->scratchpadAutocompleteActive = false;
        $this->scratchpadAutocompleteOptions = [];

        $lines = explode("\n", $this->scratchpadQuery);
        $line = $lines[$this->scratchpadCursor['line']] ?? '';
        $col = $this->scratchpadCursor['col'];

        // Get text before cursor on current line
        $beforeCursor = mb_substr($line, 0, $col, 'UTF-8');

        // Extract current word/prefix being typed
        $prefix = '';
        if (preg_match('/([a-zA-Z_][a-zA-Z0-9_]*)$/', $beforeCursor, $matches)) {
            $prefix = $matches[1];
        } else {
            $this->scratchpadAutocompleteActive = false;
            return;
        }

        // Debug: always log
        file_put_contents('/tmp/pdodb_autocomplete.log', "triggerAutocomplete called with prefix: '$prefix'\n", FILE_APPEND);

        // Step 1: Get all SQL keywords and filter by prefix
        $allKeywords = [
            'SELECT', 'FROM', 'WHERE', 'JOIN', 'INNER JOIN', 'LEFT JOIN', 'RIGHT JOIN',
            'ON', 'AS', 'AND', 'OR', 'NOT', 'IN', 'LIKE', 'BETWEEN', 'IS NULL', 'IS NOT NULL',
            'ORDER BY', 'GROUP BY', 'HAVING', 'LIMIT', 'OFFSET',
            'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE',
            'COUNT', 'SUM', 'AVG', 'MAX', 'MIN', 'DISTINCT',
            'CASE', 'WHEN', 'THEN', 'ELSE', 'END',
            'UNION', 'ALL', 'EXISTS', 'EXCEPT', 'INTERSECT',
        ];

        $prefixLower = mb_strtolower($prefix, 'UTF-8');
        $keywordOptions = [];
        foreach ($allKeywords as $keyword) {
            $keywordLower = mb_strtolower($keyword, 'UTF-8');
            if (mb_strlen($keywordLower, 'UTF-8') >= mb_strlen($prefixLower, 'UTF-8')) {
                $keywordPrefix = mb_substr($keywordLower, 0, mb_strlen($prefixLower, 'UTF-8'), 'UTF-8');
                if ($keywordPrefix === $prefixLower && $keywordLower !== $prefixLower) {
                    $keywordOptions[] = $keyword;
                }
            }
        }
        sort($keywordOptions);

        // Step 2: Get tables and columns, filter by prefix
        $tables = $this->getTablesForAutocomplete();
        $tableColumnOptions = [];

        // Filter tables
        foreach ($tables as $table) {
            $tableLower = mb_strtolower($table, 'UTF-8');
            if (mb_strlen($tableLower, 'UTF-8') >= mb_strlen($prefixLower, 'UTF-8')) {
                $tablePrefix = mb_substr($tableLower, 0, mb_strlen($prefixLower, 'UTF-8'), 'UTF-8');
                if ($tablePrefix === $prefixLower && $tableLower !== $prefixLower) {
                    $tableColumnOptions[] = $table;
                }
            }
        }

        // Filter columns
        foreach ($tables as $table) {
            $columns = $this->getColumnsForAutocomplete($table);
            foreach ($columns as $col) {
                $colLower = mb_strtolower($col, 'UTF-8');
                if (mb_strlen($colLower, 'UTF-8') >= mb_strlen($prefixLower, 'UTF-8')) {
                    $colPrefix = mb_substr($colLower, 0, mb_strlen($prefixLower, 'UTF-8'), 'UTF-8');
                    if ($colPrefix === $prefixLower && $colLower !== $prefixLower) {
                        if (!in_array($col, $tableColumnOptions, true)) {
                            $tableColumnOptions[] = $col;
                        }
                    }
                }
            }
        }
        sort($tableColumnOptions);

        // Step 3: Combine - keywords first, then tables/columns
        $options = array_merge($keywordOptions, $tableColumnOptions);

        // Limit to 20
        if (count($options) > 20) {
            $keywordCount = count($keywordOptions);
            if ($keywordCount >= 20) {
                $options = array_slice($keywordOptions, 0, 20);
            } else {
                $options = array_merge(
                    $keywordOptions,
                    array_slice($tableColumnOptions, 0, 20 - $keywordCount)
                );
            }
        }

        // Set autocomplete
        if (!empty($options)) {
            $this->scratchpadAutocompleteActive = true;
            $this->scratchpadAutocompleteOptions = $options;
            $this->scratchpadAutocompletePrefix = $prefix;
            $this->scratchpadAutocompleteIndex = 0;
        } else {
            $this->scratchpadAutocompleteActive = false;
        }
    }

    /**
     * Filter options by prefix (case-insensitive).
     *
     * @param array<int, string> $options Options to filter
     * @param string $prefix Prefix to match
     *
     * @return array<int, string> Filtered options
     */
    protected function filterOptionsByPrefix(array $options, string $prefix): array
    {
        if ($prefix === '') {
            return [];
        }

        $prefixLower = mb_strtolower($prefix, 'UTF-8');
        $prefixLen = mb_strlen($prefixLower, 'UTF-8');
        $filtered = [];

        foreach ($options as $option) {
            $optionLower = mb_strtolower($option, 'UTF-8');
            $optionLen = mb_strlen($optionLower, 'UTF-8');

            // Match if option starts with prefix (case-insensitive)
            if ($optionLen >= $prefixLen) {
                $optionPrefix = mb_substr($optionLower, 0, $prefixLen, 'UTF-8');
                if ($optionPrefix === $prefixLower) {
                    // Don't include if prefix is exactly the same as option
                    if ($optionLower !== $prefixLower) {
                        $filtered[] = $option;
                    }
                }
            }
        }

        return $filtered;
    }

    /**
     * Get autocomplete context from SQL text (deprecated - not used in simplified autocomplete).
     *
     * @param string $before Text before cursor
     * @param string $after Text after cursor
     *
     * @return string Context type: 'table', 'column', 'keyword', 'table_column', 'select_keyword'
     *
     * @deprecated Not used in simplified autocomplete
     */
    protected function getAutocompleteContext(string $before, string $after): string
    {
        // Don't trim - we need to check for spaces
        $beforeLower = mb_strtolower($before, 'UTF-8');

        // Check if we're after a dot (table.column)
        if (preg_match('/([a-zA-Z_][a-zA-Z0-9_]*)\s*\.\s*$/', $before, $matches)) {
            $tableAlias = $matches[1];
            // Find table name from FROM/JOIN clauses
            $tableName = $this->findTableNameFromAlias($tableAlias);
            if ($tableName !== null) {
                return 'table_column:' . $tableName;
            }
            return 'column';
        }

        // Check if we're right after FROM or JOIN (expecting table name)
        if (preg_match('/\b(?:FROM|JOIN|INNER\s+JOIN|LEFT\s+JOIN|RIGHT\s+JOIN)\s+$/i', $before)) {
            return 'table';
        }

        // Check if we're after FROM/JOIN with space (expecting table name)
        if (preg_match('/\b(?:FROM|JOIN|INNER\s+JOIN|LEFT\s+JOIN|RIGHT\s+JOIN)\s+\S+\s*$/i', $before)) {
            // There's a table name, check what comes next
            // If it's a JOIN, we need ON
            if (preg_match('/\b(?:LEFT|RIGHT|INNER)?\s*JOIN\s+[a-zA-Z_][a-zA-Z0-9_]*(?:\s+[a-zA-Z_][a-zA-Z0-9_]*)?\s*$/i', $before)) {
                return 'on_keyword';
            }
            // Otherwise suggest AS, ON, WHERE, etc.
            return 'keyword_after_table';
        }

        // Check if we're right after FROM/JOIN with just space (no table name yet)
        if (preg_match('/\b(?:FROM|JOIN|INNER\s+JOIN|LEFT\s+JOIN|RIGHT\s+JOIN)\s+$/i', $before)) {
            return 'table';
        }

        // Check if we're after LEFT JOIN / RIGHT JOIN / INNER JOIN with table name (expecting ON)
        // Match: LEFT JOIN table or LEFT JOIN table alias
        if (preg_match('/\b(?:LEFT|RIGHT|INNER)\s+JOIN\s+[a-zA-Z_][a-zA-Z0-9_]*(?:\s+[a-zA-Z_][a-zA-Z0-9_]*)?\s+$/i', $before)) {
            return 'on_keyword';
        }

        // Also check if we're after JOIN table (without LEFT/RIGHT/INNER)
        if (preg_match('/\bJOIN\s+[a-zA-Z_][a-zA-Z0-9_]*(?:\s+[a-zA-Z_][a-zA-Z0-9_]*)?\s+$/i', $before) &&
            !preg_match('/\b(?:LEFT|RIGHT|INNER)\s+JOIN/i', $before)) {
            return 'on_keyword';
        }

        // Check if we're after SELECT (expecting column or *)
        if (preg_match('/\bSELECT\s+$/i', $before)) {
            return 'select_column';
        }

        // Check if we're after SELECT with some columns already
        if (preg_match('/\bSELECT\s+/i', $before) && !preg_match('/\bFROM\b/i', $before)) {
            // Still in SELECT clause, suggest columns or FROM
            return 'select_column';
        }

        // Check if we're after WHERE (expecting column name)
        if (preg_match('/\bWHERE\s+$/i', $before)) {
            return 'column';
        }

        // Check if we're after WHERE with some condition already
        if (preg_match('/\bWHERE\s+/i', $before)) {
            // Might need column or operator (AND, OR, etc.)
            // Check if we're in the middle of a condition
            if (preg_match('/\bWHERE\s+[^=<>!]+[=<>!]+\s*$/i', $before)) {
                // After operator, suggest value or AND/OR
                return 'keyword_after_where';
            }
            return 'column';
        }

        // Check if we're after ORDER BY, GROUP BY, HAVING
        if (preg_match('/\b(?:ORDER\s+BY|GROUP\s+BY|HAVING)\s+$/i', $before)) {
            return 'column';
        }

        // Check if we're at the start of query or after semicolon
        if (trim($before) === '' || preg_match('/;\s*$/', $before)) {
            return 'select_keyword';
        }

        // Default: suggest keywords (but filter out inappropriate ones)
        return 'keyword';
    }

    /**
     * Find table name from alias in FROM/JOIN clauses.
     *
     * @param string $alias Table alias
     *
     * @return string|null Table name or null if not found
     */
    protected function findTableNameFromAlias(string $alias): ?string
    {
        $query = $this->scratchpadQuery;

        // Look for: FROM table AS alias or FROM table alias or JOIN table AS alias
        if (preg_match('/\b(?:FROM|JOIN)\s+([a-zA-Z_][a-zA-Z0-9_]*)\s+(?:AS\s+)?' . preg_quote($alias, '/') . '\b/i', $query, $matches)) {
            return $matches[1];
        }

        // If alias matches a table name, return it
        $tables = $this->getTablesForAutocomplete();
        if (in_array($alias, $tables, true)) {
            return $alias;
        }

        return null;
    }

    /**
     * Get tables for autocomplete (cached).
     *
     * @return array<int, string>
     */
    protected function getTablesForAutocomplete(): array
    {
        if ($this->scratchpadTableCache === null) {
            try {
                $this->scratchpadTableCache = TableManager::listTables($this->db);
            } catch (\Throwable $e) {
                $this->scratchpadTableCache = [];
            }
        }

        return $this->scratchpadTableCache;
    }

    /**
     * Get columns for table (cached).
     *
     * @param string $table Table name
     *
     * @return array<int, string>
     */
    protected function getColumnsForAutocomplete(string $table): array
    {
        if (!isset($this->scratchpadColumnCache[$table])) {
            try {
                $columns = TableManager::describe($this->db, $table);
                $columnNames = [];
                foreach ($columns as $col) {
                    $colName = is_array($col) ? ($col['Field'] ?? $col['column_name'] ?? $col['name'] ?? '') : (string)$col;
                    if ($colName !== '') {
                        $columnNames[] = $colName;
                    }
                }
                $this->scratchpadColumnCache[$table] = $columnNames;
            } catch (\Throwable $e) {
                $this->scratchpadColumnCache[$table] = [];
            }
        }

        return $this->scratchpadColumnCache[$table];
    }

    /**
     * Get SQL keywords for autocomplete.
     *
     * @return array<int, string>
     */
    protected function getSqlKeywords(): array
    {
        return [
            'SELECT', 'FROM', 'WHERE', 'JOIN', 'INNER JOIN', 'LEFT JOIN', 'RIGHT JOIN',
            'ON', 'AS', 'AND', 'OR', 'NOT', 'IN', 'LIKE', 'BETWEEN', 'IS NULL', 'IS NOT NULL',
            'ORDER BY', 'GROUP BY', 'HAVING', 'LIMIT', 'OFFSET',
            'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE',
            'COUNT', 'SUM', 'AVG', 'MAX', 'MIN', 'DISTINCT',
            'CASE', 'WHEN', 'THEN', 'ELSE', 'END',
            'UNION', 'ALL', 'EXISTS', 'EXCEPT', 'INTERSECT',
        ];
    }

    /**
     * Select current autocomplete option and insert it.
     */
    protected function selectAutocompleteOption(): void
    {
        if (!$this->scratchpadAutocompleteActive || empty($this->scratchpadAutocompleteOptions)) {
            return;
        }

        $selected = $this->scratchpadAutocompleteOptions[$this->scratchpadAutocompleteIndex] ?? '';
        if ($selected === '') {
            return;
        }

        $lines = explode("\n", $this->scratchpadQuery);
        $line = $lines[$this->scratchpadCursor['line']] ?? '';
        $col = $this->scratchpadCursor['col'];

        // Get text before and after cursor
        $before = mb_substr($line, 0, $col, 'UTF-8');
        $after = mb_substr($line, $col, null, 'UTF-8');

        // Replace prefix with selected option
        if ($this->scratchpadAutocompletePrefix !== '') {
            $prefixLen = mb_strlen($this->scratchpadAutocompletePrefix, 'UTF-8');
            $before = mb_substr($before, 0, -$prefixLen, 'UTF-8');
        }

        // Insert selected option
        $newLine = $before . $selected . $after;
        $lines[$this->scratchpadCursor['line']] = $newLine;
        $this->scratchpadQuery = implode("\n", $lines);

        // Update cursor position
        $this->scratchpadCursor['col'] = mb_strlen($before, 'UTF-8') + mb_strlen($selected, 'UTF-8');

        // Close autocomplete
        $this->scratchpadAutocompleteActive = false;
    }

    /**
     * Render autocomplete suggestions.
     *
     * @param int $startRow Start row for autocomplete
     * @param int $width Width
     */
    protected function renderAutocomplete(int $startRow, int $width): void
    {
        if (empty($this->scratchpadAutocompleteOptions)) {
            return;
        }

        // Debug: log what we're rendering
        file_put_contents(
            '/tmp/pdodb_autocomplete.log',
            'renderAutocomplete: ' . count($this->scratchpadAutocompleteOptions) . ' options - ' .
            implode(', ', array_slice($this->scratchpadAutocompleteOptions, 0, 10)) . "\n",
            FILE_APPEND
        );

        [$rows, $cols] = Terminal::getSize();

        // Calculate available space (leave at least 1 row for footer if needed)
        $availableHeight = max(1, $rows - $startRow - 1);
        $maxHeight = min(10, $availableHeight);
        $numOptions = count($this->scratchpadAutocompleteOptions);

        // Calculate scroll offset (start from 0, no offset initially)
        $scrollOffset = 0;
        if ($this->scratchpadAutocompleteIndex >= $maxHeight) {
            $scrollOffset = $this->scratchpadAutocompleteIndex - $maxHeight + 1;
        }

        // Render header (only if it fits)
        if ($startRow < $rows) {
            Terminal::moveTo($startRow, 1);
            Terminal::clearLine();
            if (Terminal::supportsColors()) {
                Terminal::bold();
                Terminal::color(Terminal::COLOR_CYAN);
            }
            echo 'Autocomplete:';
            Terminal::reset();
        }

        // Render options starting from index 0 (no scroll offset initially)
        $visibleOptions = array_slice($this->scratchpadAutocompleteOptions, $scrollOffset, $maxHeight);
        foreach ($visibleOptions as $i => $option) {
            $actualIndex = $scrollOffset + $i;
            $row = $startRow + 1 + $i;

            // Make sure we don't go beyond screen
            if ($row >= $rows) {
                break;
            }

            Terminal::moveTo($row, 1);
            Terminal::clearLine();

            if ($actualIndex === $this->scratchpadAutocompleteIndex) {
                // Highlight selected option
                if (Terminal::supportsColors()) {
                    Terminal::color(Terminal::BG_BLUE);
                    Terminal::color(Terminal::COLOR_WHITE);
                }
                echo '> ';
            } else {
                echo '  ';
            }

            $displayOption = mb_substr($option, 0, $width - 3, 'UTF-8');
            echo $displayOption;
            Terminal::reset();
        }

        // Show hint
        if ($numOptions > $maxHeight) {
            Terminal::moveTo($startRow + $maxHeight + 1, 1);
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_YELLOW);
            }
            echo sprintf('(%d more, ↑↓:Navigate, Tab/Enter:Select, Esc:Cancel)', $numOptions - $maxHeight);
            Terminal::reset();
        }
    }
}
