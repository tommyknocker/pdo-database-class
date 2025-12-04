<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui;

use tommyknocker\pdodb\cli\ui\actions\KillConnectionAction;
use tommyknocker\pdodb\cli\ui\actions\KillQueryAction;
use tommyknocker\pdodb\cli\ui\panes\ActiveQueriesPane;
use tommyknocker\pdodb\cli\ui\panes\CacheStatsPane;
use tommyknocker\pdodb\cli\ui\panes\ConnectionPoolPane;
use tommyknocker\pdodb\cli\ui\panes\ServerMetricsPane;
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
    }

    /**
     * Run dashboard main loop.
     */
    public function run(): void
    {
        // Check if interactive
        if (!stream_isatty(STDOUT) || getenv('PDODB_NON_INTERACTIVE') !== false) {
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

            // Handle input (non-blocking)
            $key = InputHandler::readKey(100000); // 0.1 seconds

            if ($key !== null) {
                if ($this->handleKey($key)) {
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
            // Close detail view on any key
            $this->detailViewPane = -1;
            return false;
        }

        // Handle fullscreen mode
        if ($this->fullscreenPane >= 0) {
            if ($key === 'esc') {
                $this->fullscreenPane = -1;
                return false;
            }
            if ($key === 'enter') {
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
                }
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
                break;

            case '2':
                $this->activePane = Layout::PANE_CONNECTIONS;
                $this->selectedIndex[$this->activePane] = 0;
                $this->scrollOffset[$this->activePane] = 0;
                break;

            case '3':
                $this->activePane = Layout::PANE_CACHE;
                $this->selectedIndex[$this->activePane] = 0;
                $this->scrollOffset[$this->activePane] = 0;
                break;

            case '4':
                $this->activePane = Layout::PANE_METRICS;
                $this->selectedIndex[$this->activePane] = 0;
                $this->scrollOffset[$this->activePane] = 0;
                break;

            case 'tab':
            case 'right':
                $this->activePane = ($this->activePane + 1) % Layout::PANE_COUNT;
                $this->selectedIndex[$this->activePane] = 0;
                $this->scrollOffset[$this->activePane] = 0;
                break;

            case 'left':
                $this->activePane = ($this->activePane - 1 + Layout::PANE_COUNT) % Layout::PANE_COUNT;
                $this->selectedIndex[$this->activePane] = 0;
                $this->scrollOffset[$this->activePane] = 0;
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

        // Render all panes
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

        CacheStatsPane::render($this->db, $this->layout, Layout::PANE_CACHE, $this->activePane === Layout::PANE_CACHE);
        ServerMetricsPane::render($this->db, $this->layout, Layout::PANE_METRICS, $this->activePane === Layout::PANE_METRICS, $this->cachedServerMetrics);

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
        $help = '1-4:Switch | ↑↓:Select | Enter:Full | x:Kill | r:Ref(' . $refreshLabel . ') | h:Help | q:Quit';
        
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
        $this->cachedQueries = \tommyknocker\pdodb\cli\MonitorManager::getActiveQueries($this->db);
        $this->cachedConnections = \tommyknocker\pdodb\cli\MonitorManager::getActiveConnections($this->db);
        $this->cachedServerMetrics = $this->db->schema()->getDialect()->getServerMetrics($this->db);
    }

    /**
     * Handle up arrow key.
     */
    protected function handleUp(): void
    {
        $pane = $this->fullscreenPane >= 0 ? $this->fullscreenPane : $this->activePane;
        if ($pane === Layout::PANE_QUERIES || $pane === Layout::PANE_CONNECTIONS) {
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
        Terminal::clear();
        [$rows, $cols] = Terminal::getSize();

        if ($this->fullscreenPane === Layout::PANE_QUERIES) {
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

                $relativeIndex = $selectedIdx - $this->scrollOffset[Layout::PANE_QUERIES];
                if ($relativeIndex < 0) {
                    $this->scrollOffset[Layout::PANE_QUERIES] = $selectedIdx;
                } elseif ($relativeIndex >= $visibleHeight) {
                    $this->scrollOffset[Layout::PANE_QUERIES] = max(0, $selectedIdx - $visibleHeight + 1);
                }
            }

            // Render header
            Terminal::moveTo(1, 1);
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

                $relativeIndex = $selectedIdx - $this->scrollOffset[Layout::PANE_CONNECTIONS];
                if ($relativeIndex < 0) {
                    $this->scrollOffset[Layout::PANE_CONNECTIONS] = $selectedIdx;
                } elseif ($relativeIndex >= $visibleHeight) {
                    $this->scrollOffset[Layout::PANE_CONNECTIONS] = max(0, $selectedIdx - $visibleHeight + 1);
                }
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
            Terminal::moveTo($rows, 1);
            Terminal::clearLine();
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::BG_BLUE);
                Terminal::color(Terminal::COLOR_WHITE);
            }
            echo 'Press Esc to exit fullscreen';
            Terminal::reset();
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
            Terminal::moveTo($rows, 1);
            Terminal::clearLine();
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::BG_BLUE);
                Terminal::color(Terminal::COLOR_WHITE);
            }
            echo 'Press Esc to exit fullscreen';
            Terminal::reset();
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
        }

        // Render footer
        Terminal::moveTo($rows, 1);
        Terminal::clearLine();
        if (Terminal::supportsColors()) {
            Terminal::color(Terminal::BG_BLUE);
            Terminal::color(Terminal::COLOR_WHITE);
        }
        echo 'Press any key to close';
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
                    echo \tommyknocker\pdodb\cli\ui\panes\ServerMetricsPane::formatBytes((int)$size);
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
            echo \tommyknocker\pdodb\cli\ui\panes\ServerMetricsPane::formatUptime($uptime);
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
            echo \tommyknocker\pdodb\cli\ui\panes\ServerMetricsPane::formatBytes($size);
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
}
